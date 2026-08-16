<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\MarketplaceCategory;

class SyncMarketplaceCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Atualiza as categorias Globais do ML/Shopee no Repositório para consulta no Painel
     */
    public function handle(): void
    {
        // Aqui conectariamos na classe base do ML (ex. via Guzzle)
        // para extrair '/sites/MLB/categories' e salvar como Cache Local em MarketplaceCategory

        // ML Mock Node Update
        MarketplaceCategory::updateOrCreate(
            ['platform' => 'mercadolivre', 'external_id' => 'MLB5672'],
            ['name' => 'Acessórios para Veículos', 'full_path' => 'Acessórios para Veículos']
        );

        // Shopee Mock Node Update
        MarketplaceCategory::updateOrCreate(
            ['platform' => 'shopee', 'external_id' => '100010'],
            ['name' => 'Roupas Femininas', 'full_path' => 'Roupas Femininas']
        );
    }
}
