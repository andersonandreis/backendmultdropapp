<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-227 item 30 — Fulfillment (armazenamento + preparo).
 * client_id: dono do contrato · marketplace: canal atendido (mercadolivre/shopee/amazon/all)
 * mode: envio (seller manda produtos pra ficarem armazenados) OU apenas_processamento (multdrop só recebe e prepara pra Full).
 * m3_reservado / valor_m3 (mensal) / valor_por_pedido: cobrança separada.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('fulfillment_contracts')) {
            Schema::create('fulfillment_contracts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('marketplace', 32)->default('all'); // mercadolivre, shopee, amazon, all
                $t->string('mode', 32)->default('envio');      // envio | apenas_processamento
                $t->decimal('m3_reservado', 8, 2)->default(0);
                $t->decimal('valor_m3', 10, 2)->default(0);
                $t->decimal('valor_por_pedido', 10, 2)->default(0);
                $t->string('warehouse_location')->nullable();  // rastreamento onde a mercadoria fica alocada
                $t->string('status', 32)->default('active');   // active, paused, cancelled
                $t->timestamp('started_at')->nullable();
                $t->timestamps();

                $t->index(['client_id', 'marketplace']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_contracts');
    }
};
