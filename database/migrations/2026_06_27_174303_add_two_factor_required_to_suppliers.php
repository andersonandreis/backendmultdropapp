<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-147 — 2FA obrigatorio por whitelabel.
 *
 * Quando true, todos os usuarios desse supplier devem ter 2FA ativo pra logar.
 * Frontend (Prism) cria a UI; middleware adicionado depois em sprint separado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('two_factor_required')->default(false)->after('is_private');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('two_factor_required');
        });
    }
};
