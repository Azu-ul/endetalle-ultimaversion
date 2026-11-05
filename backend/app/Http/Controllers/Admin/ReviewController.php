<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StudioController;
use App\Models\Detail;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bandeja de aprobación y verificación técnica (solo superadmin, ver routes/api.php). */
class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        abort_unless(in_array($status, ['pending', 'published', 'rejected'], true), 422);

        $query = Detail::where('status', $status)
            ->with(['category:id,name', 'requiredPlan:id,slug,name', 'author:id,name,avatar_url']);

        // Pendientes: el que más espera primero. Publicados: los más nuevos primero.
        $status === 'pending'
            ? $query->orderBy('updated_at')
            : $query->orderByDesc($status === 'published' ? 'published_at' : 'updated_at');

        return response()->json([
            'details' => $query->limit(200)->get()->map(fn (Detail $d) => StudioController::summary($d)),
            'pending_count' => Detail::where('status', 'pending')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(StudioController::full(Detail::findOrFail($id)));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $detail = Detail::with('category:id,slug')->findOrFail($id);
        $data = $request->validate([
            'required_plan' => ['nullable', 'in:estudiante,pro'],
            'verified' => ['boolean'],
            'is_public' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date'],
        ]);
        abort_unless($detail->cover_url, 422, 'El detalle no tiene portada.');

        $planSlug = $detail->category?->slug === 'premium' ? 'pro' : ($data['required_plan'] ?? null);
        $isPublic = $request->has('is_public')
            ? ($request->boolean('is_public') && $planSlug === null && $detail->category?->slug !== 'premium')
            : (bool) $detail->is_public;
        $detail->update([
            'status' => 'published',
            'published_at' => DetailController::publishAt($detail, $data['publish_at'] ?? null),
            'required_plan_id' => $planSlug ? Plan::where('slug', $planSlug)->value('id') : null,
            'is_public' => $isPublic,
            'review_notes' => null,
        ]);
        if ($request->boolean('verified')) {
            $this->setVerified($request, $detail, true);
        }

        return response()->json(StudioController::full($detail->fresh()));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $detail = Detail::findOrFail($id);
        $data = $request->validate([
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'notes.required' => 'Contale al colaborador qué tiene que corregir.',
            'notes.min' => 'Explicá un poco más (al menos 10 caracteres).',
        ]);

        $detail->update(['status' => 'rejected', 'review_notes' => $data['notes']]);

        return response()->json(StudioController::full($detail));
    }

    /** Verificación técnica: insignia "Verificado" (solo detalles publicados). */
    public function verify(Request $request, int $id): JsonResponse
    {
        $detail = Detail::where('status', 'published')->findOrFail($id);
        $this->setVerified($request, $detail, $request->isMethod('post'));

        return response()->json(StudioController::full($detail));
    }

    private function setVerified(Request $request, Detail $detail, bool $verified): void
    {
        $detail->update([
            'is_verified' => $verified,
            'verified_by' => $verified ? $request->user()->id : null,
            'verified_at' => $verified ? now() : null,
        ]);
    }
}
