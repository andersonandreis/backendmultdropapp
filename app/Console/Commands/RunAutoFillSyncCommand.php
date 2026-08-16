<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Jobs\AutoFillProductAttributesJob;
use App\Services\ProductQualityService;
use Illuminate\Console\Command;

class RunAutoFillSyncCommand extends Command
{
    protected $signature   = 'products:autofill-sync {product_id : ID do produto}';
    protected $description = 'Executa AutoFillProductAttributesJob de forma sincrona (sem fila)';

    public function handle(ProductQualityService $qualityService): int
    {
        $productId = (int) $this->argument('product_id');

        $job = new AutoFillProductAttributesJob($productId);
        $job->handle($qualityService);

        $this->info("AutoFill executado para produto #{$productId}");

        $product = Product::find($productId);
        if ($product) {
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['ml_category_id',     $product->ml_category_id ?? '(null)'],
                    ['quality_score_ml',   $product->quality_score_ml ?? '(null)'],
                    ['quality_score_shopee', $product->quality_score_shopee ?? '(null)'],
                    ['ml_attributes_count', count($product->ml_attributes ?? [])],
                    ['quality_issues_count', count($product->quality_issues ?? [])],
                ]
            );
        }

        return 0;
    }
}