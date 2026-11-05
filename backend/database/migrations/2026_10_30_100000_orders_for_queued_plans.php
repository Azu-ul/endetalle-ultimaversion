<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bajar de plan: el plan menor se paga completo y empieza cuando vence la suscripción indicada
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('starts_after_id')->nullable()->constrained('subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('starts_after_id');
        });
    }
};
