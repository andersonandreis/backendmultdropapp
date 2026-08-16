<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-135: Adiciona content_hash em product_media para dedup nativo por conteúdo.
 * A coluna é nullable (hash preenchido no insert quando disponível).
 * O índice único (product_id, content_hash) previne inserção de imagem duplicada por produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table("product_media", function (Blueprint $table) {
            $table->string("content_hash", 32)->nullable()->after("url")
                ->comment("MD5 do conteúdo do arquivo — dedup nativo por produto (MUL-135)");
            $table->unique(["product_id", "content_hash"], "product_media_product_content_hash_unique");
        });
    }

    public function down(): void
    {
        Schema::table("product_media", function (Blueprint $table) {
            $table->dropUnique("product_media_product_content_hash_unique");
            $table->dropColumn("content_hash");
        });
    }
};
