<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Models\User;
use App\Services\DetailAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mini red social: directorio, perfiles públicos y "me gusta" entre usuarios.
 * Solo se exponen datos públicos del perfil (nunca el email).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // El administrador también figura entre los autores destacados
        $query = $this->publicUsers();

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('profession', 'like', $like)
                ->orWhere('company', 'like', $like));
        }

        $page = $query->orderByDesc('likes_count')
            ->orderByDesc('details_count')
            ->orderBy('name')
            ->paginate(24);

        $liked = $this->likedIds($request->user('sanctum'), $page->getCollection()->pluck('id')->all());

        return response()->json($page->through(fn (User $u) => $this->summary($u, $liked)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->publicUsers()->findOrFail($id);
        $viewer = $request->user('sanctum');
        $level = (new DetailAccess($viewer))->effectiveLevel();

        $details = DetailController::baseQuery()
            ->where('author_id', $user->id)
            ->orderByDesc('votes_count')
            ->orderByDesc('id')
            ->get();

        return response()->json($this->summary($user, $this->likedIds($viewer, [$user->id])) + [
            'cover_url' => $user->cover_url,
            'bio' => $user->bio,
            'portfolio_url' => $user->portfolio_url,
            'member_since' => $user->created_at?->toIso8601String(),
            'votes_received' => (int) $details->sum('votes_count'),
            'details' => $details->map(fn (Detail $d) => DetailController::card($d, $level))->values(),
        ]);
    }

    public function like(Request $request, int $id): JsonResponse
    {
        $target = $this->publicUsers()->findOrFail($id);
        $userId = (int) $request->user()->id;
        abort_if($userId === (int) $target->id, 422, 'No podés darte "me gusta" a vos mismo.');

        DB::table('user_likes')->insertOrIgnore([
            'from_user_id' => $userId,
            'to_user_id' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->likeState($userId, $target->id);
    }

    public function unlike(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;
        DB::table('user_likes')->where('from_user_id', $userId)->where('to_user_id', $id)->delete();

        return $this->likeState($userId, $id);
    }

    private function publicUsers(): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->with('roles:id,name')
            ->withCount([
                'likesReceived as likes_count',
                'details as details_count' => fn ($q) => $q->where('status', 'published'),
            ]);
    }

    /** Ids (de la lista) a los que el usuario ya les dio "me gusta". */
    private function likedIds(?User $viewer, array $ids): array
    {
        if (! $viewer || ! $ids) {
            return [];
        }

        return DB::table('user_likes')
            ->where('from_user_id', $viewer->id)
            ->whereIn('to_user_id', $ids)
            ->pluck('to_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function summary(User $u, array $likedIds): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'avatar_url' => $u->avatar_url,
            'profession' => $u->profession,
            'company' => $u->company,
            // Autor: colaborador o alguien con detalles publicados
            'is_author' => $u->details_count > 0 || $u->roles->contains(fn ($r) => in_array($r->name, ['colaborador', 'superadmin'], true)),
            'details_count' => (int) $u->details_count,
            'likes_count' => (int) $u->likes_count,
            'liked_by_me' => in_array((int) $u->id, $likedIds, true),
        ];
    }

    private function likeState(int $userId, int $targetId): JsonResponse
    {
        return response()->json([
            'liked' => DB::table('user_likes')->where('from_user_id', $userId)->where('to_user_id', $targetId)->exists(),
            'likes_count' => DB::table('user_likes')->where('to_user_id', $targetId)->count(),
        ]);
    }
}
