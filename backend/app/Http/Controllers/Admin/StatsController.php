<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Detail;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Números del dashboard del panel admin (todos reales, sacados de la base). */
class StatsController extends Controller
{
    private const RANGES = ['today' => 0, '7' => 7, '15' => 15, '30' => 30];

    public function index(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '15');
        abort_unless(array_key_exists($range, self::RANGES), 422, 'Rango no válido.');
        $tz = (string) config('cms.display_timezone');

        // "Online": quien tuvo actividad en los últimos 5 minutos (con sesión = usuarios; sin sesión = visitantes)
        $online = DB::table('visitors')->where('last_seen_at', '>=', now()->subMinutes(5))->pluck('user_id');
        $onlineUserIds = $online->filter()->unique()->values();
        $onlineGuests = $online->filter(fn ($id) => $id === null)->count();
        $onlineStaff = User::whereIn('id', $onlineUserIds)->role(['superadmin', 'colaborador'])->count();

        // Suscripciones pagas activas (las manuales no son ingresos)
        $paid = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->where('plans.level', '>', 0)
            ->where(fn ($q) => $q->whereNull('subscriptions.ends_at')->orWhere('subscriptions.ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('subscriptions.provider')->orWhere('subscriptions.provider', '!=', 'manual'));

        [$traffic, $from] = $this->traffic($range, $tz);

        // Los detalles más abiertos del período (cada apertura de la página del detalle cuenta una vez)
        $top = DB::table('page_views')
            ->join('details', 'details.id', '=', 'page_views.detail_id')
            ->where('details.status', 'published')
            ->where('page_views.created_at', '>=', $from)
            ->selectRaw('details.id, details.title, details.slug, COUNT(*) as views')
            ->groupBy('details.id', 'details.title', 'details.slug')
            ->orderByDesc('views')
            ->orderBy('details.title')
            ->limit(5)
            ->get()
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'slug' => $d->slug, 'views' => (int) $d->views])
            ->values();

        $recent = User::with(['subscriptions' => fn ($q) => $q->where('status', 'active')->with('plan:id,name')])
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar_url' => $u->avatar_url,
                'profession' => $u->profession,
                'plan' => $u->subscriptions->first()?->plan?->name ?? 'Free',
            ]);

        return response()->json([
            'cms_version' => config('cms.version'),
            'cms_released_at' => config('cms.released_at'),
            'online_users' => $onlineUserIds->count(),
            'online_visitors' => $onlineGuests,
            'online_total' => $onlineUserIds->count() + $onlineGuests,
            'online_staff' => $onlineStaff,
            'new_users_7d' => User::role('usuario')->where('created_at', '>=', now()->subDays(7))->count(),
            // Clientes activos (no cuenta al staff ni al administrador)
            'active_users' => User::role('usuario')->where('status', 'active')->count(),
            'paid_subscriptions' => (clone $paid)->count(),
            'monthly_revenue_ars' => (float) (clone $paid)->sum('plans.price_ars'),
            'revenue' => $this->revenue($tz),
            'published_details' => Detail::published()->count(),
            'scheduled_details' => Detail::scheduled()->count(),
            'verified_details' => Detail::published()->where('is_verified', true)->count(),
            'pending_reports' => DB::table('error_reports')->where('status', 'pending')->count(),
            'pending_reviews' => Detail::where('status', 'pending')->count(),
            'traffic' => ['range' => $range] + $traffic,
            'top_details' => $top,
            'recent_users' => $recent,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Visitas por día (o por hora si el rango es "hoy"): personas distintas y páginas vistas.
     *
     * @return array{0: array, 1: Carbon} los datos y desde cuándo (UTC) se contó
     */
    private function traffic(string $range, string $tz): array
    {
        $days = self::RANGES[$range];
        $today = Carbon::now($tz)->startOfDay();
        $from = $days === 0 ? $today->copy() : $today->copy()->subDays($days - 1);

        $buckets = [];
        if ($days === 0) {
            for ($h = 0; $h < 24; $h++) {
                $buckets[sprintf('%02d', $h)] = ['key' => sprintf('%02d', $h), 'label' => sprintf('%02d:00', $h), 'visitors' => [], 'views' => 0, 'detail_opens' => 0];
            }
        } else {
            for ($i = 0; $i < $days; $i++) {
                $d = $from->copy()->addDays($i)->toDateString();
                $buckets[$d] = ['key' => $d, 'label' => $d, 'visitors' => [], 'views' => 0, 'detail_opens' => 0];
            }
        }

        $allVisitors = [];
        $views = 0;
        $detailOpens = 0;

        $rows = DB::table('page_views')
            ->where('created_at', '>=', $from->copy()->utc())
            ->orderBy('id')
            ->select('created_at', 'visitor_id', 'detail_id')
            ->cursor();

        foreach ($rows as $row) {
            $local = Carbon::parse($row->created_at, 'UTC')->setTimezone($tz);
            $key = $days === 0 ? $local->format('H') : $local->toDateString();
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['visitors'][$row->visitor_id] = true;
            $buckets[$key]['views']++;
            $allVisitors[$row->visitor_id] = true;
            $views++;
            if ($row->detail_id) {
                $buckets[$key]['detail_opens']++;
                $detailOpens++;
            }
        }

        $points = array_values(array_map(fn ($b) => [
            'key' => $b['key'],
            'label' => $b['label'],
            'visitors' => count($b['visitors']),
            'views' => $b['views'],
            'detail_opens' => $b['detail_opens'],
        ], $buckets));

        return [[
            'points' => $points,
            'totals' => ['visitors' => count($allVisitors), 'views' => $views, 'detail_opens' => $detailOpens],
        ], $from->copy()->utc()];
    }

    /** Cobros reales del mes y próximos vencimientos (el plan no se renueva solo: la persona vuelve a pagar). */
    private function revenue(string $tz): array
    {
        $monthStart = Carbon::now($tz)->startOfMonth()->utc();
        $collected = DB::table('orders')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $monthStart)
            ->selectRaw('currency, SUM(amount) as total, COUNT(*) as orders')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $todayStart = Carbon::now($tz)->startOfDay()->utc();
        $todayEnd = Carbon::now($tz)->endOfDay()->utc();

        $subs = Subscription::with(['user:id,name,email', 'plan:id,name,price_ars', 'orders' => fn ($q) => $q->where('status', 'paid')->orderByDesc('paid_at')])
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', 'manual'))
            ->whereBetween('ends_at', [now(), now()->addDays(30)])
            ->orderBy('ends_at')
            ->limit(200)
            ->get()
            ->map(function (Subscription $s) use ($todayStart, $todayEnd) {
                $order = $s->orders->first();

                return [
                    'user' => $s->user?->name,
                    'email' => $s->user?->email,
                    'plan' => $s->plan?->name,
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    'is_today' => $s->ends_at && $s->ends_at->between($todayStart, $todayEnd),
                    'in_7_days' => $s->ends_at && $s->ends_at->lte(now()->addDays(7)),
                    'amount' => $order ? (float) $order->amount : (float) ($s->plan?->price_ars ?? 0),
                    'currency' => $order?->currency ?? 'ARS',
                ];
            });

        $sum = fn ($list) => $list->where('currency', 'ARS')->sum('amount');

        return [
            'collected_month_ars' => (float) ($collected['ARS']->total ?? 0),
            'collected_month_usd' => (float) ($collected['USD']->total ?? 0),
            'paid_orders_month' => (int) $collected->sum('orders'),
            'renewals_today_count' => $subs->where('is_today', true)->count(),
            'renewals_today_ars' => (float) $sum($subs->where('is_today', true)),
            'renewals_7d_count' => $subs->where('in_7_days', true)->count(),
            'renewals_7d_ars' => (float) $sum($subs->where('in_7_days', true)),
            'upcoming' => $subs->take(8)->values(),
        ];
    }
}
