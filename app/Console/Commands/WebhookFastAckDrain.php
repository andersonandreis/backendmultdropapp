<?php

namespace App\Console\Commands;

use App\Jobs\WebhookIngestJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * NOV-210: drena a lista redis `fastack:webhooks` (alimentada pelo
 * public/webhook-fast-ack.php sem framework) e despacha WebhookIngestJob,
 * mantendo o pipeline INF-034 (dedup, signature, guard órfão) intacto.
 *
 * Usa phpredis puro (sem prefixo Laravel) porque o produtor escreve a chave
 * crua, fora do namespace hubai_database_.
 */
class WebhookFastAckDrain extends Command
{
    protected $signature = 'webhooks:fastack-drain {--max-time=3600}';

    protected $description = 'Drena webhooks do fast-ack redis e despacha WebhookIngestJob';

    public function handle(): int
    {
        $maxTime = (int) $this->option('max-time');
        $start = time();

        $cfg = config('database.redis.default');
        $redis = new \Redis();
        $redis->connect($cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 6379), 2.0);
        if (!empty($cfg['password'])) {
            $redis->auth($cfg['password']);
        }
        $redis->select((int) ($cfg['database'] ?? 0));

        $this->info('fastack-drain iniciado (max-time=' . $maxTime . 's)');
        $count = 0;

        while (time() - $start < $maxTime) {
            $popped = $redis->brPop(['fastack:webhooks'], 5);
            if (!$popped || !isset($popped[1])) {
                continue;
            }

            $item = json_decode($popped[1], true);
            if (!is_array($item) || empty($item['platform']) || !isset($item['body'])) {
                Log::warning('[fastack-drain] item inválido descartado', ['raw' => substr($popped[1], 0, 500)]);
                continue;
            }

            try {
                WebhookIngestJob::dispatch(
                    $item['platform'],
                    $item['body'],
                    $item['headers'] ?? [],
                    $item['ip'] ?? '0.0.0.0',
                    $item['method'] ?? 'POST',
                    $item['uri'] ?? '/webhooks'
                );
                $count++;
            } catch (\Throwable $e) {
                // Devolve pra lista pra não perder o webhook se a fila falhar
                $redis->lPush('fastack:webhooks', $popped[1]);
                Log::error('[fastack-drain] falha ao despachar, item devolvido', ['error' => $e->getMessage()]);
                sleep(2);
            }
        }

        $this->info('fastack-drain encerrando após ' . $count . ' itens (max-time atingido)');

        return self::SUCCESS;
    }
}
