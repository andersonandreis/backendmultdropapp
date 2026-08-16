<?php

namespace App\Filament\App\Resources\ClientProductResource\Pages;

use App\Filament\App\Resources\ClientProductResource;
use App\Models\ClientProduct;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditClientProduct extends EditRecord
{
    protected static string $resource = ClientProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('send_to_marketplace')
                ->label('Enviar ao Mercado Livre')
                ->icon('heroicon-m-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publicar no Mercado Livre')
                ->modalDescription('Isso vai publicar ou atualizar este anúncio no Mercado Livre. Confirmar?')
                ->action(function () {
                    /** @var ClientProduct $record */
                    $record = $this->record;
                    $account = $record->marketplaceAccount;
                    if (!$account) {
                        Notification::make()->title('Nenhuma loja vinculada.')->danger()->send();
                        return;
                    }
                    try {
                        $mlService = app(\App\Services\Integrations\MercadoLivreService::class);
                        $result = $mlService->syncProduct($account, $record->product);
                        $record->update([
                            'sync_status'    => $result ? 'synced' : 'error',
                            'last_sync_at'   => now(),
                            'last_sync_error' => $result ? null : 'Falha ao publicar no Mercado Livre.',
                        ]);
                        Notification::make()
                            ->title($result ? 'Publicado com sucesso!' : 'Falha ao publicar')
                            ->{$result ? 'success' : 'danger'}()
                            ->send();
                    } catch (\Throwable $e) {
                        $record->update([
                            'sync_status'    => 'error',
                            'last_sync_at'   => now(),
                            'last_sync_error' => $e->getMessage(),
                        ]);
                        Notification::make()->title('Erro: ' . $e->getMessage())->danger()->send();
                    }
                })
                ->visible(fn () =>
                    $this->record->marketplaceAccount?->platform === 'mercadolivre'
                    && ($this->record->listing_status ?? 'draft') === 'published'
                ),

            Actions\DeleteAction::make()->label('Excluir Anúncio'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Persiste ai_bullet_points no produto base (não na tabela client_products)
        if (array_key_exists('ai_bullet_points', $data)) {
            $raw = $data['ai_bullet_points'] ?? '';
            unset($data['ai_bullet_points']);

            if ($this->record->product && $raw !== null) {
                // Converte texto com bullets para array JSON
                $lines = array_filter(array_map(fn($l) => ltrim(trim($l), '•·- '), explode("
", $raw)));
                $this->record->product->update(['ai_bullet_points' => array_values($lines)]);
            }
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        $storeId = $this->record->marketplace_account_id;
        return $storeId
            ? static::getResource()::getUrl('index', ['store_id' => $storeId])
            : static::getResource()::getUrl('index');
    }
}
