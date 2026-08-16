<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOR-127: o hub nao tem (nem deve ter) o cliente da whitelabel — cliente e local de
 * cada WL. Sem estes campos o painel exibe o Seller em branco em todo pedido vindo de WL.
 * NAO confundir com seller_nickname, que guarda o apelido da conta NO MARKETPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplace_accounts', 'wl_client_name')) {
                $table->string('wl_client_name', 191)->nullable()->after('wl_client_id');
            }
            if (! Schema::hasColumn('marketplace_accounts', 'wl_client_email')) {
                $table->string('wl_client_email', 191)->nullable()->after('wl_client_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['wl_client_name', 'wl_client_email']);
        });
    }
};
