<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Subir de plan: se paga la diferencia de precio y el plan nuevo conserva el vencimiento del anterior
            $table->foreignId('upgrade_from_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->timestamp('ends_at')->nullable(); // vencimiento que conserva el plan nuevo
            $table->dropColumn('credit');             // ya no hay descuento por días
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('credit', 10, 2)->default(0);
            $table->dropConstrainedForeignId('upgrade_from_id');
            $table->dropColumn('ends_at');
        });
    }
};
