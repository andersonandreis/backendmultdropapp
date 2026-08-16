<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-334 — agrupar catalogos do mesmo fornecedor.
 *
 * Hoje "catalogo" e "fornecedor" sao a mesma coisa: catalogo e o conjunto de products com
 * um supplier_id. Funciona — cada catalogo tem estoque, preco e isolamento proprios. O que
 * NAO existe e dizer que N catalogos pertencem ao mesmo dono.
 *
 * MultDrop tem dois (matriz D498 e filial D773) e pode ter dez. Nada no banco liga o
 * supplier 30 ao 157: a filial nem CNPJ tem, e o unico vinculo e o nome comecar igual.
 *
 * parent_supplier_id resolve isso sem tocar no eixo do sistema. O isolamento continua por
 * supplier_id (TenantSupplierScope, 13 models), o estoque por warehouse_id, o pedido pelo
 * supplier de quem vende. Os 1.355 pontos de codigo que tocam supplier_id seguem intactos,
 * porque cada catalogo continua sendo um supplier de verdade.
 *
 * Passa a permitir:
 *   - agrupar catalogos do mesmo dono na tela, separando-os dos de outros fornecedores
 *   - o seletor de catalogo do rascunho (MUL-334 fase 2) listar [raiz] + filhos
 *   - o espelho de estoque (fase 3) ter fronteira definida: irmao = mesmo grupo,
 *     em vez de adivinhar por prefixo de SKU
 *
 * Divida assumida: a filial continua sendo um supplier, entao segue aparecendo em relatorio
 * de fornecedor e podendo ter conta de marketplace e saldo — coisas que nao fazem sentido
 * para o que ela e. Nao atrapalha hoje (0 pedidos, 0 contas) e nao bloqueia separar de
 * verdade depois; pelo contrario, registra no banco qual supplier e catalogo de qual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_supplier_id')->nullable()->after('id');
            $table->index('parent_supplier_id', 'suppliers_parent_supplier_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('suppliers_parent_supplier_id_index');
            $table->dropColumn('parent_supplier_id');
        });
    }
};
