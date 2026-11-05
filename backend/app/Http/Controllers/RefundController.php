<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\Billing\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Botón de arrepentimiento: el usuario ve sus compras y pide el reembolso de las que están dentro de plazo. */
class RefundController extends Controller
{
    public function __construct(private RefundService $refunds)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::with('plan')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['paid', 'refunded'])
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->limit(20)
            ->get();

        $latest = RefundRequest::whereIn('order_id', $orders->pluck('id'))->latest('id')->get()->groupBy('order_id');

        return response()->json([
            'window_days' => (int) config('billing.refund_window_days'),
            'orders' => $orders->map(function (Order $order) use ($latest) {
                $window = $this->refunds->window($order);
                $request = $latest->get($order->id)?->first();
                $blocked = $this->refunds->blockedReason($order);

                return [
                    'id' => $order->id,
                    'plan' => $order->plan->name,
                    'period' => $order->period,
                    'months' => $order->months,
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'provider' => $order->provider,
                    'paid_at' => $order->paid_at->toIso8601String(),
                    'deadline' => $window['deadline']->toIso8601String(),
                    'within_window' => $window['within'],
                    'order_status' => $order->status,
                    'refund' => $request ? ['code' => $request->code, 'status' => $request->status, 'rejection_reason' => $request->rejection_reason] : null,
                    'can_request' => $blocked === null,
                    'message' => $blocked,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s.]{6,40}$/'],
        ], [
            'order_id.required' => 'Elegí la compra que querés reembolsar.',
            'phone.regex' => 'El teléfono no es válido.',
        ]);

        $refund = $this->refunds->request($request->user(), $data['order_id'], $data['reason'] ?? null, $data['phone'] ?? null);

        return response()->json(['code' => $refund->code, 'status' => $refund->status, 'amount' => $refund->amount, 'currency' => $refund->currency], 201);
    }
}
