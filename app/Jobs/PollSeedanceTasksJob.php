<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Services\Ai\SeedanceCatalog;
use App\Services\Ai\SeedanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-115: faz polling das tasks Seedance em estado "processing" a cada 30s.
 * Elimina a dependência do frontend fazer polling manualmente.
 * Fecha tasks com sucesso (grava output_url + cost_usd), falha ou expiradas.
 * Limite: max 50 tasks por run pra não sobrecarregar a API BytePlus.
 */
class PollSeedanceTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout conservador: BytePlus pode demorar ate 5min por task. */
    public int $timeout = 120;
    public int $tries = 1;

    public function handle(SeedanceService $seedance): void
    {
        if (! $seedance->isConfigured()) {
            return; // sem chave, sem custo, sem ruido no log
        }

        $tasks = AiGeneration::query()
            ->where('service', 'video')
            ->where('provider', 'seedance')
            ->whereIn('status', ['queued', 'processing'])
            ->whereNotNull('provider_task_id')
            ->where('created_at', '>=', now()->subHours(6)) // ignora geracoes muito antigas
            ->orderBy('updated_at') // mais antigas primeiro
            ->limit(50)
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        foreach ($tasks as $gen) {
            try {
                $out = $seedance->getTask($gen->provider_task_id);
            } catch (Throwable $e) {
                Log::warning("SEL-115 PollSeedance task {$gen->provider_task_id}: " . $e->getMessage());
                continue;
            }

            $status   = $out['status'] ?? 'processing';
            $videoUrl = $out['content']['video_url'] ?? null;

            if ($status === 'succeeded' && $videoUrl) {
                $update = [
                    'status'     => 'succeeded',
                    'output_url' => $videoUrl,
                    'expires_at' => now()->addHours(24),
                ];

                // Custo real via usage.completion_tokens (so na 1a transicao para sucesso)
                $tokens = (int) ($out['usage']['completion_tokens'] ?? 0);
                $opts   = $gen->wizard_payload['_options'] ?? [];
                if ($tokens > 0 && $gen->status !== 'succeeded') {
                    $update['usage_tokens'] = $tokens;
                    $update['cost_usd']     = SeedanceCatalog::costFromTokens(
                        $gen->provider_model,
                        $tokens,
                        $opts['resolution'] ?? '720p'
                    );
                }

                $gen->update($update);
                Log::info("SEL-115 PollSeedance task {$gen->provider_task_id} succeeded — url={$videoUrl}");
            } elseif (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
                $errMsg = $out['error']['message'] ?? $out['error'] ?? $status;
                $gen->update([
                    'status'        => $status,
                    'error_message' => is_string($errMsg) ? $errMsg : json_encode($errMsg),
                ]);
                Log::warning("SEL-115 PollSeedance task {$gen->provider_task_id} terminal: {$status}");
            }
            // se ainda processing, nao faz nada — proximo run vai tentar de novo
        }
    }
}
