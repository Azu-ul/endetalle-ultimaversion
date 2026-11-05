<?php

namespace Tests\Feature;

use App\Dev\Commands\DevPayDate;
use App\Dev\Commands\DevReset;
use App\Dev\Commands\DevSubscription;
use App\Models\Order;
use App\Models\Plan;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DevCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Notification::fake();
        Storage::fake('local');
        Cache::flush();
        config([
            'services.mercadopago.access_token' => 'SECRETO-MP-123',
            'services.paypal.client_id' => 'client', 'services.paypal.secret' => 'SECRETO-PAYPAL-456',
            'services.paypal.webhook_id' => 'WH-1',
            'app.frontend_url' => 'http://localhost:5173', 'app.url' => 'http://localhost:8000',
            'mail.contact_to' => null,
        ]);

        $this->user = User::create(['name' => 'Puto', 'email' => 'puto@test.com', 'password' => 'password12']);
        $this->user->forceFill(['email_verified_at' => now()])->save();
    }

    /** Los comandos dev:* solo se registran con APP_ENV=local: en las pruebas se registran a mano. */
    private function inLocal(): void
    {
        $this->app['env'] = 'local';
        foreach ([DevSubscription::class, DevReset::class, DevPayDate::class] as $command) {
            Artisan::registerCommand($this->app->make($command));
        }
    }

    private function cli(string $command, array $args): int
    {
        return Artisan::call($command, $args);
    }

    private function subscription(string $plan, array $extra = []): int
    {
        return $this->cli('dev:subscription', ['email' => 'puto@test.com', 'plan' => $plan] + $extra);
    }

    private function resetHttp(): void
    {
        Http::clearResolvedInstance(Factory::class);
        app()->forgetInstance(Factory::class);
    }

    private function checkout(string $provider, string $plan, string $period = 'monthly')
    {
        $this->resetHttp();
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF', 'init_point' => 'https://mp.test/pay']),
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'PP', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']]]),
        ]);
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/checkout', ['plan' => $plan, 'period' => $period, 'provider' => $provider]);
    }

    private function lastOrder(): Order
    {
        return Order::latest('id')->first();
    }

    // ---------- Solo local ----------

    public function test_los_comandos_no_estan_registrados_fuera_de_local(): void
    {
        $this->assertSame('testing', app()->environment());
        foreach (['dev:subscription', 'dev:reset', 'dev:pay-date'] as $name) {
            $this->assertArrayNotHasKey($name, Artisan::all());
        }
    }

    public function test_se_niegan_a_correr_si_app_env_no_es_local_y_no_cambian_nada(): void
    {
        $this->inLocal();
        $this->subscription('pro');
        $order = $this->lastOrder();
        $this->app['env'] = 'production';

        foreach ([
            ['dev:subscription', ['email' => 'puto@test.com', 'plan' => 'estudiante']],
            ['dev:reset', ['email' => 'puto@test.com']],
            ['dev:pay-date', ['order_id' => $order->id, '--days-ago' => 20]],
        ] as [$name, $args]) {
            $this->assertSame(1, $this->cli($name, $args), $name);
            $this->assertStringContainsString('solo para el entorno local', Artisan::output(), $name);
        }

        $this->assertSame(1, Order::count());
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);
        $this->assertTrue($order->fresh()->paid_at->equalTo($order->paid_at)); // dev:pay-date no movió la fecha
    }

    // ---------- dev:subscription ----------

    public function test_deja_al_usuario_con_el_plan_pago_activo_con_orden_pagada_y_fechas_coherentes(): void
    {
        $this->inLocal();
        // Otro plan activo, uno encolado y una compra sin pagar: se cierran
        $old = Subscription::create(['user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'pro')->value('id'), 'status' => 'active',
            'provider' => 'paypal', 'provider_subscription_id' => 'x', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $queued = Subscription::create(['user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'estudiante')->value('id'), 'status' => 'scheduled',
            'provider' => 'paypal', 'provider_subscription_id' => 'y', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonths(2)]);
        $pending = Order::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => $old->plan_id, 'period' => 'monthly',
            'months' => 1, 'provider' => 'paypal', 'currency' => 'USD', 'amount' => 29]);

        $this->assertSame(0, $this->subscription('estudiante', ['--days-left' => 10]));

        $order = Order::where('status', 'paid')->first();
        $this->assertSame('mercadopago', $order->provider);
        $this->assertSame('ARS', $order->currency);
        $this->assertEquals(12000, $order->amount);
        $this->assertStringStartsWith('DEV-', $order->provider_payment_id);
        $sub = $this->user->currentSubscription();
        $this->assertSame('estudiante', $sub->plan->slug);
        $this->assertSame($sub->id, $order->subscription_id);
        $this->assertEquals(now()->addDays(10)->toDateString(), $sub->ends_at->toDateString());      // vence hoy + días restantes
        $this->assertEquals($sub->ends_at->copy()->subMonth()->toDateString(), $order->paid_at->toDateString()); // pagada hace "el período menos lo que queda"
        $this->assertEquals($order->paid_at->toDateTimeString(), $sub->starts_at->toDateTimeString());
        $this->assertSame(1, DB::table('payments')->where('provider_payment_id', $order->provider_payment_id)->where('status', 'paid')->count());

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('cancelled', $queued->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(1, Subscription::whereIn('status', ['active', 'scheduled'])->count());
    }

    public function test_en_paypal_cobra_en_dolares_segun_el_periodo(): void
    {
        $this->inLocal();

        $this->subscription('estudiante', ['--provider' => 'paypal']);
        $this->assertSame('USD', $this->lastOrder()->currency);
        $this->assertEquals(9.0, $this->lastOrder()->amount);
        $this->assertSame('paypal', $this->user->currentSubscription()->provider);

        $this->subscription('pro', ['--provider' => 'paypal', '--period' => 'quarterly', '--days-left' => 40]);
        $this->assertEquals(73.95, $this->lastOrder()->amount);
        $this->assertEquals(3, $this->lastOrder()->months);

        $this->subscription('pro', ['--period' => 'yearly', '--days-left' => 200]);
        $this->assertSame('ARS', $this->lastOrder()->currency);
        $this->assertEquals(327600, $this->lastOrder()->amount);
    }

    public function test_valida_los_parametros(): void
    {
        $this->inLocal();

        $this->assertSame(1, $this->cli('dev:subscription', ['email' => 'nadie@test.com', 'plan' => 'pro']));
        $this->assertStringContainsString('No existe ningún usuario', Artisan::output());
        $this->assertSame(1, $this->subscription('free'));
        $this->assertSame(1, $this->subscription('pro', ['--period' => 'semanal']));
        $this->assertSame(1, $this->subscription('pro', ['--provider' => 'stripe']));
        $this->assertSame(1, $this->subscription('pro', ['--days-left' => 'muchos']));
        $this->assertSame(1, $this->subscription('pro', ['--days-left' => 90])); // más que un mes
        $this->assertStringContainsString('no puede superar la duración del período', Artisan::output());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_el_resumen_no_muestra_claves_ni_tokens(): void
    {
        $this->inLocal();
        foreach ([['dev:subscription', ['email' => 'puto@test.com', 'plan' => 'pro', '--provider' => 'paypal']], ['dev:reset', ['email' => 'puto@test.com']]] as [$name, $args]) {
            $this->cli($name, $args);
            $output = Artisan::output();
            foreach (['SECRETO-MP-123', 'SECRETO-PAYPAL-456', 'client', 'WH-1'] as $secret) {
                $this->assertStringNotContainsString($secret, $output, $name);
            }
        }
        $this->subscription('estudiante');
        $output = Artisan::output(); // el buffer se vacía al leerlo: se lee una sola vez
        $this->assertStringContainsString('Plan: Estudiante', $output);
        $this->assertStringContainsString('Órdenes:', $output);
    }

    // ---------- Escenarios que se quieren probar ----------

    public function test_subir_a_pro_pagando_en_paypal_desde_un_estudiante_pagado_con_paypal(): void
    {
        $this->inLocal();
        $this->subscription('estudiante', ['--provider' => 'paypal', '--days-left' => 10]);
        $estudianteEnds = $this->user->currentSubscription()->ends_at->copy();

        $res = $this->checkout('paypal', 'pro')->assertOk();

        $order = Order::where('uuid', $res->json('order'))->first();
        $this->assertSame('USD', $order->currency);
        $this->assertEquals(20.0, $order->amount);   // 29 - 9
        $this->assertNotNull($order->upgrade_from_id);
        $this->assertEquals($estudianteEnds->toDateTimeString(), $order->ends_at->toDateTimeString());
    }

    public function test_renovar_con_3_dias_o_menos_y_bloqueo_con_4(): void
    {
        $this->inLocal();
        $this->subscription('estudiante', ['--provider' => 'paypal', '--days-left' => 3]);
        $this->checkout('paypal', 'estudiante')->assertOk();

        $this->subscription('estudiante', ['--provider' => 'paypal', '--days-left' => 4]);
        $this->checkout('paypal', 'estudiante')->assertStatus(422);
    }

    public function test_bajar_de_plan_con_el_plan_por_vencer(): void
    {
        $this->inLocal();
        $this->subscription('pro', ['--provider' => 'paypal', '--days-left' => 2]);
        $res = $this->checkout('paypal', 'estudiante')->assertOk();
        $order = Order::where('uuid', $res->json('order'))->first();
        $this->assertEquals(9.0, $order->amount);                 // se paga completo
        $this->assertNotNull($order->starts_after_id);

        $this->subscription('pro', ['--provider' => 'paypal', '--days-left' => 20]);
        $this->checkout('paypal', 'estudiante')->assertStatus(422);
    }

    public function test_reembolsos_dentro_y_fuera_de_plazo_moviendo_la_fecha_de_pago(): void
    {
        $this->inLocal();
        $this->subscription('estudiante', ['--days-left' => 25]);
        $order = $this->lastOrder();
        Sanctum::actingAs($this->user);

        $this->assertSame(0, $this->cli('dev:pay-date', ['order_id' => $order->id, '--days-ago' => 15]));
        $this->assertStringContainsString('DENTRO de plazo', Artisan::output());
        $this->postJson('/api/refunds', ['order_id' => $order->id])->assertCreated();

        RefundRequest::query()->delete();
        $this->assertSame(0, $this->cli('dev:pay-date', ['order_id' => $order->id, '--days-ago' => 16]));
        $this->assertStringContainsString('FUERA de plazo', Artisan::output());
        $this->postJson('/api/refunds', ['order_id' => $order->id])->assertStatus(422)->assertJsonPath('errors.order_id.0', fn ($m) => str_contains($m, 'El plazo de reembolso venció el'));
    }

    // ---------- dev:pay-date ----------

    public function test_pay_date_mueve_la_fecha_de_pago_de_la_orden_y_del_pago(): void
    {
        $this->inLocal();
        $this->subscription('estudiante');
        $order = $this->lastOrder();

        $this->assertSame(0, $this->cli('dev:pay-date', ['order_id' => $order->id, '--days-ago' => 7]));

        $this->assertEquals(now()->subDays(7)->toDateString(), $order->fresh()->paid_at->toDateString());
        $this->assertEquals(now()->subDays(7)->toDateString(), substr((string) DB::table('payments')->where('provider_payment_id', $order->provider_payment_id)->value('paid_at'), 0, 10));
        $this->assertStringContainsString('Días desde el pago: 7', Artisan::output());
    }

    public function test_pay_date_valida_la_orden_y_los_dias(): void
    {
        $this->inLocal();
        $this->subscription('estudiante');
        $order = $this->lastOrder();
        $pending = Order::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => $order->plan_id, 'period' => 'monthly',
            'months' => 1, 'provider' => 'paypal', 'currency' => 'USD', 'amount' => 9]);

        $this->assertSame(1, $this->cli('dev:pay-date', ['order_id' => $order->id]));                       // falta --days-ago
        $this->assertSame(1, $this->cli('dev:pay-date', ['order_id' => $order->id, '--days-ago' => 'x']));
        $this->assertSame(1, $this->cli('dev:pay-date', ['order_id' => 9999, '--days-ago' => 3]));
        $this->assertSame(1, $this->cli('dev:pay-date', ['order_id' => $pending->id, '--days-ago' => 3]));  // no está pagada
        $this->assertStringContainsString('no está pagada', Artisan::output());
    }

    // ---------- dev:reset ----------

    public function test_reset_deja_en_free_y_borra_ordenes_reembolsos_y_planes_sin_tocar_a_otros(): void
    {
        $this->inLocal();
        $other = User::create(['name' => 'Otra', 'email' => 'otra@test.com', 'password' => 'password12']);
        $this->cli('dev:subscription', ['email' => 'otra@test.com', 'plan' => 'pro']);

        $this->subscription('estudiante', ['--provider' => 'paypal']);
        $order = Order::where('user_id', $this->user->id)->first();
        $refund = RefundRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'amount' => 9, 'currency' => 'USD']);
        Storage::disk('local')->put("refund-receipts/{$refund->id}/comprobante.pdf", 'x');
        Subscription::create(['user_id' => $this->user->id, 'plan_id' => $order->plan_id, 'status' => 'scheduled', 'provider' => 'paypal',
            'provider_subscription_id' => 'q', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonths(2)]);

        $this->assertSame(0, $this->cli('dev:reset', ['email' => 'puto@test.com']));

        $this->assertNull($this->user->currentSubscription());
        foreach (['subscriptions', 'orders', 'payments', 'refund_requests'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $this->user->id)->count(), $table);
        }
        Storage::disk('local')->assertMissing("refund-receipts/{$refund->id}/comprobante.pdf");
        $this->assertStringContainsString('quedó en Free', Artisan::output());

        // Otro usuario: intacto
        $this->assertSame('pro', $other->currentSubscription()->plan->slug);
        $this->assertSame(1, Order::where('user_id', $other->id)->count());
        $this->assertSame(1, DB::table('payments')->where('user_id', $other->id)->count());

        // Y se puede repetir la compra desde cero
        $this->subscription('estudiante');
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
    }
}
