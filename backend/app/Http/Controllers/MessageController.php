<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mensajes privados del equipo. Solo colaboradores y administradores (la ruta ya exige el rol),
 * y además cada mensaje solo se puede mandar a otra persona del equipo: nunca a un usuario común.
 * Cada persona ve únicamente las conversaciones en las que participa.
 */
class MessageController extends Controller
{
    private const MAX_LENGTH = 2000;
    private const THREAD_LIMIT = 100;

    /** Bandeja: una fila por persona con la que hay conversación. */
    public function conversations(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;

        $groups = $this->visible($me)
            ->selectRaw('CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END AS other_id', [$me])
            ->selectRaw('MAX(id) AS last_id')
            ->selectRaw('SUM(CASE WHEN recipient_id = ? AND read_at IS NULL THEN 1 ELSE 0 END) AS unread', [$me])
            ->groupByRaw('CASE WHEN sender_id = ? THEN recipient_id ELSE sender_id END', [$me])
            ->orderByDesc('last_id')
            ->limit(100)
            ->get();

        $users = User::with('roles:id,name')->whereIn('id', $groups->pluck('other_id'))->get()->keyBy('id');
        $last = DB::table('messages')->whereIn('id', $groups->pluck('last_id'))->get()->keyBy('id');

        return response()->json([
            'unread_count' => self::unreadCount($me),
            'conversations' => $groups->filter(fn ($g) => $users->has($g->other_id))->map(function ($g) use ($users, $last, $me) {
                $m = $last[$g->last_id];

                return [
                    'user' => self::person($users[$g->other_id]),
                    'last_body' => mb_substr($m->body, 0, 140),
                    'last_from_me' => (int) $m->sender_id === $me,
                    'last_at' => Carbon::parse($m->created_at)->toIso8601String(),
                    'unread' => (int) $g->unread,
                ];
            })->values(),
        ]);
    }

    /** Personas del equipo con las que se puede hablar (todas menos uno mismo). */
    public function contacts(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $query = $this->staff()->where('users.id', '!=', $request->user()->id);
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where('users.name', 'like', $like);
        }

        return response()->json(
            $query->with('roles:id,name')->orderBy('users.name')->limit(100)->get()->map(fn (User $u) => self::person($u))->values()
        );
    }

    /** Conversación con una persona. Al abrirla se marcan como leídos los mensajes que recibí. */
    public function thread(Request $request, int $id): JsonResponse
    {
        $me = (int) $request->user()->id;
        $other = User::with('roles:id,name')->findOrFail($id);
        abort_unless($this->isStaff($other), 404);

        DB::table('messages')
            ->where('sender_id', $other->id)->where('recipient_id', $me)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $rows = $this->between($me, $other->id)
            ->orderByDesc('id')->limit(self::THREAD_LIMIT)->get(['id', 'sender_id', 'body', 'read_at', 'created_at'])
            ->reverse()->values();

        return response()->json([
            'user' => self::person($other),
            'unread_count' => self::unreadCount($me),
            'messages' => $rows->map(fn ($m) => self::message($m, $me)),
        ]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . self::MAX_LENGTH],
        ], [
            'body.required' => 'Escribí un mensaje.',
            'body.max' => 'El mensaje es muy largo (máximo ' . self::MAX_LENGTH . ' caracteres).',
        ]);
        $body = trim(str_replace("\r\n", "\n", $data['body']));
        abort_if($body === '', 422, 'Escribí un mensaje.');
        abort_if((int) $me->id === $id, 422, 'No podés mandarte mensajes a vos mismo.');

        $recipient = User::findOrFail($id);
        abort_unless($this->isStaff($recipient), 403, 'Los mensajes privados son solo entre colaboradores y administradores.');

        $messageId = DB::table('messages')->insertGetId([
            'sender_id' => $me->id,
            'recipient_id' => $recipient->id,
            'body' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(self::message(DB::table('messages')->find($messageId), (int) $me->id), 201);
    }

    /** Saca la conversación de MI bandeja (la otra persona la sigue viendo). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $me = (int) $request->user()->id;

        DB::table('messages')->where('sender_id', $me)->where('recipient_id', $id)->update(['sender_deleted_at' => now()]);
        DB::table('messages')->where('recipient_id', $me)->where('sender_id', $id)->update(['recipient_deleted_at' => now()]);

        return response()->json(['unread_count' => self::unreadCount($me)]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => self::unreadCount((int) $request->user()->id)]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::table('messages')
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->whereNull('recipient_deleted_at')
            ->count();
    }

    // ---------------------------------------------------------------------

    private function staff()
    {
        return User::query()
            ->where('users.status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['colaborador', 'superadmin']));
    }

    private function isStaff(User $u): bool
    {
        return $u->status === 'active' && $u->hasAnyRole(['colaborador', 'superadmin']);
    }

    /** Mensajes en los que participo y que no saqué de mi bandeja. */
    private function visible(int $me)
    {
        return DB::table('messages')->where(function ($q) use ($me) {
            $q->where(fn ($s) => $s->where('sender_id', $me)->whereNull('sender_deleted_at'))
                ->orWhere(fn ($r) => $r->where('recipient_id', $me)->whereNull('recipient_deleted_at'));
        });
    }

    private function between(int $me, int $other)
    {
        return $this->visible($me)->where(function ($q) use ($me, $other) {
            $q->where(fn ($a) => $a->where('sender_id', $me)->where('recipient_id', $other))
                ->orWhere(fn ($b) => $b->where('sender_id', $other)->where('recipient_id', $me));
        });
    }

    private static function person(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'avatar_url' => $u->avatar_url,
            'role' => $u->roles->contains('name', 'superadmin') ? 'administrador' : 'colaborador',
        ];
    }

    private static function message(object $m, int $me): array
    {
        return [
            'id' => $m->id,
            'mine' => (int) $m->sender_id === $me,
            'body' => $m->body,
            'read' => $m->read_at !== null,
            'created_at' => Carbon::parse($m->created_at)->toIso8601String(),
        ];
    }
}
