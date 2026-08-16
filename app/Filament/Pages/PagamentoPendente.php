<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Illuminate\Support\Facades\Cache;

/**
 * NOV-XXX — Tela de bloqueio do dono da WL (role=supplier) quando a WL está
 * inadimplente. Ver App\Http\Middleware\EnforceWlOwnerBillingGate.
 *
 * Página fora da navegação (não aparece no menu) — só é alcançada via redirect
 * do gate. Layout "simple" (sem sidebar, centralizado) igual à tela de login do
 * Filament — mas estende `Page` (não `SimplePage`) porque `SimplePage` não é
 * auto-descoberta pelo `discoverPages()` do painel (ele filtra por
 * `is_subclass_of(Page::class)`, e `SimplePage` é irmã de `Page`, não filha —
 * só páginas como Login/Register são registradas via método fluente dedicado
 * do Panel). Aqui replicamos manualmente o comportamento do SimplePage
 * (traits HasMaxWidth/HasTopbar + $layout apontando pra mesma view) para ter
 * roteamento normal de Page com visual sem sidebar.
 */
class PagamentoPendente extends Page
{
    use HasMaxWidth;
    use HasTopbar;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pagamento-pendente';

    protected static ?string $title = 'Pagamento Pendente';

    protected static string $view = 'filament.pages.pagamento-pendente';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxWidth'  => $this->getMaxWidth(),
        ];
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function getMaxWidth(): \Filament\Support\Enums\MaxWidth | string | null
    {
        return 'md';
    }

    /** Mesmo mapa do EnforceWlOwnerBillingGate — só para montar a cache key aqui. */
    private const TENANT_MAP = [
        'multdrop'    => 'MultDrop',
        'fornecefy'   => 'Fornecefy',
        'mestoredrop' => 'MEStoreDrop',
        'jtdrop'      => 'JTDrop',
        'dropksr'     => 'DropKsr',
    ];

    /**
     * Botão "Já paguei, verificar novamente" — força reconsulta ao invés de
     * esperar o TTL de 60s do cache expirar. Não desbloqueia nada por conta
     * própria (não escreve is_blocked=false) — só limpa o cache local pra
     * reconsultar o Supabase na próxima navegação.
     */
    public function verificarPagamento(): void
    {
        $tenant      = config('app.tenant', env('APP_TENANT', 'hubai'));
        $empresaNome = self::TENANT_MAP[$tenant] ?? null;

        if ($empresaNome) {
            $cacheKey = 'wl_billing_gate:' . strtolower(str_replace(' ', '_', $empresaNome));
            Cache::forget($cacheKey);
        }

        $this->redirect('/admin');
    }
}
