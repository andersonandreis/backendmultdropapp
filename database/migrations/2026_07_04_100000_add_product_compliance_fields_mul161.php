<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-161 Item 20: Adiciona campos de conformidade/fabricante em products.
 * ADITIVA: nao remove ou altera colunas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'inmetro')) {
                $table->string('inmetro', 100)->nullable()->after('ean')
                    ->comment('Numero de certificado INMETRO do produto');
            }
            if (! Schema::hasColumn('products', 'homologation_number')) {
                $table->string('homologation_number', 100)->nullable()->after('inmetro')
                    ->comment('Numero de homologacao (ANATEL, ANVISA, etc.)');
            }
            if (! Schema::hasColumn('products', 'manufacturer')) {
                $table->string('manufacturer', 255)->nullable()->after('homologation_number')
                    ->comment('Nome do fabricante do produto');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['inmetro', 'homologation_number', 'manufacturer']);
        });
    }
};
