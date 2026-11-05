<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Descuento por los días que le quedaban del plan anterior (solo al subir de plan), en la moneda de la orden
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('credit', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('credit');
        });
    }
};
