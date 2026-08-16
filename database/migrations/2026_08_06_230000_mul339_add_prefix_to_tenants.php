<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-339 — prefixo do numero do pedido por ORIGEM.
 *
 * O prefixo marca de onde o pedido veio, nao para onde vai. Ate aqui ele saia de
 * suppliers.prefix, que e o destino: coincidia enquanto cada WL tinha um fornecedor principal, e
 * quebrava quando o fornecedor nao resolvia — 541 pedidos do MultDrop nasceram com HUB- por isso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('order_prefix', 8)->nullable()->after('slug');
        });

        // Os prefixos existentes vinham de suppliers.prefix. Aqui eles passam a viver no tenant,
        // que e quem representa a origem.
        $mapa = [
            'hubai'         => 'HUB',
            'multdrop.app'  => 'MUL',
            'multdrop'      => 'MUL',
            'fornecefy'     => 'FOR',
            'mestoredrop'   => 'MES',
            'jtdrop'        => 'JTD',
            'dropksr'       => 'DKS',
            'sellerglobal'  => 'SEL',
            'dropautopecas' => 'DRO',
        ];

        foreach ($mapa as $slug => $prefixo) {
            DB::table('tenants')->where('slug', $slug)->update(['order_prefix' => $prefixo]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('order_prefix');
        });
    }
};