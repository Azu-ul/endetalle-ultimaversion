<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;

/**
 * Reglas de compra de planes y cálculo del monto. Es lo único que decide qué se puede comprar y a qué precio:
 * lo usan el checkout (Mercado Pago y PayPal) y la pantalla de Planes, así lo que se ve es lo que se cobra.
 *
 * - Mismo plan: solo se puede renovar cuando faltan RENEW_WINDOW_DAYS días o menos (o ya venció).
 * - Plan de mayor nivel: en cualquier momento. Se paga la DIFERENCIA de precio entre los planes para el
 *   período que el usuario tiene vigente, sin importar cuántos días le queden, y el plan nuevo conserva
 *   el mismo vencimiento (no se reinicia el período ni se suman días).
 * - Plan de menor nivel: no cambia en el momento. Con más de RENEW_WINDOW_DAYS días por delante está bloqueado;
 *   dentro de esa ventana se paga completo y queda encolado para empezar cuando vence el actual.
 *
 * Todo se calcula con fechas (ends_at), no con el estado que deja el cron.
 */
class PlanPurchase
{
    public const RENEW_WINDOW_DAYS = 3;

    public const AVAILABLE = 'available';
    public const RENEW = 'renew';
    public const UPGRADE = 'upgrade';
    public const BLOCKED_SAME = 'blocked_same';
    public const BLOCKED_LOWER = 'blocked_lower';
    public const DOWNGRADE = 'downgrade';           // plan menor, se encola al vencimiento
    public const SCHEDULED = 'scheduled';           // este es justo el plan que ya quedó encolado
    public const BLOCKED_SCHEDULED = 'blocked_scheduled'; // hay un cambio encolado: no se compra otro hasta que empiece

    /** Plan pago vigente por fecha (los planes manuales de cortesía no cuentan). */
    public function activeSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('plan')
            ->live()
            ->where('provider', '!=', 'manual')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    /** Plan menor ya pago que empieza cuando vence el actual. */
    public function scheduledSubscription(User $user): ?Subscription
    {
        return $user->subscriptions()
            ->with('plan')
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * @return array{state: string, subscription: ?Subscription, ends_at: ?Carbon, renew_from: ?Carbon, message: ?string, segments: array<int, array{period: string, months: int}>}
     */
    public function evaluate(User $user, Plan $target, ?Subscription $current = null): array
    {
        $current ??= $this->activeSubscription($user);
        $result = ['state' => self::AVAILABLE, 'subscription' => $current, 'ends_at' => null, 'renew_from' => null, 'message' => null, 'segments' => []];

        if (! $current) {
            return $result;
        }

        $ends = $current->ends_at;
        $renewFrom = $ends->copy()->subDays(self::RENEW_WINDOW_DAYS);
        $result['ends_at'] = $ends;
        $result['renew_from'] = $renewFrom;

        // Con un cambio ya encolado no se compra otro plan hasta que empiece
        if ($scheduled = $this->scheduledSubscription($user)) {
            $result['state'] = $target->id === $scheduled->plan_id ? self::SCHEDULED : self::BLOCKED_SCHEDULED;
            $result['message'] = $target->id === $scheduled->plan_id
                ? "Ya tenés programado el cambio a {$target->name}: empieza el {$this->date($scheduled->starts_at)}."
                : "Ya tenés programado el cambio a {$scheduled->plan->name} desde el {$this->date($scheduled->starts_at)}. Podés comprar de nuevo cuando empiece.";

            return $result;
        }

        if ($target->level > $current->plan->level) {
            $result['state'] = self::UPGRADE;
            $result['segments'] = $this->pendingSegments($current);
        } elseif ($target->id === $current->plan_id) {
            if ($this->calendarDaysLeft($ends) <= self::RENEW_WINDOW_DAYS) {
                $result['state'] = self::RENEW;
            } else {
                $result['state'] = self::BLOCKED_SAME;
                $result['message'] = "Tu plan {$current->plan->name} está activo hasta {$this->date($ends)}. Podés renovarlo desde el {$this->date($renewFrom)}.";
            }
        } elseif ($this->calendarDaysLeft($ends) <= self::RENEW_WINDOW_DAYS) {
            $result['state'] = self::DOWNGRADE;
        } else {
            $result['state'] = self::BLOCKED_LOWER;
            $result['message'] = "Tu plan {$current->plan->name} está activo hasta {$this->date($ends)}. Podés cambiarte a {$target->name} desde el {$this->date($renewFrom)}.";
        }

        return $result;
    }

    /**
     * Monto a cobrar en una moneda. Al subir de plan es la diferencia de precio de cada período vigente
     * (el período elegido en pantalla no cuenta); en cualquier otro caso, el precio del período elegido.
     * Si da cero o menos para una subida, devuelve 0 y el checkout la rechaza.
     *
     * @return array{amount: float}
     */
    public function quote(Plan $target, string $periodId, string $currency, array $evaluation): array
    {
        if ($evaluation['state'] !== self::UPGRADE) {
            return ['amount' => $this->round($this->periodTotal($target, $periodId, $currency), $currency)];
        }

        $currentPlan = $evaluation['subscription']->plan;
        $difference = 0.0;
        foreach ($evaluation['segments'] as $segment) {
            $difference += $this->periodTotal($target, $segment['period'], $currency) - $this->periodTotal($currentPlan, $segment['period'], $currency);
        }

        return ['amount' => max(0.0, $this->round($difference, $currency))];
    }

    /**
     * Períodos pagados de esta suscripción que todavía no terminaron, del más viejo al más nuevo.
     *
     * Una suscripción se arma con sus órdenes pagadas una atrás de otra (la primera compra y las renovaciones,
     * que solo se permiten en los últimos días): desde starts_at, cada orden cubre sus meses. Si el usuario
     * renovó antes de que termine el período actual, quedan dos (o más) períodos pendientes y se suma la
     * diferencia de cada uno. Sin órdenes (datos cargados a mano) se deduce un único período por la duración.
     *
     * @return array<int, array{period: string, months: int}>
     */
    public function pendingSegments(Subscription $subscription): array
    {
        $segments = [];
        $cursor = $subscription->starts_at->copy();

        Order::where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->whereNull('upgrade_from_id')
            ->orderBy('paid_at')->orderBy('id')
            ->get()
            ->each(function (Order $order) use (&$segments, &$cursor) {
                $cursor = $cursor->copy()->addMonthsNoOverflow($order->months);
                if ($cursor->isFuture()) {
                    $segments[] = ['period' => $order->period, 'months' => $order->months];
                }
            });

        if ($segments) {
            return $segments;
        }

        $months = ($subscription->ends_at->getTimestamp() - $subscription->starts_at->getTimestamp()) / 86400 / 30.4375;
        $nearest = collect(Plan::PERIODS)->sortBy(fn ($p) => abs($p['months'] - $months))->first();

        return [['period' => $nearest['id'], 'months' => $nearest['months']]];
    }

    /** Días de calendario hasta el vencimiento: 0 el mismo día, negativo si ya pasó. */
    public function calendarDaysLeft(Carbon $ends): int
    {
        return (int) now()->startOfDay()->diffInDays($ends->copy()->startOfDay(), false);
    }

    public function date(Carbon $date): string
    {
        return $date->copy()->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }

    /** Total de un plan para un período en una moneda, con el descuento del período (tabla de planes). */
    private function periodTotal(Plan $plan, string $periodId, string $currency): float
    {
        $period = collect($plan->periodPrices())->firstWhere('id', $periodId);

        return (float) ($period ? ($currency === 'ARS' ? $period['total_ars'] : $period['total_usd']) : 0);
    }

    private function round(float $value, string $currency): float
    {
        return $currency === 'ARS' ? (float) round($value) : round($value, 2);
    }
}
