<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Detail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Comentarios en hilo: un solo nivel de respuestas debajo de cada comentario. */
class CommentController extends Controller
{
    private const WITH_USER = ['user:id,name,avatar_url', 'user.roles'];

    public function index(Request $request, int $id): JsonResponse
    {
        $detail = $this->openableDetail($request, $id);

        $comments = Comment::where('detail_id', $detail->id)
            ->whereNull('parent_id')
            ->with([
                ...self::WITH_USER,
                'replies' => fn ($q) => $q->orderBy('id'),
                'replies.user:id,name,avatar_url',
                'replies.user.roles',
            ])
            ->orderByDesc('id')
            ->get();

        $viewer = $request->user('sanctum');

        return response()->json($comments->map(fn (Comment $c) => $this->serialize($c, $detail, $viewer))->values());
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $detail = $this->openableDetail($request, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
        ], [
            'body.required' => 'Escribí un comentario.',
            'body.max' => 'El comentario puede tener hasta 2000 caracteres.',
        ]);

        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parent = Comment::where('detail_id', $detail->id)->findOrFail($data['parent_id']);
            // Responder a una respuesta cuelga del comentario original
            $parentId = $parent->parent_id ?? $parent->id;
        }

        $comment = Comment::create([
            'detail_id' => $detail->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'body' => $data['body'],
        ]);
        $comment->load(self::WITH_USER)->setRelation('replies', collect());

        return response()->json($this->serialize($comment, $detail, $request->user()), 201);
    }

    /** Editar el texto: el autor edita el suyo y el superadmin puede corregir cualquiera. Queda marcado como "editado". */
    public function update(Request $request, int $id): JsonResponse
    {
        $comment = Comment::with(self::WITH_USER, 'detail')->findOrFail($id);
        abort_unless($this->canEdit($comment, $request->user()), 403, 'No podés editar este comentario.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Escribí un comentario.',
            'body.max' => 'El comentario puede tener hasta 2000 caracteres.',
        ]);

        // "Editado" se deduce de updated_at: no hace falta una columna nueva (ni migración)
        $comment->update(['body' => trim($data['body'])]);
        $comment->setRelation('replies', collect());

        return response()->json($this->serialize($comment, $comment->detail, $request->user()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = Comment::findOrFail($id);
        abort_unless($this->canDelete($comment, $request->user()), 403, 'No podés eliminar este comentario.');

        // Las respuestas se borran en cascada
        $comment->delete();

        return response()->json(['deleted' => true]);
    }

    private function serialize(Comment $c, Detail $detail, ?User $viewer): array
    {
        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'body' => $c->body,
            'created_at' => $c->created_at?->toIso8601String(),
            'edited' => self::wasEdited($c),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'user' => $c->user->only(['id', 'name', 'avatar_url']),
            'badge' => $this->badge($c->user, $detail),
            'can_edit' => $this->canEdit($c, $viewer),
            'can_delete' => $this->canDelete($c, $viewer),
            'replies' => $c->parent_id
                ? []
                : $c->replies->map(fn (Comment $r) => $this->serialize($r, $detail, $viewer))->values(),
        ];
    }

    private function badge(User $user, Detail $detail): ?string
    {
        if ((int) $user->id === (int) $detail->author_id) {
            return 'Autor';
        }

        $roles = $user->roles->pluck('name');

        return match (true) {
            $roles->contains('superadmin') => 'Admin',
            $roles->contains('colaborador') => 'Colaborador',
            default => null,
        };
    }

    /** ¿Se modificó después de publicarlo? (updated_at más de 2 segundos posterior a created_at) */
    public static function wasEdited(Comment $c): bool
    {
        return $c->created_at && $c->updated_at && $c->updated_at->diffInSeconds($c->created_at, true) > 2;
    }

    private function canEdit(Comment $comment, ?User $viewer): bool
    {
        return $viewer !== null
            && ((int) $viewer->id === (int) $comment->user_id || $viewer->hasRole('superadmin'));
    }

    private function canDelete(Comment $comment, ?User $viewer): bool
    {
        return $viewer !== null
            && ((int) $viewer->id === (int) $comment->user_id || $viewer->hasRole('superadmin'));
    }
}
