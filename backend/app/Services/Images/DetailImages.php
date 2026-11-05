<?php

namespace App\Services\Images;

use App\Models\Detail;
use App\Models\DetailFile;
use App\Models\DetailImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dónde viven las imágenes de un detalle:
 *  - original (JPG, hasta 2 MB): disco privado, solo se entrega con "Descargar" según el plan.
 *  - vista previa (WebP liviano): disco privado, se entrega por la ruta protegida /api/details/{id}/media/...
 *  - miniatura de la portada (WebP): disco público, es la que se ve en las tarjetas y al compartir el link.
 * Las imágenes cargadas antes de esta versión siguen funcionando con su URL pública hasta que se corre
 * migrateLegacy() (botón "Optimizar imágenes" del panel).
 */
class DetailImages
{
    public static function privateDisk(): FilesystemAdapter
    {
        return DetailFile::disk();
    }

    public static function publicDisk(): FilesystemAdapter
    {
        return Storage::disk('public');
    }

    /** Ruta (relativa al sitio) por la que se ve una imagen de la galería o la portada. */
    public static function mediaUrl(int $detailId, int|string $key): string
    {
        return "/api/details/{$detailId}/media/{$key}";
    }

    // ---------- Guardar ----------

    /** @return array{cover_url: string, cover_path: string, cover_preview_path: string} */
    public static function storeCover(Detail $detail, string $binary): array
    {
        $cfg = config('cms.images');
        $original = ImageOptimizer::encode($binary, $cfg['original_max_edge'], $cfg['original_max_bytes'], 'jpg');
        $preview = ImageOptimizer::encode($binary, $cfg['preview_max_edge'], $cfg['preview_max_bytes'], 'webp');
        $thumb = ImageOptimizer::encode($binary, $cfg['thumb_max_edge'], $cfg['thumb_max_bytes'], 'webp');

        $name = Str::random(32);
        $private = self::privateDisk();
        $originalPath = "detail-images/{$detail->id}/{$name}.{$original['ext']}";
        $previewPath = "detail-images/{$detail->id}/{$name}-vista.{$preview['ext']}";
        $thumbPath = "details/{$detail->id}/portada-{$name}.{$thumb['ext']}";

        $private->put($originalPath, $original['data']);
        $private->put($previewPath, $preview['data']);
        self::publicDisk()->put($thumbPath, $thumb['data']);

        return [
            'cover_url' => self::publicDisk()->url($thumbPath),
            'cover_path' => $originalPath,
            'cover_preview_path' => $previewPath,
        ];
    }

    /** Crea la lámina de la galería. */
    public static function storeImage(Detail $detail, string $binary, string $type, ?string $caption, int $sortOrder): DetailImage
    {
        $cfg = config('cms.images');
        $original = ImageOptimizer::encode($binary, $cfg['original_max_edge'], $cfg['original_max_bytes'], 'jpg');
        $preview = ImageOptimizer::encode($binary, $cfg['preview_max_edge'], $cfg['preview_max_bytes'], 'webp');

        $name = Str::random(32);
        $private = self::privateDisk();
        $originalPath = "detail-images/{$detail->id}/{$name}.{$original['ext']}";
        $previewPath = "detail-images/{$detail->id}/{$name}-vista.{$preview['ext']}";
        $private->put($originalPath, $original['data']);
        $private->put($previewPath, $preview['data']);

        $image = $detail->images()->create([
            'type' => $type,
            'url' => '',
            'path' => $originalPath,
            'preview_path' => $previewPath,
            'size_bytes' => strlen($original['data']),
            'caption' => $caption,
            'sort_order' => $sortOrder,
        ]);
        $image->update(['url' => self::mediaUrl($detail->id, $image->id)]);

        return $image;
    }

    public static function binaryOf(UploadedFile $file): string
    {
        return (string) file_get_contents($file->getRealPath());
    }

    // ---------- Borrar ----------

    public static function deleteCoverFiles(Detail $detail): void
    {
        foreach ([$detail->cover_path, $detail->cover_preview_path] as $path) {
            if ($path) {
                self::privateDisk()->delete($path);
            }
        }
        self::deletePublicUrl($detail->cover_url);
    }

    public static function deleteImageFiles(DetailImage $image): void
    {
        foreach ([$image->path, $image->preview_path] as $path) {
            if ($path) {
                self::privateDisk()->delete($path);
            }
        }
        self::deletePublicUrl($image->url);
    }

    /** Solo se borran archivos subidos acá (no los links externos de los datos demo ni las rutas protegidas). */
    public static function deletePublicUrl(?string $url): void
    {
        $base = self::publicDisk()->url('');
        if ($url && Str::startsWith($url, $base)) {
            self::publicDisk()->delete(Str::after($url, $base));
        }
    }

    // ---------- Imágenes viejas (cargadas antes de la v2) ----------

    private static function publicBase(): string
    {
        return self::publicDisk()->url('');
    }

    public static function legacyCovers()
    {
        return Detail::whereNull('cover_path')->whereNotNull('cover_url')->where('cover_url', 'like', self::publicBase() . '%');
    }

    public static function legacyImages()
    {
        return DetailImage::whereNull('path')->where('url', 'like', self::publicBase() . '%');
    }

    public static function pendingCount(): int
    {
        return self::legacyCovers()->count() + self::legacyImages()->count();
    }

    /**
     * Pasa a la protección y optimización nuevas hasta $limit imágenes viejas.
     *
     * @return array{done: int, failed: int, pending: int, saved_bytes: int}
     */
    public static function migrateLegacy(int $limit = 5): array
    {
        $done = 0;
        $failed = 0;
        $saved = 0;
        $base = self::publicBase();

        foreach (self::legacyCovers()->limit($limit)->get() as $detail) {
            if ($done + $failed >= $limit) {
                break;
            }
            $path = Str::after($detail->cover_url, $base);
            try {
                $binary = self::publicDisk()->get($path);
                if ($binary === null || $binary === '') {
                    throw new \RuntimeException('archivo vacío o inexistente');
                }
                $attributes = self::storeCover($detail, $binary);
                $saved += max(0, strlen($binary) - (int) strlen(self::privateDisk()->get($attributes['cover_path'])));
                self::publicDisk()->delete($path);
                $detail->update($attributes);
                $done++;
            } catch (\Throwable $e) {
                report($e);
                // Se marca para no reintentar todo el tiempo la misma: queda con su URL pública
                $detail->update(['cover_path' => '']);
                $failed++;
            }
        }

        foreach (self::legacyImages()->limit(max(0, $limit - $done - $failed))->get() as $image) {
            $path = Str::after($image->url, $base);
            try {
                $binary = self::publicDisk()->get($path);
                if ($binary === null || $binary === '') {
                    throw new \RuntimeException('archivo vacío o inexistente');
                }
                $detail = Detail::findOrFail($image->detail_id);
                $new = self::storeImage($detail, $binary, $image->type, $image->caption, (int) $image->sort_order);
                $saved += max(0, strlen($binary) - (int) $new->size_bytes);
                self::publicDisk()->delete($path);
                $image->delete();
                // La lámina nueva toma el lugar de la vieja
                $new->update(['sort_order' => $image->sort_order]);
                $done++;
            } catch (\Throwable $e) {
                report($e);
                $image->update(['path' => '']);
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed, 'pending' => self::pendingCount(), 'saved_bytes' => $saved];
    }
}
