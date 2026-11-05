<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Si la base de datos no responde, /api/health lo dice con un 503 y sin filtrar el error. */
class HealthWithoutDatabaseTest extends TestCase
{
    public function test_health_avisa_que_la_base_no_responde_sin_mostrar_el_error(): void
    {
        config(['database.connections.sqlite.database' => storage_path('app/no-existe/base.sqlite')]);
        DB::purge('sqlite');

        $res = $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'error')->assertJsonPath('database', false);

        $body = $res->getContent();
        foreach (['SQLSTATE', 'no-existe', 'sqlite', 'unable to open', base_path()] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }
}
