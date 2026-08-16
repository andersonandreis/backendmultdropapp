<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FOR-027: Adiciona campos de chave PIX ao cadastro do fornecedor.
     */
    public function up(): void
    {
        Schema::table("suppliers", function (Blueprint $table) {
            $table->string("pix_key")->nullable()->after("allows_direct_deposit");
            $table->enum("pix_key_type", ["cpf", "cnpj", "email", "telefone", "aleatoria"])->nullable()->after("pix_key");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table("suppliers", function (Blueprint $table) {
            $table->dropColumn(["pix_key", "pix_key_type"]);
        });
    }
};
