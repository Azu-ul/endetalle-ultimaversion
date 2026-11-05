<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Detail;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Comentarios: editar, marca de "editado", fecha de publicación y listado de moderación del admin. */
class CommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $ana;
    private User $beto;
    private Detail $detail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PlanSeeder::class]);
        $this->admin = $this->makeUser('Admin', 'admin@test.com', 'superadmin');
        $this->ana = $this->makeUser('Ana', 'ana@test.com', 'usuario');
        $this->beto = $this->makeUser('Beto', 'beto@test.com', 'usuario');
        $cat = Category::create(['name' => 'Cat', 'slug' => 'cat']);
        $this->detail = Detail::create([
            'title' => 'Detalle Uno', 'slug' => 'detalle-uno', 'description' => 'x',
            'category_id' => $cat->id, 'author_id' => $this->admin->id, 'status' => 'published', 'published_at' => now(),
        ]);
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        $user->assignRole($role);

        return $user;
    }

    private function comment(User $user, string $body, ?int $parent = null): Comment
    {
        return Comment::create(['detail_id' => $this->detail->id, 'user_id' => $user->id, 'parent_id' => $parent, 'body' => $body]);
    }

    public function test_el_autor_edita_su_comentario_y_queda_marcado_como_editado(): void
    {
        $c = $this->comment($this->ana, 'Original');
        // Pasaron minutos desde que lo publicó
        $c->forceFill(['created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)])->saveQuietly();
        Sanctum::actingAs($this->ana);

        $this->putJson("/api/comments/{$c->id}", ['body' => '  Corregido  '])
            ->assertOk()
            ->assertJsonPath('body', 'Corregido')
            ->assertJsonPath('edited', true)
            ->assertJsonPath('can_edit', true);
        $this->assertSame('Corregido', $c->fresh()->body);
    }

    public function test_un_comentario_recien_publicado_no_figura_como_editado(): void
    {
        $this->comment($this->ana, 'Hola');
        Sanctum::actingAs($this->admin);

        $res = $this->getJson("/api/details/{$this->detail->id}/comments")->assertOk();

        $res->assertJsonPath('0.edited', false);
        $this->assertNotEmpty($res->json('0.created_at'));
    }

    public function test_otro_usuario_no_puede_editar_pero_el_superadmin_si(): void
    {
        $c = $this->comment($this->ana, 'De Ana');

        Sanctum::actingAs($this->beto);
        $this->putJson("/api/comments/{$c->id}", ['body' => 'Hackeado'])->assertForbidden();
        $this->assertSame('De Ana', $c->fresh()->body);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/comments/{$c->id}", ['body' => 'Moderado'])->assertOk();
        $this->assertSame('Moderado', $c->fresh()->body);
    }

    public function test_editar_valida_el_texto(): void
    {
        $c = $this->comment($this->ana, 'Hola');
        Sanctum::actingAs($this->ana);

        $this->putJson("/api/comments/{$c->id}", ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->putJson("/api/comments/{$c->id}", ['body' => str_repeat('a', 2001)])->assertStatus(422);
    }

    public function test_el_listado_de_admin_trae_usuario_detalle_y_fecha_y_filtra(): void
    {
        $root = $this->comment($this->ana, 'Muy buen detalle');
        $this->comment($this->beto, 'Gracias por la respuesta', $root->id);
        Sanctum::actingAs($this->admin);

        $all = $this->getJson('/api/admin/comments')->assertOk();
        $this->assertSame(2, $all->json('total'));
        $first = $all->json('comments.0'); // el más nuevo primero
        $this->assertSame('Beto', $first['user']['name']);
        $this->assertSame('beto@test.com', $first['user']['email']);
        $this->assertSame('Detalle Uno', $first['detail']['title']);
        $this->assertTrue($first['is_reply']);
        $this->assertSame('Ana', $first['reply_to']);
        $this->assertNotEmpty($first['created_at']);

        $this->assertSame(1, $this->getJson('/api/admin/comments?q=Muy+buen')->json('total'));
        $this->assertSame(1, $this->getJson('/api/admin/comments?q=ana@test')->json('total'));
        $this->assertSame(2, $this->getJson('/api/admin/comments?q=Detalle+Uno')->json('total'));
        $this->assertSame(0, $this->getJson('/api/admin/comments?q=zzz')->json('total'));
    }

    public function test_el_listado_de_comentarios_es_solo_del_superadmin(): void
    {
        Sanctum::actingAs($this->ana);

        $this->getJson('/api/admin/comments')->assertForbidden();
    }

    public function test_el_superadmin_elimina_cualquier_comentario_con_sus_respuestas(): void
    {
        $root = $this->comment($this->ana, 'Raíz');
        $this->comment($this->beto, 'Respuesta', $root->id);
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/comments/{$root->id}")->assertOk();

        $this->assertSame(0, Comment::count());
    }
}
