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
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->default(0)->after('price_margin')->comment('Taxa percentual de impostos (DAS, ICMS, etc)');
            $table->decimal('marketplace_commission', 5, 2)->default(0)->after('tax_percentage')->comment('Comissão percentual do marketplace');
            $table->decimal('marketplace_fixed_fee', 10, 2)->default(0)->after('marketplace_commission')->comment('Taxa fixa cobrada pelo marketplace (ex: R$ 6)');
            $table->decimal('marketplace_shipping_fee', 10, 2)->default(0)->after('marketplace_fixed_fee')->comment('Frete padrão ou taxa de envio fixa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'tax_percentage',
                'marketplace_commission',
                'marketplace_fixed_fee',
                'marketplace_shipping_fee',
            ]);
        });
    }
};
