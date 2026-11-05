<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS 2.0: Me gusta / No me gusta, detalles públicos, programación (published_at a futuro),
 * avisos a usuarios, visitas (online y estadísticas), carrusel de avisos de la portada
 * e imágenes protegidas (original y vista previa en disco privado).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Me gusta / No me gusta ----------
        Schema::create('detail_reactions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('detail_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('value');                       // 1 = me gusta, -1 = no me gusta
            $table->timestamps();
            $table->primary(['user_id', 'detail_id']);
            $table->index(['detail_id', 'value']);
        });

        Schema::table('details', function (Blueprint $table) {
            $table->unsignedInteger('dislikes_count')->default(0);
            $table->boolean('is_public')->default(false);        // lo ven también los visitantes sin cuenta
            $table->string('cover_path')->nullable();             // original (JPG, hasta 2 MB) en disco privado
            $table->string('cover_preview_path')->nullable();     // vista previa liviana en disco privado
        });

        Schema::table('detail_images', function (Blueprint $table) {
            $table->string('path')->nullable();
            $table->string('preview_path')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
        });

        // Los votos que ya existían pasan a ser "me gusta" (votes_count queda como contador de "me gusta")
        DB::table('detail_votes')->orderBy('user_id')->orderBy('detail_id')->each(function ($vote) {
            DB::table('detail_reactions')->insertOrIgnore([
                'user_id' => $vote->user_id,
                'detail_id' => $vote->detail_id,
                'value' => 1,
                'created_at' => $vote->created_at,
                'updated_at' => $vote->updated_at,
            ]);
        });

        // ---------- Avisos del administrador ----------
        Schema::create('admin_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('link', 500)->nullable();
            $table->string('kind', 20)->default('info');          // info | success | warning
            $table->string('audience', 20);                       // all | free | estudiante | pro | colaboradores | user
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();
        });

        Schema::create('user_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('admin_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'read_at']);
            $table->unique(['notice_id', 'user_id']);
        });

        // ---------- Visitas ----------
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();                // identificador anónimo guardado en el navegador
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
        });

        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('path', 255);
            $table->unsignedBigInteger('detail_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['detail_id', 'created_at']);
        });

        // ---------- Carrusel de la portada ----------
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('badge', 60)->nullable();              // texto chico amarillo de arriba
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->string('button_text', 60)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('image_path')->nullable();             // archivo subido (disco público)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('page_views');
        Schema::dropIfExists('visitors');
        Schema::dropIfExists('user_notices');
        Schema::dropIfExists('admin_notices');

        Schema::table('detail_images', function (Blueprint $table) {
            $table->dropColumn(['path', 'preview_path', 'size_bytes']);
        });
        Schema::table('details', function (Blueprint $table) {
            $table->dropColumn(['dislikes_count', 'is_public', 'cover_path', 'cover_preview_path']);
        });
        Schema::dropIfExists('detail_reactions');
    }
};
