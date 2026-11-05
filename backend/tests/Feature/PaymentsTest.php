<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        config([
            'services.mercadopago.access_token' => 'TEST-token',
            'services.mercadopago.webhook_secret' => null,
            'services.paypal.client_id' => 'client',
            'services.paypal.secret' => 'secret',
            'services.paypal.webhook_id' => 'WH-1',
            'app.frontend_url' => 'http://localhost:5173',
            'app.url' => 'http://localhost:8000',
        ]);
        Cache::flush();
        Notification::fake();

        $this->user = User::create([
            'name' => 'Ana', 'email' => 'ana@test.com', 'password' => 'password12',
        ]);
        $this->user->forceFill(['email_verified_at' => now()])->save();
    }

    /** Las simulaciones de Http se acumulan y gana la primera: cada paso arranca de cero. */
    private function resetHttp(): void
    {
        Http::clearResolvedInstance(Factory::class);
        app()->forgetInstance(Factory::class);
    }

    private function mpPreference(): void
    {
        $this->resetHttp();
        Http::fake([
            'api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'PREF-1', 'init_point' => 'https://mp.test/pay']),
        ]);
    }

    private function startCheckout(string $provider, string $period = 'quarterly', string $plan = 'pro'): array
    {
        if ($provider === 'mercadopago') {
            $this->mpPreference();
        }
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/checkout', ['plan' => $plan, 'period' => $period, 'provider' => $provider])
            ->assertOk()->json();
    }

    private function mpPayment(Order $order, array $overrides = []): array
    {
        return array_merge([
            'id' => 555, 'status' => 'approved', 'external_reference' => $order->uuid,
            'transaction_amount' => $order->amount, 'currency_id' => 'ARS',
        ], $overrides);
    }

    private function fakeMpPayment(array $payment): void
    {
        $this->resetHttp();
        Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response($payment)]);
    }

    private function webhook(string $id = '555')
    {
        return $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => $id]]);
    }

    public function test_el_monto_lo_pone_el_servidor_y_la_orden_queda_pendiente(): void
    {
        $this->mpPreference();
        $res = $this->startCheckout('mercadopago', 'quarterly');

        $this->assertSame('https://mp.test/pay', $res['url']);
        $order = Order::where('uuid', $res['order'])->firstOrFail();
        $expected = collect(Plan::where('slug', 'pro')->first()->periodPrices())->firstWhere('id', 'quarterly')['total_ars'];
        $this->assertEquals($expected, $order->amount);
        $this->assertSame('ARS', $order->currency);
        $this->assertSame('pending', $order->status);
        $this->assertSame('PREF-1', $order->provider_order_id);
        $this->assertSame(0, Subscription::count());
    }

    public function test_el_navegador_no_puede_mandar_el_monto(): void
    {
        $this->mpPreference();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/checkout', ['plan' => 'pro', 'period' => 'monthly', 'provider' => 'mercadopago', 'amount' => 1])->assertOk();

        $this->assertEquals(39000, Order::first()->amount);
    }

    public function test_sin_email_verificado_no_se_puede_comprar(): void
    {
        $this->user->forceFill(['email_verified_at' => null])->save();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/checkout', ['plan' => 'pro', 'period' => 'monthly', 'provider' => 'mercadopago'])->assertStatus(403);
    }

    public function test_el_pago_aprobado_activa_el_plan_y_repetir_el_aviso_no_duplica_nada(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();
        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, DB::table('payments')->count());
        $sub = Subscription::first();
        $this->assertSame('active', $sub->status);
        $this->assertSame('pro', $sub->plan->slug);
        $this->assertEquals(now()->addMonthsNoOverflow(3)->toDateString(), $sub->ends_at->toDateString());
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);
        Notification::assertSentToTimes($this->user, \App\Notifications\PaymentConfirmedEs::class, 1);
    }

    public function test_al_acreditar_un_pago_se_le_avisa_por_mail_a_quien_recibe_las_ventas_una_sola_vez(): void
    {
        config(['mail.sales_to' => 'ventas@estudio.test']);
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();
        $this->webhook()->assertOk(); // el aviso repetido no manda otro mail

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent->first()->getOriginalMessage();
        $this->assertSame('ventas@estudio.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Nuevo pago: plan Pro', $email->getSubject());
        $body = $email->getTextBody();
        $this->assertStringContainsString('ana@test.com', $body);
        $this->assertStringContainsString('Mercado Pago', $body);
        $this->assertStringContainsString($order->uuid, $body);
    }

    public function test_sin_casilla_de_ventas_no_se_manda_el_aviso_interno_y_el_pago_igual_se_acredita(): void
    {
        config(['mail.sales_to' => null]);
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_un_pago_con_otro_monto_no_activa_nada(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($order, ['transaction_amount' => 1]));

        $this->webhook()->assertOk();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_un_pago_pendiente_o_rechazado_no_activa_nada(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();

        foreach (['pending', 'in_process', 'rejected'] as $status) {
            $this->fakeMpPayment($this->mpPayment($order, ['status' => $status]));
            $this->webhook()->assertOk();
        }

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_al_volver_del_checkout_se_confirma_aunque_el_webhook_no_llegue(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['results' => [$this->mpPayment($order)]])]);

        $this->getJson("/api/orders/{$order->uuid}")->assertOk()->assertJsonPath('status', 'paid')->assertJsonPath('plan.slug', 'pro');
        $this->assertSame(1, Subscription::count());
    }

    public function test_nadie_ve_las_ordenes_de_otro(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();
        $other = User::create(['name' => 'Beto', 'email' => 'beto@test.com', 'password' => 'password12']);
        $other->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($other);

        $this->getJson("/api/orders/{$order->uuid}")->assertNotFound();
    }

    public function test_renovar_el_mismo_plan_suma_los_meses_al_final_del_vigente(): void
    {
        $this->mpPreference();
        $first = Order::where('uuid', $this->startCheckout('mercadopago', 'monthly', 'estudiante')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($first, ['id' => 1]));
        $this->webhook('1');
        Subscription::first()->update(['ends_at' => now()->addDays(2)]); // dentro de la ventana de renovación
        $endsAfterFirst = Subscription::first()->fresh()->ends_at->copy();

        $second = Order::where('uuid', $this->startCheckout('mercadopago', 'quarterly', 'estudiante')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($second, ['id' => 2]));
        $this->webhook('2');

        $this->assertSame(1, Subscription::count());
        $this->assertEquals($endsAfterFirst->copy()->addMonthsNoOverflow(3)->toDateString(), Subscription::first()->ends_at->toDateString());
        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_comprar_otro_plan_reemplaza_al_anterior(): void
    {
        $this->mpPreference();
        $est = Order::where('uuid', $this->startCheckout('mercadopago', 'monthly', 'estudiante')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($est, ['id' => 1]));
        $this->webhook('1');

        $pro = Order::where('uuid', $this->startCheckout('mercadopago', 'monthly', 'pro')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($pro, ['id' => 2]));
        $this->webhook('2');

        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);
        $this->assertSame(1, Subscription::where('status', 'active')->count());
        $this->assertSame(1, Subscription::where('status', 'cancelled')->count());
    }

    public function test_una_devolucion_quita_el_plan(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago', 'monthly')['order'])->first();
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();
        $this->assertNotNull($this->user->currentSubscription());

        $this->fakeMpPayment($this->mpPayment($order, ['status' => 'refunded']));
        $this->webhook();
        $this->webhook();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertNull($this->user->currentSubscription());
        $this->assertSame('refunded', DB::table('payments')->value('status'));
    }

    public function test_un_aviso_de_un_pago_que_no_es_nuestro_se_ignora(): void
    {
        $this->fakeMpPayment(['id' => 9, 'status' => 'approved', 'external_reference' => 'otra-cosa', 'transaction_amount' => 10, 'currency_id' => 'ARS']);

        $this->webhook('9')->assertOk();
        $this->assertSame(0, Subscription::count());
    }

    public function test_firma_invalida_de_mercado_pago_se_rechaza(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto']);

        $this->webhook()->assertStatus(401);
    }

    public function test_firma_valida_de_mercado_pago_se_acepta(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto']);
        $this->fakeMpPayment(['id' => 9, 'status' => 'pending', 'external_reference' => 'x']);
        $ts = '1700000000';
        $v1 = hash_hmac('sha256', "id:555;request-id:req-1;ts:{$ts};", 'secreto');

        $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']], [
            'x-signature' => "ts={$ts},v1={$v1}", 'x-request-id' => 'req-1',
        ])->assertOk();
    }

    // ---------- Preferencia y confirmación al volver ----------

    public function test_la_preferencia_lleva_las_tres_back_urls_con_la_orden_tambien_en_local(): void
    {
        $order = Order::where('uuid', $this->startCheckout('mercadopago')['order'])->first();

        Http::assertSent(function ($request) use ($order) {
            if (! str_contains($request->url(), '/checkout/preferences')) {
                return false;
            }
            $back = $request['back_urls'];
            $expected = "http://localhost:5173/pago/resultado?orden={$order->uuid}&proveedor=mercadopago";

            return $back['success'] === $expected && $back['pending'] === $expected && $back['failure'] === $expected
                && $request['external_reference'] === $order->uuid
                && ! isset($request['auto_return'])        // Mercado Pago lo rechaza con http local
                && ! isset($request['notification_url']);  // y no puede avisar a localhost
        });
    }

    public function test_con_direcciones_publicas_se_pide_auto_return_y_webhook(): void
    {
        config(['app.frontend_url' => 'https://endetalle.test', 'app.url' => 'https://endetalle.test']);
        $this->startCheckout('mercadopago');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/preferences')
            && $request['auto_return'] === 'approved'
            && $request['notification_url'] === 'https://endetalle.test/api/webhooks/mercadopago');
    }

    private function pendingOrder(User $user, string $plan = 'estudiante'): Order
    {
        $p = Plan::where('slug', $plan)->first();

        return Order::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id, 'plan_id' => $p->id,
            'period' => 'monthly', 'months' => 1, 'provider' => 'mercadopago', 'currency' => 'ARS',
            'amount' => $p->price_ars, 'provider_order_id' => 'PREF',
        ]);
    }

    /** Respuestas de búsqueda por external_reference: [uuid => payments[]] */
    private function fakeMpSearch(array $byReference): void
    {
        $this->resetHttp();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => function ($request) use ($byReference) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['results' => $byReference[$query['external_reference']] ?? []]);
        }]);
    }

    public function test_al_abrir_mi_cuenta_se_acreditan_las_ordenes_pendientes_con_pago_aprobado(): void
    {
        $a = $this->pendingOrder($this->user);
        $b = $this->pendingOrder($this->user);
        $this->fakeMpSearch([
            $a->uuid => [$this->mpPayment($a, ['id' => 185162758095])],
            $b->uuid => [$this->mpPayment($b, ['id' => 186207450162])],
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 2);

        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame('185162758095', $a->fresh()->provider_payment_id);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertSame(1, Subscription::count());            // el segundo pago renueva el mismo plan
        $this->assertSame(2, DB::table('payments')->count());
        $this->assertEquals(now()->addMonthsNoOverflow(1)->addMonthsNoOverflow(1)->toDateString(), Subscription::first()->ends_at->toDateString());

        // Repetir no acredita nada más
        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 0);
        $this->assertSame(2, DB::table('payments')->count());
    }

    public function test_la_sincronizacion_no_toca_las_ordenes_de_otros_usuarios(): void
    {
        $other = User::create(['name' => 'Beto', 'email' => 'beto@test.com', 'password' => 'password12']);
        $theirs = $this->pendingOrder($other);
        $mine = $this->pendingOrder($this->user);
        $this->fakeMpSearch([
            $theirs->uuid => [$this->mpPayment($theirs)],
            $mine->uuid => [],
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 0);

        $this->assertSame('pending', $theirs->fresh()->status);
        $this->assertSame(0, Subscription::count());
    }

    public function test_la_sincronizacion_no_acredita_pagos_pendientes_rechazados_ni_de_otro_monto(): void
    {
        $a = $this->pendingOrder($this->user);
        $b = $this->pendingOrder($this->user);
        $c = $this->pendingOrder($this->user);
        $this->fakeMpSearch([
            $a->uuid => [$this->mpPayment($a, ['status' => 'in_process'])],
            $b->uuid => [$this->mpPayment($b, ['status' => 'rejected'])],
            $c->uuid => [$this->mpPayment($c, ['transaction_amount' => 1])],
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 0);
        $this->assertSame(0, Subscription::count());
    }

    public function test_la_sincronizacion_acredita_una_orden_vencida_y_sobrevive_a_un_error_de_mercado_pago(): void
    {
        $expired = $this->pendingOrder($this->user);
        $expired->update(['status' => 'expired']);
        $this->fakeMpSearch([$expired->uuid => [$this->mpPayment($expired)]]);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 1);
        $this->assertSame('paid', $expired->fresh()->status);

        $again = $this->pendingOrder($this->user);
        $this->resetHttp();
        Http::fake(['api.mercadopago.com/v1/payments/search*' => Http::response(['message' => 'error'], 500)]);
        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 0);
        $this->assertSame('pending', $again->fresh()->status);
    }

    public function test_la_sincronizacion_pide_sesion(): void
    {
        $this->postJson('/api/orders/sync')->assertUnauthorized();
    }

    // ---------- Reglas de compra, subida de plan y cancelación ----------

    private function subscribeTo(string $plan, \Carbon\Carbon $ends, string $provider = 'mercadopago', string $status = 'active'): Subscription
    {
        return Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => Plan::where('slug', $plan)->value('id'), 'status' => $status,
            'provider' => $provider, 'provider_subscription_id' => 'sub-' . uniqid(), 'starts_at' => now()->subDays(5), 'ends_at' => $ends,
        ]);
    }

    private function tryCheckout(string $provider, string $plan, string $period = 'monthly')
    {
        if ($provider === 'mercadopago') {
            $this->mpPreference();
        } else {
            $this->fakePayPal();
        }
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/checkout', ['plan' => $plan, 'period' => $period, 'provider' => $provider]);
    }

    public function test_el_mismo_plan_se_bloquea_con_mas_de_3_dias_y_dice_desde_cuando_se_puede_renovar(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeTo('estudiante', now()->addDays(4)); // vence el 5 de noviembre

        foreach (['mercadopago', 'paypal'] as $provider) {
            $this->tryCheckout($provider, 'estudiante', 'yearly')
                ->assertStatus(422)
                ->assertJsonPath('message', 'Tu plan Estudiante está activo hasta 5 de noviembre de 2026. Podés renovarlo desde el 2 de noviembre de 2026.');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_el_mismo_plan_se_puede_renovar_con_3_dias_o_menos_y_el_dia_del_vencimiento(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $sub = $this->subscribeTo('estudiante', now()->addDays(3));
        $this->tryCheckout('mercadopago', 'estudiante')->assertOk();

        $sub->update(['ends_at' => now()->addDay()]);
        $this->tryCheckout('mercadopago', 'estudiante')->assertOk();

        $sub->update(['ends_at' => now()->setTime(23, 0)]); // el mismo día, más tarde
        $this->tryCheckout('paypal', 'estudiante')->assertOk();
    }

    public function test_un_plan_vencido_se_puede_comprar_aunque_el_cron_no_haya_corrido(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $sub = $this->subscribeTo('estudiante', now()->subHour()); // sigue "active" en la base
        $this->tryCheckout('mercadopago', 'estudiante')->assertOk();

        $sub->update(['status' => 'expired']);
        $this->tryCheckout('mercadopago', 'estudiante')->assertOk();
    }

    /** Plan pago con sus órdenes pagadas, como lo deja el flujo real (la compra y sus renovaciones una atrás de otra). */
    private function subscribeWithOrders(string $plan, array $periods, ?\Carbon\Carbon $start = null): Subscription
    {
        $start ??= now();
        $planModel = Plan::where('slug', $plan)->first();
        $ends = $start->copy();
        foreach ($periods as $periodId) {
            $ends = $ends->copy()->addMonthsNoOverflow(collect(Plan::PERIODS)->firstWhere('id', $periodId)['months']);
        }
        $sub = Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => $planModel->id, 'status' => 'active', 'provider' => 'mercadopago',
            'provider_subscription_id' => 'sub-' . uniqid(), 'starts_at' => $start, 'ends_at' => $ends,
        ]);
        foreach ($periods as $i => $periodId) {
            $months = collect(Plan::PERIODS)->firstWhere('id', $periodId)['months'];
            Order::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => $planModel->id,
                'period' => $periodId, 'months' => $months, 'provider' => 'mercadopago', 'currency' => 'ARS',
                'amount' => 1, 'status' => 'paid', 'subscription_id' => $sub->id, 'paid_at' => $start->copy()->addSeconds($i),
            ]);
        }

        return $sub;
    }

    private function upgradeOrder(string $provider = 'mercadopago', string $period = 'monthly'): Order
    {
        return Order::where('uuid', $this->tryCheckout($provider, 'pro', $period)->assertOk()->json('order'))->first();
    }

    public function test_subir_a_pro_cobra_la_diferencia_del_periodo_vigente_en_pesos_y_en_dolares(): void
    {
        $this->travelTo('2026-11-01 12:00:00');

        // Mensual: Pro 39000 - Estudiante 12000 = 27000 / US$ 29 - 9 = 20
        $this->subscribeWithOrders('estudiante', ['monthly']);
        $ars = $this->upgradeOrder('mercadopago');
        $this->assertEquals(27000, $ars->amount);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/checkout/preferences') && $r['items'][0]['unit_price'] == 27000);
        $this->assertEquals(20.0, $this->upgradeOrder('paypal')->amount);
        Subscription::query()->delete();

        // 3 meses: (99450 - 30600) = 68850 / (73.95 - 22.95) = 51
        $this->subscribeWithOrders('estudiante', ['quarterly']);
        $this->assertEquals(68850, $this->upgradeOrder('mercadopago')->amount);
        $this->assertEquals(51.0, $this->upgradeOrder('paypal')->amount);
        Subscription::query()->delete();

        // Anual: (327600 - 100800) = 226800 / (243.60 - 75.60) = 168
        $this->subscribeWithOrders('estudiante', ['yearly']);
        $this->assertEquals(226800, $this->upgradeOrder('mercadopago')->amount);
        $this->assertEquals(168.0, $this->upgradeOrder('paypal')->amount);
    }

    public function test_la_diferencia_no_depende_de_cuantos_dias_quedan_ni_el_periodo_que_se_elija_en_pantalla(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('estudiante', ['monthly']); // vence el 1 de diciembre

        // El primer día, a mitad de período y el último día del plan: siempre la diferencia completa
        foreach (['2026-11-01 13:00:00', '2026-11-16 12:00:00', '2026-12-01 09:00:00'] as $moment) {
            $this->travelTo($moment);
            $this->assertEquals(27000, $this->upgradeOrder('mercadopago', 'yearly')->amount, $moment);
        }
    }

    public function test_al_acreditar_la_subida_el_plan_nuevo_empieza_hoy_y_conserva_el_vencimiento(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $old = $this->subscribeWithOrders('estudiante', ['quarterly']); // vence el 1 de febrero de 2027
        $this->travelTo('2026-12-10 10:00:00');
        $order = $this->upgradeOrder('mercadopago', 'monthly');
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $current = $this->user->currentSubscription();
        $this->assertSame('pro', $current->plan->slug);
        $this->assertEquals('2026-12-10', $current->starts_at->toDateString());
        $this->assertEquals($old->ends_at->toDateTimeString(), $current->ends_at->toDateTimeString()); // mismo vencimiento
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(1, Subscription::where('status', 'active')->count());
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_con_renovaciones_apiladas_se_suma_la_diferencia_de_cada_periodo_que_no_termino(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        // Compró un mes y renovó otro mes antes de que venciera: dos períodos pagados, vence el 1 de enero
        $this->subscribeWithOrders('estudiante', ['monthly', 'monthly']);

        $this->travelTo('2026-11-20 12:00:00'); // el primero todavía no terminó: cuentan los dos
        $this->assertEquals(54000, $this->upgradeOrder('mercadopago')->amount);
        $this->assertEquals(40.0, $this->upgradeOrder('paypal')->amount);

        $this->travelTo('2026-12-05 12:00:00'); // el primero terminó: solo cuenta el segundo
        $this->assertEquals(27000, $this->upgradeOrder('mercadopago')->amount);

        // Con períodos distintos: mensual + trimestral = 27000 + 68850
        Subscription::query()->delete();
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('estudiante', ['monthly', 'quarterly']);
        $this->assertEquals(95850, $this->upgradeOrder('mercadopago')->amount);
    }

    public function test_si_la_diferencia_da_cero_o_menos_se_rechaza_la_subida(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('estudiante', ['monthly']);
        Plan::where('slug', 'estudiante')->update(['price_ars' => 39000]); // mismo precio que Pro en pesos

        $this->tryCheckout('mercadopago', 'pro')
            ->assertStatus(422)
            ->assertJsonPath('message', 'No pudimos calcular la diferencia de precio para subir a Pro. Escribinos desde Contacto y lo resolvemos.');
        $this->assertSame(0, Order::whereNotNull('upgrade_from_id')->count());

        Plan::where('slug', 'estudiante')->update(['price_ars' => 50000]); // más caro que Pro: diferencia negativa
        $this->tryCheckout('mercadopago', 'pro')->assertStatus(422);

        $this->assertEquals(20.0, $this->upgradeOrder('paypal')->amount); // en dólares sigue siendo válida
    }

    public function test_si_cancelo_antes_de_subir_el_plan_nuevo_sigue_sin_renovarse(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $old = $this->subscribeWithOrders('estudiante', ['monthly']);
        $old->update(['cancelled_at' => now()]);
        $order = $this->upgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();

        $this->assertNotNull($this->user->currentSubscription()->cancelled_at);
        $this->assertEquals($old->ends_at->toDateTimeString(), $this->user->currentSubscription()->ends_at->toDateTimeString());
    }

    public function test_si_se_devuelve_la_diferencia_vuelve_el_plan_anterior(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $old = $this->subscribeWithOrders('estudiante', ['quarterly']);
        $order = $this->upgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);

        $this->fakeMpPayment($this->mpPayment($order, ['status' => 'refunded']));
        $this->webhook();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
        $this->assertEquals($old->ends_at->toDateTimeString(), $this->user->currentSubscription()->ends_at->toDateTimeString());
    }

    public function test_un_pago_de_subida_que_entra_cuando_el_plan_anterior_ya_vencio_igual_se_acredita(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('estudiante', ['monthly']); // vence el 1 de diciembre
        $this->travelTo('2026-11-21 12:00:00');
        $order = $this->upgradeOrder('mercadopago');           // quedaban 10 días
        $this->travelTo('2026-12-05 12:00:00');                // paga cuando el anterior ya venció
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $current = $this->user->currentSubscription();
        $this->assertSame('pro', $current->plan->slug);
        $this->assertEquals('2026-12-15', $current->ends_at->toDateString()); // los 10 días acordados desde hoy
    }

    public function test_los_usuarios_free_y_los_planes_manuales_no_tienen_restricciones(): void
    {
        $this->tryCheckout('mercadopago', 'pro')->assertOk();
        $this->assertEquals(39000, Order::first()->amount);

        // Un Pro de cortesía no cuenta como plan pago: no bloquea ni da crédito
        $this->subscribeTo('pro', now()->addDays(100), 'manual');
        $order = Order::where('uuid', $this->tryCheckout('mercadopago', 'estudiante')->assertOk()->json('order'))->first();
        $this->assertEquals(12000, $order->amount);
        $this->assertNull($order->upgrade_from_id);
    }

    public function test_si_ya_hay_una_compra_igual_sin_pagar_se_reutiliza(): void
    {
        $first = $this->tryCheckout('mercadopago', 'pro', 'quarterly')->assertOk()->json('order');
        $second = $this->tryCheckout('mercadopago', 'pro', 'quarterly')->assertOk()->json('order');
        $other = $this->tryCheckout('mercadopago', 'pro', 'yearly')->assertOk()->json('order');

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
        $this->assertSame(2, Order::count());
    }

    public function test_la_pantalla_de_planes_recibe_estado_y_monto_calculados_en_el_servidor(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('estudiante', ['monthly']);
        Sanctum::actingAs($this->user);

        $res = $this->getJson('/api/checkout/status')->assertOk();
        $res->assertJsonPath('current.plan', 'estudiante');
        $plans = collect($res->json('plans'))->keyBy('slug');
        $this->assertSame('blocked_same', $plans['estudiante']['state']);
        $this->assertSame('upgrade', $plans['pro']['state']);
        $monthly = collect($plans['pro']['periods'])->firstWhere('id', 'monthly');
        $this->assertEquals(27000, $monthly['amount_ars']);
        $this->assertEquals(20.0, $monthly['amount_usd']);
        // La subida cuesta lo mismo sea cual sea el período que se mire en pantalla
        $yearly = collect($plans['pro']['periods'])->firstWhere('id', 'yearly');
        $this->assertEquals(27000, $yearly['amount_ars']);
    }

    public function test_cancelar_no_quita_dias_cierra_pendientes_y_es_idempotente(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $sub = $this->subscribeTo('estudiante', now()->addDays(10));
        $pending = $this->pendingOrder($this->user, 'pro');
        Sanctum::actingAs($this->user);

        $this->postJson('/api/subscription/cancel')->assertOk()->assertJsonPath('subscription.cancelled', true);
        $this->postJson('/api/subscription/cancel')->assertOk();

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->cancelled_at);
        $this->assertEquals('2026-11-11', $sub->ends_at->toDateString()); // los días pagados quedan
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
        $this->assertSame('cancelled', $pending->fresh()->status);

        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('plan.slug', 'estudiante')
            ->assertJsonPath('subscription.cancelled', true);

        // Para volver a comprar rigen las mismas reglas: mismo plan bloqueado, subir a Pro permitido
        $this->tryCheckout('mercadopago', 'estudiante')->assertStatus(422);
        $this->tryCheckout('mercadopago', 'pro')->assertOk();
    }

    public function test_cancelar_sin_plan_pago_activo_da_error(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/subscription/cancel')->assertStatus(422);
    }

    public function test_si_cancelo_no_se_manda_el_aviso_de_vencimiento_y_al_vencer_pasa_a_free(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $sub = $this->subscribeTo('estudiante', now()->addDays(2));
        $sub->update(['cancelled_at' => now()]);

        $this->artisan('subscriptions:maintain')->assertSuccessful();
        Notification::assertNothingSentTo($this->user);
        $this->assertNull($sub->fresh()->reminded_at);

        $this->travelTo('2026-11-04 12:00:00');
        $this->artisan('subscriptions:maintain')->assertSuccessful();
        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertNull($this->user->currentSubscription());
    }

    public function test_un_pago_tardio_se_acredita_aunque_el_plan_este_activo_y_deja_de_estar_cancelado(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $sub = $this->subscribeTo('estudiante', now()->addDays(10));
        $sub->update(['cancelled_at' => now()]);
        $late = $this->pendingOrder($this->user);
        $late->update(['status' => 'cancelled']); // se cerró al cancelar, pero el pago entró
        $this->fakeMpPayment($this->mpPayment($late, ['id' => 777]));

        $this->webhook('777')->assertOk();

        $this->assertSame('paid', $late->fresh()->status);
        $sub->refresh();
        $this->assertEquals('2026-12-11', $sub->ends_at->toDateString()); // +1 mes sobre el vencimiento vigente
        $this->assertNull($sub->cancelled_at);

        // Y con un monto distinto, aunque el plan esté activo, no se acredita
        $other = $this->pendingOrder($this->user);
        $this->fakeMpPayment($this->mpPayment($other, ['id' => 778, 'transaction_amount' => 5]));
        $this->webhook('778')->assertOk();
        $this->assertSame('pending', $other->fresh()->status);
    }

    // ---------- Bajar de plan (queda encolado hasta el vencimiento) ----------

    private function downgradeOrder(string $provider = 'mercadopago', string $period = 'monthly'): Order
    {
        return Order::where('uuid', $this->tryCheckout($provider, 'estudiante', $period)->assertOk()->json('order'))->first();
    }

    public function test_bajar_de_plan_esta_bloqueado_con_mas_de_3_dias_y_dice_desde_cuando(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']); // vence el 1 de diciembre

        foreach (['mercadopago', 'paypal'] as $provider) {
            $this->tryCheckout($provider, 'estudiante')
                ->assertStatus(422)
                ->assertJsonPath('message', 'Tu plan Pro está activo hasta 1 de diciembre de 2026. Podés cambiarte a Estudiante desde el 28 de noviembre de 2026.');
        }
        $this->assertSame(0, Order::whereNotNull('starts_after_id')->count());
    }

    public function test_bajar_de_plan_se_puede_con_3_dias_o_menos_el_ultimo_dia_o_ya_vencido_y_se_paga_completo(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $pro = $this->subscribeWithOrders('pro', ['monthly']);

        foreach (['2026-11-28 12:00:00', '2026-12-01 09:00:00'] as $moment) { // faltan 3 días / es el último día
            $this->travelTo($moment);
            $order = $this->downgradeOrder('mercadopago');
            $this->assertEquals(12000, $order->amount, $moment);       // precio completo del plan menor
            $this->assertSame($pro->id, $order->starts_after_id);
            $this->assertEquals(9.0, $this->downgradeOrder('paypal')->amount, $moment);
        }

        $this->travelTo('2026-12-02 12:00:00'); // ya venció: es una compra común
        $order = $this->downgradeOrder('mercadopago');
        $this->assertNull($order->starts_after_id);
    }

    public function test_al_pagar_la_bajada_se_encola_y_hasta_el_vencimiento_sigue_el_plan_actual(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $pro = $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-29 12:00:00');
        $order = $this->downgradeOrder('mercadopago', 'quarterly');
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();
        $this->webhook()->assertOk();

        $queued = Subscription::where('status', 'scheduled')->first();
        $this->assertSame('estudiante', $queued->plan->slug);
        $this->assertEquals($pro->ends_at->toDateTimeString(), $queued->starts_at->toDateTimeString()); // empieza cuando vence Pro
        $this->assertEquals($pro->ends_at->copy()->addMonthsNoOverflow(3)->toDateTimeString(), $queued->ends_at->toDateTimeString());
        $this->assertSame('active', $pro->fresh()->status);                    // Pro sigue
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, Subscription::where('status', 'scheduled')->count());

        Sanctum::actingAs($this->user);
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('plan.slug', 'pro')
            ->assertJsonPath('scheduled.plan.slug', 'estudiante')
            ->assertJsonPath('scheduled.plan.name', 'Estudiante');
        $this->getJson("/api/orders/{$order->uuid}")->assertOk()->assertJsonPath('scheduled', true);
    }

    public function test_al_vencer_el_plan_actual_pasa_al_encolado_y_el_cron_lo_activa(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-30 12:00:00');
        $order = $this->downgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();

        $this->travelTo('2026-12-01 12:00:01'); // venció Pro: el cambio se nota enseguida, sin esperar al cron
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
        Sanctum::actingAs($this->user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('plan.slug', 'estudiante')->assertJsonPath('scheduled', null);

        $this->artisan('subscriptions:maintain')->assertSuccessful();
        $this->assertSame(0, Subscription::where('status', 'scheduled')->count());
        $this->assertSame('active', Subscription::where('plan_id', Plan::where('slug', 'estudiante')->value('id'))->value('status'));
        $this->assertSame('expired', Subscription::where('plan_id', Plan::where('slug', 'pro')->value('id'))->value('status'));
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
    }

    public function test_con_un_cambio_encolado_no_se_puede_comprar_otro_plan_hasta_que_empiece(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-30 12:00:00');
        $order = $this->downgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();

        $this->tryCheckout('mercadopago', 'estudiante')->assertStatus(422);
        $this->tryCheckout('paypal', 'pro')->assertStatus(422)
            ->assertJsonPath('message', 'Ya tenés programado el cambio a Estudiante desde el 1 de diciembre de 2026. Podés comprar de nuevo cuando empiece.');

        Sanctum::actingAs($this->user);
        $plans = collect($this->getJson('/api/checkout/status')->assertOk()->json('plans'))->keyBy('slug');
        $this->assertSame('scheduled', $plans['estudiante']['state']);
        $this->assertSame('blocked_scheduled', $plans['pro']['state']);
    }

    public function test_la_pantalla_de_planes_marca_la_bajada_como_disponible_solo_dentro_de_la_ventana(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']);
        Sanctum::actingAs($this->user);

        $state = fn () => collect($this->getJson('/api/checkout/status')->json('plans'))->firstWhere('slug', 'estudiante');
        $this->assertSame('blocked_lower', $state()['state']);
        $this->assertStringStartsWith('2026-11-28', $state()['renew_from']); // "Podés cambiarte desde el 28 de noviembre"

        $this->travelTo('2026-11-29 12:00:00');
        $this->assertSame('downgrade', $state()['state']);
    }

    public function test_un_pago_de_bajada_que_entra_cuando_el_plan_ya_vencio_empieza_hoy(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-30 12:00:00');
        $order = $this->downgradeOrder('mercadopago');
        $this->travelTo('2026-12-03 12:00:00'); // Pro venció antes de que entrara el pago
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $current = $this->user->currentSubscription();
        $this->assertSame('estudiante', $current->plan->slug);
        $this->assertEquals('2026-12-03', $current->starts_at->toDateString());
        $this->assertSame(0, Subscription::where('status', 'scheduled')->count());
    }

    public function test_si_se_devuelve_la_bajada_encolada_se_cancela_y_el_plan_actual_sigue(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $pro = $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-30 12:00:00');
        $order = $this->downgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();
        $this->fakeMpPayment($this->mpPayment($order, ['status' => 'refunded']));

        $this->webhook();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(0, Subscription::where('status', 'scheduled')->count());
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);
        $this->assertEquals($pro->ends_at->toDateTimeString(), $this->user->currentSubscription()->ends_at->toDateTimeString());
    }

    public function test_renovar_el_plan_actual_con_un_cambio_encolado_corre_el_inicio_del_encolado(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $pro = $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-30 12:00:00');
        $down = $this->downgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($down, ['id' => 1]));
        $this->webhook('1');

        // Una renovación de Pro que ya estaba iniciada (orden anterior) se acredita igual y el encolado la espera
        $renew = Order::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->user->id,
            'plan_id' => Plan::where('slug', 'pro')->value('id'), 'period' => 'monthly', 'months' => 1, 'provider' => 'mercadopago',
            'currency' => 'ARS', 'amount' => 39000, 'provider_order_id' => 'PREF']);
        $this->fakeMpPayment($this->mpPayment($renew, ['id' => 2]));
        $this->webhook('2');

        $queued = Subscription::where('status', 'scheduled')->first();
        $this->assertEquals($pro->fresh()->ends_at->toDateTimeString(), $queued->starts_at->toDateTimeString());
        $this->assertEquals('2027-01-01', $queued->starts_at->toDateString());
    }

    public function test_el_aviso_de_vencimiento_no_sale_si_ya_pago_el_plan_que_sigue(): void
    {
        $this->travelTo('2026-11-01 12:00:00');
        $this->subscribeWithOrders('pro', ['monthly']);
        $this->travelTo('2026-11-29 12:00:00');
        $order = $this->downgradeOrder('mercadopago');
        $this->fakeMpPayment($this->mpPayment($order));
        $this->webhook();

        $this->artisan('subscriptions:maintain')->assertSuccessful();

        Notification::assertNotSentTo($this->user, \App\Notifications\SubscriptionEndingEs::class);
    }

    // ---------- Mi cuenta: actualización después de pagar ----------

    public function test_la_sincronizacion_devuelve_el_usuario_actualizado_cuando_acredita(): void
    {
        $order = $this->pendingOrder($this->user);
        $this->fakeMpSearch([$order->uuid => [$this->mpPayment($order)]]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/orders/sync')->assertOk()
            ->assertJsonPath('credited', 1)
            ->assertJsonPath('pending', 0)
            ->assertJsonPath('user.plan.slug', 'estudiante')
            ->assertJsonPath('user.subscription.ends_at', Subscription::first()->ends_at->toIso8601String());
    }

    public function test_sin_ordenes_pendientes_la_sincronizacion_no_devuelve_nada_para_mostrar(): void
    {
        $this->fakeMpSearch([]);
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/orders/sync')->assertOk();
        $res->assertJsonPath('credited', 0)->assertJsonPath('pending', 0);
        $this->assertArrayNotHasKey('user', $res->json());
    }

    public function test_si_mercado_pago_todavia_no_confirma_avisa_que_hay_una_compra_pendiente_reciente(): void
    {
        $recent = $this->pendingOrder($this->user);
        $old = $this->pendingOrder($this->user);
        $old->forceFill(['created_at' => now()->subHours(5)])->save(); // vieja: no se vuelve a preguntar por ella
        $this->fakeMpSearch([$recent->uuid => [], $old->uuid => []]);
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 0)->assertJsonPath('pending', 1);
        $this->assertArrayNotHasKey('user', $res->json());

        // Pasados unos segundos Mercado Pago ya lo tiene: la segunda consulta lo acredita
        $this->fakeMpSearch([$recent->uuid => [$this->mpPayment($recent)], $old->uuid => []]);
        $this->postJson('/api/orders/sync')->assertOk()->assertJsonPath('credited', 1)->assertJsonPath('user.plan.slug', 'estudiante');
    }

    // ---------- PayPal ----------

    private function fakePayPal(array $extra = []): void
    {
        $this->resetHttp();
        Http::fake($extra + [
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PP-ORDER', 'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.test/approve']],
            ]),
        ]);
    }

    private function paypalCaptureResponse(Order $order, string $status = 'COMPLETED', ?float $amount = null): array
    {
        return ['id' => 'PP-ORDER', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
            'id' => 'CAP-1', 'status' => $status,
            'amount' => ['value' => number_format($amount ?? $order->amount, 2, '.', ''), 'currency_code' => 'USD'],
        ]]]]]];
    }

    public function test_paypal_crea_la_orden_en_dolares_y_captura_una_sola_vez(): void
    {
        $this->fakePayPal();
        $res = $this->startCheckout('paypal', 'yearly', 'pro');
        $this->assertSame('https://paypal.test/approve', $res['url']);
        $order = Order::where('uuid', $res['order'])->first();
        $this->assertSame('USD', $order->currency);
        $this->assertEquals(243.6, $order->amount); // 29 * 12 * 0.70

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/PP-ORDER/capture' => Http::response($this->paypalCaptureResponse($order)),
        ]);

        $this->postJson("/api/orders/{$order->uuid}/capture")->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson("/api/orders/{$order->uuid}/capture")->assertOk()->assertJsonPath('status', 'paid');

        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertEquals(now()->addMonthsNoOverflow(12)->toDateString(), Subscription::first()->ends_at->toDateString());
    }

    public function test_paypal_captura_con_monto_distinto_no_activa(): void
    {
        $this->fakePayPal();
        $order = Order::where('uuid', $this->startCheckout('paypal', 'monthly')['order'])->first();
        Http::fake(['api-m.sandbox.paypal.com/v2/checkout/orders/PP-ORDER/capture' => Http::response($this->paypalCaptureResponse($order, 'COMPLETED', 1.0))]);

        $this->postJson("/api/orders/{$order->uuid}/capture")->assertOk()->assertJsonPath('status', 'pending');
        $this->assertSame(0, Subscription::count());
    }

    public function test_paypal_orden_ya_capturada_se_recupera_sin_duplicar(): void
    {
        $this->fakePayPal();
        $order = Order::where('uuid', $this->startCheckout('paypal', 'monthly')['order'])->first();
        Http::fake([
            'api-m.sandbox.paypal.com/v2/checkout/orders/PP-ORDER/capture' => Http::response(['details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            'api-m.sandbox.paypal.com/v2/checkout/orders/PP-ORDER' => Http::response($this->paypalCaptureResponse($order)),
        ]);

        $this->postJson("/api/orders/{$order->uuid}/capture")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame(1, Subscription::count());
    }

    public function test_webhook_de_paypal_sin_firma_valida_se_rechaza(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $this->postJson('/api/webhooks/paypal', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])->assertStatus(401);
    }

    public function test_webhook_de_paypal_acredita_y_el_reembolso_lo_revoca(): void
    {
        $this->fakePayPal();
        $order = Order::where('uuid', $this->startCheckout('paypal', 'monthly')['order'])->first();

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);

        $completed = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP-9', 'status' => 'COMPLETED', 'custom_id' => $order->uuid,
            'amount' => ['value' => number_format($order->amount, 2, '.', ''), 'currency_code' => 'USD'],
        ]];
        $this->postJson('/api/webhooks/paypal', $completed)->assertOk();
        $this->postJson('/api/webhooks/paypal', $completed)->assertOk();
        $this->assertSame(1, Subscription::count());
        $this->assertSame('paid', $order->fresh()->status);

        $refund = ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => 'REF-1', 'links' => [['rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/CAP-9']],
        ]];
        $this->postJson('/api/webhooks/paypal', $refund)->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertNull($this->user->currentSubscription());
    }

    // ---------- Mantenimiento diario ----------

    public function test_el_mantenimiento_vence_avisa_y_cierra_ordenes(): void
    {
        $pro = Plan::where('slug', 'pro')->first();
        $expired = Subscription::create(['user_id' => $this->user->id, 'plan_id' => $pro->id, 'status' => 'active', 'provider' => 'mercadopago',
            'provider_subscription_id' => 'a', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

        $other = User::create(['name' => 'Beto', 'email' => 'beto@test.com', 'password' => 'password12']);
        $ending = Subscription::create(['user_id' => $other->id, 'plan_id' => $pro->id, 'status' => 'active', 'provider' => 'paypal',
            'provider_subscription_id' => 'b', 'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(2)]);

        $stale = Order::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => $pro->id,
            'period' => 'monthly', 'months' => 1, 'provider' => 'paypal', 'currency' => 'USD', 'amount' => 29]);
        $stale->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('subscriptions:maintain')->assertSuccessful();
        $this->artisan('subscriptions:maintain')->assertSuccessful(); // no repite el aviso

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('active', $ending->fresh()->status);
        $this->assertNotNull($ending->fresh()->reminded_at);
        Notification::assertSentToTimes($other, \App\Notifications\SubscriptionEndingEs::class, 1);
        $this->assertSame('expired', $stale->fresh()->status);
    }

    public function test_un_pago_tardio_de_una_orden_vencida_igual_se_acredita(): void
    {
        $this->mpPreference();
        $order = Order::where('uuid', $this->startCheckout('mercadopago', 'monthly')['order'])->first();
        $order->update(['status' => 'expired']);
        $this->fakeMpPayment($this->mpPayment($order));

        $this->webhook()->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Subscription::count());
    }
}
