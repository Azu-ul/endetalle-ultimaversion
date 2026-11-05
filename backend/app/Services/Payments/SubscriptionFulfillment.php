<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Subscription;
use App\Notifications\PaymentConfirmedEs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Activa (o extiende) la suscripción de una orden pagada y la revoca si el pago se devuelve.
 * Los webhooks pueden llegar repetidos y en cualquier orden: ambos métodos son idempotentes.
 */
class SubscriptionFulfillment
{
    /** @return bool true si esta llamada fue la que activó el plan (false si ya estaba procesado) */
    public function confirm(Order $order, string $providerPaymentId, array $payload = []): bool
    {
        $activated = DB::transaction(function () use ($order, $providerPaymentId, $payload) {
            // La fila de la orden se bloquea: dos webhooks simultáneos no pueden activar dos veces
            $locked = Order::with(['plan', 'user'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            // Un pago que llega tarde (orden vencida) igual se acredita: la plata ya entró
            if (in_array($locked->status, ['paid', 'refunded'], true)) {
                return false;
            }

            $now = now();
            $user = $locked->user;

            $upgradeFrom = $locked->upgrade_from_id ? Subscription::lockForUpdate()->find($locked->upgrade_from_id) : null;
            $startsAfter = $locked->starts_after_id ? Subscription::lockForUpdate()->find($locked->starts_after_id) : null;
            // Plan menor: solo se encola si el plan que tiene que esperar todavía rige
            $queueBehind = $startsAfter && in_array($startsAfter->status, ['active', 'scheduled'], true) && $startsAfter->ends_at->isFuture();

            $current = $user->subscriptions()
                ->live()
                ->where('plan_id', $locked->plan_id)
                ->where('provider', '!=', 'manual')
                ->where('ends_at', '>', $now)
                ->orderByDesc('ends_at')
                ->first();

            if ($queueBehind) {
                // Bajada de plan: empieza cuando vence el actual (se suma al vencimiento); hasta entonces sigue el actual
                $start = $startsAfter->ends_at->copy();
                $lastQueued = $user->subscriptions()->where('status', 'scheduled')->orderByDesc('ends_at')->first();
                if ($lastQueued && $lastQueued->ends_at->greaterThan($start)) {
                    $start = $lastQueued->ends_at->copy();
                }

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $locked->plan_id,
                    'status' => 'scheduled',
                    'provider' => $locked->provider,
                    'provider_subscription_id' => $providerPaymentId,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addMonthsNoOverflow($locked->months),
                ]);
            } elseif ($locked->upgrade_from_id) {
                // Subida de plan: el nuevo arranca hoy y conserva el vencimiento del anterior (sin reiniciar el período)
                $oldIsActive = $upgradeFrom && $upgradeFrom->status === 'active' && $upgradeFrom->ends_at->isFuture();
                $ends = $oldIsActive
                    ? $upgradeFrom->ends_at->copy()->max($locked->ends_at)
                    // El anterior ya terminó cuando entró el pago: se le da el tiempo que había acordado
                    : $now->copy()->addSeconds(max(86400, $locked->ends_at->getTimestamp() - $locked->created_at->getTimestamp()));

                $user->subscriptions()
                    ->live()
                    ->where('provider', '!=', 'manual')
                    ->update(['status' => 'cancelled', 'cancelled_at' => $now]);

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $locked->plan_id,
                    'status' => 'active',
                    'provider' => $locked->provider,
                    'provider_subscription_id' => $providerPaymentId,
                    'starts_at' => $now,
                    'ends_at' => $ends,
                    // Si había cancelado la renovación, sigue sin renovarse
                    'cancelled_at' => $oldIsActive ? $upgradeFrom->cancelled_at : null,
                ]);
            } elseif ($current) {
                // Renovación: los meses se suman al final del plan vigente (y si estaba cancelado, vuelve a estar activo)
                $current->update([
                    'ends_at' => $current->ends_at->copy()->addMonthsNoOverflow($locked->months),
                    'reminded_at' => null,
                    'cancelled_at' => null,
                ]);
                // Un plan menor encolado se corre para seguir empezando después del vencimiento nuevo
                $user->subscriptions()->where('status', 'scheduled')->get()->each(fn (Subscription $queued) => $queued->update([
                    'starts_at' => $queued->starts_at->copy()->addMonthsNoOverflow($locked->months),
                    'ends_at' => $queued->ends_at->copy()->addMonthsNoOverflow($locked->months),
                ]));
                $subscription = $current;
            } else {
                // Otro plan pago que siga vigente queda reemplazado por el nuevo
                $user->subscriptions()
                    ->live()
                    ->where('provider', '!=', 'manual')
                    ->update(['status' => 'cancelled', 'cancelled_at' => $now]);

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $locked->plan_id,
                    'status' => 'active',
                    'provider' => $locked->provider,
                    'provider_subscription_id' => $providerPaymentId,
                    'starts_at' => $now,
                    'ends_at' => $now->copy()->addMonthsNoOverflow($locked->months),
                ]);
            }

            DB::table('payments')->insertOrIgnore([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'provider' => $locked->provider,
                'provider_payment_id' => $providerPaymentId,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
                'status' => 'paid',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'paid_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locked->update([
                'status' => 'paid',
                'provider_payment_id' => $providerPaymentId,
                'subscription_id' => $subscription->id,
                'paid_at' => $now,
            ]);

            return true;
        });

        if ($activated) {
            // Si el correo falla, el plan ya quedó activo
            try {
                $order->refresh()->user->notify(new PaymentConfirmedEs($order->load('plan', 'subscription')));
            } catch (\Throwable $e) {
                report($e);
            }

            $this->notifySale($order);
        }

        return $activated;
    }

    /** Aviso interno por mail: "alguien pagó". Si falla, no afecta al pago ni al plan. */
    private function notifySale(Order $order): void
    {
        $to = config('mail.sales_to');
        if (! $to) {
            return;
        }

        try {
            $order = $order->fresh(['plan', 'user', 'subscription']);
            $amount = ($order->currency === 'USD' ? 'US$ ' : '$ ') . number_format($order->amount, 2, ',', '.');
            $ends = $order->subscription?->ends_at?->locale('es')->translatedFormat('j \d\e F \d\e Y');
            $provider = $order->provider === 'paypal' ? 'PayPal' : 'Mercado Pago';
            $plan = $order->plan->name;

            Mail::raw(
                "Se acreditó un pago en " . config('app.name') . ".\n\n"
                . "Plan: {$plan} ({$order->months} " . ($order->months === 1 ? 'mes' : 'meses') . ")\n"
                . "Monto: {$amount}\n"
                . "Medio de pago: {$provider}\n"
                . "Cliente: {$order->user->name} <{$order->user->email}>\n"
                . ($ends ? "Plan activo hasta: {$ends}\n" : '')
                . "Orden: {$order->uuid}\n",
                fn ($m) => $m->to($to)->subject("Nuevo pago: plan {$plan} ({$amount})")
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Devolución o contracargo: se quita lo que cubría esa orden. */
    public function revoke(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::with('subscription')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'paid') {
                return false;
            }

            $locked->update(['status' => 'refunded']);
            DB::table('payments')
                ->where('provider', $locked->provider)
                ->where('provider_payment_id', $locked->provider_payment_id)
                ->update(['status' => 'refunded', 'updated_at' => now()]);

            if ($locked->starts_after_id) {
                // Se devuelve un plan menor encolado: se cancela (si ya había empezado, termina hoy); el actual no se toca
                if ($queued = $locked->subscription) {
                    $queued->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'ends_at' => $queued->starts_at->isFuture() ? $queued->ends_at : now(),
                    ]);
                }
            } elseif ($locked->upgrade_from_id) {
                // Se devuelve la diferencia: el plan nuevo se cierra y, si todavía rige, vuelve el anterior
                $locked->subscription?->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()]);
                $old = Subscription::find($locked->upgrade_from_id);
                if ($old && $old->status === 'cancelled' && $old->ends_at->isFuture()) {
                    $old->update(['status' => 'active', 'cancelled_at' => null]);
                }
            } elseif ($subscription = $locked->subscription) {
                $ends = $subscription->ends_at?->copy()->subMonthsNoOverflow($locked->months);
                if (! $ends || $ends->lessThanOrEqualTo(now())) {
                    $subscription->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()]);
                } else {
                    $subscription->update(['ends_at' => $ends]);
                }
            }

            return true;
        });
    }
}
