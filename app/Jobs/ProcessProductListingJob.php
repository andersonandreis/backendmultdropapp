<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Models\ProductListingJob;
use App\Services\AIProductContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Processa 1 item da fila product_listing_jobs:
 * 1. Valida que o ClientProduct existe e ainda nao foi publicado.
 * 2. Opcionalmente melhora titulo e descricao com IA (gpt-4o-mini).
 * 3. Dispara PublishClientProductToMLJob (fluxo ja existente de publicacao ML/Shopee).
 * 4. Atualiza o status do ProductListingJob para done/failed/skipped.
 *
 * Queue: product-listing (worker dedicado configurado no Supervisor)
 */
class ProcessProductListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Uma tentativa so - retry controlado manualmente pelo dispatcher. */
    public int $tries = 1;

    /** Timeout generoso para aguardar resposta da API do marketplace. */
    public int $timeout = 120;

    public function __construct(
        public readonly int $listingJobId
    ) {
        $this->onQueue('product-listing');
    }

    public function handle(AIProductContentService $aiService): void
    {
        $job = ProductListingJob::find($this->listingJobId);

        if (! $job) {
            Log::channel('queue')->warning('[ProcessProductListingJob] Job nao encontrado', [
                'listing_job_id' => $this->listingJobId,
            ]);
            return;
        }

        // Evitar reprocessamento de jobs ja em outro estado
        if (! in_array($job->status, ['pending', 'processing'])) {
            Log::channel('queue')->info('[ProcessProductListingJob] Job ignorado - status inesperado', [
                'listing_job_id' => $job->id,
                'status'         => $job->status,
            ]);
            return;
        }

        $job->markProcessing();

        $clientProduct = ClientProduct::find($job->client_product_id);

        if (! $clientProduct) {
            $job->markFailed('ClientProduct #' . $job->client_product_id . ' nao encontrado.');
            return;
        }

        // Verificar se ja esta publicado (tem external_listing_id)
        if (! empty($clientProduct->external_listing_id)) {
            $job->markSkipped('already_listed');
            return;
        }

        try {
            // 1. Melhorar titulo e descricao com IA (se solicitado)
            if ($job->generate_image) {
                $this->applyAIEnhancements($job, $clientProduct, $aiService);
            }

            // 2. Disparar publicacao via job ja existente (ML por padrao).
            //    O PublishClientProductToMLJob atualiza external_listing_id quando bem-sucedido.
            PublishClientProductToMLJob::dispatch($clientProduct->id);

            // 3. Marcar como done - external_listing_id sera preenchido pelo PublishClientProductToMLJob.
            //    Usamos 'dispatched:<id>' como referencia de sucesso nesta etapa.
            $job->markDone('dispatched:' . $clientProduct->id);

            Log::channel('queue')->info('[ProcessProductListingJob] Publicacao enfileirada', [
                'listing_job_id'         => $job->id,
                'client_product_id'      => $clientProduct->id,
                'marketplace_account_id' => $job->marketplace_account_id,
                'generate_image'         => $job->generate_image,
            ]);

        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage());

            Log::channel('queue')->error('[ProcessProductListingJob] Falha ao processar', [
                'listing_job_id'    => $job->id,
                'client_product_id' => $job->client_product_id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function applyAIEnhancements(
        ProductListingJob $job,
        ClientProduct $clientProduct,
        AIProductContentService $aiService
    ): void {
        try {
            $title = $aiService->generateTitleForClientProduct($clientProduct, '');
            if (! empty($title)) {
                $clientProduct->update(['custom_title' => $title]);
            }

            $description = $aiService->generateDescriptionForClientProduct($clientProduct, '');
            if (! empty($description)) {
                $clientProduct->update(['custom_description' => $description]);
            }
        } catch (\Throwable $e) {
            // Nao falhar o job inteiro por erro na IA - publicar com dados originais
            Log::channel('queue')->warning('[ProcessProductListingJob] IA falhou, publicando sem enhancement', [
                'listing_job_id' => $job->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
