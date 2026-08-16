<?php

namespace App\Filament\App\Resources\ClientProductResource\Pages;

use App\Filament\App\Resources\ClientProductResource;
use App\Jobs\PublishClientProductToMLJob;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\AIProductContentService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Computed;

class ListClientProducts extends ListRecords
{
    protected static string $resource = ClientProductResource::class;

    /** View customizada que inclui o banner de alerta de conta */
    protected static string $view = 'filament.app.resources.client-product-resource.pages.list-client-products';

    #[\Livewire\Attributes\Url]
    public ?string $store_id = null;

    public bool $isGridLayout = true;

    /** Indica que um retry falhou novamente — bloqueia novas tentativas */
    public bool $accountBlocked = false;

    // -------------------------------------------------------------------------
    // Detecção de erro de conta
    // -------------------------------------------------------------------------

    /** Padrões que indicam problema na CONTA do vendedor (não no produto) */
    private static array $accountErrorPatterns = [
        'Endereço não cadastrado',
        'Conta não habilitada',
        'Token expirado',
        'Sem permissão',
        'address_pending',
        'seller.unable_to_list',
        'invalid_token',
        'reconecte',
    ];

    public static function isAccountError(?string $error): bool
    {
        if (!$error) {
            return false;
        }
        foreach (self::$accountErrorPatterns as $pattern) {
            if (stripos($error, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retorna true se há algum produto com erro de conta na store atual.
     */
    // -------------------------------------------------------------------------
    // Limite de SKUs por plano — banner upsell
    // -------------------------------------------------------------------------

    /**
     * Retorna dados do plano para o banner de limite de SKUs.
     */
    #[Computed]
    public function skuLimitInfo(): array
    {
        return ClientProductResource::planInfo();
    }

    /**
     * True quando motivo de canCreate()=false e limite de SKUs
     * (tem client, tem sub ativa, current >= max_skus > 0).
     */
    #[Computed]
    public function hasSkuLimitReached(): bool
    {
        $info = $this->skuLimitInfo;
        if (! $info["has_sub"]) {
            return false;
        }
        if ($info["max_skus"] === 0) {
            return false;
        }
        return $info["current"] >= $info["max_skus"];
    }

    #[Computed]
    public function hasAccountError(): bool
    {
        return $this->getAccountErrorProduct() !== null;
    }

    /**
     * Retorna a mensagem do primeiro erro de conta encontrado.
     */
    #[Computed]
    public function accountErrorMessage(): string
    {
        $product = $this->getAccountErrorProduct();
        if (!$product) {
            return '';
        }

        $error = $product->last_sync_error ?? '';

        // Mensagens amigáveis mapeadas por padrão
        $friendlyMessages = [
            'address_pending'      => 'Seu endereço de entrega não está cadastrado no Mercado Livre. Acesse sua conta e complete o cadastro em "Meus dados".',
            'Endereço não cadastrado' => 'Seu endereço de entrega não está cadastrado no Mercado Livre. Acesse sua conta e complete o cadastro em "Meus dados".',
            'seller.unable_to_list' => 'Sua conta vendedora não está habilitada para criar anúncios. Verifique as pendências no painel do Mercado Livre.',
            'Conta não habilitada'  => 'Sua conta vendedora não está habilitada para criar anúncios. Verifique as pendências no painel do Mercado Livre.',
            'invalid_token'         => 'A conexão com o Mercado Livre expirou. Reconecte sua conta nas configurações da loja.',
            'Token expirado'        => 'A conexão com o Mercado Livre expirou. Reconecte sua conta nas configurações da loja.',
            'reconecte'             => 'A conexão com o Mercado Livre expirou. Reconecte sua conta nas configurações da loja.',
            'Sem permissão'         => 'Sem permissão para criar anúncios. Verifique as permissões da sua conta no Mercado Livre.',
        ];

        foreach ($friendlyMessages as $pattern => $message) {
            if (stripos($error, $pattern) !== false) {
                return $message;
            }
        }

        return 'Problema detectado: ' . $error;
    }

    /**
     * Retorna o primeiro ClientProduct com erro de conta na store atual.
     */
    private function getAccountErrorProduct(): ?ClientProduct
    {
        $storeId = $this->store_id ?? request()->query('store_id');

        $query = ClientProduct::where('sync_status', 'error')
            ->whereNotNull('last_sync_error');

        if ($storeId) {
            $query->where('marketplace_account_id', $storeId);
        } else {
            $user = auth()->user();
            if ($user && $user->client) {
                $storeIds = MarketplaceAccount::where('client_id', $user->client->id)->pluck('id');
                $query->whereIn('marketplace_account_id', $storeIds);
            }
        }

        $products = $query->get();

        foreach ($products as $product) {
            if (self::isAccountError($product->last_sync_error)) {
                return $product;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // mount — verificar se conta está bloqueada
    // -------------------------------------------------------------------------

    public function mount(): void
    {
        parent::mount();

        $storeId = $this->store_id ?? request()->query('store_id');
        if ($storeId) {
            $account = MarketplaceAccount::find($storeId);
            if ($account && $account->sync_blocked_at !== null) {
                $this->accountBlocked = true;
            }
        }
    }

    // -------------------------------------------------------------------------
    // retryAfterFix — retentar envio após correção na conta
    // -------------------------------------------------------------------------

    public function retryAfterFix(): void
    {
        $product = $this->getAccountErrorProduct();

        if (!$product) {
            Notification::make()
                ->title('Nenhum produto com erro de conta encontrado.')
                ->warning()
                ->send();
            return;
        }

        $account = $product->marketplaceAccount;

        // Limpa o bloqueio anterior para dar uma nova chance
        if ($account) {
            $account->update(['sync_blocked_at' => null]);
        }

        // Marca como pending e dispara o job
        $product->update([
            'sync_status'     => 'pending',
            'last_sync_error' => null,
        ]);

        PublishClientProductToMLJob::dispatch($product->id);

        // Aguarda brevemente para checar resultado imediato
        // (jobs síncronos em ambiente local retornam imediatamente)
        // Em produção com filas assíncronas, o usuário verá o status atualizar
        sleep(1);

        // Re-busca o produto para ver se houve erro imediato
        $product->refresh();

        if ($product->sync_status === 'error' && self::isAccountError($product->last_sync_error)) {
            // Retry falhou novamente — bloquear
            $this->accountBlocked = true;
            if ($account) {
                $account->update(['sync_blocked_at' => now()]);
            }

            Notification::make()
                ->title('Problema persiste na sua conta')
                ->body('O erro continua após a nova tentativa. As publicações foram pausadas. Verifique sua conta no Mercado Livre.')
                ->danger()
                ->persistent()
                ->send();
        } else {
            $this->accountBlocked = false;

            Notification::make()
                ->title('Produto enviado para publicação!')
                ->body('Acompanhe o status na listagem. Se o problema foi corrigido, o anúncio será publicado em breve.')
                ->success()
                ->duration(8000)
                ->send();
        }
    }

    // -------------------------------------------------------------------------
    // Header actions padrão
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleGrid')
                ->label(fn() => $this->isGridLayout ? 'Ver em Lista' : 'Ver em Grade')
                ->icon(fn() => $this->isGridLayout ? 'heroicon-o-list-bullet' : 'heroicon-o-squares-2x2')
                ->action(function () {
                    $this->isGridLayout = !$this->isGridLayout;
                }),
            Actions\CreateAction::make(),
        ];
    }

    // -------------------------------------------------------------------------
    // Query filtrada por store
    // -------------------------------------------------------------------------

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getTableQuery();

        $storeId = $this->store_id ?? request()->query('store_id');
        if ($storeId) {
            $query->where('marketplace_account_id', $storeId);
        }

        return $query;
    }

    // -------------------------------------------------------------------------
    // Métodos Livewire para geração de conteúdo via IA
    // -------------------------------------------------------------------------

    /**
     * Gera título via IA para o produto informado e dispara evento para atualizar o form.
     */
    public function generateAiTitle(int $recordId): void
    {
        $record = ClientProduct::find($recordId);
        if (!$record) {
            Notification::make()
                ->title('Produto não encontrado.')
                ->danger()
                ->send();
            return;
        }

        try {
            $service = app(AIProductContentService::class);
            $title   = $service->generateTitleForClientProduct($record);

            $this->dispatch('ai-field-updated', field: 'custom_title', value: $title, recordId: $recordId);

            Notification::make()
                ->title('Título gerado com sucesso!')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao gerar título: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Gera descrição via IA para o produto informado e dispara evento para atualizar o form.
     */
    public function generateAiDescription(int $recordId): void
    {
        $record = ClientProduct::find($recordId);
        if (!$record) {
            Notification::make()
                ->title('Produto não encontrado.')
                ->danger()
                ->send();
            return;
        }

        try {
            $service = app(AIProductContentService::class);
            $desc    = $service->generateDescriptionForClientProduct($record);

            $this->dispatch('ai-field-updated', field: 'custom_description', value: $desc, recordId: $recordId);

            Notification::make()
                ->title('Descrição gerada com sucesso!')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao gerar descrição: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
