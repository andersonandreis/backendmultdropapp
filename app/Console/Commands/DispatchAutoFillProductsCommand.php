<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Jobs\AutoFillProductAttributesJob;
use Illuminate\Console\Command;

class DispatchAutoFillProductsCommand extends Command
{
    protected $signature   = 'products:autofill {--product_id= : ID de produto especifico}';
    protected $description = 'Dispara AutoFillProductAttributesJob para todos os produtos ativos (ou produto especifico)';

    public function handle(): int
    {
        $productId = $this->option('product_id');

        if ($productId) {
            $product = Product::find((int) $productId);
            if (! $product) {
                $this->error("Produto #{$productId} nao encontrado.");
                return 1;
            }
            AutoFillProductAttributesJob::dispatch($product->id);
            $this->info("Job disparado para produto #{$product->id} ({$product->name})");
            return 0;
        }

        $count = Product::where('is_active', true)->count();
        $this->info("Disparando jobs para {$count} produtos ativos...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $dispatched = 0;
        Product::where('is_active', true)->each(function (Product $p) use (&$dispatched, $bar) {
            AutoFillProductAttributesJob::dispatch($p->id);
            $dispatched++;
            $bar->advance();
        });

        $bar->finish();
        $this->newLine();
        $this->info("Disparados {$dispatched} jobs na fila.");

        return 0;
    }
}