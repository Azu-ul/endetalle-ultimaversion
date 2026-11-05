<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\BannerController;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(self::settingsData());
    }

    public function categories(): JsonResponse
    {
        return response()->json(self::categoriesData());
    }

    public function plans(): JsonResponse
    {
        return response()->json(self::plansData());
    }

    public function banners(): JsonResponse
    {
        return response()->json(self::bannersData());
    }

    /** $lite = sin los textos legales (muy largos): es lo que viaja dentro del HTML de la primera carga. */
    public static function settingsData(bool $lite = false): array
    {
        $settings = DB::table('settings')->pluck('value', 'key')->all();

        if ($lite) {
            unset($settings['terms_content'], $settings['privacy_content']);
        } else {
            // Si el admin no escribió los suyos (Configuración), se muestran los textos que trae el sistema
            foreach (['terms_content' => 'terms', 'privacy_content' => 'privacy'] as $key => $file) {
                if (trim((string) ($settings[$key] ?? '')) === '') {
                    $settings[$key] = self::defaultLegal($file);
                }
            }
        }
        $settings['social_links'] = json_decode($settings['social_links'] ?? '{}', true) ?: (object) [];
        $settings['maintenance_mode'] = filter_var($settings['maintenance_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // La presentación inicial del carrusel se muestra salvo que el admin la apague
        $settings['welcome_slide'] = filter_var($settings['welcome_slide'] ?? true, FILTER_VALIDATE_BOOLEAN);

        return $settings;
    }

    /** Texto legal por defecto (resources/legal/*.txt) con los datos del sitio ya puestos. */
    public static function defaultLegal(string $name): string
    {
        $path = resource_path("legal/{$name}.txt");
        if (! is_file($path)) {
            return '';
        }

        return strtr((string) file_get_contents($path), [
            '{{SITE_URL}}' => rtrim((string) config('app.url'), '/'),
            '{{CONTACT_EMAIL}}' => (string) (config('mail.contact_to') ?: 'el formulario de Contacto del sitio'),
            '{{REFUND_DAYS}}' => (string) config('billing.refund_window_days'),
            '{{UPDATED}}' => '5 de noviembre de 2026',
        ]);
    }

    public static function categoriesData(): array
    {
        return Category::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'image_url' => $c->image_url,
                'subcategories' => $c->children->map(fn (Category $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                ])->values(),
            ])
            ->all();
    }

    public static function plansData(): array
    {
        return Plan::where('is_active', true)
            ->orderBy('level')
            ->get()
            ->map(fn (Plan $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'level' => $p->level,
                'price' => (float) $p->price,
                'price_ars' => $p->price_ars,
                'price_usd' => $p->price_usd,
                'periods' => $p->periodPrices(),
                'currency' => $p->currency,
                'interval' => $p->interval,
                'description' => $p->description,
                'features' => $p->features ?? [],
                'detail_limit' => $p->detail_limit,
                'allows_dwg' => $p->allows_dwg,
                'allows_skp' => $p->allows_skp,
                'allows_ebooks' => $p->allows_ebooks,
                'is_recommended' => $p->is_recommended,
                'highlight_color' => $p->highlight_color,
            ])
            ->all();
    }

    /** Avisos del carrusel que se ven ahora (activos y dentro de su fecha). */
    public static function bannersData(): array
    {
        try {
            return Banner::visible()->get()->map(fn (Banner $b) => collect(BannerController::serialize($b))->only([
                'id', 'badge', 'title', 'subtitle', 'button_text', 'button_url', 'image_url',
            ])->all())->all();
        } catch (\Illuminate\Database\QueryException $e) {
            // Entre subir los archivos y correr la migración nueva el sitio sigue abriendo (sin carrusel)
            return [];
        }
    }
}
