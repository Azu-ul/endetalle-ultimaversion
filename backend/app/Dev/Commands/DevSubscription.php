<?php

namespace App\Dev\Commands;

use App\Dev\Commands\Concerns\OnlyInLocal;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevSubscription extends Command
{
    use OnlyInLocal;

    protected $signature = 'dev:subscription {email : Email del usuario} {plan : estudiante o pro}
        {--period=monthly : monthly, quarterly o yearly}
        {--provider=mercadopago : mercadopago (pesos) o paypal (dólares)}
        {--days-left=30 : Días que le quedan al plan}';

    protected $description = 'SOLO LOCAL: deja al usuario con un plan pago activo como si hubiera pagado de verdad (orden pagada + suscripción)';

    public function handle(): int
    {
        if ($this->refusesOutsideLocal()) {
            return self::FAILURE;
        }

        $user = $this->findUser($this->argument('email'));
        $plan = Plan::whereIn('slug', ['estudiante', 'pro'])->where('slug', $this->argument('plan'))->first();
        $period = collect(Plan::PERIODS)->firstWhere('id', $this->option('period'));
        $provider = $this->option('provider');
        $daysLeft = $this->option('days-left');

        if (! $user) {
            return self::FAILURE;
        }
        if (! $plan) {
            $this->error('El plan tiene que ser "estudiante" o "pro".');

            return self::FAILURE;
        }
        if (! $period) {
            $this->error('El período tiene que ser monthly, quarterly o yearly.');

            return self::FAILURE;
        }
        if (! in_array($provider, ['mercadopago', 'paypal'], true)) {
            $this->error('El medio de pago tiene que ser mercadopago o paypal.');

            return self::FAILURE;
        }
        if (! ctype_digit((string) $daysLeft)) {
            $this->error('--days-left tiene que ser un número entero de días (0 o más).');

            return self::FAILURE;
        }

        // Vencimiento = hoy + días restantes; el período empezó "meses del período" antes de esa fecha
        $ends = now()->addDays((int) $daysLeft);
        $start = $ends->copy()->subMonthsNoOverflow($period['months']);
        if ($start->isFuture()) {
            $max = (int) now()->diffInDays(now()->addMonthsNoOverflow($period['months']));
            $this->error("--days-left no puede superar la duración del período ({$max} días para {$period['id']}).");

            return self::FAILURE;
        }

        $currency = $provider === 'mercadopago' ? 'ARS' : 'USD';
        $prices = collect($plan->periodPrices())->firstWhere('id', $period['id']);
        $amount = $currency === 'ARS' ? $prices['total_ars'] : $prices['total_usd'];

        DB::transaction(function () use ($user, $plan, $period, $provider, $currency, $amount, $start, $ends) {
            // Cualquier otro plan activo o encolado queda cerrado, y las compras sin pagar también
            $user->subscriptions()->whereIn('status', ['active', 'scheduled'])->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            Order::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);

            $paymentId = 'DEV-' . strtoupper(Str::random(10));
            $subscription = Subscription::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'provider' => $provider,
                'provider_subscription_id' => $paymentId, 'starts_at' => $start, 'ends_at' => $ends,
            ]);
            Order::create([
                'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_id' => $plan->id, 'period' => $period['id'],
                'months' => $period['months'], 'provider' => $provider, 'currency' => $currency, 'amount' => $amount,
                'status' => 'paid', 'provider_order_id' => 'DEV-ORDER', 'provider_payment_id' => $paymentId,
                'subscription_id' => $subscription->id, 'paid_at' => $start,
            ]);
            DB::table('payments')->insert([
                'user_id' => $user->id, 'subscription_id' => $subscription->id, 'provider' => $provider, 'provider_payment_id' => $paymentId,
                'amount' => $amount, 'currency' => $currency, 'status' => 'paid', 'payload' => json_encode(['dev' => true]),
                'paid_at' => $start, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->info("Listo: {$user->email} quedó con el plan {$plan->name} ({$period['id']}, {$provider}), pagado el {$start->format('Y-m-d')}.");
        $this->printSummary($user);

        return self::SUCCESS;
    }
}
