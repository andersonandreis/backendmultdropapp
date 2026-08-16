<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adiciona IDs do Pagar.me na tabela de assinaturas
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pagarme_subscription_id')->nullable()->after('id');
            $table->string('pagarme_customer_id')->nullable()->after('pagarme_subscription_id');
            $table->string('pagarme_status')->nullable()->after('pagarme_customer_id');
        });

        // Adiciona customer_id do Pagar.me nos clientes para reuso
        Schema::table('clients', function (Blueprint $table) {
            $table->string('pagarme_customer_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['pagarme_subscription_id', 'pagarme_customer_id', 'pagarme_status']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('pagarme_customer_id');
        });
    }
};
