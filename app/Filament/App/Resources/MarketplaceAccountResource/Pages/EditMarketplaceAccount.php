<?php

namespace App\Filament\App\Resources\MarketplaceAccountResource\Pages;

use App\Filament\App\Resources\MarketplaceAccountResource;
use App\Models\AutoListingQueueItem;
use App\Models\ClientProduct;
use App\Models\Product;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceAccount extends EditRecord
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('enqueue_products')
                ->label('Enfileirar Produtos')
                ->icon('heroicon-o-queue-list')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Enfileirar produtos para cadastro automático')
                ->modalDescription('Todos os produtos do fornecedor vinculado a esta loja serão adicionados à fila de cadastro automático. Produtos já cadastrados ou já na fila serão ignorados.')
                ->modalSubmitActionLabel('Enfileirar')
                ->action(function () {
                    $account = $this->record;
                    $supplierId = $account->supplier_id;
                    $clientId = $account->client_id;

                    if (! $supplierId) {
                        Notification::make()
                            ->title('Nenhum fornecedor vinculado')
                            ->body('Esta loja não tem fornecedor roteado.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Produtos ativos do fornecedor
                    $productIds = Product::where('supplier_id', $supplierId)
                        ->where('is_active', true)
                        ->pluck('id');

                    // Excluir os que já estão cadastrados como ClientProduct nesta loja
                    $alreadyCatalogued = ClientProduct::where('marketplace_account_id', $account->id)
                        ->whereIn('product_id', $productIds)
                        ->pluck('product_id');

                    // Excluir os que já estão na fila para esta loja
                    $alreadyQueued = AutoListingQueueItem::where('marketplace_account_id', $account->id)
                        ->whereIn('product_id', $productIds)
                        ->pluck('product_id');

                    $excluded = $alreadyCatalogued->merge($alreadyQueued)->unique();
                    $toEnqueue = $productIds->diff($excluded);

                    if ($toEnqueue->isEmpty()) {
                        Notification::make()
                            ->title('Nenhum produto novo')
                            ->body('Todos os produtos do fornecedor já estão cadastrados ou na fila.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Inserir em lote
                    $rows = $toEnqueue->map(fn (int $productId) => [
                        'client_id' => $clientId,
                        'marketplace_account_id' => $account->id,
                        'product_id' => $productId,
                        'status' => 'pending',
                        'priority' => 5,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->toArray();

                    AutoListingQueueItem::insert($rows);

                    Notification::make()
                        ->title('Produtos enfileirados!')
                        ->body(count($rows) . ' produtos adicionados à fila de cadastro automático.')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
