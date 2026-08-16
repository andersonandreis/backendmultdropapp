<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: drop_profit_reports
 * Armazena relatorios financeiros periodicos de clientes do modulo Drop Internacional.
 * Cada linha representa o consolidado de um intervalo de datas para um cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_profit_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('period_start');
            $table->date('period_end');

            // Receitas e custos em USD
            $table->decimal('total_revenue', 12, 4)->default(0);
            $table->decimal('total_cost_product', 12, 4)->default(0);
            $table->decimal('total_cost_shipping', 12, 4)->default(0);
            $table->decimal('total_gateway_fees', 12, 4)->default(0);
            $table->decimal('total_platform_fees', 12, 4)->default(0);
            $table->decimal('total_chargebacks', 12, 4)->default(0);
            $table->decimal('total_refunds', 12, 4)->default(0);

            // Lucro
            $table->decimal('gross_profit', 12, 4)->default(0);
            $table->decimal('net_profit', 12, 4)->default(0);

            // Contadores
            $table->integer('orders_count')->default(0);
            $table->integer('profitable_orders')->default(0);
            $table->integer('loss_orders')->default(0);

            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->index(['client_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_profit_reports');
    }
};
