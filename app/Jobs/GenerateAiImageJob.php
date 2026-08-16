<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Services\Ai\OpenAiService;
use App\Services\Ai\PromptMasterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-303: Job async pra geracao de imagem DALL-E-3.
 * Substitui chamada sync no AIController::image() que
 * causava timeout Cloudflare (100s) em requests de 20-90s.
 *
 * Fluxo: POST /ai/image/generate -> 202 {task_id}
 *        GET  /ai/image/tasks/{id} polling ate done/failed
 */
class GenerateAiImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        private int $generationId,
        private string $prompt,
        private string $size,
        private string $quality,
        private string $style,
        private string $mode,
    ) {}

    public function handle(OpenAiService $openai, PromptMasterService $promptMaster): void
    {
        $gen = AiGeneration::find($this->generationId);
        if (!$gen) {
            Log::warning("SEL-303 GenerateAiImageJob: generation {$this->generationId} nao encontrada, abortando.");
            return;
        }

        $gen->update(['status' => 'processing']);

        try {
            // SEL-220: injecao silenciosa do prompt master — igual ao controller sync
            $enhancedPrompt = $promptMaster->enhance($this->prompt, $this->mode);

            $out = $openai->generateImage(
                $enhancedPrompt,
                $this->size,
                $this->quality,
                $this->style,
            );

            $gen->update([
                'status'       => 'succeeded',
                'output_url'   => $out['url'],
                'final_prompt' => $enhancedPrompt,
                'expires_at'   => now()->addHours(24),
            ]);
        } catch (Throwable $e) {
            Log::warning("SEL-303 GenerateAiImageJob falhou para gen {$this->generationId}: " . $e->getMessage());
            $gen->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error("SEL-303 GenerateAiImageJob failed (job-level) gen {$this->generationId}: " . $e->getMessage());
        AiGeneration::where('id', $this->generationId)
            ->where('status', '!=', 'succeeded')
            ->update(['status' => 'failed', 'error_message' => 'Job falhou: ' . $e->getMessage()]);
    }
}
