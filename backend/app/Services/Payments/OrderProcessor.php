<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\User;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Support\Facades\Log;

/**
 * Traduce lo que informan Mercado Pago y PayPal (webhooks o consulta directa) en cambios
 * sobre la orden. Antes de acreditar siempre se controlan el monto y la moneda contra la orden.
 */
class OrderProcessor
{
    private const SYNC_DAYS = 14;
    private const SYNC_MAX_ORDERS = 10;
    private const RECENT_PENDING_HOURS = 2;

    public function __construct(private SubscriptionFulfillment $fulfillment, private MercadoPago $mercadoPago)
    {
    }

    /**
     * Pregunta a Mercado Pago por los pagos de una orden (por external_reference) y acredita el aprobado.
     * Sirve cuando el webhook no llega (por ejemplo, probando en localhost).
     */
    public function syncMercadoPagoOrder(Order $order): Order
    {
        foreach ($this->mercadoPago->paymentsFor($order) as $payment) {
            $order = $this->mercadoPagoPayment($payment) ?? $order;
            if ($order->status === 'paid') {
                break;
            }
        }

        return $order;
    }

    /**
     * Revisa las órdenes de Mercado Pago sin acreditar de un usuario (también las vencidas: un pago puede llegar tarde).
     *
     * @return int cuántas órdenes se acreditaron en esta llamada
     */
    public function syncPendingOrders(User $user): int
    {
        if (! $this->mercadoPago->configured()) {
            return 0;
        }

        $credited = 0;
        Order::where('user_id', $user->id)
            ->where('provider', 'mercadopago')
            ->whereIn('status', ['pending', 'expired', 'cancelled']) // un pago aprobado se acredita aunque la orden se haya cerrado
            ->where('created_at', '>', now()->subDays(self::SYNC_DAYS))
            ->latest('id')
            ->limit(self::SYNC_MAX_ORDERS)
            ->get()
            ->each(function (Order $order) use (&$credited) {
                try {
                    if ($this->syncMercadoPagoOrder($order)->status === 'paid') {
                        $credited++;
                    }
                } catch (PaymentException $e) {
                    report($e); // si Mercado Pago no responde, se reintenta la próxima vez
                }
            });

        return $credited;
    }

    /** Compras de Mercado Pago de las últimas horas que siguen sin acreditar (Mercado Pago a veces tarda unos segundos). */
    public function recentPendingCount(User $user): int
    {
        return Order::where('user_id', $user->id)
            ->where('provider', 'mercadopago')
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subHours(self::RECENT_PENDING_HOURS))
            ->count();
    }

    /** @param array $payment respuesta de GET /v1/payments/{id} */
    public function mercadoPagoPayment(array $payment): ?Order
    {
        $order = Order::where('uuid', (string) ($payment['external_reference'] ?? ''))
            ->where('provider', 'mercadopago')
            ->first();
        if (! $order) {
            return null;
        }

        $id = (string) ($payment['id'] ?? '');
        $status = $payment['status'] ?? '';

        if ($status === 'approved') {
            if ($this->matches($order, (float) ($payment['transaction_amount'] ?? 0), (string) ($payment['currency_id'] ?? ''))) {
                $this->fulfillment->confirm($order, $id, $payment);
            } else {
                Log::warning("Mercado Pago: el pago {$id} no coincide con la orden {$order->uuid}.");
            }
        } elseif (in_array($status, ['refunded', 'charged_back'], true) && $order->provider_payment_id === $id) {
            $this->fulfillment->revoke($order);
        }

        return $order->fresh();
    }

    /** @param array{id: string, status: string, amount: float, currency: string}|null $capture */
    public function paypalCapture(Order $order, ?array $capture): Order
    {
        if ($capture && $capture['status'] === 'COMPLETED') {
            if ($this->matches($order, $capture['amount'], $capture['currency'])) {
                $this->fulfillment->confirm($order, $capture['id'], $capture);
            } else {
                Log::warning("PayPal: la captura {$capture['id']} no coincide con la orden {$order->uuid}.");
            }
        }

        return $order->fresh();
    }

    /** Webhook de PayPal ya verificado. Devuelve la orden afectada, si la hay. */
    public function paypalEvent(array $event): ?Order
    {
        $type = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];

        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            $order = Order::where('uuid', (string) ($resource['custom_id'] ?? ''))->where('provider', 'paypal')->first();

            return $order ? $this->paypalCapture($order, [
                'id' => (string) ($resource['id'] ?? ''),
                'status' => $resource['status'] ?? '',
                'amount' => (float) ($resource['amount']['value'] ?? 0),
                'currency' => $resource['amount']['currency_code'] ?? '',
            ]) : null;
        }

        if (in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            // El reembolso apunta a la captura original en el link "up"
            $up = collect($resource['links'] ?? [])->first(fn ($l) => ($l['rel'] ?? '') === 'up');
            $captureId = $up ? basename(parse_url($up['href'], PHP_URL_PATH)) : ($type === 'PAYMENT.CAPTURE.REVERSED' ? ($resource['id'] ?? '') : '');

            $order = Order::where('provider', 'paypal')
                ->where(fn ($q) => $q->where('provider_payment_id', $captureId)->orWhere('uuid', (string) ($resource['custom_id'] ?? '')))
                ->first();
            if ($order) {
                $this->fulfillment->revoke($order);
            }

            return $order?->fresh();
        }

        return null;
    }

    private function matches(Order $order, float $amount, string $currency): bool
    {
        return abs($order->amount - $amount) < 0.01 && strtoupper($currency) === $order->currency;
    }
}
