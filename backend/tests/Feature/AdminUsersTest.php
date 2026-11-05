<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordEs;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Alta de clientes desde el panel admin y números del dashboard. */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PlanSeeder::class]);
        Notification::fake();

        $this->admin = $this->makeUser('Admin', 'admin@test.com', 'superadmin');
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        $user->assignRole($role);

        return $user;
    }

    public function test_el_admin_crea_un_cliente_con_contrasena_y_plan_manual(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/admin/users', [
            'name' => 'Laura Gómez',
            'email' => 'laura@test.com',
            'password' => 'claveSegura1',
            'plan' => 'pro',
            'months' => 3,
        ])->assertCreated();

        $res->assertJsonPath('email', 'laura@test.com')
            ->assertJsonPath('plan.slug', 'pro')
            ->assertJsonPath('subscription.provider', 'manual')
            ->assertJsonPath('invite_sent', null);

        $user = User::where('email', 'laura@test.com')->first();
        $this->assertTrue($user->hasRole('usuario'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('claveSegura1', $user->password));
        $this->assertSame('pro', $user->currentSubscription()->plan->slug);
        $this->assertEquals(now()->addMonths(3)->toDateString(), $user->currentSubscription()->ends_at->toDateString());
        Notification::assertNothingSent();
    }

    public function test_sin_contrasena_la_persona_recibe_el_mail_para_elegir_la_suya(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users', ['name' => 'Mario Paz', 'email' => 'mario@test.com', 'plan' => 'estudiante'])
            ->assertCreated()
            ->assertJsonPath('invite_sent', true)
            ->assertJsonPath('plan.slug', 'estudiante');

        $user = User::where('email', 'mario@test.com')->first();
        Notification::assertSentTo($user, ResetPasswordEs::class);
        // La contraseña al azar no la sabe nadie: no se puede entrar hasta elegir una
        $this->assertFalse(Hash::check('password12', $user->password));
    }

    public function test_un_cliente_free_no_lleva_suscripcion_y_sin_vencimiento_si_no_se_pide(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users', ['name' => 'Sol', 'email' => 'sol@test.com', 'password' => 'claveSegura1', 'plan' => 'free'])
            ->assertCreated()->assertJsonPath('plan.slug', 'free');
        $this->assertSame(0, User::where('email', 'sol@test.com')->first()->subscriptions()->count());

        $this->postJson('/api/admin/users', ['name' => 'Ana', 'email' => 'ana2@test.com', 'password' => 'claveSegura1', 'plan' => 'pro'])
            ->assertCreated()->assertJsonPath('subscription.ends_at', null);
    }

    public function test_no_se_puede_repetir_un_email_ni_mandar_datos_invalidos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users', ['name' => 'Otra', 'email' => 'admin@test.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/users', ['name' => '', 'email' => 'no-es-un-mail'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@test.com', 'plan' => 'superadmin'])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@test.com', 'password' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_solo_el_superadmin_puede_crear_clientes(): void
    {
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@test.com'])->assertStatus(401);

        Sanctum::actingAs($this->makeUser('Cliente', 'cliente@test.com', 'usuario'));
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@test.com'])->assertStatus(403);
        $this->assertSame(0, User::where('email', 'x@test.com')->count());
    }

    public function test_el_dashboard_cuenta_solo_clientes_y_no_al_staff_ni_al_admin(): void
    {
        $this->makeUser('Cliente', 'cliente@test.com', 'usuario');
        $this->makeUser('Autor', 'autor@test.com', 'colaborador');
        $suspendido = $this->makeUser('Suspendido', 'susp@test.com', 'usuario');
        $suspendido->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/stats')->assertOk()
            ->assertJsonPath('active_users', 1)
            ->assertJsonPath('new_users_7d', 2);
    }
}
