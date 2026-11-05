<?php

namespace App\Dev\Commands;

use App\Dev\Commands\Concerns\OnlyInLocal;
use App\Models\Order;
use App\Services\Billing\RefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DevPayDate extends Command
{
    use OnlyInLocal;

    protected $signature = 'dev:pay-date {order_id : Id de la orden} {--days-ago= : Cuántos días atrás se pagó}';

    protected $description = 'SOLO LOCAL: mueve la fecha de pago de una orden N días atrás (para probar el plazo de reembolso)';

    public function handle(RefundService $refunds): int
    {
        if ($this->refusesOutsideLocal()) {
            return self::FAILURE;
        }

        $daysAgo = $this->option('days-ago');
        if ($daysAgo === null || ! ctype_digit((string) $daysAgo)) {
            $this->error('Indicá cuántos días atrás con --days-ago=N (número entero, 0 o más).');

            return self::FAILURE;
        }

        $order = Order::with(['user', 'plan'])->find($this->argument('order_id'));
        if (! $order) {
            $this->error("No existe la orden #{$this->argument('order_id')}.");

            return self::FAILURE;
        }
        if ($order->status !== 'paid' && $order->status !== 'refunded') {
            $this->error("La orden #{$order->id} no está pagada (estado: {$order->status}).");

            return self::FAILURE;
        }

        $paidAt = now()->subDays((int) $daysAgo);
        DB::transaction(function () use ($order, $paidAt) {
            $order->update(['paid_at' => $paidAt]);
            DB::table('payments')->where('provider', $order->provider)->where('provider_payment_id', $order->provider_payment_id)
                ->update(['paid_at' => $paidAt, 'updated_at' => now()]);
        });

        $window = $refunds->window($order->fresh());
        $this->info("Listo: la orden #{$order->id} ({$order->plan->name}, {$order->currency} {$order->amount}) figura pagada el {$paidAt->format('Y-m-d')}.");
        $this->line("Días desde el pago: {$window['days_since']}. Plazo de reembolso hasta el {$window['deadline']->format('Y-m-d')}: " . ($window['within'] ? 'DENTRO de plazo.' : 'FUERA de plazo.'));
        $this->printSummary($order->user);

        return self::SUCCESS;
    }
}
