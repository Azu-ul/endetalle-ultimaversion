<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Avisos y notificaciones personalizadas del administrador a los usuarios (free, pro, colaboradores o uno puntual). */
class NoticeController extends Controller
{
    private const AUDIENCES = ['all', 'free', 'estudiante', 'pro', 'colaboradores', 'user'];

    public function index(): JsonResponse
    {
        $notices = DB::table('admin_notices')
            ->leftJoin('users as target', 'target.id', '=', 'admin_notices.target_user_id')
            ->orderByDesc('admin_notices.created_at')
            ->orderByDesc('admin_notices.id')
            ->limit(100)
            ->get(['admin_notices.*', 'target.name as target_name', 'target.email as target_email']);

        $reads = DB::table('user_notices')
            ->whereIn('notice_id', $notices->pluck('id'))
            ->whereNotNull('read_at')
            ->selectRaw('notice_id, COUNT(*) as total')
            ->groupBy('notice_id')
            ->pluck('total', 'notice_id');

        return response()->json($notices->map(fn ($n) => [
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'kind' => $n->kind,
            'audience' => $n->audience,
            'target' => $n->target_name ? "{$n->target_name} ({$n->target_email})" : null,
            'recipients_count' => (int) $n->recipients_count,
            'read_count' => (int) ($reads[$n->id] ?? 0),
            'created_at' => \Illuminate\Support\Carbon::parse($n->created_at)->toIso8601String(),
        ]));
    }

    /** Cuántas personas lo van a recibir (para mostrarlo antes de enviar). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:' . implode(',', self::AUDIENCES)],
            'user_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['recipients' => $this->recipients($request, $data['audience'], $data['user_id'] ?? null)->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            // Un link interno (/planes) o externo (https://...)
            'link' => ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)|https?://)#'],
            'kind' => ['nullable', 'in:info,success,warning'],
            'audience' => ['required', 'in:' . implode(',', self::AUDIENCES)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'title.required' => 'Escribí un título para el aviso.',
            'body.required' => 'Escribí el mensaje.',
            'link.regex' => 'El link tiene que empezar con / (una página del sitio) o con https://',
            'user_id.exists' => 'No encontramos a ese usuario.',
        ]);

        if ($data['audience'] === 'user') {
            abort_unless(! empty($data['user_id']), 422, 'Elegí a qué usuario le querés mandar el aviso.');
        }

        $recipients = $this->recipients($request, $data['audience'], $data['user_id'] ?? null);
        abort_if($recipients->isEmpty(), 422, 'No hay usuarios que reciban este aviso.');

        $noticeId = DB::transaction(function () use ($request, $data, $recipients) {
            $id = DB::table('admin_notices')->insertGetId([
                'sender_id' => $request->user()->id,
                'title' => $data['title'],
                'body' => $data['body'],
                'link' => $data['link'] ?? null,
                'kind' => $data['kind'] ?? 'info',
                'audience' => $data['audience'],
                'target_user_id' => $data['audience'] === 'user' ? $data['user_id'] : null,
                'recipients_count' => $recipients->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($recipients->chunk(500) as $chunk) {
                DB::table('user_notices')->insert($chunk->map(fn ($userId) => [
                    'notice_id' => $id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ])->all());
            }

            return $id;
        });

        return response()->json(['id' => $noticeId, 'recipients_count' => $recipients->count()], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        // Al borrar el aviso desaparece también para quienes lo recibieron
        DB::table('admin_notices')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Búsqueda de usuarios para el aviso personalizado. */
    public function users(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        abort_if(mb_strlen($term) < 2, 422, 'Escribí al menos 2 letras.');
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        return response()->json(
            User::where('id', '!=', $request->user()->id)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'email'])
        );
    }

    /** @return Collection<int, int> ids de los usuarios activos que reciben el aviso (sin contar a quien lo envía) */
    private function recipients(Request $request, string $audience, ?int $userId): Collection
    {
        $sender = $request->user()->id;
        $active = User::query()->where('status', 'active')->where('id', '!=', $sender);

        return match ($audience) {
            'user' => $userId && $userId !== $sender ? $active->whereKey($userId)->pluck('id') : collect(),
            'colaboradores' => $active->role('colaborador')->pluck('id'),
            'all' => $active->pluck('id'),
            default => $this->byPlan($active, $audience),
        };
    }

    /** Clientes (no staff) según el plan que tienen hoy: free = sin plan pago vigente. */
    private function byPlan($active, string $audience): Collection
    {
        $paid = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where(function ($q) {
                $q->where('subscriptions.status', 'active')
                    ->orWhere(fn ($s) => $s->where('subscriptions.status', 'scheduled')->where('subscriptions.starts_at', '<=', now()));
            })
            ->where(fn ($q) => $q->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now()))
            ->where('plans.level', '>', 0)
            ->orderBy('plans.level')
            ->get(['subscriptions.user_id', 'plans.slug'])
            ->pluck('slug', 'user_id'); // si hay dos, queda el de nivel más alto (el último)

        $clients = $active->role('usuario')->pluck('id');

        return $audience === 'free'
            ? $clients->reject(fn ($id) => isset($paid[$id]))->values()
            : $clients->filter(fn ($id) => ($paid[$id] ?? null) === $audience)->values();
    }
}
