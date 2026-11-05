<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Listado de órdenes y pagos para el panel admin (solo lectura). */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['user:id,name,email', 'plan:id,name'])->latest('id');

        if (in_array($status = $request->query('status'), ['pending', 'paid', 'refunded', 'expired', 'cancelled', 'failed'], true)) {
            $query->where('status', $status);
        }
        if (in_array($provider = $request->query('provider'), ['mercadopago', 'paypal'], true)) {
            $query->where('provider', $provider);
        }
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        return response()->json($query->limit(300)->get()->map(fn (Order $o) => [
            'id' => $o->id,
            'user' => $o->user->only(['id', 'name', 'email']),
            'plan' => $o->plan->name,
            'period' => $o->period,
            'months' => $o->months,
            'amount' => $o->amount,
            'currency' => $o->currency,
            'status' => $o->status,
            'provider' => $o->provider,
            'provider_payment_id' => $o->provider_payment_id,
            // subida (paga la diferencia) o bajada encolada; null = compra común
            'kind' => $o->upgrade_from_id ? 'upgrade' : ($o->starts_after_id ? 'queued' : null),
            'paid_at' => $o->paid_at?->toIso8601String(),
            'created_at' => $o->created_at->toIso8601String(),
        ]));
    }
}
