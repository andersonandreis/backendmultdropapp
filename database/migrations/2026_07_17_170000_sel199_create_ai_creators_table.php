<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-199 — tabela ai_creators
 *
 * Separada de tiktok_shop_trends (criadores TT reais).
 * Armazena criadores IA fake: importados do Tokfy + scrapeados por hashtag IA + manuais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_creators', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('name');
            $table->string('avatar_url')->nullable();
            $table->text('bio')->nullable();
            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedInteger('videos_count')->default(0);
            $table->decimal('estimated_revenue', 12, 2)->default(0);
            $table->unsignedInteger('rank_position')->nullable()->index();
            $table->enum('source', ['tokfy', 'scrape', 'manual'])->default('manual')->index();
            $table->json('raw')->nullable();
            $table->boolean('is_visible')->default(true)->index();
            $table->boolean('is_approved')->default(true)->index();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_creators');
    }
};
