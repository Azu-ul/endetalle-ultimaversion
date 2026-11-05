<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Detail;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Descuentos configurables, alta de categorías y top 5 de detalles del dashboard. */
class AdminExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PlanSeeder::class]);
        $this->admin = $this->makeUser('Admin', 'admin@test.com', 'superadmin');
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        $user->assignRole($role);

        return $user;
    }

    // ---------- Descuentos ----------

    public function test_sin_configurar_los_descuentos_son_15_y_30(): void
    {
        $periods = collect(Plan::where('slug', 'pro')->first()->periodPrices())->keyBy('id');

        $this->assertSame(0, $periods['monthly']['discount_percent']);
        $this->assertSame(15, $periods['quarterly']['discount_percent']);
        $this->assertSame(30, $periods['yearly']['discount_percent']);
    }

    public function test_el_admin_cambia_los_descuentos_y_los_precios_publicos_los_usan(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->putJson('/api/admin/billing/discounts', ['quarterly' => 10, 'yearly' => 20])->assertOk();

        $pro = collect($res->json())->firstWhere('slug', 'pro');
        $price = $pro['price_ars'];
        $periods = collect($pro['periods'])->keyBy('id');
        $this->assertSame(10, $periods['quarterly']['discount_percent']);
        $this->assertSame(20, $periods['yearly']['discount_percent']);
        $this->assertEquals(round($price * 3 * 0.90), $periods['quarterly']['total_ars']);
        $this->assertEquals(round($price * 12 * 0.80), $periods['yearly']['total_ars']);

        // La API pública (sin sesión) muestra lo mismo
        $this->app['auth']->forgetGuards();
        $public = collect($this->getJson('/api/plans')->json())->firstWhere('slug', 'pro');
        $this->assertSame(10, collect($public['periods'])->firstWhere('id', 'quarterly')['discount_percent']);
    }

    public function test_el_cobro_usa_el_descuento_que_define_el_admin(): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'discount_quarterly'], ['value' => '5']);
        $plan = Plan::where('slug', 'pro')->first();

        $quarterly = collect($plan->periodPrices())->firstWhere('id', 'quarterly');
        $this->assertSame(5, $quarterly['discount_percent']);
        $this->assertEquals(round($plan->price_ars * 3 * 0.95), $quarterly['total_ars']);
    }

    public function test_los_descuentos_se_validan(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/admin/billing/discounts', ['quarterly' => 95, 'yearly' => 20])->assertStatus(422)->assertJsonValidationErrors('quarterly');
        $this->putJson('/api/admin/billing/discounts', ['quarterly' => -1, 'yearly' => 20])->assertStatus(422);
        $this->putJson('/api/admin/billing/discounts', ['quarterly' => 10])->assertStatus(422)->assertJsonValidationErrors('yearly');
        $this->putJson('/api/admin/billing/discounts', ['quarterly' => 'abc', 'yearly' => 20])->assertStatus(422);
        $this->assertSame(0, DB::table('settings')->where('key', 'discount_quarterly')->count());
    }

    public function test_solo_el_superadmin_cambia_descuentos_y_categorias(): void
    {
        $this->putJson('/api/admin/billing/discounts', ['quarterly' => 1, 'yearly' => 2])->assertStatus(401);

        Sanctum::actingAs($this->makeUser('Cliente', 'c@test.com', 'usuario'));
        $this->putJson('/api/admin/billing/discounts', ['quarterly' => 1, 'yearly' => 2])->assertStatus(403);
        $this->postJson('/api/admin/categories', ['name' => 'Nueva'])->assertStatus(403);
    }

    // ---------- Categorías ----------

    public function test_el_admin_crea_una_categoria_y_una_subcategoria(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/admin/categories', ['name' => 'Cerramientos Especiales'])->assertCreated();
        $cat = collect($res->json())->firstWhere('slug', 'cerramientos-especiales');
        $this->assertNotNull($cat);
        $this->assertSame([], $cat['subcategories']);

        $res = $this->postJson('/api/admin/categories', ['name' => 'Portones', 'parent_id' => $cat['id']])->assertCreated();
        $cat = collect($res->json())->firstWhere('id', $cat['id']);
        $this->assertSame(['Portones'], array_column($cat['subcategories'], 'name'));
        $this->assertSame('portones', $cat['subcategories'][0]['slug']);
    }

    public function test_no_se_repiten_nombres_en_el_mismo_nivel_pero_si_entre_categorias(): void
    {
        Sanctum::actingAs($this->admin);
        $a = Category::create(['name' => 'Aberturas', 'slug' => 'aberturas', 'sort_order' => 1]);
        $b = Category::create(['name' => 'Pisos', 'slug' => 'pisos', 'sort_order' => 2]);

        $this->postJson('/api/admin/categories', ['name' => 'aberturas'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/categories', ['name' => 'Vidrio', 'parent_id' => $a->id])->assertCreated();
        $this->postJson('/api/admin/categories', ['name' => 'Vidrio', 'parent_id' => $a->id])->assertStatus(422);
        // La misma subcategoría en otra categoría sí se puede
        $this->postJson('/api/admin/categories', ['name' => 'Vidrio', 'parent_id' => $b->id])->assertCreated();
    }

    public function test_una_subcategoria_no_puede_tener_subcategorias_y_los_datos_se_validan(): void
    {
        Sanctum::actingAs($this->admin);
        $cat = Category::create(['name' => 'Techos', 'slug' => 'techos', 'sort_order' => 1]);
        $sub = Category::create(['name' => 'Chapa', 'slug' => 'chapa', 'parent_id' => $cat->id, 'sort_order' => 1]);

        $this->postJson('/api/admin/categories', ['name' => 'Anidada', 'parent_id' => $sub->id])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/admin/categories', ['name' => 'Huérfana', 'parent_id' => 9999])->assertStatus(422);
        $this->postJson('/api/admin/categories', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/categories', ['name' => '!!!'])->assertStatus(422);
        $this->postJson('/api/admin/categories', ['name' => 'Con imagen', 'image_url' => 'no-es-un-link'])->assertStatus(422);
        $this->postJson('/api/admin/categories', ['name' => 'Con imagen', 'image_url' => 'https://example.com/a.jpg'])->assertCreated();
        $this->assertSame('https://example.com/a.jpg', Category::where('slug', 'con-imagen')->value('image_url'));
    }

    // ---------- Top 5 del dashboard ----------

    public function test_el_dashboard_lista_los_5_detalles_mas_abiertos(): void
    {
        $cat = Category::create(['name' => 'Muros', 'slug' => 'muros', 'sort_order' => 1]);
        $author = $this->makeUser('Autor', 'autor@test.com', 'colaborador');
        $viewers = collect(range(1, 7))->map(fn ($i) => $this->makeUser("V$i", "v$i@test.com", 'usuario'));

        // Detalle N lo abren N personas (N de 1 a 7): los más vistos son el 7, 6, 5, 4 y 3
        $details = collect(range(1, 7))->map(function ($n) use ($cat, $author, $viewers) {
            $d = Detail::create([
                'title' => "Detalle $n", 'slug' => "detalle-$n", 'description' => 'x',
                'category_id' => $cat->id, 'author_id' => $author->id, 'status' => 'published',
            ]);
            foreach ($viewers->take($n) as $v) {
                DB::table('detail_views')->insert(['user_id' => $v->id, 'detail_id' => $d->id, 'first_viewed_at' => now()]);
            }

            return $d;
        });
        // Un borrador muy visto no entra en el top
        $draft = Detail::create([
            'title' => 'Borrador', 'slug' => 'borrador', 'description' => 'x',
            'category_id' => $cat->id, 'author_id' => $author->id, 'status' => 'draft',
        ]);
        foreach ($viewers as $v) {
            DB::table('detail_views')->insert(['user_id' => $v->id, 'detail_id' => $draft->id, 'first_viewed_at' => now()]);
        }

        Sanctum::actingAs($this->admin);
        $top = $this->getJson('/api/admin/stats')->assertOk()->json('top_details');

        $this->assertSame(['Detalle 7', 'Detalle 6', 'Detalle 5', 'Detalle 4', 'Detalle 3'], array_column($top, 'title'));
        $this->assertSame([7, 6, 5, 4, 3], array_column($top, 'views'));
    }

    public function test_sin_visitas_el_top_viene_vacio(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/stats')->assertOk()->assertJsonPath('top_details', []);
    }

    // ---------- Staff ----------

    public function test_el_staff_lista_al_administrador_primero_y_a_los_colaboradores(): void
    {
        $colab = $this->makeUser('Colaboradora', 'colab@test.com', 'colaborador');
        $this->makeUser('Cliente', 'cliente@test.com', 'usuario');
        Sanctum::actingAs($this->admin);

        $res = $this->getJson('/api/admin/users?role=staff')->assertOk();

        $this->assertSame(['admin@test.com', 'colab@test.com'], array_column($res->json(), 'email'));
        $this->assertContains('superadmin', $res->json('0.roles'));
        $this->assertContains('colaborador', $res->json('1.roles'));
        $this->assertSame($colab->id, $res->json('1.id'));
    }

    public function test_se_crea_un_colaborador_desde_staff(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/admin/users', [
            'name' => 'Nuevo Colaborador', 'email' => 'nuevo@test.com', 'password' => 'claveSegura1', 'role' => 'colaborador',
        ])->assertCreated();

        $this->assertContains('colaborador', $res->json('roles'));
        $this->assertTrue(User::where('email', 'nuevo@test.com')->first()->hasRole('colaborador'));
    }

    public function test_no_se_puede_crear_otro_superadmin(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@test.com', 'role' => 'superadmin'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'x@test.com']);
    }

    public function test_un_cliente_se_convierte_en_colaborador_y_vuelve_a_cliente(): void
    {
        $cliente = $this->makeUser('Cliente', 'cliente@test.com', 'usuario');
        Sanctum::actingAs($this->admin);
        $body = ['name' => 'Cliente', 'profession' => null, 'company' => null, 'status' => 'active'];

        $this->putJson("/api/admin/users/{$cliente->id}", $body + ['role' => 'colaborador'])->assertOk();
        $this->assertTrue($cliente->fresh()->hasRole('colaborador'));
        $this->assertContains('cliente@test.com', array_column($this->getJson('/api/admin/users?role=staff')->json(), 'email'));

        $this->putJson("/api/admin/users/{$cliente->id}", $body + ['role' => 'usuario'])->assertOk();
        $this->assertTrue($cliente->fresh()->hasRole('usuario'));
    }

    public function test_solo_el_superadmin_ve_el_staff(): void
    {
        Sanctum::actingAs($this->makeUser('Colab', 'c@test.com', 'colaborador'));

        $this->getJson('/api/admin/users?role=staff')->assertForbidden();
    }
}
