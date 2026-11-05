<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bandeja de reportes de error del panel admin (solo superadmin, ver routes/api.php). */
class ErrorReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reports = ErrorReport::with(['detail:id,title,slug', 'user:id,name'])
            ->orderByRaw("status = 'pending' desc")
            ->orderByDesc('id')
            ->get()
            ->map(fn (ErrorReport $r) => $this->serialize($r));

        return response()->json($reports);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $report = ErrorReport::with(['detail:id,title,slug', 'user:id,name'])->findOrFail($id);

        if ($report->status !== 'resolved') {
            $report->update([
                'status' => 'resolved',
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);
        }

        return response()->json($this->serialize($report));
    }

    private function serialize(ErrorReport $r): array
    {
        return [
            'id' => $r->id,
            'detail' => $r->detail?->only(['id', 'title', 'slug']),
            'user' => $r->user?->only(['id', 'name']),
            'description' => $r->description,
            'status' => $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
        ];
    }
}
