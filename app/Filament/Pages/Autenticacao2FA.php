<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class Autenticacao2FA extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'Autenticação 2FA';
    protected static ?string $title = 'Autenticação de Dois Fatores';
    protected static ?string $slug = 'autenticacao-2fa';
    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.autenticacao-2fa';

    public bool $twoFactorEnabled = false;

    public function mount(): void
    {
        $user = auth()->user();
        // Verifica se 2FA esta habilitado (usando coluna two_factor_secret do Jetstream/Fortify, se existir)
        $this->twoFactorEnabled = !empty($user->two_factor_secret ?? null);
    }

    public function ativar(): void
    {
        $user = auth()->user();

        if (method_exists($user, 'enableTwoFactorAuthentication')) {
            app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class)($user);
            $this->twoFactorEnabled = true;
            Notification::make()->title('2FA ativado com sucesso!')->success()->send();
        } else {
            Notification::make()
                ->title('2FA não configurado')
                ->body('O projeto não possui Laravel Fortify/Jetstream. Integre o pacote para habilitar 2FA.')
                ->warning()
                ->send();
        }
    }

    public function desativar(): void
    {
        $user = auth()->user();

        if (method_exists($user, 'disableTwoFactorAuthentication')) {
            app(\Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class)($user);
            $this->twoFactorEnabled = false;
            Notification::make()->title('2FA desativado.')->success()->send();
        } else {
            // Limpeza direta se não tiver Fortify
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null])->save();
            $this->twoFactorEnabled = false;
            Notification::make()->title('2FA desativado.')->success()->send();
        }
    }
}
