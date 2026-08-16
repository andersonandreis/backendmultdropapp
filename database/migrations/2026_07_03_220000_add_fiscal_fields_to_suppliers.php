<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-142-E #19 — Dados fiscais do fornecedor para cadastro de produto no Bling.
 * Adiciona ie, indicator_icms, trade_name, address_number, address_complement, neighborhood.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'ie')) {
                $table->string('ie', 20)->nullable()->after('document')
                      ->comment('Inscrição Estadual — obrigatório para emissão NF-e no Bling');
            }
            if (! Schema::hasColumn('suppliers', 'indicator_icms')) {
                $table->tinyInteger('indicator_icms')->default(1)->after('ie')
                      ->comment('Indicador ICMS: 1=Contribuinte, 2=Isento, 9=Não contribuinte');
            }
            if (! Schema::hasColumn('suppliers', 'trade_name')) {
                $table->string('trade_name', 255)->nullable()->after('company_name')
                      ->comment('Nome fantasia do fornecedor');
            }
            if (! Schema::hasColumn('suppliers', 'address_number')) {
                $table->string('address_number', 20)->nullable()->after('address')
                      ->comment('Número do endereço');
            }
            if (! Schema::hasColumn('suppliers', 'address_complement')) {
                $table->string('address_complement', 100)->nullable()->after('address_number')
                      ->comment('Complemento do endereço');
            }
            if (! Schema::hasColumn('suppliers', 'neighborhood')) {
                $table->string('neighborhood', 100)->nullable()->after('address_complement')
                      ->comment('Bairro');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['ie', 'indicator_icms', 'trade_name', 'address_number', 'address_complement', 'neighborhood'] as $col) {
                if (Schema::hasColumn('suppliers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
