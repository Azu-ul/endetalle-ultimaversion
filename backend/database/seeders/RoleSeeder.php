<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Los tres roles del sitio. El administrador lo crea AdminUserSeeder (con los datos del .env). */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['superadmin', 'colaborador', 'usuario'] as $name) {
            Role::findOrCreate($name, 'web');
        }
    }
}
