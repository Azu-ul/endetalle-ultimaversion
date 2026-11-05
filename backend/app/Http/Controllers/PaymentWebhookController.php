<?php

namespace App\Http\Controllers;

use App\Services\Payments\MercadoPago;
use App\Services\Payments\OrderProcessor;
use App\Services\Payments\PayPal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avisos de Mercado Pago y PayPal. Son públicos (no tienen sesión), así que cada uno se
 * valida contra el proveedor. Si algo falla con un error 5xx, el proveedor reintenta.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private OrderProcessor $processor)
    {
    }

    public function mercadoPago(Request $request, MercadoPago $mercadoPago): JsonResponse
    {
        // Llega como ?type=payment&data.id=123 o como JSON {"type":"payment","data":{"id":"123"}}
        $type = $request->input('type', $request->query('topic'));
        $id = $request->input('data.id', $request->query('data_id', $request->query('id')));

        if ($type !== 'payment' || ! $id) {
            return response()->json(['ignored' => true]); // otros avisos (merchant_order, etc.)
        }
        abort_unless($mercadoPago->signatureIsValid($request, (string) $id), 401, 'Firma inválida.');

        // No se confía en lo que dice el aviso: el pago se vuelve a pedir a Mercado Pago
        $this->processor->mercadoPagoPayment($mercadoPago->payment((string) $id));

        return response()->json(['ok' => true]);
    }

    public function paypal(Request $request, PayPal $paypal): JsonResponse
    {
        abort_unless($paypal->webhookIsValid($request), 401, 'Firma inválida.');

        $this->processor->paypalEvent($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
