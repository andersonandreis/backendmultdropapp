<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INF-030 (Ruan 12/08, ampliação) — "quero o NÚMERO da campanha e o ID do
 * anúncio". O Matomo tem Referrers.getCampaigns (bom pra relatório agregado),
 * mas não guarda o elo por VISITANTE de forma que dê pra cruzar com quem
 * comprou depois. Essa tabela é a nossa: 1 linha por visitante (primeiro
 * toque, nunca sobrescrita — modelo first-touch attribution, padrão de
 * mercado), capturada no primeiro load da SPA quando tem utm_source/fbclid/
 * gclid/ttclid na URL. Chave única em visitor_uid — reenvio no mesmo
 * visitante não duplica nem sobrescreve (o controller faz firstOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visitor_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_uid', 64)->unique();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_content', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('utm_id', 100)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('ttclid', 255)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('landing_page', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visitor_campaigns');
    }
};
