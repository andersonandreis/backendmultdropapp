<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: coluna pode ja existir se 2026_04_30_010000 foi rodada antes
        if (Schema::hasColumn("marketplace_accounts", "ai_instructions")) {
            return;
        }
        Schema::table("marketplace_accounts", function (Blueprint $table) {
            $table->text("ai_instructions")->nullable()->after("import_mode")
                ->comment("Instrucoes personalizadas para IA ao gerar titulos/descricoes desta loja");
        });
    }

    public function down(): void
    {
        Schema::table("marketplace_accounts", function (Blueprint $table) {
            if (Schema::hasColumn("marketplace_accounts", "ai_instructions")) {
                $table->dropColumn("ai_instructions");
            }
        });
    }
};
