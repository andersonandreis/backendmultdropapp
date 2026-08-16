<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MUL-269: User = pessoa fisica (dados pessoais); Client = entidade
        // vendedora. Guards hasColumn porque o repo e compartilhado por 7
        // backends com schemas de users divergentes.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'cpf')) {
                $table->string('cpf', 11)->nullable();
            }
            if (! Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->nullable();
            }
            if (! Schema::hasColumn('users', 'birth_date')) {
                $table->date('birth_date')->nullable();
            }
            if (! Schema::hasColumn('users', 'rg')) {
                $table->string('rg', 30)->nullable();
            }
            if (! Schema::hasColumn('users', 'rg_issuer')) {
                $table->string('rg_issuer', 20)->nullable();
            }
            if (! Schema::hasColumn('users', 'mother_name')) {
                $table->string('mother_name')->nullable();
            }
            if (! Schema::hasColumn('users', 'father_name')) {
                $table->string('father_name')->nullable();
            }
            if (! Schema::hasColumn('users', 'rg_front_file')) {
                $table->string('rg_front_file')->nullable();
            }
            if (! Schema::hasColumn('users', 'rg_back_file')) {
                $table->string('rg_back_file')->nullable();
            }
            if (! Schema::hasColumn('users', 'residence_proof_file')) {
                $table->string('residence_proof_file')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['cpf', 'full_name', 'birth_date', 'rg', 'rg_issuer',
                'mother_name', 'father_name', 'rg_front_file', 'rg_back_file',
                'residence_proof_file'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
