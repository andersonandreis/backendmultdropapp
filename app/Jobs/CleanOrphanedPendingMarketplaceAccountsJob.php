<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * NOV-156 — Limpa contas marketplace pending/fantasma criadas pelo /redirect
 * mas que o usuario nunca concluiu o OAuth (sem seller_id, sem token).
 *
 * Sintomas no cliente: ao publicar com store=all, o sistema iterava por essas
 * contas e retornava "Falha ao publicar" sem mensagem util. Fix: ignorar essas
 * contas no fluxo de publicacao + limpar do banco a cada hora.
 *
 * Critério: status=pending AND seller_id IS NULL AND sem nenhum token,
 * criada ha mais de 30 minutos.
 */
class CleanOrphanedPendingMarketplaceAccountsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        $cutoff = now()->subMinutes(30);

        $query = MarketplaceAccount::where('status', 'pending')
            ->whereNull('seller_id')
            ->whereNull('access_token')
            ->whereNull('ml_access_token')
            ->whereNull('bling_access_token')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            Log::debug('[CleanOrphanedPendingMarketplaceAccountsJob] Nenhuma conta pending fantasma para limpar.');
            return;
        }

        $deleted = $query->delete();

        Log::info('[CleanOrphanedPendingMarketplaceAccountsJob] Contas pending fantasma removidas', [
            'deleted' => $deleted,
            'cutoff'  => $cutoff->toDateTimeString(),
        ]);
    }
}
