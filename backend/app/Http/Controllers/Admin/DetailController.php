<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StudioController;
use App\Models\Detail;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gestión de detalles del panel admin: listado con todos los estados y
 * cambio de estado / acceso. El contenido se edita con StudioController
 * (el superadmin puede editar cualquier detalle, ver DetailPolicy).
 */
class DetailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Detail::with(['category:id,name', 'requiredPlan:id,slug,name', 'author:id,name,avatar_url']);

        if (($status = $request->query('status')) && $status !== 'all') {
            $query->where('status', $status);
        }

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where('title', 'like', $like);
        }

        return response()->json(
            $query->orderByDesc('updated_at')->limit(500)->get()->map(fn (Detail $d) => StudioController::summary($d))
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $detail = Detail::with('category:id,slug')->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:draft,published,archived'],
            'required_plan' => ['nullable', 'in:estudiante,pro'],
            'is_public' => ['nullable', 'boolean'],
            // Programación: con una fecha futura el detalle queda "Programado" y aparece solo a esa hora
            'publish_at' => ['nullable', 'date'],
        ], [
            'publish_at.date' => 'La fecha de publicación no es válida.',
        ]);

        if ($data['status'] === 'published') {
            abort_unless($detail->cover_url, 422, 'Para publicar, el detalle necesita una portada.');
        }

        // La categoría Premium siempre pide Pro
        $planSlug = $detail->category?->slug === 'premium' ? 'pro' : ($data['required_plan'] ?? null);

        $isPublic = ! empty($data['is_public']) && $planSlug === null && $detail->category?->slug !== 'premium';

        $detail->update([
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published' ? self::publishAt($detail, $data['publish_at'] ?? null) : $detail->published_at,
            'required_plan_id' => $planSlug ? Plan::where('slug', $planSlug)->value('id') : null,
            'is_public' => $isPublic,
        ]);

        return response()->json(StudioController::full($detail->fresh()));
    }

    /**
     * Fecha de publicación: la que eligió el admin; si no eligió ninguna, la que ya tenía (si ya se cumplió)
     * o ahora mismo. Un detalle que estaba programado y se publica "ahora" sale al instante.
     */
    public static function publishAt(Detail $detail, ?string $requested): Carbon
    {
        if ($requested) {
            return Carbon::parse($requested)->utc();
        }

        return $detail->published_at && $detail->published_at->isPast() ? $detail->published_at : now();
    }
}
