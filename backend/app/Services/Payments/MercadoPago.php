<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Checkout Pro de Mercado Pago (pagos en pesos). Nunca se confía en el contenido de un webhook: se consulta el pago a la API. */
class MercadoPago
{
    private const BASE = 'https://api.mercadopago.com';

    public function configured(): bool
    {
        return (bool) config('services.mercadopago.access_token');
    }

    /** @return string URL a la que se manda al usuario para pagar */
    public function createCheckout(Order $order, string $title): string
    {
        $return = rtrim(config('app.frontend_url'), '/') . '/pago/resultado?' . http_build_query([
            'orden' => $order->uuid,
            'proveedor' => 'mercadopago',
        ]);

        $payload = [
            'items' => [[
                'id' => $order->plan->slug,
                'title' => $title,
                'quantity' => 1,
                'currency_id' => 'ARS',
                'unit_price' => $order->amount,
            ]],
            'payer' => ['email' => $order->user->email],
            'external_reference' => $order->uuid,
            'back_urls' => ['success' => $return, 'failure' => $return, 'pending' => $return],
            'statement_descriptor' => 'ENDETALLE',
        ];

        // Con direcciones locales (http://localhost) Mercado Pago descarta las back_urls y rechaza auto_return y
        // notification_url: en desarrollo no hay botón "Volver al sitio" (se confirma con OrderProcessor::syncPendingOrders)
        if (Str::startsWith($return, 'https://')) {
            $payload['auto_return'] = 'approved';
        }
        if (Str::startsWith(config('app.url'), 'https://')) {
            $payload['notification_url'] = rtrim(config('app.url'), '/') . '/api/webhooks/mercadopago';
        }

        $response = $this->http()->post(self::BASE . '/checkout/preferences', $payload);
        if ($response->failed() || ! $response->json('init_point')) {
            throw new PaymentException('Mercado Pago rechazó la preferencia: ' . $response->body());
        }

        $order->update(['provider_order_id' => (string) $response->json('id')]);

        return $response->json('init_point');
    }

    /** Un pago por id (el que avisa el webhook). */
    public function payment(string $id): array
    {
        $response = $this->http()->get(self::BASE . '/v1/payments/' . urlencode($id));
        if ($response->failed()) {
            throw new PaymentException("No se pudo consultar el pago {$id} en Mercado Pago ({$response->status()}).");
        }

        return $response->json();
    }

    /** Pagos asociados a una orden, del más nuevo al más viejo (para confirmar al volver del checkout). */
    public function paymentsFor(Order $order): array
    {
        $response = $this->http()->get(self::BASE . '/v1/payments/search', [
            'external_reference' => $order->uuid,
            'sort' => 'date_created',
            'criteria' => 'desc',
        ]);
        if ($response->failed()) {
            throw new PaymentException("No se pudieron buscar los pagos de la orden {$order->uuid} ({$response->status()}).");
        }

        return $response->json('results') ?? [];
    }

    /**
     * Firma opcional del webhook (x-signature). Si no hay clave configurada no se exige:
     * igual todo se confirma consultando el pago a la API.
     */
    public function signatureIsValid(Request $request, ?string $dataId): bool
    {
        $secret = config('services.mercadopago.webhook_secret');
        if (! $secret) {
            return true;
        }

        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature')) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$key] = $value;
        }
        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }

        $manifest = 'id:' . strtolower((string) $dataId) . ';request-id:' . $request->header('x-request-id') . ';ts:' . $parts['ts'] . ';';

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $parts['v1']);
    }

    private function http()
    {
        return Http::withToken((string) config('services.mercadopago.access_token'))
            ->acceptJson()
            ->timeout(15);
    }
}
