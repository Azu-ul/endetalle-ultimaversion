<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Services\DetailAccess;
use App\Services\Images\DetailImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DetailController extends Controller
{
    /** Largo del texto de la tarjeta en los listados (el detalle abierto trae la descripción completa). */
    private const CARD_DESCRIPTION = 240;

    public function index(Request $request): JsonResponse
    {
        $level = (new DetailAccess($request->user('sanctum')))->effectiveLevel();

        $query = self::baseQuery();

        if ($category = $request->query('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        if ($subcategory = $request->query('subcategory')) {
            $query->whereHas('subcategory', fn ($q) => $q->where('slug', $subcategory));
        }

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }

        if ($request->query('sort') === 'date') {
            $query->orderByDesc('published_at')->orderByDesc('id');
        } else {
            $query->orderByDesc('votes_count')->orderByDesc('id');
        }

        $perPage = min(max((int) $request->query('per_page', 24), 1), 100);

        $page = $query->paginate($perPage)->through(fn (Detail $d) => self::card($d, $level, true));

        return response()->json($page);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $detail = self::baseQuery()->with(['images', 'files'])->where('slug', $slug)->firstOrFail();

        $user = $request->user('sanctum');
        $access = new DetailAccess($user);
        $decision = $access->check($detail);
        $level = $access->effectiveLevel();

        $payload = self::card($detail, $level) + ['access' => $decision];

        // Vista previa: sin especificaciones, materiales, imágenes ni archivos.
        if (! $decision['allowed']) {
            $payload['description'] = Str::limit($detail->description, 200);

            return response()->json($payload);
        }

        $access->recordView($detail);

        $related = self::baseQuery()
            ->where('category_id', $detail->category_id)
            ->where('id', '!=', $detail->id)
            ->orderByDesc('votes_count')
            ->limit(4)
            ->get()
            ->map(fn (Detail $d) => self::card($d, $level, true));

        $reaction = $user
            ? DB::table('detail_reactions')->where('user_id', $user->id)->where('detail_id', $detail->id)->value('value')
            : null;

        return response()->json($payload + [
            'specs' => $detail->specs ?? [],
            'materials' => $detail->materials ?? [],
            // La imagen grande sale por la ruta protegida (vista previa); la portada vieja sigue con su URL pública
            'cover_view_url' => $detail->cover_path
                ? DetailImages::mediaUrl($detail->id, 'cover')
                : $detail->cover_url,
            'can_download_image' => $access->canDownload('image'),
            'images' => $detail->images->map(fn ($i) => [
                'id' => $i->id,
                'type' => $i->type,
                'url' => $i->url,
                'caption' => $i->caption,
            ])->values(),
            'files' => $detail->files->map(fn ($f) => [
                'type' => $f->type,
                'can_download' => $access->canDownload($f->type),
            ])->values(),
            'seo_title' => $detail->seo_title,
            'meta_description' => $detail->meta_description,
            'my_reaction' => $reaction === null ? null : ((int) $reaction === 1 ? 'like' : 'dislike'),
            'related' => $related,
        ]);
    }

    public static function baseQuery(): Builder
    {
        return Detail::published()->with([
            'category:id,name,slug',
            'subcategory:id,name,slug',
            'author:id,name,avatar_url,profession,company',
            'requiredPlan:id,slug,name,level',
            'tags:id,name,slug',
        ]);
    }

    public static function card(Detail $d, int $viewerLevel, bool $short = false): array
    {
        $required = $d->requiredPlan?->level ?? 0;

        return [
            'id' => $d->id,
            'slug' => $d->slug,
            'title' => $d->title,
            'description' => $short ? Str::limit($d->description, self::CARD_DESCRIPTION) : $d->description,
            'cover_url' => $d->cover_url,
            'category' => $d->category?->only(['id', 'name', 'slug']),
            'subcategory' => $d->subcategory?->only(['id', 'name', 'slug']),
            'author' => $d->author?->only(['id', 'name', 'avatar_url', 'profession', 'company']),
            'tags' => $d->tags->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug])->values(),
            'is_verified' => $d->is_verified,
            'is_public' => (bool) $d->is_public,
            // "votes_count" es el contador de "me gusta" (se llama así desde la primera versión)
            'votes_count' => $d->votes_count,
            'dislikes_count' => (int) $d->dislikes_count,
            'published_at' => $d->published_at?->toDateString(),
            'required_plan' => $d->requiredPlan?->only(['slug', 'name', 'level']),
            'locked' => ! $d->is_public && $required > $viewerLevel,
        ];
    }
}
