<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Services\Payments\PlanPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Cancelar no quita días ya pagados: el plan sigue activo hasta su vencimiento y ahí el cron lo pasa a Free.
     * Se marca la fecha, se cierran las compras sin pagar y deja de salir el aviso de vencimiento. No hay reembolso.
     */
    public function cancel(Request $request, PlanPurchase $purchase): JsonResponse
    {
        $user = $request->user();
        $subscription = $purchase->activeSubscription($user);
        abort_unless($subscription, 422, 'No tenés un plan pago activo para cancelar.');

        if (! $subscription->cancelled_at) {
            $subscription->update(['cancelled_at' => now()]);
        }

        Order::where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'cancelled']);

        return response()->json(UserResource::make($user->fresh()));
    }
}
