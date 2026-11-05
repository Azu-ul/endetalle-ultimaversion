<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** PayPal Orders v2 (pagos en dólares): se crea la orden, el usuario aprueba y el servidor captura. */
class PayPal
{
    public function configured(): bool
    {
        return (bool) config('services.paypal.client_id') && (bool) config('services.paypal.secret');
    }

    /** @return string URL de PayPal donde el usuario aprueba el pago */
    public function createCheckout(Order $order, string $title): string
    {
        $site = rtrim(config('app.frontend_url'), '/');

        $response = $this->http()
            ->withHeaders(['PayPal-Request-Id' => $order->uuid]) // reintentar no crea una orden nueva
            ->post($this->base() . '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $order->uuid,
                    'custom_id' => $order->uuid,
                    'description' => $title,
                    'amount' => ['currency_code' => 'USD', 'value' => number_format($order->amount, 2, '.', '')],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => config('app.name'),
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $site . '/pago/resultado?' . http_build_query(['orden' => $order->uuid, 'proveedor' => 'paypal']),
                    'cancel_url' => $site . '/planes',
                ]]],
            ]);

        $link = collect($response->json('links') ?? [])->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));
        if ($response->failed() || ! $response->json('id') || ! $link) {
            throw new PaymentException('PayPal rechazó la orden: ' . $response->body());
        }

        $order->update(['provider_order_id' => $response->json('id')]);

        return $link['href'];
    }

    /**
     * Captura la orden aprobada por el usuario. Si ya estaba capturada devuelve el estado actual.
     *
     * @return array{id: string, status: string, amount: float, currency: string}|null  null si PayPal no devolvió ninguna captura
     */
    public function capture(Order $order): ?array
    {
        $response = $this->http()
            ->withHeaders(['PayPal-Request-Id' => 'capture-' . $order->uuid])
            ->withBody('{}', 'application/json')
            ->post($this->base() . '/v2/checkout/orders/' . urlencode((string) $order->provider_order_id) . '/capture');

        if ($response->failed()) {
            $alreadyCaptured = collect($response->json('details') ?? [])->contains(fn ($d) => ($d['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED');
            if (! $alreadyCaptured) {
                throw new PaymentException("PayPal no pudo capturar la orden {$order->uuid}: " . $response->body());
            }

            return $this->captureFromOrder($this->order((string) $order->provider_order_id));
        }

        return $this->captureFromOrder($response->json());
    }

    public function order(string $id): array
    {
        $response = $this->http()->get($this->base() . '/v2/checkout/orders/' . urlencode($id));
        if ($response->failed()) {
            throw new PaymentException("No se pudo consultar la orden {$id} en PayPal ({$response->status()}).");
        }

        return $response->json();
    }

    /** Verifica con PayPal la firma de un webhook. Sin PAYPAL_WEBHOOK_ID no se acepta ninguno. */
    public function webhookIsValid(Request $request): bool
    {
        $webhookId = config('services.paypal.webhook_id');
        if (! $webhookId) {
            return false;
        }

        $response = $this->http()->post($this->base() . '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    /** @return array{id: string, status: string, amount: float, currency: string}|null */
    public function captureFromOrder(array $order): ?array
    {
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (! $capture) {
            return null;
        }

        return [
            'id' => $capture['id'],
            'status' => $capture['status'] ?? '',
            'amount' => (float) ($capture['amount']['value'] ?? 0),
            'currency' => $capture['amount']['currency_code'] ?? '',
        ];
    }

    private function base(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function http()
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(20);
    }

    private function accessToken(): string
    {
        return Cache::remember('paypal.access_token.' . config('services.paypal.mode'), 3000, function () {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.paypal.client_id'), (string) config('services.paypal.secret'))
                ->timeout(15)
                ->post($this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

            if ($response->failed() || ! $response->json('access_token')) {
                throw new PaymentException('PayPal rechazó las credenciales: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }
}
