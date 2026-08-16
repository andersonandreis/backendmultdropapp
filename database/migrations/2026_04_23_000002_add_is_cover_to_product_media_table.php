<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campo is_cover à tabela product_media.
     * A tabela product_media já existe e cobre imagens e vídeos (campo type).
     * Não foi criada tabela product_images separada para evitar duplicidade.
     */
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            if (! Schema::hasColumn('product_media', 'is_cover')) {
                $table->boolean('is_cover')->default(false)->after('position');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            if (Schema::hasColumn('product_media', 'is_cover')) {
                $table->dropColumn('is_cover');
            }
        });
    }
};
