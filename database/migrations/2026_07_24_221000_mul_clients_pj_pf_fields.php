<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/**
 * MUL: enriquece clients com dados PF (responsavel) + endereco + PJ (razao social/IE — nullable).
 * Idempotente, aplica nos 7 backends.
 */
return new class extends Migration {
    public function up(): void {
        Schema::table('clients', function (Blueprint $t) {
            // Tipo pessoa (PF ou PJ) — derivado do document (11=PF, 14=PJ)
            if (!Schema::hasColumn('clients','person_type')) $t->enum('person_type', ['PF','PJ'])->nullable();
            // PJ
            if (!Schema::hasColumn('clients','legal_name')) $t->string('legal_name', 255)->nullable(); // razao social
            if (!Schema::hasColumn('clients','trade_name')) $t->string('trade_name', 255)->nullable(); // nome fantasia
            if (!Schema::hasColumn('clients','state_registration')) $t->string('state_registration', 30)->nullable(); // IE
            // Dados do responsavel (obrigatorio pra PJ, e o proprio dono pra PF)
            if (!Schema::hasColumn('clients','full_name')) $t->string('full_name', 255)->nullable();
            if (!Schema::hasColumn('clients','birth_date')) $t->date('birth_date')->nullable();
            if (!Schema::hasColumn('clients','mother_name')) $t->string('mother_name', 255)->nullable();
            if (!Schema::hasColumn('clients','father_name')) $t->string('father_name', 255)->nullable();
            if (!Schema::hasColumn('clients','rg')) $t->string('rg', 30)->nullable();
            // Endereco (do responsavel — se PJ precisar de endereco PJ separado, ver Fase 3)
            if (!Schema::hasColumn('clients','address_cep')) $t->string('address_cep', 10)->nullable();
            if (!Schema::hasColumn('clients','address_street')) $t->string('address_street', 255)->nullable();
            if (!Schema::hasColumn('clients','address_number')) $t->string('address_number', 20)->nullable();
            if (!Schema::hasColumn('clients','address_complement')) $t->string('address_complement', 100)->nullable();
            if (!Schema::hasColumn('clients','address_neighborhood')) $t->string('address_neighborhood', 100)->nullable();
            if (!Schema::hasColumn('clients','address_city')) $t->string('address_city', 100)->nullable();
            if (!Schema::hasColumn('clients','address_state')) $t->string('address_state', 2)->nullable();
        });
    }
    public function down(): void {}
};
