<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos mínimos para publicar el sitio: roles, administrador (desde el .env), planes, categorías y ajustes.
 * NO carga datos de demostración ni cuentas de prueba (eso es solo para desarrollo, ver DatabaseSeeder).
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Se puede correr más de una vez sin duplicar nada. Los precios de los planes son de ejemplo: el administrador
 * los cambia desde el panel (Planes y Precios) antes de abrir el sitio.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            PlanSeeder::class,
            CategorySeeder::class,
            SettingSeeder::class,
        ]);
    }
}
