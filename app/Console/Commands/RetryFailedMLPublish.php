<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ClientProduct;
use App\Jobs\PublishClientProductToMLJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RetryFailedMLPublish extends Command
{
    protected $signature = 'ml:retry-failed
                            {--max=5 : Maximo de produtos re-tentados por execucao}
                            {--age=30 : Ignorar erros mais recentes que X minutos}';

    protected $description = 'Re-tenta publicar produtos com sync_status=error no Mercado Livre';

    private const SKIP_PATTERNS = [
        'invalid_grant',
        'reauth',
        'unauthorized',
        'reconecte',
        'sem token',
        'nao conectada',
    ];

    public function handle(): int
    {
        $max        = max(1, (int) $this->option('max'));
        $ageMinutes = max(1, (int) $this->option('age'));

        $products = ClientProduct::where('sync_status', 'error')
            ->where('excluido', 0)
            ->whereNotNull('marketplace_account_id')
            ->where('sync_attempt_count', '<', 5)
            ->where(function ($q) use ($ageMinutes) {
                $q->whereNull('last_sync_at')
                  ->orWhere('last_sync_at', '<=', now()->subMinutes($ageMinutes));
            })
            ->where(function ($q) {
                foreach (self::SKIP_PATTERNS as $pattern) {
                    $q->where('last_sync_error', 'NOT LIKE', "%{$pattern}%");
                }
            })
            ->orderBy('sync_attempt_count')
            ->orderBy('last_sync_at')
            ->limit($max)
            ->get();

        $count = $products->count();
        $this->info("ML Retry: {$count} produto(s) elegivel(is) para re-tentativa (max={$max}, age>={$ageMinutes}min).");

        if ($count === 0) {
            $this->line('Nenhum produto elegivel. Encerrando.');
            return 0;
        }

        foreach ($products as $product) {
            Log::info('[ML-RETRY] Re-tentando produto', [
                'client_product_id' => $product->id,
                'attempt'           => $product->sync_attempt_count + 1,
                'last_error'        => $product->last_sync_error,
                'account_id'        => $product->marketplace_account_id,
            ]);

            DB::table('ml_sync_attempts')->insert([
                'client_product_id' => $product->id,
                'attempt_at'        => now(),
                'status'            => 'error',
                'error_message'     => 'Retry dispatched (aguardando job)',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            PublishClientProductToMLJob::dispatch($product->id);

            $this->line("  Dispatched produto #{$product->id} (tentativa " . ($product->sync_attempt_count + 1) . "/5)");
        }

        $this->info("Concluido. Jobs despachados: {$count}");
        return 0;
    }
}
