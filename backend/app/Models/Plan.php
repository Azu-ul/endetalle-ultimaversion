<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Plan extends Model
{
    /**
     * Períodos de pago: meses que cubre y descuento (%) por defecto sobre el precio mensual.
     * Los descuentos reales los define el administrador desde el panel (Planes y Precios) y se guardan
     * en la tabla settings: ver Plan::periods().
     */
    public const PERIODS = [
        ['id' => 'monthly', 'months' => 1, 'discount' => 0],
        ['id' => 'quarterly', 'months' => 3, 'discount' => 15],
        ['id' => 'yearly', 'months' => 12, 'discount' => 30],
    ];

    protected $guarded = [];

    /** Claves de la tabla settings donde el admin guarda los descuentos. */
    public const DISCOUNT_SETTINGS = ['quarterly' => 'discount_quarterly', 'yearly' => 'discount_yearly'];

    /** Los mismos períodos de PERIODS, con los descuentos que el admin definió (si no definió, los de PERIODS). */
    public static function periods(): array
    {
        $stored = DB::table('settings')->whereIn('key', array_values(self::DISCOUNT_SETTINGS))->pluck('value', 'key');

        return array_map(function (array $p) use ($stored) {
            $key = self::DISCOUNT_SETTINGS[$p['id']] ?? null;
            $value = $key ? ($stored[$key] ?? null) : null;
            if ($value !== null && is_numeric($value) && $value >= 0 && $value <= 90) {
                $p['discount'] = (int) $value;
            }

            return $p;
        }, self::PERIODS);
    }

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price_ars' => 'float',
            'price_usd' => 'float',
            'allows_dwg' => 'boolean',
            'allows_skp' => 'boolean',
            'allows_ebooks' => 'boolean',
            'is_recommended' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Total a cobrar por cada período, en pesos y en dólares.
     * Es la misma cuenta que va a usar el cobro, así que lo que se ve es lo que se paga.
     *
     * @return array<int, array<string, mixed>>
     */
    public function periodPrices(): array
    {
        if ($this->price_ars <= 0 && $this->price_usd <= 0) {
            return []; // plan gratuito: no tiene períodos
        }

        return array_map(function (array $p) {
            $factor = $p['months'] * (1 - $p['discount'] / 100);
            $totalArs = round($this->price_ars * $factor);
            $totalUsd = round($this->price_usd * $factor, 2);

            return [
                'id' => $p['id'],
                'months' => $p['months'],
                'discount_percent' => $p['discount'],
                'total_ars' => $totalArs,
                'total_usd' => $totalUsd,
                'per_month_ars' => round($totalArs / $p['months']),
                'per_month_usd' => round($totalUsd / $p['months'], 2),
            ];
        }, self::periods());
    }
}
