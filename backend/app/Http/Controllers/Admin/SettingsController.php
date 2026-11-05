<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SiteController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Configuración del sitio (tabla settings, clave => valor). */
class SettingsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_title' => ['required', 'string', 'max:100'],
            'site_description' => ['nullable', 'string', 'max:300'],
            'home_title' => ['nullable', 'string', 'max:255'],
            'home_subtitle' => ['nullable', 'string', 'max:500'],
            'header_image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'paywall_title' => ['nullable', 'string', 'max:255'],
            'paywall_description' => ['nullable', 'string', 'max:1000'],
            'paywall_button_text' => ['nullable', 'string', 'max:100'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:255'],
            'terms_content' => ['nullable', 'string', 'max:50000'],
            'privacy_content' => ['nullable', 'string', 'max:50000'],
            'maintenance_mode' => ['boolean'],
            'welcome_slide' => ['boolean'],
            'social_links' => ['nullable', 'array'],
            'social_links.twitter' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.instagram' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.linkedin' => ['nullable', 'url:http,https', 'max:2048'],
            'social_links.github' => ['nullable', 'url:http,https', 'max:2048'],
        ], [
            'site_title.required' => 'El sitio necesita un título.',
            'header_image_url.url' => 'La imagen de cabecera tiene que ser un link (https://...).',
            'social_links.*.url' => 'Los links de redes tienen que empezar con https://',
        ]);

        $now = now();
        foreach ($data as $key => $value) {
            $stored = match ($key) {
                'social_links' => json_encode(array_filter($value ?? []), JSON_UNESCAPED_SLASHES),
                'maintenance_mode', 'welcome_slide' => $value ? 'true' : 'false',
                default => $value,
            };
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $stored, 'updated_at' => $now]);
        }

        return (new SiteController())->settings();
    }
}
