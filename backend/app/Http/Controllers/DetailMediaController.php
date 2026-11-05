<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Services\DetailAccess;
use App\Services\Images\DetailImages;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las imágenes grandes del detalle no tienen una URL pública: se entregan acá, solo a quien puede abrir el
 * detalle (vista previa) o descargarlo (original, con el plan que corresponda).
 */
class DetailMediaController extends Controller
{
    /** Imagen para mostrar en la página (vista previa liviana). */
    public function show(Request $request, int $id, string $key): Response
    {
        // Abrir la imagen en otra pestaña, incrustarla en otra web o pedirla "a mano": no
        if (in_array($request->header('Sec-Fetch-Dest'), ['document', 'iframe', 'frame', 'embed', 'object'], true)
            || $request->header('Sec-Fetch-Site') === 'cross-site') {
            abort(403, 'Esta imagen solo se puede ver dentro de enDetalle.');
        }

        [$detail, $media] = $this->resolve($request, $id, $key);

        $disk = DetailImages::privateDisk();
        $path = $media['preview_path'];
        if (! $path) {
            // Imagen anterior a la protección: se sirve su archivo público
            return $this->legacy($media['url'], inline: true);
        }
        abort_unless($disk->exists($path), 404, 'La imagen no está disponible.');

        return $disk->response($path, $this->fileName($detail, $key, 'webp'), [
            'Cache-Control' => 'private, max-age=604800, immutable',
            // La copia guardada por el navegador no se entrega a una pestaña nueva que pide la imagen "suelta"
            'Vary' => 'Sec-Fetch-Dest, Sec-Fetch-Site',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** Botón "Descargar ilustración": el original en alta calidad (hasta 2 MB). */
    public function download(Request $request, int $id, string $key): Response
    {
        [$detail, $media] = $this->resolve($request, $id, $key);

        $access = new DetailAccess($request->user());
        abort_unless($access->canDownload('image'), 403, 'La descarga de ilustraciones se incluye con los planes Estudiante y Pro.');

        $path = $media['path'];
        if (! $path) {
            return $this->legacy($media['url'], inline: false, name: $this->fileName($detail, $key, Str::afterLast($media['url'], '.')));
        }

        $disk = DetailImages::privateDisk();
        abort_unless($disk->exists($path), 404, 'La imagen no está disponible.');

        return $disk->download($path, $this->fileName($detail, $key, Str::afterLast($path, '.')), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Detalle + datos de la imagen pedida, con los permisos aplicados.
     *
     * @return array{0: Detail, 1: array{path: ?string, preview_path: ?string, url: string}}
     */
    private function resolve(Request $request, int $id, string $key): array
    {
        $detail = Detail::findOrFail($id);
        $user = $request->user('sanctum');

        $isOwner = $user && ($user->hasRole('superadmin') || (int) $detail->author_id === (int) $user->id);
        if (! $isOwner) {
            abort_unless($detail->status === 'published' && ! $detail->published_at?->isFuture(), 404);
            $detail->loadMissing('requiredPlan:id,slug,name,level');
            abort_unless((new DetailAccess($user))->check($detail)['allowed'], 403, 'No tenés acceso a este detalle.');
        }

        if ($key === 'cover') {
            return [$detail, [
                'path' => $detail->cover_path ?: null,
                'preview_path' => $detail->cover_preview_path ?: null,
                'url' => (string) $detail->cover_url,
            ]];
        }

        abort_unless(ctype_digit($key), 404);
        $image = $detail->images()->findOrFail((int) $key);

        return [$detail, [
            'path' => $image->path ?: null,
            'preview_path' => $image->preview_path ?: null,
            'url' => (string) $image->url,
        ]];
    }

    private function legacy(string $url, bool $inline, ?string $name = null): Response
    {
        $public = DetailImages::publicDisk();
        $base = $public->url('');
        if (Str::startsWith($url, $base) && $public->exists(Str::after($url, $base))) {
            $relative = Str::after($url, $base);

            return $inline
                ? $public->response($relative, null, ['Cache-Control' => 'private, max-age=3600'], 'inline')
                : $public->download($relative, $name);
        }

        abort_unless(Str::startsWith($url, ['http://', 'https://']), 404);

        return redirect()->away($url);
    }

    private function fileName(Detail $detail, string $key, string $ext): string
    {
        $suffix = $key === 'cover' ? 'principal' : "lamina-{$key}";
        $ext = Str::lower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg');

        return Str::slug($detail->slug) . "-{$suffix}.{$ext}";
    }
}
