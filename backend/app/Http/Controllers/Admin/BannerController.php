<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\Images\DetailImages;
use App\Services\Images\ImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Carrusel de avisos de la portada: crear, editar, ordenar, programar y borrar (solo superadmin). */
class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Banner::orderBy('sort_order')->orderBy('id')->get()->map(fn (Banner $b) => self::serialize($b)));
    }

    public function store(Request $request): JsonResponse
    {
        $banner = Banner::create($this->validated($request) + ['sort_order' => (int) Banner::max('sort_order') + 1]);

        return response()->json(self::serialize($banner), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $banner = Banner::findOrFail($id);
        $data = $this->validated($request);

        // Si pasó de una imagen subida a un link (o la quitó), se borra el archivo viejo
        if ($banner->image_path && ($data['image_url'] ?? null) !== $banner->image_url) {
            DetailImages::publicDisk()->delete($banner->image_path);
            $data['image_path'] = null;
        }
        $banner->update($data);

        return response()->json(self::serialize($banner->fresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        $banner = Banner::findOrFail($id);
        if ($banner->image_path) {
            DetailImages::publicDisk()->delete($banner->image_path);
        }
        $banner->delete();

        return response()->json(['deleted' => true]);
    }

    /** Subir la imagen del aviso: se reduce a WebP liviano (máx. 400 KB). */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $banner = Banner::findOrFail($id);
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . (int) config('cms.images.input_max_kb')],
        ], [
            'image.required' => 'Elegí una imagen.',
            'image.image' => 'El archivo tiene que ser una imagen.',
            'image.mimes' => 'La imagen tiene que ser JPG, PNG o WebP.',
            'image.uploaded' => 'No se pudo subir la imagen (máximo 12 MB).',
        ]);

        $cfg = config('cms.images');
        $out = ImageOptimizer::encode(DetailImages::binaryOf($request->file('image')), $cfg['banner_max_edge'], $cfg['banner_max_bytes'], 'webp');
        $path = "banners/{$banner->id}-" . Str::random(16) . ".{$out['ext']}";
        DetailImages::publicDisk()->put($path, $out['data']);

        if ($banner->image_path) {
            DetailImages::publicDisk()->delete($banner->image_path);
        }
        $banner->update(['image_path' => $path, 'image_url' => DetailImages::publicDisk()->url($path)]);

        return response()->json(self::serialize($banner->fresh()));
    }

    /** Orden del carrusel: llega la lista de ids en el orden nuevo. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        foreach (array_values($data['ids']) as $position => $id) {
            Banner::where('id', $id)->update(['sort_order' => $position + 1]);
        }

        return $this->index();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'badge' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:400'],
            'button_text' => ['nullable', 'string', 'max:60'],
            'button_url' => ['nullable', 'string', 'max:500', 'regex:#^(/(?!/)|https?://)#'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], [
            'title.required' => 'Escribí el título del aviso.',
            'button_url.regex' => 'El link del botón tiene que empezar con / (una página del sitio) o con https://',
            'image_url.url' => 'La imagen tiene que ser un link (https://...) o subirla desde tu compu.',
            'ends_at.after' => 'La fecha de fin tiene que ser posterior a la de inicio.',
        ]);
    }

    public static function serialize(Banner $b): array
    {
        return [
            'id' => $b->id,
            'badge' => $b->badge,
            'title' => $b->title,
            'subtitle' => $b->subtitle,
            'button_text' => $b->button_text,
            'button_url' => $b->button_url,
            'image_url' => $b->image_url,
            'is_active' => (bool) $b->is_active,
            'sort_order' => (int) $b->sort_order,
            'starts_at' => $b->starts_at?->toIso8601String(),
            'ends_at' => $b->ends_at?->toIso8601String(),
        ];
    }
}
