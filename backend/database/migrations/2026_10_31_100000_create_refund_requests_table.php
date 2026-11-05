<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solicitudes de reembolso (botón de arrepentimiento). El reembolso lo hace el admin a mano.
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();          // ARR-000123
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');          // pending | approved | rejected
            $table->text('reason')->nullable();
            $table->string('phone', 40)->nullable();
            $table->unsignedTinyInteger('refund_percent')->default(100);
            $table->decimal('amount', 10, 2);                          // monto a devolver, en la moneda de la orden
            $table->char('currency', 3);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('receipt_path')->nullable();                // comprobante (disco privado)
            $table->string('receipt_name')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
