<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Clientes y staff del panel admin. El superadmin es único y no se toca desde acá (UserPolicy). */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = $request->query('role', 'usuario');
        abort_unless(in_array($role, ['usuario', 'colaborador', 'staff', 'all'], true), 422);

        // "all" = absolutamente todos los usuarios (clientes y staff) menos quien está mirando, paginado.
        // "staff" = el equipo completo: administrador(es) y colaboradores
        $query = ($role === 'all'
            ? User::query()->where('users.id', '!=', $request->user()->id)
            : User::role($role === 'staff' ? ['superadmin', 'colaborador'] : $role))
            ->with(['roles:id,name', 'subscriptions' => fn ($q) => $this->activeSubscriptions($q)])
            ->withCount(['details as details_count' => fn ($q) => $q->where('status', 'published')]);

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        if ($role === 'all') {
            // Filtro opcional dentro de "todos"
            $kind = $request->query('kind');
            if ($kind === 'clients') {
                $query->role('usuario');
            } elseif ($kind === 'staff') {
                $query->role(['superadmin', 'colaborador']);
            }

            $page = $query->latest('users.id')->paginate(50);
            $page->setCollection($page->getCollection()->map(fn (User $u) => $this->serialize($u)));

            return response()->json($page);
        }

        $users = $query->latest('id')->limit(500)->get();
        if ($role === 'staff') {
            // El administrador va primero
            $users = $users->sortByDesc(fn (User $u) => $u->hasRole('superadmin'))->values();
        }

        return response()->json($users->map(fn (User $u) => $this->serialize($u)));
    }

    /**
     * Alta de un cliente hecha por el admin (por ejemplo, alguien que paga por transferencia o a quien se le regala un plan).
     * La cuenta queda activa y con el email verificado. Si no se define contraseña, se genera una al azar y la persona
     * recibe un mail con el enlace para elegir la suya.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'plan' => ['nullable', 'in:free,estudiante,pro'],
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            // Desde la sección Staff se crean directamente colaboradores ("superadmin" no se puede asignar)
            'role' => ['nullable', 'in:usuario,colaborador'],
        ], [
            'name.required' => 'Ingresá el nombre.',
            'email.required' => 'Ingresá el email.',
            'email.email' => 'El email no es válido.',
            'email.unique' => 'Ese email ya está registrado.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $invite = empty($data['password']);

        $user = DB::transaction(function () use ($data, $invite) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($invite ? Str::random(40) : $data['password']),
            ]);
            // Lo creó el dueño del sitio: cuenta activa y email verificado
            $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
            $user->assignRole($data['role'] ?? 'usuario');
            $this->applyManualPlan($user, $data['plan'] ?? 'free', $data['months'] ?? null);

            return $user;
        });

        $inviteSent = null;
        if ($invite) {
            $inviteSent = false;
            try {
                $inviteSent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
            } catch (\Throwable $e) {
                report($e); // la cuenta ya existe; se puede reenviar desde "¿Olvidaste tu contraseña?"
            }
        }

        return response()->json($this->serialize($this->fresh($user)) + ['invite_sent' => $inviteSent], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'profession' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,suspended'],
            // "superadmin" no está entre las opciones: es único
            'role' => ['required', 'in:usuario,colaborador'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'role.in' => 'El rol no es válido.',
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'name' => $data['name'],
                'profession' => $data['profession'] ?? null,
                'company' => $data['company'] ?? null,
                'status' => $data['status'],
            ])->save();
            $user->syncRoles([$data['role']]);

            // Al suspender se cierran sus sesiones abiertas
            if ($data['status'] === 'suspended') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return response()->json($this->serialize($this->fresh($user)));
    }

    /** Plan manual (cortesía, canje, pruebas). "free" quita los planes manuales activos. */
    public function setPlan(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        Gate::authorize('manage', $user);

        $data = $request->validate([
            'plan' => ['required', 'in:free,estudiante,pro'],
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
        ]);

        DB::transaction(fn () => $this->applyManualPlan($user, $data['plan'], $data['months'] ?? null));

        return response()->json($this->serialize($this->fresh($user)));
    }

    /** Deja al usuario con un plan manual (cortesía, canje, pruebas). "free" quita los planes manuales activos. */
    private function applyManualPlan(User $user, string $plan, ?int $months): void
    {
        $user->subscriptions()
            ->where('provider', 'manual')
            ->where('status', 'active')
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        if ($plan !== 'free') {
            $user->subscriptions()->create([
                'plan_id' => Plan::where('slug', $plan)->value('id'),
                'status' => 'active',
                'provider' => 'manual',
                'starts_at' => now(),
                'ends_at' => empty($months) ? null : now()->addMonths($months),
            ]);
        }
    }

    private function activeSubscriptions($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('starts_at')
            ->with('plan:id,slug,name');
    }

    private function fresh(User $user): User
    {
        return $user->fresh()
            ->load(['roles:id,name', 'subscriptions' => fn ($q) => $this->activeSubscriptions($q)])
            ->loadCount(['details as details_count' => fn ($q) => $q->where('status', 'published')]);
    }

    private function serialize(User $u): array
    {
        $subscription = $u->subscriptions->first();

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'profession' => $u->profession,
            'company' => $u->company,
            'status' => $u->status,
            'roles' => $u->roles->pluck('name')->values(),
            'plan' => $subscription?->plan?->only(['slug', 'name']) ?? ['slug' => 'free', 'name' => 'Free'],
            'subscription' => $subscription ? [
                'provider' => $subscription->provider,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ] : null,
            'details_count' => (int) ($u->details_count ?? 0),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
