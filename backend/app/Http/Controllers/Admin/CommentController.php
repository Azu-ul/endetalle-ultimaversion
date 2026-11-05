<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CommentController as PublicCommentController;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moderación de comentarios (sección Comunidad del panel). Listar es de acá; editar y borrar
 * reutilizan PUT/DELETE /api/comments/{id}, donde el superadmin puede sobre cualquier comentario.
 */
class CommentController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $query = Comment::with(['user:id,name,email,avatar_url', 'detail:id,title,slug', 'parent:id,user_id', 'parent.user:id,name'])
            ->latest('id');

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($q) => $q
                ->where('body', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('detail', fn ($d) => $d->where('title', 'like', $like)));
        }

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'comments' => $page->getCollection()->map(fn (Comment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'created_at' => $c->created_at?->toIso8601String(),
                'edited' => PublicCommentController::wasEdited($c),
                'updated_at' => $c->updated_at?->toIso8601String(),
                'is_reply' => $c->parent_id !== null,
                'reply_to' => $c->parent?->user?->name,
                'user' => $c->user ? $c->user->only(['id', 'name', 'email', 'avatar_url']) : null,
                'detail' => $c->detail ? $c->detail->only(['id', 'title', 'slug']) : null,
            ])->values(),
        ]);
    }
}
