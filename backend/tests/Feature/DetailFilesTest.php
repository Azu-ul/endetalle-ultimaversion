<?php

namespace Tests\Feature;

use App\Models\DetailFile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DetailFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $author;
    private int $detailId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Storage::fake('local');
        Storage::fake('public');

        $this->author = $this->makeUser('autor@test.com');
        $this->author->assignRole(Role::findOrCreate('colaborador', 'web'));
        $this->detailId = $this->makeDetail($this->author->id, 'draft');
    }

    private function makeUser(string $email): User
    {
        $user = User::create(['name' => 'Usuario', 'email' => $email, 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeDetail(?int $authorId, string $status = 'published', ?string $requiredPlan = null): int
    {
        $categoryId = DB::table('categories')->value('id') ?? DB::table('categories')->insertGetId(['name' => 'Fundaciones', 'slug' => 'fundaciones', 'created_at' => now(), 'updated_at' => now()]);
        $slug = 'detalle-' . uniqid();

        return DB::table('details')->insertGetId([
            'title' => 'Detalle ' . $slug, 'slug' => $slug, 'description' => 'Descripción', 'category_id' => $categoryId, 'author_id' => $authorId,
            'status' => $status, 'required_plan_id' => $requiredPlan ? Plan::where('slug', $requiredPlan)->value('id') : null,
            'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dwg(string $name = 'plano.dwg', string $content = "AC1032\0\0\0\0\0\0DATOS DEL PLANO"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function skp(string $name = 'modelo.skp'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xFE\xFF\x0ES\0k\0e\0t\0c\0h\0U\0p\0 MODELO");
    }

    private function upload(string $type, UploadedFile $file, ?int $detailId = null)
    {
        Sanctum::actingAs($this->author);

        return $this->post("/api/studio/details/" . ($detailId ?? $this->detailId) . "/files/{$type}", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function subscribe(User $user, string $plan, string $endsIn = '+30 days'): void
    {
        Subscription::create(['user_id' => $user->id, 'plan_id' => Plan::where('slug', $plan)->value('id'), 'status' => 'active', 'provider' => 'manual',
            'provider_subscription_id' => uniqid('sub-'), 'starts_at' => now()->subDay(), 'ends_at' => now()->modify($endsIn)]);
    }

    /** Detalle publicado con sus archivos ya guardados en el disco privado. */
    private function publishedWithFiles(?string $requiredPlan = null, array $types = ['dwg', 'skp']): int
    {
        $id = $this->makeDetail(null, 'published', $requiredPlan);
        foreach ($types as $type) {
            $path = "detail-files/{$id}/archivo-{$type}";
            DetailFile::disk()->put($path, $type === 'dwg' ? 'AC1032-CONTENIDO-DWG' : 'SKP-CONTENIDO');
            DetailFile::create(['detail_id' => $id, 'type' => $type, 'path' => $path, 'original_name' => "plano-original.{$type}", 'size_bytes' => 20]);
        }

        return $id;
    }

    private function download(User $user, int $detailId, string $type)
    {
        Sanctum::actingAs($user);

        return $this->get("/api/details/{$detailId}/files/{$type}", ['Accept' => 'application/json']);
    }

    // ---------- Disco privado y configurable ----------

    public function test_los_archivos_se_guardan_en_el_disco_privado_y_nunca_en_public(): void
    {
        $this->upload('dwg', $this->dwg())->assertOk();
        $this->upload('skp', $this->skp())->assertOk();

        foreach (['dwg', 'skp'] as $type) {
            $file = DetailFile::where('detail_id', $this->detailId)->where('type', $type)->first();
            $this->assertStringStartsWith("detail-files/{$this->detailId}/", $file->path);
            Storage::disk('local')->assertExists($file->path);      // storage/app/private
            Storage::disk('public')->assertMissing($file->path);    // jamás en public
        }
        $this->assertSame('plano.dwg', DetailFile::where('type', 'dwg')->value('original_name'));
    }

    public function test_el_disco_se_cambia_solo_con_la_variable_details_disk(): void
    {
        // Un disco cualquiera (mañana, uno S3): el código no cambia
        config(['filesystems.disks.otro' => ['driver' => 'local', 'root' => storage_path('framework/testing/otro')], 'filesystems.details_disk' => 'otro']);
        Storage::fake('otro');

        $this->upload('dwg', $this->dwg())->assertOk();

        $file = DetailFile::first();
        Storage::disk('otro')->assertExists($file->path);
        Storage::disk('local')->assertMissing($file->path);

        // y la descarga lee de ese mismo disco
        $user = $this->makeUser('pro@test.com');
        $this->subscribe($user, 'pro');
        DB::table('details')->where('id', $this->detailId)->update(['status' => 'published']);
        $this->assertSame('AC1032', substr($this->download($user, $this->detailId, 'dwg')->assertOk()->streamedContent(), 0, 6));
    }

    public function test_sin_symlinks_el_hosting_los_bloquea(): void
    {
        $this->assertSame([], config('filesystems.links'));
        $this->assertSame(public_path('storage'), config('filesystems.disks.public.root')); // escribe directo en public/storage
        $this->assertFalse(config('filesystems.disks.local.serve'));                          // el disco privado no expone ninguna ruta
        $this->assertSame('local', config('filesystems.details_disk'));
    }

    public function test_reemplazar_o_quitar_un_archivo_lo_borra_del_disco(): void
    {
        $this->upload('dwg', $this->dwg())->assertOk();
        $first = DetailFile::first()->path;

        $this->upload('dwg', $this->dwg('nuevo.dwg'))->assertOk();
        $second = DetailFile::first()->path;
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        Sanctum::actingAs($this->author);
        $this->deleteJson("/api/studio/details/{$this->detailId}/files/dwg")->assertOk();
        Storage::disk('local')->assertMissing($second);
        $this->assertSame(0, DetailFile::count());
    }

    // ---------- Validación de la subida ----------

    public function test_se_valida_tipo_extension_y_contenido(): void
    {
        $this->upload('dwg', $this->dwg('plano.txt'))->assertStatus(422)->assertJsonValidationErrors('file');            // extensión
        $this->upload('skp', $this->dwg('plano.dwg'))->assertStatus(422)->assertJsonValidationErrors('file');            // un DWG como SKP
        $this->upload('dwg', $this->dwg('virus.dwg', "MZ\x90\0ejecutable"))->assertStatus(422)->assertJsonValidationErrors('file'); // .exe renombrado
        $this->upload('dwg', $this->dwg('vacio.dwg', ''))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload('skp', $this->dwg('virus.skp', "PK\x03\x04zip"))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload('skp', $this->dwg('script.skp', '#!/bin/sh'))->assertStatus(422);
        $this->assertSame(0, DetailFile::count());

        $this->upload('dwg', $this->dwg())->assertOk();
        $this->upload('skp', $this->skp())->assertOk();
        $this->assertSame(2, DetailFile::count());
    }

    public function test_se_valida_el_tamano_maximo_configurable(): void
    {
        $this->assertSame(15, config('filesystems.details_max_mb'));

        $big = UploadedFile::fake()->create('grande.dwg', 15 * 1024 + 1); // 1 KB de más
        $this->upload('dwg', $big)->assertStatus(422)->assertJsonPath('errors.file.0', 'El archivo puede pesar hasta 15 MB.');

        config(['filesystems.details_max_mb' => 1]);
        $this->upload('dwg', UploadedFile::fake()->createWithContent('dos.dwg', 'AC1032' . str_repeat('x', 2 * 1024 * 1024)))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'El archivo puede pesar hasta 1 MB.');

        config(['filesystems.details_max_mb' => 15]);
        $this->upload('dwg', UploadedFile::fake()->createWithContent('tres.dwg', 'AC1032' . str_repeat('x', 3 * 1024 * 1024)))->assertOk(); // ~3 MB, lo habitual
    }

    public function test_solo_el_autor_o_el_admin_pueden_subir(): void
    {
        $other = $this->makeUser('otro@test.com');
        $other->assignRole(Role::findOrCreate('colaborador', 'web'));
        Sanctum::actingAs($other);
        $this->post("/api/studio/details/{$this->detailId}/files/dwg", ['file' => $this->dwg()], ['Accept' => 'application/json'])->assertStatus(404);

        $plain = $this->makeUser('comun@test.com');
        Sanctum::actingAs($plain);
        $this->post("/api/studio/details/{$this->detailId}/files/dwg", ['file' => $this->dwg()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, DetailFile::count());
    }

    // ---------- Descarga autenticada ----------

    public function test_sin_sesion_no_se_descarga(): void
    {
        $id = $this->publishedWithFiles();

        $this->getJson("/api/details/{$id}/files/dwg")->assertUnauthorized();
    }

    public function test_la_descarga_depende_del_plan_activo(): void
    {
        $id = $this->publishedWithFiles();
        $free = $this->makeUser('free@test.com');
        $estudiante = $this->makeUser('est@test.com');
        $this->subscribe($estudiante, 'estudiante');
        $pro = $this->makeUser('pro@test.com');
        $this->subscribe($pro, 'pro');
        $vencido = $this->makeUser('vencido@test.com');
        $this->subscribe($vencido, 'pro', '-1 hour'); // el plan ya venció

        $this->download($free, $id, 'dwg')->assertForbidden();
        $this->download($free, $id, 'skp')->assertForbidden();
        $this->download($vencido, $id, 'dwg')->assertForbidden();

        $this->download($estudiante, $id, 'dwg')->assertOk();
        $this->download($estudiante, $id, 'skp')->assertForbidden()->assertJsonPath('message', 'Los archivos SKP son exclusivos del plan Pro.');

        $this->download($pro, $id, 'dwg')->assertOk();
        $this->download($pro, $id, 'skp')->assertOk();
    }

    public function test_la_descarga_se_entrega_en_streaming_sin_exponer_la_ruta_real(): void
    {
        $id = $this->publishedWithFiles();
        $pro = $this->makeUser('pro@test.com');
        $this->subscribe($pro, 'pro');

        $res = $this->download($pro, $id, 'dwg')->assertOk();

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $res->baseResponse);
        $this->assertSame('AC1032-CONTENIDO-DWG', $res->streamedContent());
        $disposition = $res->headers->get('content-disposition');
        $this->assertStringContainsString('plano-original.dwg', $disposition);
        $this->assertStringNotContainsString('detail-files', $disposition);
        $this->assertStringNotContainsString('archivo-dwg', $disposition);

        // El detalle público tampoco cuenta dónde están guardados
        $slug = DB::table('details')->where('id', $id)->value('slug');
        $json = $this->getJson("/api/details/{$slug}")->assertOk();
        $this->assertStringNotContainsString('detail-files', $json->getContent());
        $this->assertSame(['type' => 'dwg', 'can_download' => true], $json->json('files.0'));
    }

    public function test_respeta_el_plan_requerido_del_detalle_y_su_cupo(): void
    {
        $estudiante = $this->makeUser('est@test.com');
        $this->subscribe($estudiante, 'estudiante');

        $premium = $this->publishedWithFiles('pro');
        $this->download($estudiante, $premium, 'dwg')->assertForbidden(); // detalle solo Pro

        // Con un cupo de 1 detalle: si ya abrió otro, no puede bajar los archivos de uno nuevo
        Plan::where('slug', 'estudiante')->update(['detail_limit' => 1]);
        $first = $this->publishedWithFiles();
        $second = $this->publishedWithFiles();
        DB::table('detail_views')->insert(['user_id' => $estudiante->id, 'detail_id' => $first, 'first_viewed_at' => now()]);

        $this->download($estudiante, $second, 'dwg')->assertForbidden();
        $this->download($estudiante, $first, 'dwg')->assertOk(); // el que ya abrió sigue disponible
    }

    public function test_no_baja_un_detalle_sin_publicar_ni_un_archivo_que_no_existe(): void
    {
        $pro = $this->makeUser('pro@test.com');
        $this->subscribe($pro, 'pro');

        $draft = $this->publishedWithFiles();
        DB::table('details')->where('id', $draft)->update(['status' => 'draft']);
        $this->download($pro, $draft, 'dwg')->assertNotFound();

        $onlyDwg = $this->publishedWithFiles(null, ['dwg']);
        $this->download($pro, $onlyDwg, 'skp')->assertNotFound()->assertJsonPath('message', 'Este detalle no tiene archivo SKP.');

        // El registro existe pero el archivo desapareció del disco
        $broken = $this->publishedWithFiles(null, ['dwg']);
        DetailFile::disk()->deleteDirectory("detail-files/{$broken}");
        $this->download($pro, $broken, 'dwg')->assertNotFound();
    }

    public function test_con_el_email_sin_verificar_no_se_descarga(): void
    {
        $id = $this->publishedWithFiles();
        $pro = $this->makeUser('pro@test.com');
        $this->subscribe($pro, 'pro');
        $pro->forceFill(['email_verified_at' => null])->save();

        $this->download($pro, $id, 'dwg')->assertForbidden();
    }
}
