<?php

namespace App\Services\Billing;

use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\RefundApprovedEs;
use App\Notifications\RefundRejectedEs;
use App\Notifications\RefundRequestedEs;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Reembolsos manuales con aprobación del administrador (reglas en config/billing.php).
 * Cada orden pagada se evalúa por separado. La solicitud no toca el plan: el plan se corta cuando el admin aprueba.
 * El dinero se devuelve a mano desde Mercado Pago o PayPal: acá no se llama a ninguna de sus APIs de reembolso.
 */
class RefundService
{
    /** @return array{days_since: int, within: bool, deadline: Carbon} días corridos desde el pago y vencimiento del plazo */
    public function window(Order $order): array
    {
        $max = (int) config('billing.refund_window_days');
        $days = (int) $order->paid_at->copy()->startOfDay()->diffInDays(now()->startOfDay());

        return [
            'days_since' => $days,
            'within' => $days <= $max,
            'deadline' => $order->paid_at->copy()->addDays($max),
        ];
    }

    /** Cada compra pagada admite UNA sola solicitud: la que exista, sea cual sea su estado (pendiente, aprobada o rechazada). */
    public function existingRequest(Order $order): ?RefundRequest
    {
        return RefundRequest::where('order_id', $order->id)->latest('id')->first();
    }

    /** Por qué no se puede pedir el reembolso de esta orden (null = se puede). */
    public function blockedReason(Order $order): ?string
    {
        if ($order->status === 'refunded') {
            return 'Esta compra ya fue reembolsada.';
        }
        if ($order->status !== 'paid' || ! $order->paid_at) {
            return 'Solo se pueden reembolsar compras pagadas.';
        }
        if ($existing = $this->existingRequest($order)) {
            // Una solicitud rechazada no se puede volver a pedir: se consulta por Contacto
            return $existing->status === 'rejected'
                ? "Solicitud rechazada ({$existing->code}). Si querés volver a consultarlo, escribinos desde Contacto"
                : "Esta compra ya tiene una solicitud de reembolso ({$existing->code}).";
        }
        $window = $this->window($order);
        if (! $window['within']) {
            return 'El plazo de reembolso venció el ' . $this->date($window['deadline']) . '.';
        }

        return null;
    }

    /** ¿Tiene alguna compra que todavía pueda reembolsar? (para mostrar el botón en Mi Suscripción) */
    public function hasEligibleOrder(User $user): bool
    {
        return Order::where('user_id', $user->id)
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays((int) config('billing.refund_window_days') + 1)->startOfDay())
            ->get()
            ->contains(fn (Order $order) => $this->blockedReason($order) === null);
    }

    public function request(User $user, int $orderId, ?string $reason, ?string $phone): RefundRequest
    {
        $order = Order::with('plan')->where('user_id', $user->id)->findOrFail($orderId);

        if ($message = $this->blockedReason($order)) {
            throw ValidationException::withMessages(['order_id' => [$message]]);
        }

        $percent = (int) config('billing.refund_percent');
        $refund = DB::transaction(function () use ($user, $order, $reason, $phone, $percent) {
            $refund = RefundRequest::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'reason' => $reason,
                'phone' => $phone,
                'refund_percent' => $percent,
                'amount' => round($order->amount * $percent / 100, 2),
                'currency' => $order->currency,
            ]);
            $refund->update(['code' => sprintf('ARR-%06d', $refund->id)]);

            return $refund->refresh(); // con los valores por defecto de la base (status)
        });

        $this->safely(fn () => $user->notify(new RefundRequestedEs($refund->load('order.plan'))));
        // Aviso al equipo, a la misma casilla que recibe los mensajes de contacto
        if ($to = config('mail.contact_to')) {
            $this->safely(fn () => Mail::raw(
                "Nueva solicitud de reembolso {$refund->code}\nUsuario: {$user->name} <{$user->email}>\nOrden: #{$order->id} - {$order->plan->name} ({$order->currency} {$order->amount})\n\nEntrá al panel admin, sección Reembolsos, para revisarla.",
                fn ($m) => $m->to($to)->subject("[Reembolso] {$refund->code}")
            ));
        }

        return $refund;
    }

    /** Aprueba con el comprobante del reembolso hecho a mano: marca la orden, corta el plan y avisa al usuario. */
    public function approve(RefundRequest $refund, User $admin, UploadedFile $receipt, ?string $note): RefundRequest
    {
        $this->assertPending($refund);

        $path = $receipt->store("refund-receipts/{$refund->id}", 'local'); // disco privado
        DB::transaction(function () use ($refund, $admin, $receipt, $path, $note) {
            $refund->update([
                'status' => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $note,
                'receipt_path' => $path,
                'receipt_name' => $receipt->getClientOriginalName(),
            ]);

            // Si un aviso del proveedor ya había marcado la orden, no se toca de nuevo
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->first();
            if ($order->status === 'paid') {
                $order->update(['status' => 'refunded']);
                DB::table('payments')->where('provider', $order->provider)->where('provider_payment_id', $order->provider_payment_id)
                    ->update(['status' => 'refunded', 'updated_at' => now()]);
            }

            $this->cutPlan($refund->user);
        });

        $this->safely(fn () => $refund->user->notify(new RefundApprovedEs($refund->fresh(['order.plan']))));

        return $refund->fresh();
    }

    public function reject(RefundRequest $refund, User $admin, string $reason): RefundRequest
    {
        $this->assertPending($refund);

        $refund->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->safely(fn () => $refund->user->notify(new RefundRejectedEs($refund->fresh(['order.plan']))));

        return $refund->fresh();
    }

    /**
     * Un reembolso aprobado de cualquier orden corta el plan: el usuario vuelve a Free y se cancelan las
     * compras pendientes y los planes encolados. Los planes manuales (cortesías) no se tocan.
     */
    private function cutPlan(User $user): void
    {
        $now = now();

        $user->subscriptions()
            ->where('provider', '!=', 'manual')
            ->whereIn('status', ['active', 'scheduled'])
            ->get()
            ->each(function (Subscription $subscription) use ($now) {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $now,
                    // un plan que no llegó a empezar conserva sus fechas; uno en curso termina hoy
                    'ends_at' => $subscription->starts_at->isFuture() ? $subscription->ends_at : $now,
                ]);
            });

        Order::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    private function assertPending(RefundRequest $refund): void
    {
        if ($refund->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ["La solicitud {$refund->code} ya fue resuelta."]]);
        }
    }

    /** Si el correo falla (SMTP caído) la operación igual queda hecha. */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function date(Carbon $date): string
    {
        return $date->copy()->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
