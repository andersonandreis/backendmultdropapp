<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona configuracoes de pagamento e financeiras por loja Drop.
     * Fase 3: gateway configuravel + split de receita plataforma.
     */
    public function up(): void
    {
        Schema::table('drop_stores', function (Blueprint $table) {
            // Divisao de receita: percentual que vai para a plataforma HubAI (default 10%)
            $table->unsignedTinyInteger('split_platform_pct')->default(10)->after('is_published');

            // Gateway preferencial da loja (pode usar StorePaymentGateway para credenciais detalhadas)
            $table->enum('payment_gateway', ['pagarme', 'pix', 'stripe', 'mercadopago', 'pix_manual'])
                ->nullable()
                ->after('split_platform_pct');
        });
    }

    public function down(): void
    {
        Schema::table('drop_stores', function (Blueprint $table) {
            $table->dropColumn(['split_platform_pct', 'payment_gateway']);
        });
    }
};
