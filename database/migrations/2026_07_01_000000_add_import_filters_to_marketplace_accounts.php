<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MUL-082: config de importacao por conta marketplace (Bling/Shopee/ML)
// - data_inicial_import: cutoff de data (nenhum pedido anterior sera importado)
// - allowed_integrations: array de integracoes/canais permitidos (Bling extensoes)
// - only_ready_to_ship: se true, importa somente pedidos com status 'a enviar' (Shopee)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplace_accounts', 'data_inicial_import')) {
                $table->date('data_inicial_import')->nullable()->after('import_mode');
            }
            if (! Schema::hasColumn('marketplace_accounts', 'allowed_integrations')) {
                $table->json('allowed_integrations')->nullable()->after('data_inicial_import');
            }
            if (! Schema::hasColumn('marketplace_accounts', 'only_ready_to_ship')) {
                $table->boolean('only_ready_to_ship')->default(true)->after('allowed_integrations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['data_inicial_import', 'allowed_integrations', 'only_ready_to_ship']);
        });
    }
};
