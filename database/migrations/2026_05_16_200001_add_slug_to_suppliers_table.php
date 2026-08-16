<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona coluna slug na tabela suppliers.
     *
     * Necessario para roteamento de webhooks de gateway:
     * POST /api/webhooks/payment/{supplier_slug}/{gateway}
     *
     * O HasSlug trait existente usa tabela polimórfica separada (slugs),
     * mas o SupplierPaymentWebhookController e buildWebhookUrl() precisam
     * de coluna direta para lookup O(1) por índice único.
     */
    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'slug')) {
            return; // coluna ja existe no servidor — skip idempotente
        }
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('slug', 64)->nullable()->unique()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
