<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\AIProductContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProductContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $productId,
        public readonly bool $force = false // SEL-XXX (04/08): true reprocessa mesmo com ai_title preenchido
    ) {}

    public function handle(AIProductContentService $aiService): void
    {
        $product = Product::find($this->productId);

        if (! $product) {
            Log::warning("[GenerateProductContentJob] Produto #{$this->productId} não encontrado.");
            return;
        }

        // SEL-XXX (04/08): idempotencia -- nao sobrescreve ai_title ja preenchido
        // a menos que force=true seja passado explicitamente.
        if (! $this->force && ! empty($product->ai_title)) {
            Log::info("[GenerateProductContentJob] Produto #{$product->id} já tem ai_title — pulando (force=false).");
            return;
        }

        Log::info("[GenerateProductContentJob] Gerando conteúdo IA para produto #{$product->id} — {$product->name}");

        try {
            $product->update([
                'ai_title'        => $aiService->generateTitle($product),
                'ai_description'  => $aiService->generateDescription($product),
                'ai_bullet_points' => $aiService->generateBulletPoints($product),
            ]);

            Log::info("[GenerateProductContentJob] Conteúdo gerado com sucesso para produto #{$product->id}");

        } catch (\Throwable $e) {
            Log::error("[GenerateProductContentJob] Erro ao gerar conteúdo", [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);
            throw $e; // Permite retry automático da queue
        }
    }
}
