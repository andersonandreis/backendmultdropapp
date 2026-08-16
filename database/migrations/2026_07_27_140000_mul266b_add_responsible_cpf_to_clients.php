<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Cadastro fiscal BR: empresa (document=CNPJ) + pessoa fisica
            // responsavel (responsible_cpf) sao documentos distintos e coexistem.
            $table->string('responsible_cpf', 14)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('responsible_cpf');
        });
    }
};
