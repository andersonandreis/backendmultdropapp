<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FOR-080: Armazena o family_name do catalogo ML para bloquear edicao do titulo no frontend.
     * Anuncios com ml_family_name preenchido pertencem a um catalogo ML e nao
     * permitem edicao direta do titulo.
     */
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->string('ml_family_name', 255)->nullable()->after('image_url')
                  ->comment('FOR-080: family_name do catalogo ML. Preenchido = titulo nao editavel.');
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn('ml_family_name');
        });
    }
};
