<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-171-A — M-171-02b
 * Adiciona federation_source em products — TODOS os 4 backends.
 * Identifica a origem do produto na federacao (APP_TENANT de onde veio).
 * NULL = produto criado localmente (nao veio via federacao).
 * Usado pelo ProductObserver como gate anti-loop:
 *   se federation_source IS NOT NULL, nao dispara FederationPushProductJob de volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'federation_source')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $after = Schema::hasColumn('products', 'hub_product_id')
                ? 'hub_product_id'
                : 'id';

            $table->string('federation_source', 20)
                ->nullable()
                ->after($after)
                ->comment('Tenant de origem via federacao (hubai, multdrop, fornecefy, mestoredrop). NULL = produto local. Usado como gate anti-loop no ProductObserver.');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'federation_source')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('federation_source');
        });
    }
};
