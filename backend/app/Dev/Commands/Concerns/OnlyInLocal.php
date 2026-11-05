<?php

namespace App\Dev\Commands\Concerns;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;

/**
 * Los comandos dev:* modifican planes, órdenes y pagos a mano: solo corren con APP_ENV=local
 * (además, AppServiceProvider ni siquiera los registra en otros entornos).
 */
trait OnlyInLocal
{
    private function refusesOutsideLocal(): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        $this->error('Este comando es solo para el entorno local (APP_ENV=local). No se hizo ningún cambio.');

        return true;
    }

    private function findUser(string $email): ?User
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No existe ningún usuario con el email {$email}.");
        }

        return $user;
    }

    /** Resumen del estado del usuario: plan, vencimiento y órdenes. Nunca muestra claves ni tokens. */
    private function printSummary(User $user): void
    {
        $subscription = $user->currentSubscription();
        $scheduled = Subscription::with('plan')->where('user_id', $user->id)->where('status', 'scheduled')->where('starts_at', '>', now())->first();

        $this->newLine();
        $this->info("Usuario: {$user->name} <{$user->email}>");
        $this->line('Plan: ' . ($subscription ? $subscription->plan->name . ($subscription->cancelled_at ? ' (cancelado)' : '') : 'Free'));
        $this->line('Vence: ' . ($subscription?->ends_at ? $subscription->ends_at->format('Y-m-d H:i') . ' (faltan ' . max(0, (int) ceil(($subscription->ends_at->getTimestamp() - now()->getTimestamp()) / 86400)) . ' días)' : '—'));
        if ($scheduled) {
            $this->line("Encolado: {$scheduled->plan->name} desde {$scheduled->starts_at->format('Y-m-d')}");
        }

        $orders = Order::with('plan')->where('user_id', $user->id)->orderBy('id')->get();
        $this->line('Órdenes: ' . ($orders->isEmpty() ? 'ninguna' : $orders->count()));
        if ($orders->isNotEmpty()) {
            $this->table(
                ['Orden', 'Plan', 'Período', 'Medio', 'Monto', 'Estado', 'Pagada'],
                $orders->map(fn (Order $o) => [
                    "#{$o->id}", $o->plan->name, $o->period, $o->provider, "{$o->currency} {$o->amount}", $o->status, $o->paid_at?->format('Y-m-d') ?? '—',
                ])->all()
            );
        }
    }
}
