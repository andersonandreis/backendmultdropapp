<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta 2 colunas em orders pra fechar o mapeamento de NF do legado:
 *
 *  - invoice_status: status textual da NF no legado (`desc_status_nota`,
 *    ex: "Autorizada", "Rejeitada", "Em processamento").
 *  - invoice_xml: XML inline da nota (`xml_nota` no legado, TEXT).
 *    Pode ser grande — usa longtext.
 *
 * Os outros campos (invoice_number, invoice_series, invoice_access_key,
 * invoice_issued_at, invoice_url, invoice_xml_url) ja existiam mas estavam
 * vazios nos 10.410 pedidos importados. O `ImportLegacyOrdersJob` e
 * `SyncLegacyOrdersJob` serao atualizados pra popular tudo na sequencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'invoice_status')) {
                $table->string('invoice_status', 50)->nullable()->after('invoice_issued_at');
            }
            if (!Schema::hasColumn('orders', 'invoice_xml')) {
                $table->longText('invoice_xml')->nullable()->after('invoice_xml_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_status')) {
                $table->dropColumn('invoice_status');
            }
            if (Schema::hasColumn('orders', 'invoice_xml')) {
                $table->dropColumn('invoice_xml');
            }
        });
    }
};
