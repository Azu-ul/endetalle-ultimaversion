<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Crea el administrador con los datos del .env (ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD; ver config/admin.php).
 *
 * - El superadmin es único: si ya hay uno con otro email, no se crea un segundo.
 * - Si el usuario ya existe no se le cambia la contraseña (se puede volver a correr sin riesgo).
 * - En producción exige una contraseña de al menos 12 caracteres.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('admin.email'));
        $password = (string) config('admin.password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->command?->error('Definí ADMIN_EMAIL (un email válido) y ADMIN_PASSWORD en el .env y volvé a correr el seeder.');

            return;
        }
        $min = (int) config('admin.min_password_length');
        if (app()->isProduction() && mb_strlen($password) < $min) {
            $this->command?->error("ADMIN_PASSWORD tiene que tener al menos {$min} caracteres en producción.");

            return;
        }

        $role = Role::findOrCreate('superadmin', 'web');
        $existing = User::role('superadmin')->where('email', '!=', $email)->first();
        if ($existing) {
            $this->command?->error("Ya existe un superadmin ({$existing->email}). No se crea otro.");

            return;
        }

        $admin = User::firstOrNew(['email' => $email]);
        $isNew = ! $admin->exists;
        if ($isNew) {
            $admin->forceFill(['name' => config('admin.name') ?: 'Administrador', 'password' => $password]); // el modelo la guarda hasheada
        }
        // Es el dueño del sitio: cuenta verificada y activa
        $admin->forceFill(['email_verified_at' => $admin->email_verified_at ?? now(), 'status' => 'active'])->save();
        $admin->assignRole($role);

        $this->command?->info($isNew ? "Administrador creado: {$email}" : "El administrador {$email} ya existía: no se cambió su contraseña.");
    }
}
