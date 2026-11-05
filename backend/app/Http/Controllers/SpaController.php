<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sirve el index.html del frontend (React) en todas las rutas del sitio.
 * WhatsApp, Instagram y compañía no ejecutan JavaScript, así que las
 * metaetiquetas Open Graph de cada detalle, perfil y categoría se agregan acá.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $index = config('app.spa_index');
        if (! Str::startsWith($index, ['/', '\\']) && ! preg_match('#^[A-Za-z]:[\\\\/]#', $index)) {
            $index = base_path($index);
        }

        if (! is_file($index)) {
            // En producción la API y el sitio van en dominios separados: acá no hay frontend que servir
            abort_if(app()->isProduction(), 404);

            return response('Falta el build del frontend: corré "npx vite build" en frontend/ y revisá SPA_INDEX en el .env.', 503)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $settings = SiteController::settingsData(lite: true);
        $siteTitle = $settings['site_title'] ?? null ?: 'enDetalle';
        $meta = [
            'title' => $siteTitle,
            'description' => ($settings['site_description'] ?? null) ?: 'Biblioteca de detalles constructivos.',
            'image' => null,
            'type' => 'website',
            'keywords' => [],
        ];
        $status = 200;
        $path = trim($request->path(), '/');

        if (preg_match('#^detalle/([^/]+)$#', $path, $m)) {
            $detail = Detail::published()->with('tags:id,name')->where('slug', urldecode($m[1]))->first();
            if ($detail) {
                $meta = [
                    'title' => ($detail->seo_title ?: $detail->title) . ' | ' . $siteTitle,
                    'description' => $detail->meta_description ?: Str::limit($detail->description, 200),
                    'image' => $detail->cover_url,
                    'type' => 'article',
                    // Las etiquetas del detalle suman al SEO
                    'keywords' => $detail->tags->pluck('name')->all(),
                ];
            } else {
                $status = 404;
            }
        } elseif (preg_match('#^perfil/(\d+)#', $path, $m)) {
            $author = User::where('status', 'active')->find($m[1]);
            if ($author) {
                $meta = [
                    'title' => $author->name . ' | ' . $siteTitle,
                    'description' => implode(' · ', array_filter([$author->profession, $author->company]))
                        ?: "Perfil de {$author->name} en {$siteTitle}.",
                    'image' => $author->avatar_url,
                    'type' => 'profile',
                ];
            } else {
                $status = 404;
            }
        } elseif (preg_match('#^categoria/([^/]+)(?:/([^/]+))?$#', $path, $m)) {
            $category = Category::whereNull('parent_id')->where('slug', $m[1])->first();
            $sub = isset($m[2]) ? $category?->children()->where('slug', $m[2])->first() : null;
            if ($category && (! isset($m[2]) || $sub)) {
                $name = $sub ? "{$sub->name} · {$category->name}" : $category->name;
                $meta['title'] = $name . ' | ' . $siteTitle;
                $meta['description'] = "Detalles constructivos de {$name}.";
                $meta['image'] = $category->image_url;
            } else {
                $status = 404;
            }
        }

        $image = $meta['image'] ? $this->absoluteUrl($meta['image']) : null;
        $tags = [
            '<meta name="description" content="' . e($meta['description']) . '">',
            '<meta property="og:site_name" content="' . e($siteTitle) . '">',
            '<meta property="og:locale" content="es_AR">',
            '<meta property="og:type" content="' . $meta['type'] . '">',
            '<meta property="og:title" content="' . e($meta['title']) . '">',
            '<meta property="og:description" content="' . e($meta['description']) . '">',
            '<meta property="og:url" content="' . e($request->url()) . '">',
            '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">',
        ];
        if ($image) {
            $tags[] = '<meta property="og:image" content="' . e($image) . '">';
        }
        if ($meta['keywords']) {
            $tags[] = '<meta name="keywords" content="' . e(implode(', ', $meta['keywords'])) . '">';
            foreach ($meta['keywords'] as $keyword) {
                $tags[] = '<meta property="article:tag" content="' . e($keyword) . '">';
            }
        }

        // Lo que la portada necesita para dibujarse ya viaja dentro del HTML: se ahorran 4 pedidos y la espera
        $boot = [
            'settings' => $settings,
            'categories' => SiteController::categoriesData(),
            'plans' => SiteController::plansData(),
            'banners' => SiteController::bannersData(),
        ];
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $tags[] = '<script>window.__BOOT__=' . json_encode($boot, $flags) . ';</script>';

        $html = file_get_contents($index);
        $html = preg_replace_callback('#<title>.*?</title>#s', fn () => '<title>' . e($meta['title']) . '</title>', $html, 1);
        $html = str_replace('</head>', implode("\n", $tags) . "\n</head>", $html);

        return response($html, $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache');
    }

    private function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return Str::startsWith($url, '//') ? 'https:' . $url : url($url);
    }
}
