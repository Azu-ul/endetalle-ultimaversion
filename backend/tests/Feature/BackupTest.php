<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Backup\BackupService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/** Backup manual y automático: zip con database.sql + archivos, sin .env, con retención y solo para el superadmin. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/backups-test-' . uniqid();
        config(['backup.path' => $this->dir, 'backup.keep_auto' => 2, 'backup.keep_manual' => 2]);
        $this->seed([RoleSeeder::class, PlanSeeder::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password12']);
        $u->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_el_backup_trae_la_base_y_los_archivos_pero_no_el_env(): void
    {
        $this->admin();

        $b = app(BackupService::class)->create('manual');

        $this->assertMatchesRegularExpression('/^backup-\d{4}-\d{2}-\d{2}_\d{6}-manual\.zip$/', $b['name']);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->dir . '/' . $b['name']) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        $this->assertContains('database.sql', $names);
        $this->assertContains('LEEME.txt', $names);
        $this->assertContains('sitio/routes/api.php', $names);
        $this->assertContains('sitio/artisan', $names);
        $this->assertSame([], array_values(array_filter($names, fn ($n) => str_contains($n, '.env') || str_contains($n, 'vendor/'))));
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('admin@test.com', $sql);
        $this->assertStringNotContainsString('password12', $sql); // la contraseña va hasheada
        $this->assertSame([], glob($this->dir . '/*.tmp') + glob($this->dir . '/*.part'));
    }

    public function test_el_sql_se_puede_volver_a_importar(): void
    {
        $this->admin();
        $b = app(BackupService::class)->create('manual');
        $zip = new ZipArchive();
        $zip->open($this->dir . '/' . $b['name']);
        $sql = $zip->getFromName('database.sql');
        $zip->close();

        // Se importa sobre una base distinta (sqlite en memoria nueva) y vuelve el usuario
        config(['database.connections.restore' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        $conn = \Illuminate\Support\Facades\DB::connection('restore');
        $conn->getPdo()->exec($sql);

        $this->assertSame('admin@test.com', $conn->table('users')->value('email'));
        $this->assertSame(3, $conn->table('plans')->count());
    }

    public function test_el_comando_automatico_crea_el_backup_y_conserva_solo_los_ultimos(): void
    {
        foreach (['2026-01-01_030000', '2026-01-02_030000', '2026-01-03_030000'] as $d) {
            File::ensureDirectoryExists($this->dir);
            File::put("{$this->dir}/backup-{$d}-auto.zip", 'x');
        }

        $this->artisan('backup:run')->assertSuccessful();

        $names = array_column(app(BackupService::class)->list(), 'name');
        $this->assertCount(2, $names); // keep_auto = 2: el nuevo + el más reciente de los viejos
        $this->assertStringContainsString('-auto.zip', $names[0]);
        $this->assertNotContains('backup-2026-01-01_030000-auto.zip', $names);
    }

    public function test_esta_programado_el_backup_diario(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backup:run')->assertSuccessful();
    }

    public function test_el_superadmin_crea_lista_descarga_y_elimina(): void
    {
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/admin/backups')->assertCreated()->json();
        $this->assertSame('manual', $created['type']);

        $list = $this->getJson('/api/admin/backups')->assertOk();
        $list->assertJsonPath('backups.0.name', $created['name']);
        $list->assertJsonPath('keep_auto', 2);

        $this->get("/api/admin/backups/{$created['name']}/download")->assertOk()->assertDownload($created['name']);

        $this->deleteJson("/api/admin/backups/{$created['name']}")->assertOk();
        $this->getJson('/api/admin/backups')->assertJsonPath('backups', []);
        $this->get("/api/admin/backups/{$created['name']}/download")->assertNotFound();
    }

    public function test_no_se_puede_pedir_cualquier_ruta(): void
    {
        Sanctum::actingAs($this->admin());
        File::ensureDirectoryExists($this->dir);
        File::put(dirname($this->dir) . '/secreto.zip', 'x');

        $this->get('/api/admin/backups/..%2Fsecreto.zip/download')->assertNotFound();
        $this->get('/api/admin/backups/backup-..%2F..%2Fsecreto.zip/download')->assertNotFound();
        $this->assertNull(app(BackupService::class)->path('../secreto.zip'));
        @unlink(dirname($this->dir) . '/secreto.zip');
    }

    public function test_solo_el_superadmin_accede_a_los_backups(): void
    {
        $u = User::create(['name' => 'C', 'email' => 'c@test.com', 'password' => 'password12']);
        $u->forceFill(['email_verified_at' => now(), 'status' => 'active'])->save();
        $u->assignRole('colaborador');
        Sanctum::actingAs($u);

        $this->getJson('/api/admin/backups')->assertForbidden();
        $this->postJson('/api/admin/backups')->assertForbidden();
    }
}
