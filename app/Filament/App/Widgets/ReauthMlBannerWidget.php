<?php

namespace App\Filament\App\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class ReauthMlBannerWidget extends Widget
{
    protected static string $view = 'filament.app.widgets.reauth-ml-banner';
    protected int|string|array $columnSpan = 'full';

    // Desabilitar lazy load: montar sincrono na requisicao inicial (auth disponivel)
    protected static bool $isLazy = false;

    // Prioridade: aparece logo acima dos stats do dashboard
    protected static ?int $sort = 0;

    public int $countNeedsReauth = 0;

    /**
     * Retorna o client_id do usuario autenticado, sem usar ORM (evita problemas de lazy-load).
     */
    private static function resolveClientId(): ?int
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $clientId = DB::table('clients')
            ->where('user_id', $user->id)
            ->orderBy('id', 'asc')
            ->value('id');

        return $clientId ? (int) $clientId : null;
    }

    public function mount(): void
    {
        $clientId = self::resolveClientId();
        if (! $clientId) {
            $this->countNeedsReauth = 0;
            return;
        }

        $this->countNeedsReauth = (int) DB::table('marketplace_accounts')
            ->where('client_id', $clientId)
            ->where('platform', 'mercadolivre')
            ->where('needs_reauth', 1)
            ->count();
    }

    public static function canView(): bool
    {
        $clientId = self::resolveClientId();
        if (! $clientId) {
            return false;
        }

        return DB::table('marketplace_accounts')
            ->where('client_id', $clientId)
            ->where('platform', 'mercadolivre')
            ->where('needs_reauth', 1)
            ->exists();
    }
}