<?php

namespace App\Jobs;

use App\Models\AutoListingConfig;
use App\Models\AutoListingLog;
use App\Models\AutoListingQueueItem;
use App\Models\ClientProduct;
use App\Services\AIProductContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoListingItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 120;

    public function __construct(
        public AutoListingQueueItem $item
    ) {
        $this->onQueue('auto-listing');
    }

    public function handle(AIProductContentService $aiService): void
    {
        $item = $this->item;
        $item->markProcessing();

        $config = AutoListingConfig::getEffective($item->client_id, $item->marketplace_account_id);
        $account = $item->marketplaceAccount;
        $product = $item->product;

        $startTime = microtime(true);

        try {
            // 1. Verificar se já existe ClientProduct para esse produto nesta loja
            if ($config->skip_existing) {
                $existing = ClientProduct::where('marketplace_account_id', $account->id)
                    ->where('product_id', $product->id)
                    ->first();

                if ($existing) {
                    $item->markSkipped('product_already_exists');
                    $this->log('skip', [
                        'reason' => 'product_already_exists',
                        'client_product_id' => $existing->id,
                    ], $startTime);
                    return;
                }
            }

            // 2. Criar ClientProduct como draft primeiro
            $clientProduct = ClientProduct::create([
                'product_id' => $product->id,
                'marketplace_account_id' => $account->id,
                'custom_title' => $product->name,
                'custom_description' => $product->description ?? '',
                'custom_price' => $this->calculatePrice($product, $account),
                'pricing_mode' => $account->pricing_strategy === 'fixed' ? 'manual' : 'margin',
                'sync_status' => 'draft',
            ]);

            // 3. Gerar conteúdo IA (se habilitado)
            if ($config->ai_enabled) {
                $instructions = trim(($config->ai_instructions ?? '') . "\n" . ($account->ai_instructions ?? ''));

                if ($config->ai_generate_title) {
                    $title = $aiService->generateTitleForClientProduct($clientProduct, $instructions);
                    $clientProduct->update(['custom_title' => $title]);
                    $item->update(['generated_title' => $title]);
                    $this->log('ai_generate', ['field' => 'title', 'result' => $title], $startTime);
                }

                if ($config->ai_generate_description) {
                    $description = $aiService->generateDescriptionForClientProduct($clientProduct, $instructions);
                    $clientProduct->update(['custom_description' => $description]);
                    $item->update(['generated_description' => $description]);
                    $this->log('ai_generate', ['field' => 'description', 'length' => strlen($description)], $startTime);
                }
            }

            // 4. Publicar ou manter como draft
            if ($config->auto_publish) {
                $clientProduct->update(['sync_status' => 'pending']);
                PublishClientProductToMLJob::dispatch($clientProduct->id);
                $this->log('publish', ['client_product_id' => $clientProduct->id], $startTime);
            } else {
                $this->log('create_draft', ['client_product_id' => $clientProduct->id], $startTime);
            }

            $item->markCompleted($clientProduct->id);

        } catch (\Throwable $e) {
            $item->markFailed($e->getMessage());
            $this->log('fail', ['error' => $e->getMessage(), 'trace' => mb_substr($e->getTraceAsString(), 0, 500)], $startTime);
        }
    }

    private function calculatePrice($product, $account): float
    {
        if ($account->pricing_strategy === 'margin_percentage' && $account->price_margin > 0) {
            return round($product->price * (1 + ($account->price_margin / 100)), 2);
        }

        return $product->price;
    }

    private function log(string $action, array $details, float $startTime): void
    {
        AutoListingLog::create([
            'queue_item_id' => $this->item->id,
            'client_id' => $this->item->client_id,
            'action' => $action,
            'details' => $details,
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
        ]);
    }
}
