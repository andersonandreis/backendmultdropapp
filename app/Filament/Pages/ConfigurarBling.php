<?php

namespace App\Filament\Pages;

use App\Models\ErpAccount;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ConfigurarBling extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationGroup = 'Integrações';
    protected static ?string $navigationLabel = 'Conectar Bling';
    protected static ?string $title = 'Conectar Bling ERP';
    protected static ?string $slug = 'configurar-bling';
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.configurar-bling';

    /** Conta ERP Bling do supplier (se existir). */
    public ?ErpAccount $erpAccount = null;

    /** ID do supplier autenticado (ou fallback p/ super_admin). */
    public ?int $supplierId = null;

    public function mount(): void
    {
        $user = auth()->user();

        // Resolve supplier_id: user supplier > super_admin default (1)
        $this->supplierId = $user?->supplier?->id;

        if (! $this->supplierId && $user?->role === 'super_admin') {
            // Super admin sem supplier vinculado: cai no fornecedor padrão da WL (config).
            $this->supplierId = (int) config('multdrop.supplier_id', 1);
        }

        if (! $this->supplierId) {
            return;
        }

        $this->erpAccount = ErpAccount::where('supplier_id', $this->supplierId)
            ->where('platform', 'bling')
            ->first();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && in_array($user->role, ['super_admin', 'supplier'], true);
    }

    /** URL OAuth pra iniciar/refazer conexão Bling do supplier. */
    public function getConnectUrl(): string
    {
        if (! $this->supplierId) {
            return '#';
        }

        return url('/api/oauth/bling/redirect') . '?' . http_build_query([
            'supplier_id'   => $this->supplierId,
            'account_type'  => 'supplier_erp',
            'account_name'  => 'Bling ERP',
            'source_system' => config('bling.app_tenant', 'multdrop'),
            'return_url'    => url('/admin/configurar-bling'),
        ]);
    }

    /** Status legível da conta. */
    public function getStatusLabel(): string
    {
        if (! $this->erpAccount) {
            return 'Não conectado';
        }

        return match ($this->erpAccount->status) {
            'active'        => 'Conectado',
            'needs_reauth'  => 'Token expirado — reconectar',
            'inactive'      => 'Inativo',
            'error'         => 'Com erro',
            default         => ucfirst((string) $this->erpAccount->status),
        };
    }

    public function getStatusColor(): string
    {
        if (! $this->erpAccount) {
            return 'gray';
        }

        return match ($this->erpAccount->status) {
            'active'       => 'success',
            'needs_reauth' => 'warning',
            'error'        => 'danger',
            default        => 'gray',
        };
    }

    /** Action conectar/reconectar (header). */
    protected function getHeaderActions(): array
    {
        $needsConnect = ! $this->erpAccount || in_array($this->erpAccount->status, ['needs_reauth', 'error'], true);

        return [
            Action::make('conectar')
                ->label($needsConnect ? 'Conectar minha conta Bling' : 'Reconectar')
                ->icon('heroicon-o-link')
                ->color($needsConnect ? 'primary' : 'gray')
                ->url(fn () => $this->getConnectUrl())
                ->openUrlInNewTab(false)
                ->visible(fn () => $this->supplierId !== null),

            Action::make('desconectar')
                ->label('Desconectar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Isso vai apagar os tokens do Bling. Você precisará reconectar para usar o ERP.')
                ->visible(fn () => $this->erpAccount !== null)
                ->action(function () {
                    $this->erpAccount->update([
                        'api_key'          => null,
                        'refresh_token'    => null,
                        'token_expires_at' => null,
                        'status'           => 'inactive',
                    ]);

                    Notification::make()
                        ->title('Bling desconectado')
                        ->success()
                        ->send();

                    $this->mount();
                }),
        ];
    }
}
