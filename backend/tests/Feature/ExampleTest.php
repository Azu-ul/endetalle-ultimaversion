<?php

namespace Tests\Feature;

use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** La API pública responde (reemplaza la prueba de plantilla de Laravel, que pedía "/" sin base de datos). */
    public function test_la_api_publica_de_planes_responde(): void
    {
        $this->seed(PlanSeeder::class);

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.slug', 'free');
    }
}
