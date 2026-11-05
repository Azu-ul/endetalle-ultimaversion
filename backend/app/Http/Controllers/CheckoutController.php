<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\Plan;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\MercadoPago;
use App\Services\Payments\OrderProcessor;
use App\Services\Payments\PayPal;
use App\Services\Payments\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Compra de un plan: crea la orden (el monto sale de acá, nunca del navegador) y manda al usuario al checkout. */
class CheckoutController extends Controller
{
    private const PERIOD_LABELS = ['monthly' => '1 mes', 'quarterly' => '3 meses', 'yearly' => '12 meses'];

    public function __construct(
        private MercadoPago $mercadoPago,
        private PayPal $paypal,
        private OrderProcessor $processor,
        private PlanPurchase $purchase,
    ) {
    }

    /** Qué puede hacer el usuario con cada plan pago (comprar, renovar, subir) y cuánto pagaría. Lo usa la pantalla de Planes. */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->purchase->activeSubscription($user);

        $plans = Plan::where('is_active', true)->where('level', '>', 0)->orderBy('level')->get()->map(function (Plan $plan) use ($user, $current) {
            $evaluation = $this->purchase->evaluate($user, $plan, $current);

            return [
                'slug' => $plan->slug,
                'state' => $evaluation['state'],
                'message' => $evaluation['message'],
                'ends_at' => $evaluation['ends_at']?->toIso8601String(),
                'renew_from' => $evaluation['renew_from']?->toIso8601String(),
                'starts_at' => $evaluation['state'] === PlanPurchase::SCHEDULED ? $this->purchase->scheduledSubscription($user)?->starts_at?->toIso8601String() : null,
                'periods' => collect($plan->periodPrices())->map(function (array $period) use ($plan, $evaluation) {
                    // Al subir de plan el monto es la diferencia y no depende del período elegido
                    return [
                        'id' => $period['id'],
                        'amount_ars' => $this->purchase->quote($plan, $period['id'], 'ARS', $evaluation)['amount'],
                        'amount_usd' => $this->purchase->quote($plan, $period['id'], 'USD', $evaluation)['amount'],
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'current' => $current ? [
                'plan' => $current->plan->slug,
                'ends_at' => $current->ends_at->toIso8601String(),
                'cancelled' => $current->cancelled_at !== null,
            ] : null,
            'plans' => $plans,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:estudiante,pro'],
            'period' => ['required', 'in:' . implode(',', array_column(Plan::PERIODS, 'id'))],
            'provider' => ['required', 'in:mercadopago,paypal'],
        ]);

        $gateway = $data['provider'] === 'mercadopago' ? $this->mercadoPago : $this->paypal;
        abort_unless($gateway->configured(), 503, 'El pago con este medio todavía no está disponible.');

        $user = $request->user();
        $plan = Plan::where('slug', $data['plan'])->where('is_active', true)->firstOrFail();

        // Qué se puede comprar según el plan que ya tenga (igual para Mercado Pago y PayPal)
        $evaluation = $this->purchase->evaluate($user, $plan);
        abort_if(in_array($evaluation['state'], [
            PlanPurchase::BLOCKED_SAME, PlanPurchase::BLOCKED_LOWER, PlanPurchase::BLOCKED_SCHEDULED, PlanPurchase::SCHEDULED,
        ], true), 422, $evaluation['message']);

        $currency = $data['provider'] === 'mercadopago' ? 'ARS' : 'USD';
        $isUpgrade = $evaluation['state'] === PlanPurchase::UPGRADE;
        $quote = $this->purchase->quote($plan, $data['period'], $currency, $evaluation);
        abort_unless($quote['amount'] > 0, 422, $isUpgrade
            ? "No pudimos calcular la diferencia de precio para subir a {$plan->name}. Escribinos desde Contacto y lo resolvemos."
            : 'Este plan no tiene precio en esa moneda.');

        // Subir de plan: la orden cubre los períodos que le quedan al plan actual y conserva su vencimiento
        $segments = $evaluation['segments'];
        $period = collect(Plan::PERIODS)->firstWhere('id', $isUpgrade ? $segments[0]['period'] : $data['period']);
        $months = $isUpgrade ? array_sum(array_column($segments, 'months')) : $period['months'];
        $upgradeFrom = $isUpgrade ? $evaluation['subscription'] : null;
        // Bajar de plan: se paga completo y empieza cuando vence el plan actual
        $isDowngrade = $evaluation['state'] === PlanPurchase::DOWNGRADE;
        $startsAfter = $isDowngrade ? $evaluation['subscription'] : null;

        // Si ya empezó esta misma compra y no la pagó, se retoma en vez de crear otra
        $order = Order::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('period', $data['period'])
            ->where('provider', $data['provider'])
            ->where('currency', $currency)
            ->where('status', 'pending')
            ->where('amount', $quote['amount'])
            ->where('upgrade_from_id', $upgradeFrom?->id)
            ->where('starts_after_id', $startsAfter?->id)
            ->latest('id')
            ->first()
            ?? Order::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'period' => $period['id'],
                'months' => $months,
                'provider' => $data['provider'],
                'currency' => $currency,
                'amount' => $quote['amount'],
                'upgrade_from_id' => $upgradeFrom?->id,
                'ends_at' => $upgradeFrom?->ends_at,
                'starts_after_id' => $startsAfter?->id,
            ]);
        $order->load('plan', 'user');

        $title = $isUpgrade
            ? "enDetalle: subir a {$plan->name} (diferencia de plan)"
            : "enDetalle {$plan->name} - " . self::PERIOD_LABELS[$period['id']] . ($isDowngrade ? ' (empieza al vencer tu plan actual)' : '');

        try {
            $url = $gateway->createCheckout($order, $title);
        } catch (\Throwable $e) {
            report($e);
            $order->update(['status' => 'failed']);

            return response()->json(['message' => 'No pudimos iniciar el pago. Probá de nuevo en unos minutos.'], 502);
        }

        return response()->json(['url' => $url, 'order' => $order->uuid]);
    }

    /** Estado de una orden propia. Si sigue pendiente en Mercado Pago, se consulta al proveedor (sirve aunque el webhook no haya llegado). */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownOrder($request, $uuid);

        if ($order->status === 'pending' && $order->provider === 'mercadopago' && $this->mercadoPago->configured()) {
            try {
                $order = $this->processor->syncMercadoPagoOrder($order);
            } catch (PaymentException $e) {
                report($e);
            }
        }

        return response()->json($this->serialize($order));
    }

    /** Revisa en Mercado Pago las compras sin acreditar del usuario (al abrir Mi cuenta o con "Ya pagué, verificar"). */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        $credited = $this->processor->syncPendingOrders($user);

        // pending = compras recientes que Mercado Pago todavía no confirmó (la pantalla vuelve a preguntar)
        $payload = ['credited' => $credited, 'pending' => $this->processor->recentPendingCount($user)];
        if ($credited > 0) {
            $payload['user'] = UserResource::make($user->fresh()); // plan y vencimiento ya actualizados
        }

        return response()->json($payload);
    }

    /** PayPal: al volver del checkout el servidor captura el pago aprobado. */
    public function capture(Request $request, string $uuid): JsonResponse
    {
        $order = $this->ownOrder($request, $uuid);
        abort_unless($order->provider === 'paypal' && $order->provider_order_id, 422, 'Esta orden no se paga con PayPal.');

        if ($order->status === 'pending') {
            try {
                $order = $this->processor->paypalCapture($order, $this->paypal->capture($order));
            } catch (PaymentException $e) {
                report($e);

                return response()->json(['message' => 'No pudimos confirmar el pago con PayPal. Si te lo cobraron, se acredita solo en unos minutos.'], 502);
            }
        }

        return response()->json($this->serialize($order));
    }

    private function ownOrder(Request $request, string $uuid): Order
    {
        return Order::with(['plan', 'subscription'])
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function serialize(Order $order): array
    {
        $order->loadMissing(['plan', 'subscription']);

        return [
            'status' => $order->status,
            'provider' => $order->provider,
            'plan' => $order->plan->only(['slug', 'name']),
            'months' => $order->months,
            'ends_at' => $order->subscription?->ends_at?->toIso8601String(),
            // Plan menor pago que todavía no empezó
            'scheduled' => $order->subscription?->status === 'scheduled' && $order->subscription->starts_at->isFuture(),
            'starts_at' => $order->subscription?->starts_at?->toIso8601String(),
        ];
    }
}
