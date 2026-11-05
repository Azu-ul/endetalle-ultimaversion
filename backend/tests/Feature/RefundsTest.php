<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\RefundApprovedEs;
use App\Notifications\RefundRejectedEs;
use App\Notifications\RefundRequestedEs;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RefundsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Notification::fake();
        Storage::fake('local');
        config(['mail.contact_to' => null]);

        $this->user = $this->makeUser('ana@test.com', 'Ana');
        $this->admin = $this->makeUser('admin@test.com', 'Admin');
        $this->admin->assignRole(Role::findOrCreate('superadmin', 'web'));
    }

    private function makeUser(string $email, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password12']);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Orden pagada hace $daysAgo días, con su plan activo desde ese día. */
    private function paidOrder(int $daysAgo, string $plan = 'estudiante', string $provider = 'mercadopago', ?User $user = null, int $months = 1): Order
    {
        $user ??= $this->user;
        $planModel = Plan::where('slug', $plan)->first();
        $paidAt = now()->subDays($daysAgo);
        $sub = Subscription::create([
            'user_id' => $user->id, 'plan_id' => $planModel->id, 'status' => 'active', 'provider' => $provider,
            'provider_subscription_id' => 'pay-' . uniqid(), 'starts_at' => $paidAt, 'ends_at' => $paidAt->copy()->addMonthsNoOverflow($months),
        ]);
        $currency = $provider === 'paypal' ? 'USD' : 'ARS';

        return Order::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'plan_id' => $planModel->id, 'period' => 'monthly', 'months' => $months,
            'provider' => $provider, 'currency' => $currency, 'amount' => $currency === 'ARS' ? $planModel->price_ars : $planModel->price_usd,
            'status' => 'paid', 'provider_payment_id' => 'PAY-' . uniqid(), 'subscription_id' => $sub->id, 'paid_at' => $paidAt,
        ]);
    }

    private function ask(Order $order, array $extra = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->user);

        return $this->postJson('/api/refunds', ['order_id' => $order->id] + $extra);
    }

    private function approve(RefundRequest $refund, ?UploadedFile $receipt = null, array $extra = [])
    {
        Sanctum::actingAs($this->admin);

        return $this->post("/api/admin/refunds/{$refund->id}/approve", $extra + ['receipt' => $receipt ?? UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf')], ['Accept' => 'application/json']);
    }

    // ---------- Plazo ----------

    public function test_dentro_de_los_15_dias_se_puede_pedir_el_dia_1_y_el_dia_15(): void
    {
        foreach ([0, 1, 15] as $days) {
            $order = $this->paidOrder($days);
            $res = $this->ask($order, ['reason' => 'Me arrepentí', 'phone' => '+54 9 223 555-1234'])->assertCreated();

            $this->assertMatchesRegularExpression('/^ARR-\d{6}$/', $res->json('code'), "día {$days}");
            $this->assertSame('pending', $res->json('status'));
        }
        $this->assertSame(3, RefundRequest::count());
    }

    public function test_el_dia_16_esta_fuera_de_plazo_y_dice_cuando_vencio(): void
    {
        $this->travelTo('2026-11-30 12:00:00');
        $order = $this->paidOrder(16); // pagada el 14 de noviembre: el plazo terminó el 29

        $this->ask($order)->assertStatus(422)
            ->assertJsonPath('errors.order_id.0', 'El plazo de reembolso venció el 29 de noviembre de 2026.');
        $this->assertSame(0, RefundRequest::count());
    }

    public function test_el_plazo_sale_de_config_billing(): void
    {
        config(['billing.refund_window_days' => 30]);
        $this->ask($this->paidOrder(30))->assertCreated();
        $this->ask($this->paidOrder(31))->assertStatus(422);
    }

    public function test_una_orden_ya_reembolsada_o_ya_pedida_no_se_puede_pedir_de_nuevo(): void
    {
        $refunded = $this->paidOrder(2);
        $refunded->update(['status' => 'refunded']);
        $this->ask($refunded)->assertStatus(422)->assertJsonPath('errors.order_id.0', 'Esta compra ya fue reembolsada.');

        $order = $this->paidOrder(2);
        $code = $this->ask($order)->assertCreated()->json('code');
        $this->ask($order)->assertStatus(422)->assertJsonPath('errors.order_id.0', "Esta compra ya tiene una solicitud de reembolso ({$code}).");

    }

    public function test_tras_un_rechazo_no_se_puede_volver_a_pedir_y_se_consulta_por_contacto(): void
    {
        $order = $this->paidOrder(2);
        $code = $this->ask($order)->assertCreated()->json('code');
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/refunds/' . RefundRequest::first()->id . '/reject', ['reason' => 'Ya usó los archivos'])->assertOk();

        // Una sola solicitud por compra: sigue dentro de plazo, pero no se puede pedir de nuevo
        $this->ask($order)->assertStatus(422)
            ->assertJsonPath('errors.order_id.0', "Solicitud rechazada ({$code}). Si querés volver a consultarlo, escribinos desde Contacto");
        $this->assertSame(1, RefundRequest::count());

        $this->getJson('/api/user')->assertJsonPath('refund_eligible', false); // tampoco aparece el botón en Mi Suscripción
    }

    public function test_el_formulario_muestra_pendiente_rechazada_y_reembolsada(): void
    {
        $pending = $this->paidOrder(2);
        $rejected = $this->paidOrder(3);
        $approved = $this->paidOrder(4);
        $untouched = $this->paidOrder(5);
        $this->ask($pending)->assertCreated();
        $this->ask($rejected)->assertCreated();
        $this->ask($approved)->assertCreated();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/refunds/' . RefundRequest::where('order_id', $rejected->id)->value('id') . '/reject', ['reason' => 'Fuera de política'])->assertOk();
        $this->approve(RefundRequest::where('order_id', $approved->id)->first())->assertOk();

        Sanctum::actingAs($this->user);
        $orders = collect($this->getJson('/api/refunds')->assertOk()->json('orders'))->keyBy('id');

        $code = fn (Order $o) => RefundRequest::where('order_id', $o->id)->value('code');
        // Pendiente
        $this->assertSame('pending', $orders[$pending->id]['refund']['status']);
        $this->assertFalse($orders[$pending->id]['can_request']);
        $this->assertSame('Esta compra ya tiene una solicitud de reembolso (' . $code($pending) . ').', $orders[$pending->id]['message']);
        // Rechazada: deshabilitada, con el texto y el motivo del rechazo
        $this->assertSame('rejected', $orders[$rejected->id]['refund']['status']);
        $this->assertSame('Fuera de política', $orders[$rejected->id]['refund']['rejection_reason']);
        $this->assertFalse($orders[$rejected->id]['can_request']);
        $this->assertTrue($orders[$rejected->id]['within_window']);
        $this->assertSame('Solicitud rechazada (' . $code($rejected) . '). Si querés volver a consultarlo, escribinos desde Contacto', $orders[$rejected->id]['message']);
        // Reembolsada
        $this->assertSame('refunded', $orders[$approved->id]['order_status']);
        $this->assertSame('approved', $orders[$approved->id]['refund']['status']);
        $this->assertFalse($orders[$approved->id]['can_request']);
        // Sin solicitud: se puede pedir
        $this->assertNull($orders[$untouched->id]['refund']);
        $this->assertTrue($orders[$untouched->id]['can_request']);
    }

    public function test_no_se_puede_pedir_el_reembolso_de_la_compra_de_otro(): void
    {
        $other = $this->makeUser('beto@test.com', 'Beto');
        $order = $this->paidOrder(2, 'estudiante', 'mercadopago', $other);

        $this->ask($order)->assertNotFound();
    }

    public function test_la_solicitud_crea_un_pendiente_manda_el_email_y_no_corta_el_plan(): void
    {
        $order = $this->paidOrder(3);
        $res = $this->ask($order, ['reason' => 'No lo uso', 'phone' => '2235551234'])->assertCreated();

        $refund = RefundRequest::first();
        $this->assertSame($res->json('code'), $refund->code);
        $this->assertSame('pending', $refund->status);
        $this->assertSame('No lo uso', $refund->reason);
        $this->assertSame('2235551234', $refund->phone);
        $this->assertEquals($order->amount, $refund->amount); // 100 %
        Notification::assertSentTo($this->user, RefundRequestedEs::class, function ($n) use ($refund) {
            $mail = $n->toMail($this->user);

            return str_contains($mail->subject, $refund->code) && str_contains(implode(' ', $mail->introLines), $refund->code);
        });
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug); // el plan sigue
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_telefono_y_motivo_son_opcionales_pero_el_telefono_se_valida(): void
    {
        $this->ask($this->paidOrder(1), ['phone' => 'abc'])->assertStatus(422);
        $this->ask($this->paidOrder(1))->assertCreated();
    }

    public function test_el_usuario_ve_sus_compras_con_si_se_pueden_reembolsar(): void
    {
        $this->travelTo('2026-11-30 12:00:00');
        $recent = $this->paidOrder(2);
        $old = $this->paidOrder(20);
        Sanctum::actingAs($this->user);

        $res = $this->getJson('/api/refunds')->assertOk()->assertJsonPath('window_days', 15);
        $orders = collect($res->json('orders'))->keyBy('id');
        $this->assertTrue($orders[$recent->id]['can_request']);
        $this->assertFalse($orders[$old->id]['can_request']);
        $this->assertStringContainsString('venció el', $orders[$old->id]['message']);

        $this->getJson('/api/user')->assertJsonPath('refund_eligible', true);
        $recent->update(['status' => 'refunded']);
        $this->getJson('/api/user')->assertJsonPath('refund_eligible', false);
    }

    // ---------- Aprobar y rechazar ----------

    public function test_aprobar_marca_la_orden_corta_el_plan_cancela_lo_pendiente_y_manda_el_comprobante(): void
    {
        $order = $this->paidOrder(5, 'pro', 'paypal');
        $refund = RefundRequest::findOrFail($this->ask($order, ['phone' => '2235551234'])->json('id') ?? RefundRequest::first()->id);
        // Un plan menor encolado y una compra sin pagar: también se cancelan
        Subscription::create(['user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'estudiante')->value('id'), 'status' => 'scheduled',
            'provider' => 'paypal', 'provider_subscription_id' => 'q', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonths(2)]);
        $pending = Order::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => $order->plan_id,
            'period' => 'monthly', 'months' => 1, 'provider' => 'paypal', 'currency' => 'USD', 'amount' => 29]);

        $res = $this->approve($refund, null, ['note' => 'Reembolsado por PayPal el mismo día'])->assertOk();

        $res->assertJsonPath('status', 'approved')->assertJsonPath('reviewed_by', 'Admin')->assertJsonPath('has_receipt', true);
        $refund->refresh();
        $this->assertSame($this->admin->id, $refund->reviewed_by);
        $this->assertNotNull($refund->reviewed_at);
        $this->assertSame('Reembolsado por PayPal el mismo día', $refund->admin_note);
        Storage::disk('local')->assertExists($refund->receipt_path);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertNull($this->user->currentSubscription());   // vuelve a Free
        $this->assertSame(0, Subscription::whereIn('status', ['active', 'scheduled'])->count());
        $this->assertSame('cancelled', $pending->fresh()->status);

        Notification::assertSentTo($this->user, RefundApprovedEs::class, function ($n) {
            $mail = $n->toMail($this->user);

            // El archivo se lee del disco privado y viaja adjunto en el correo
            return count($mail->rawAttachments) === 1 && $mail->rawAttachments[0]['name'] === 'comprobante.pdf';
        });
    }

    public function test_un_reembolso_de_la_subida_a_pro_deja_al_usuario_en_free(): void
    {
        // Estudiante pagado hace 20 días (fuera de plazo) y subida a Pro pagada hace 2 días
        $estudiante = $this->paidOrder(20);
        $upgrade = $this->paidOrder(2, 'pro', 'mercadopago');
        $upgrade->update(['upgrade_from_id' => $estudiante->subscription_id]);
        Subscription::whereKey($estudiante->subscription_id)->update(['status' => 'cancelled']);
        $this->assertSame('pro', $this->user->currentSubscription()->plan->slug);

        $this->ask($estudiante)->assertStatus(422); // el Estudiante ya no se puede
        $this->ask($upgrade)->assertCreated();
        $this->approve(RefundRequest::first())->assertOk();

        $this->assertNull($this->user->currentSubscription());
        $this->assertSame('refunded', $upgrade->fresh()->status);
        $this->assertSame('paid', $estudiante->fresh()->status); // cada orden se evalúa por separado
    }

    public function test_rechazar_pide_motivo_no_corta_el_plan_y_avisa_al_usuario(): void
    {
        $order = $this->paidOrder(5);
        $this->ask($order)->assertCreated();
        $refund = RefundRequest::first();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/refunds/{$refund->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => 'Ya descargó los archivos'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('reviewed_by', 'Admin');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);
        $this->assertSame('Ya descargó los archivos', $refund->fresh()->rejection_reason);
        Notification::assertSentTo($this->user, RefundRejectedEs::class, fn ($n) => str_contains(implode(' ', $n->toMail($this->user)->introLines), 'Ya descargó los archivos'));
        Notification::assertNotSentTo($this->user, RefundApprovedEs::class);
    }

    public function test_una_solicitud_resuelta_no_se_puede_aprobar_ni_rechazar_de_nuevo(): void
    {
        $this->ask($this->paidOrder(5))->assertCreated();
        $refund = RefundRequest::first();
        $this->approve($refund)->assertOk();

        $this->approve($refund)->assertStatus(422);
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => 'tarde'])->assertStatus(422);
    }

    public function test_si_el_proveedor_ya_devolvio_la_orden_se_puede_aprobar_igual_sin_tocarla_dos_veces(): void
    {
        $order = $this->paidOrder(5);
        $this->ask($order)->assertCreated();
        $order->update(['status' => 'refunded']); // un aviso del proveedor llegó antes

        $this->approve(RefundRequest::first())->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('approved', RefundRequest::first()->status);
    }

    // ---------- Comprobante ----------

    public function test_aprobar_exige_un_comprobante_pdf_o_imagen_de_hasta_5_mb(): void
    {
        $this->ask($this->paidOrder(5))->assertCreated();
        $refund = RefundRequest::first();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/refunds/{$refund->id}/approve", [])->assertStatus(422)->assertJsonValidationErrors('receipt');
        foreach ([
            UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('notas.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('enorme.pdf', 6000, 'application/pdf'),
        ] as $bad) {
            $this->post("/api/admin/refunds/{$refund->id}/approve", ['receipt' => $bad], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('receipt');
        }
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertSame('estudiante', $this->user->currentSubscription()->plan->slug);

        // Todos los formatos válidos
        foreach (['comprobante.pdf' => 'application/pdf', 'foto.jpg' => 'image/jpeg', 'foto.png' => 'image/png', 'foto.webp' => 'image/webp'] as $name => $mime) {
            RefundRequest::query()->update(['status' => 'pending']);
            $this->post("/api/admin/refunds/{$refund->id}/approve", ['receipt' => UploadedFile::fake()->create($name, 100, $mime)], ['Accept' => 'application/json'])
                ->assertOk();
        }
    }

    public function test_el_comprobante_se_descarga_solo_desde_el_admin(): void
    {
        $this->ask($this->paidOrder(5))->assertCreated();
        $refund = RefundRequest::first();
        $this->approve($refund)->assertOk();
        $this->assertStringNotContainsString('public', $refund->fresh()->receipt_path);

        Sanctum::actingAs($this->user);
        $this->get("/api/admin/refunds/{$refund->id}/receipt", ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->get("/api/admin/refunds/{$refund->id}/receipt")->assertOk()->assertHeader('content-disposition');
    }

    // ---------- Permisos y listados ----------

    public function test_solo_el_admin_puede_ver_y_operar_reembolsos_y_ordenes(): void
    {
        $this->ask($this->paidOrder(5))->assertCreated();
        $id = RefundRequest::first()->id;
        $colaborador = $this->makeUser('colab@test.com', 'Colab');
        $colaborador->assignRole(Role::findOrCreate('colaborador', 'web'));

        $endpoints = [
            ['getJson', '/api/admin/refunds'], ['getJson', "/api/admin/refunds/{$id}"], ['getJson', '/api/admin/orders'],
            ['postJson', "/api/admin/refunds/{$id}/reject"], ['postJson', "/api/admin/refunds/{$id}/approve"],
        ];

        foreach ($endpoints as [$method, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$method}($url)->assertUnauthorized();
            foreach ([$this->user, $colaborador] as $notAdmin) {
                Sanctum::actingAs($notAdmin);
                $this->{$method}($url, $method === 'postJson' ? ['reason' => 'x'] : [])->assertForbidden();
            }
        }
        $this->assertSame('pending', RefundRequest::first()->status);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/refunds')->assertOk();
        $this->getJson('/api/admin/orders')->assertOk();
    }

    public function test_el_admin_ve_el_listado_filtrable_y_el_detalle_completo(): void
    {
        $this->travelTo('2026-11-30 12:00:00');
        $paypal = $this->paidOrder(10, 'pro', 'paypal');
        $this->ask($paypal, ['phone' => '+54 9 223 555-1234', 'reason' => 'Cambié de idea'])->assertCreated();
        $mp = $this->paidOrder(1);
        $this->ask($mp)->assertCreated();
        $second = RefundRequest::latest('id')->first();
        $second->update(['status' => 'rejected']);
        Sanctum::actingAs($this->admin);

        $all = $this->getJson('/api/admin/refunds')->assertOk()->assertJsonPath('pending_count', 1);
        $this->assertCount(2, $all->json('requests'));
        $this->assertCount(1, $this->getJson('/api/admin/refunds?status=pending')->json('requests'));
        $this->assertCount(1, $this->getJson('/api/admin/refunds?status=rejected')->json('requests'));
        $this->assertCount(0, $this->getJson('/api/admin/refunds?status=approved')->json('requests'));

        $detail = RefundRequest::where('order_id', $paypal->id)->first();
        $this->getJson("/api/admin/refunds/{$detail->id}")->assertOk()
            ->assertJsonPath('code', $detail->code)
            ->assertJsonPath('name', 'Ana')->assertJsonPath('email', 'ana@test.com')
            ->assertJsonPath('phone', '+54 9 223 555-1234')
            ->assertJsonPath('plan', 'Pro')->assertJsonPath('period', 'monthly')
            ->assertJsonPath('currency', 'USD')->assertJsonPath('provider', 'paypal')
            ->assertJsonPath('provider_payment_id', $paypal->provider_payment_id)
            ->assertJsonPath('days_since_payment', 10)->assertJsonPath('within_window', true)
            ->assertJsonPath('reason', 'Cambié de idea');
    }

    public function test_el_listado_de_ordenes_y_pagos_tiene_los_datos_y_filtros(): void
    {
        $this->paidOrder(1, 'estudiante', 'mercadopago');
        $this->paidOrder(1, 'pro', 'paypal');
        Order::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'plan_id' => Plan::where('slug', 'pro')->value('id'),
            'period' => 'yearly', 'months' => 12, 'provider' => 'paypal', 'currency' => 'USD', 'amount' => 243.6]);
        Sanctum::actingAs($this->admin);

        $all = $this->getJson('/api/admin/orders')->assertOk();
        $this->assertCount(3, $all->json());
        $first = collect($all->json())->firstWhere('provider', 'mercadopago');
        $this->assertSame(['id', 'user', 'plan', 'period', 'months', 'amount', 'currency', 'status', 'provider', 'provider_payment_id', 'kind', 'paid_at', 'created_at'], array_keys($first));
        $this->assertSame('ana@test.com', $first['user']['email']);

        $this->assertCount(2, $this->getJson('/api/admin/orders?status=paid')->json());
        $this->assertCount(1, $this->getJson('/api/admin/orders?status=pending')->json());
        $this->assertCount(2, $this->getJson('/api/admin/orders?provider=paypal')->json());
        $this->assertCount(3, $this->getJson('/api/admin/orders?q=ana@test')->json());
        $this->assertCount(0, $this->getJson('/api/admin/orders?q=nadie')->json());
    }

    public function test_un_reembolso_por_webhook_sigue_quitando_los_meses_de_esa_orden(): void
    {
        config(['services.mercadopago.access_token' => 'TEST-token']);
        $order = $this->paidOrder(3);
        $before = $order->subscription->ends_at->copy();
        $order->update(['provider_payment_id' => '555']);
        \Illuminate\Support\Facades\Http::fake(['api.mercadopago.com/v1/payments/*' => \Illuminate\Support\Facades\Http::response([
            'id' => 555, 'status' => 'refunded', 'external_reference' => $order->uuid, 'transaction_amount' => $order->amount, 'currency_id' => 'ARS',
        ])]);

        $this->postJson('/api/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '555']])->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertNull($this->user->currentSubscription());
        $this->assertTrue($before->greaterThan(now()));
    }
}
