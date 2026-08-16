<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            // Em vez de required() puro (que quebra se houver dados), vamos apenas adicionar a FK por enquanto para manter consistência referencial forte.
            $table->foreign('marketplace_account_id')
                ->references('id')
                ->on('marketplace_accounts')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropForeign(['marketplace_account_id']);
        });
    }
};
