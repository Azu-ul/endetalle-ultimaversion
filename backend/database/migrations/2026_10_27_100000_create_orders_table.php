<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un intento de compra de un plan por un período. El monto sale siempre del servidor.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                        // external_reference / custom_id en los proveedores
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('period', 20);                          // monthly | quarterly | yearly
            $table->unsignedTinyInteger('months');
            $table->string('provider', 20);                        // mercadopago | paypal
            $table->char('currency', 3);                           // ARS | USD
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');      // pending | paid | refunded | expired | failed
            $table->string('provider_order_id')->nullable();       // preferencia de Mercado Pago / orden de PayPal
            $table->string('provider_payment_id')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('provider_order_id');
            $table->index('provider_payment_id');
        });

        // Para avisar una sola vez que el plan está por vencer
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
        Schema::dropIfExists('orders');
    }
};
