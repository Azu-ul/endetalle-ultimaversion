<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SiteController;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edición de los 3 planes (free, estudiante, pro). No se crean ni borran:
 * el acceso a detalles y descargas depende de su nivel.
 * Los precios son mensuales; los períodos (3 meses, anual) se calculan solos.
 */
class PlanController extends Controller
{
    public function update(Request $request, int $id): JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['nullable', 'string', 'max:200'],
            'price_ars' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'price_usd' => ['required', 'numeric', 'min:0', 'max:99999'],
            'is_recommended' => ['boolean'],
        ], [
            'name.required' => 'El plan necesita un nombre.',
            'price_ars.required' => 'Ingresá el precio en pesos.',
            'price_usd.required' => 'Ingresá el precio en dólares.',
            'price_ars.min' => 'El precio no puede ser negativo.',
            'price_usd.min' => 'El precio no puede ser negativo.',
        ]);

        $isFree = $plan->level === 0;

        DB::transaction(function () use ($plan, $data, $isFree) {
            // Un solo plan "recomendado"
            if (! empty($data['is_recommended'])) {
                Plan::whereKeyNot($plan->id)->update(['is_recommended' => false]);
            }

            $plan->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'features' => collect($data['features'] ?? [])->filter(fn ($f) => is_string($f) && trim($f) !== '')->map(fn ($f) => trim($f))->values()->all(),
                // El plan Free no se cobra
                'price_ars' => $isFree ? 0 : $data['price_ars'],
                'price_usd' => $isFree ? 0 : $data['price_usd'],
                'price' => $isFree ? 0 : $data['price_usd'], // columna vieja, en dólares
                'is_recommended' => (bool) ($data['is_recommended'] ?? false),
            ]);
        });

        // Mismo formato que la API pública (incluye los períodos con descuento)
        return (new SiteController())->plans();
    }

    /** Descuentos (%) de los pagos por 3 meses y anual sobre el precio mensual. */
    public function discounts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quarterly' => ['required', 'integer', 'min:0', 'max:90'],
            'yearly' => ['required', 'integer', 'min:0', 'max:90'],
        ], [
            'quarterly.required' => 'Ingresá el descuento de 3 meses.',
            'yearly.required' => 'Ingresá el descuento anual.',
            '*.integer' => 'El descuento tiene que ser un número entero.',
            '*.min' => 'El descuento no puede ser negativo.',
            '*.max' => 'El descuento no puede pasar de 90%.',
        ]);

        foreach (Plan::DISCOUNT_SETTINGS as $period => $key) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => (string) $data[$period], 'updated_at' => now()]);
        }

        return (new SiteController())->plans();
    }
}
