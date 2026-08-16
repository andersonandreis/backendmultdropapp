<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MUL-269: campos do contato Bling v3 que faltavam em clients
        // (indicadorIe, inscricaoMunicipal, emailNotaFiscal).
        if (! Schema::hasTable('clients')) {
            return;
        }
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'ie_indicator')) {
                $table->unsignedTinyInteger('ie_indicator')->nullable()
                    ->comment('Bling indicadorIe: 1=contribuinte, 2=isento, 9=nao contribuinte');
            }
            if (! Schema::hasColumn('clients', 'municipal_registration')) {
                $table->string('municipal_registration', 30)->nullable();
            }
            if (! Schema::hasColumn('clients', 'nfe_email')) {
                $table->string('nfe_email')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }
        Schema::table('clients', function (Blueprint $table) {
            foreach (['ie_indicator', 'municipal_registration', 'nfe_email'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
