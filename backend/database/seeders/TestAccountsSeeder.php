<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cuentas para probar (solo en local; se puede volver a correr sin duplicar):
 * - 5 colaboradores colaborador1..5@endetalle.test, contraseña "password".
 * - El superadmin con plan Pro (suscripción manual sin vencimiento).
 */
class TestAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command->warn('TestAccountsSeeder solo corre en entorno local.');
            return;
        }

        $colaboradores = [
            ['Lucía Fernández', 'Arquitecta', 'Estudio Sur'],
            ['Martín Gómez', 'Ingeniero Civil', 'MG Estructuras'],
            ['Valentina Ruiz', 'Arquitecta paisajista', null],
            ['Tomás Herrera', 'Maestro Mayor de Obras', 'Herrera Construcciones'],
            ['Camila Sosa', 'Estudiante avanzada de Arquitectura', 'FADU UBA'],
        ];

        foreach ($colaboradores as $i => [$name, $profession, $company]) {
            $user = User::firstOrNew(['email' => 'colaborador' . ($i + 1) . '@endetalle.test']);
            $user->forceFill([
                'name' => $name,
                'profession' => $profession,
                'company' => $company,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ])->save();
            $user->syncRoles(['colaborador']);
        }

        $admin = User::role('superadmin')->first();
        $proId = DB::table('plans')->where('slug', 'pro')->value('id');
        if ($admin && $proId && ! $admin->currentSubscription()) {
            DB::table('subscriptions')->insert([
                'user_id' => $admin->id,
                'plan_id' => $proId,
                'status' => 'active',
                'provider' => 'manual',
                'starts_at' => now(),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
