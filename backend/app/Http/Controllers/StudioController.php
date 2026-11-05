<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Detail;
use App\Models\DetailFile;
use App\Models\Plan;
use App\Models\Tag;
use App\Rules\DetailFileSignature;
use App\Services\Images\DetailImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Espacio de los colaboradores: cargan sus detalles, los guardan como
 * borrador y los envían a revisión. El superadmin los aprueba o rechaza
 * (ver Admin\ReviewController).
 *
 * Estados: draft (borrador) -> pending (en revisión) -> published | rejected.
 */
class StudioController extends Controller
{
    private const IMAGE_TYPES = ['section', 'plan', 'elevation', 'axonometric', 'detail', 'photo'];
    private const MAX_IMAGES = 12;

    public function index(Request $request): JsonResponse
    {
        $details = Detail::where('author_id', $request->user()->id)
            ->with(['category:id,name', 'requiredPlan:id,slug,name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Detail $d) => self::summary($d));

        return response()->json($details);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(self::full($this->ownDetail($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateDetail($request);

        $detail = DB::transaction(function () use ($request, $data) {
            $detail = Detail::create($this->attributes($data) + [
                'slug' => $this->uniqueSlug($data['title']),
                'author_id' => $request->user()->id,
                'status' => 'draft',
            ]);
            $this->syncTags($detail, $data['tags'] ?? []);

            return $detail;
        });

        return response()->json(self::full($detail), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $data = $this->validateDetail($request);
        $this->reopen($request, $detail);

        DB::transaction(function () use ($detail, $data) {
            $attributes = $this->attributes($data);
            // El link cambia con el título solo mientras nunca se publicó
            if (! $detail->published_at && $detail->title !== $data['title']) {
                $attributes['slug'] = $this->uniqueSlug($data['title'], $detail->id);
            }
            $detail->update($attributes);
            $this->syncTags($detail, $data['tags'] ?? []);
        });

        return response()->json(self::full($detail->fresh()));
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        abort_unless($detail->cover_url, 422, 'Subí una portada antes de enviar el detalle a revisión.');

        $detail->update(['status' => 'pending']);

        return response()->json(self::full($detail));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $detail = Detail::findOrFail($id);
        Gate::authorize('delete', $detail);

        Storage::disk('public')->deleteDirectory("details/{$detail->id}");
        DetailFile::disk()->deleteDirectory("detail-files/{$detail->id}");
        DetailImages::privateDisk()->deleteDirectory("detail-images/{$detail->id}");
        $detail->delete();

        return response()->json(['deleted' => true]);
    }

    public function uploadCover(Request $request, int $id): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $this->validateImage($request);
        $this->reopen($request, $detail);

        // Se optimiza: original (JPG, hasta 2 MB) y vista previa en privado, miniatura pública para las tarjetas
        $attributes = DetailImages::storeCover($detail, DetailImages::binaryOf($request->file('image')));
        DetailImages::deleteCoverFiles($detail);
        $detail->update($attributes);

        return response()->json(self::full($detail));
    }

    public function addImage(Request $request, int $id): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $this->validateImage($request, [
            'type' => ['required', 'in:' . implode(',', self::IMAGE_TYPES)],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if($detail->images()->count() >= self::MAX_IMAGES, 422, 'Podés subir hasta ' . self::MAX_IMAGES . ' láminas por detalle.');
        $this->reopen($request, $detail);

        DetailImages::storeImage(
            $detail,
            DetailImages::binaryOf($request->file('image')),
            $request->input('type'),
            $request->input('caption'),
            (int) $detail->images()->max('sort_order') + 1,
        );

        return response()->json(self::full($detail->fresh()));
    }

    public function deleteImage(Request $request, int $id, int $imageId): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $image = $detail->images()->findOrFail($imageId);
        $this->reopen($request, $detail);

        DetailImages::deleteImageFiles($image);
        $image->delete();

        return response()->json(self::full($detail->fresh()));
    }

    /** Archivo descargable DWG o SKP (uno de cada tipo; se guarda en el disco privado). */
    public function uploadFile(Request $request, int $id, string $type): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $maxMb = (int) config('filesystems.details_max_mb');
        $request->validate([
            'file' => ['required', 'file', 'max:' . ($maxMb * 1024), 'extensions:' . $type, new DetailFileSignature($type)],
        ], [
            'file.required' => 'Elegí un archivo.',
            // PHP corta la subida antes de llegar acá si pasa el límite del hosting (upload_max_filesize)
            'file.uploaded' => "No se pudo subir el archivo (máximo {$maxMb} MB). Si pesa menos, el hosting está limitando las subidas.",
            'file.max' => "El archivo puede pesar hasta {$maxMb} MB.",
            'file.extensions' => 'El archivo tiene que ser .' . strtoupper($type) . '.',
        ]);

        $this->reopen($request, $detail);
        $upload = $request->file('file');
        $existing = $detail->files()->where('type', $type)->first();
        if ($existing?->path) {
            DetailFile::disk()->delete($existing->path);
        }

        DetailFile::updateOrCreate(
            ['detail_id' => $detail->id, 'type' => $type],
            [
                'path' => $upload->store("detail-files/{$detail->id}", config('filesystems.details_disk')),
                'original_name' => $upload->getClientOriginalName(),
                'size_bytes' => $upload->getSize(),
            ]
        );

        return response()->json(self::full($detail));
    }

    public function deleteFile(Request $request, int $id, string $type): JsonResponse
    {
        $detail = $this->editableDetail($request, $id);
        $file = $detail->files()->where('type', $type)->firstOrFail();
        $this->reopen($request, $detail);

        if ($file->path) {
            DetailFile::disk()->delete($file->path);
        }
        $file->delete();

        return response()->json(self::full($detail));
    }

    // ---------- Formato de respuesta (también lo usa la bandeja del admin) ----------

    public static function summary(Detail $d): array
    {
        return [
            'id' => $d->id,
            'slug' => $d->slug,
            'title' => $d->title,
            'status' => $d->status,
            'cover_url' => $d->cover_url,
            'category' => $d->category?->name,
            'required_plan' => $d->requiredPlan?->slug,
            'is_verified' => (bool) $d->is_verified,
            'is_public' => (bool) $d->is_public,
            'is_scheduled' => $d->isScheduled(),
            'likes_count' => (int) $d->votes_count,
            'dislikes_count' => (int) $d->dislikes_count,
            'review_notes' => $d->review_notes,
            'author' => $d->relationLoaded('author') ? $d->author?->only(['id', 'name', 'avatar_url']) : null,
            'updated_at' => $d->updated_at?->toIso8601String(),
            'published_at' => $d->published_at?->toIso8601String(),
        ];
    }

    public static function full(Detail $d): array
    {
        $d->loadMissing([
            'category:id,name', 'requiredPlan:id,slug,name', 'author:id,name,avatar_url', 'tags:id,name',
            'images' => fn ($q) => $q->orderBy('sort_order'), 'files',
        ]);

        return self::summary($d) + [
            'description' => $d->description,
            'category_id' => $d->category_id,
            'subcategory_id' => $d->subcategory_id,
            'specs' => $d->specs ?? [],
            'materials' => $d->materials ?? [],
            'tags' => $d->tags->pluck('name')->values(),
            'images' => $d->images->map(fn ($i) => $i->only(['id', 'type', 'url', 'caption']))->values(),
            'files' => $d->files->map(fn ($f) => $f->only(['type', 'original_name', 'size_bytes']))->values(),
        ];
    }

    // ---------- Ayudas ----------

    private function validateDetail(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', 'exists:categories,id,parent_id,NULL'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id,parent_id,' . (int) $request->input('category_id')],
            'specs' => ['nullable', 'array', 'max:30'],
            // Las líneas vacías llegan como null (ConvertEmptyStringsToNull) y se descartan
            'specs.*' => ['nullable', 'string', 'max:300'],
            'materials' => ['nullable', 'array', 'max:30'],
            'materials.*' => ['nullable', 'string', 'max:300'],
            'tags' => ['nullable', 'array', 'max:15'],
            'tags.*' => ['nullable', 'string', 'max:50'],
            'required_plan' => ['nullable', 'in:estudiante,pro'],
            'is_public' => ['nullable', 'boolean'],
        ], [
            'title.required' => 'Ponele un título al detalle.',
            'description.required' => 'Escribí una descripción.',
            'description.max' => 'La descripción puede tener hasta 5000 caracteres.',
            'category_id.required' => 'Elegí una categoría.',
            'category_id.exists' => 'La categoría no es válida.',
            'subcategory_id.exists' => 'La subcategoría no corresponde a esa categoría.',
            'specs.max' => 'Hasta 30 especificaciones.',
            'materials.max' => 'Hasta 30 materiales.',
            'tags.max' => 'Hasta 15 etiquetas.',
        ]);
    }

    private function attributes(array $data): array
    {
        $category = Category::find($data['category_id']);
        // La categoría Premium es solo para el plan Pro
        $planSlug = $category?->slug === 'premium' ? 'pro' : ($data['required_plan'] ?? null);
        // "Público" = lo ve cualquiera sin cuenta: no combina con un plan pago ni con la categoría Premium
        $isPublic = ! empty($data['is_public']) && $planSlug === null && $category?->slug !== 'premium';

        return [
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'subcategory_id' => $data['subcategory_id'] ?? null,
            'specs' => $this->cleanList($data['specs'] ?? []),
            'materials' => $this->cleanList($data['materials'] ?? []),
            'required_plan_id' => $planSlug ? Plan::where('slug', $planSlug)->value('id') : null,
            'is_public' => $isPublic,
        ];
    }

    /** Sin vacíos ni nulls, y sin espacios de más. */
    private function cleanList(array $items): array
    {
        return collect($items)
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            ->map(fn ($s) => trim($s))
            ->values()
            ->all();
    }

    private function syncTags(Detail $detail, array $names): void
    {
        $ids = collect($this->cleanList($names))
            ->unique(fn ($n) => Str::lower($n))
            ->map(fn ($n) => Tag::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])->id);

        $detail->tags()->sync($ids->all());
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'detalle';
        $slug = $base;
        for ($i = 2; Detail::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function validateImage(Request $request, array $extra = []): void
    {
        $request->validate([
            // Se acepta hasta 12 MB y el servidor la reduce (el original queda en 2 MB como máximo)
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('cms.images.input_max_kb')],
            ...$extra,
        ], [
            'image.required' => 'Elegí una imagen.',
            'image.uploaded' => 'No se pudo subir la imagen (máximo 12 MB). Si pesa menos, el hosting está limitando las subidas.',
            'image.image' => 'El archivo tiene que ser una imagen.',
            'image.mimes' => 'La imagen tiene que ser JPG, PNG o WebP.',
            'image.max' => 'La imagen puede pesar hasta 12 MB.',
            'type.required' => 'Elegí el tipo de lámina.',
            'type.in' => 'El tipo de lámina no es válido.',
        ]);
    }

    /** Detalle propio (el superadmin puede ver cualquiera). Ver DetailPolicy. */
    private function ownDetail(Request $request, int $id): Detail
    {
        $detail = Detail::findOrFail($id);
        Gate::authorize('view', $detail);

        return $detail;
    }

    /** Detalle propio que todavía se puede cambiar (lo publicado lo cambia el admin). */
    private function editableDetail(Request $request, int $id): Detail
    {
        $detail = Detail::findOrFail($id);
        Gate::authorize('update', $detail);

        return $detail;
    }

    /**
     * Cuando un colaborador toca un detalle ya publicado, vuelve a "en revisión" y sale
     * del sitio hasta que el admin lo apruebe. El superadmin edita sin cambiar el estado.
     */
    private function reopen(Request $request, Detail $detail): void
    {
        if ($detail->status === 'published' && ! $request->user()->hasRole('superadmin')) {
            $detail->update(['status' => 'pending']);
        }
    }
}
