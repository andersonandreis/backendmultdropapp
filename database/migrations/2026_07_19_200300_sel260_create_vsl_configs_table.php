<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-260 — Tabela de VSLs gerenciáveis pelo admin.
 *
 * Substitui o vslPool.ts hardcoded no frontend.
 * menu_slug determina onde a VSL aparece: tiktok_shopping (aba), dropshipping
 * (modal onboarding/login) ou landing (páginas de entrada).
 * Ruan faz upload no Bunny.net e cola video_url aqui via /admin/vsl.
 * thumbnail_url é opcional (preview no painel admin).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vsl_configs')) {
            return;
        }

        Schema::create('vsl_configs', function (Blueprint $t) {
            $t->id();
            $t->enum('menu_slug', ['tiktok_shopping', 'dropshipping', 'landing']);
            $t->text('video_url');
            $t->text('thumbnail_url')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['menu_slug', 'active', 'sort_order']);
            $t->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vsl_configs');
    }
};
