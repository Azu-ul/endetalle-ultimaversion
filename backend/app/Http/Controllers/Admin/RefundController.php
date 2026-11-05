<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Services\Billing\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Solicitudes de reembolso en el panel admin (solo superadmin: ver rutas). */
class RefundController extends Controller
{
    public function __construct(private RefundService $refunds)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = RefundRequest::with(['user:id,name,email', 'order.plan'])->latest('id');

        if (in_array($status = $request->query('status'), ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        return response()->json([
            'pending_count' => RefundRequest::where('status', 'pending')->count(),
            'requests' => $query->limit(500)->get()->map(fn (RefundRequest $r) => $this->serialize($r)),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->serialize($this->find($id)));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:' . implode(',', config('billing.receipt_mimes')), 'max:' . config('billing.receipt_max_kb')],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'receipt.required' => 'Adjuntá el comprobante del reembolso (PDF o imagen).',
            'receipt.mimes' => 'El comprobante tiene que ser un PDF o una imagen (JPG, PNG o WebP).',
            'receipt.max' => 'El comprobante puede pesar hasta ' . intdiv(config('billing.receipt_max_kb'), 1024) . ' MB.',
            'receipt.uploaded' => 'No se pudo subir el comprobante. Revisá que no pase de ' . intdiv(config('billing.receipt_max_kb'), 1024) . ' MB.',
        ]);

        $refund = $this->refunds->approve($this->find($id), $request->user(), $request->file('receipt'), $data['note'] ?? null);

        return response()->json($this->serialize($refund->load(['user:id,name,email', 'order.plan', 'reviewer:id,name'])));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Escribí el motivo del rechazo: se lo mandamos al usuario.',
            'reason.min' => 'El motivo es muy corto.',
        ]);

        $refund = $this->refunds->reject($this->find($id), $request->user(), $data['reason']);

        return response()->json($this->serialize($refund->load(['user:id,name,email', 'order.plan', 'reviewer:id,name'])));
    }

    /** Comprobante adjunto (disco privado: solo se entrega a través de esta ruta de admin). */
    public function receipt(int $id)
    {
        $refund = $this->find($id);
        abort_unless($refund->receipt_path && Storage::disk('local')->exists($refund->receipt_path), 404, 'Esta solicitud no tiene comprobante.');

        return Storage::disk('local')->download($refund->receipt_path, $refund->receipt_name ?? "comprobante-{$refund->code}");
    }

    private function find(int $id): RefundRequest
    {
        return RefundRequest::with(['user:id,name,email', 'order.plan', 'reviewer:id,name'])->findOrFail($id);
    }

    private function serialize(RefundRequest $r): array
    {
        $order = $r->order;
        $window = $this->refunds->window($order);

        return [
            'id' => $r->id,
            'code' => $r->code,
            'status' => $r->status,
            'requested_at' => $r->created_at->toIso8601String(),
            'name' => $r->user->name,
            'email' => $r->user->email,
            'phone' => $r->phone,
            'reason' => $r->reason,
            'plan' => $order->plan->name,
            'period' => $order->period,
            'months' => $order->months,
            'amount' => $r->amount,
            'refund_percent' => $r->refund_percent,
            'order_amount' => $order->amount,
            'currency' => $r->currency,
            'provider' => $order->provider,
            'provider_payment_id' => $order->provider_payment_id,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'days_since_payment' => $window['days_since'],
            // Se evalúa con la fecha en que se pidió, no con hoy: lo que importa es si la solicitud llegó a tiempo
            'within_window' => (int) $order->paid_at->copy()->startOfDay()->diffInDays($r->created_at->copy()->startOfDay()) <= (int) config('billing.refund_window_days'),
            'order_status' => $order->status,
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'admin_note' => $r->admin_note,
            'rejection_reason' => $r->rejection_reason,
            'has_receipt' => (bool) $r->receipt_path,
        ];
    }
}
