<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class DivergenciasPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-exclamation-triangle';
    protected static ?string $title           = 'Divergências de Sync';
    protected static ?string $navigationLabel = 'Divergências';
    protected static ?string $navigationGroup = 'Operações';
    protected static ?string $slug            = 'divergencias';
    protected static ?int    $navigationSort  = 51;
    protected static string  $view            = 'filament.pages.divergencias';

    public array  $divergences   = [];
    public int    $totalPending  = 0;
    public int    $totalResolved = 0;
    public string $lastUpdated   = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $rows = DB::table('tenant_divergence_log')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'tenant_id', 'check_id', 'kind', 'subject', 'detail', 'resolved', 'resolved_at', 'created_at']);

            $this->divergences = $rows->map(fn ($r) => (array) $r)->toArray();

            $this->totalPending  = (int) DB::table('tenant_divergence_log')->where('resolved', 0)->count();
            $this->totalResolved = (int) DB::table('tenant_divergence_log')->where('resolved', 1)->count();
        } catch (\Throwable $e) {
            $this->divergences   = [];
            $this->totalPending  = 0;
            $this->totalResolved = 0;
        }

        $this->lastUpdated = now()->setTimezone('America/Sao_Paulo')->format('d/m H:i:s');
    }

    public function markResolved(int $id): void
    {
        try {
            DB::table('tenant_divergence_log')
                ->where('id', $id)
                ->update(['resolved' => 1, 'resolved_at' => now()]);

            Notification::make()
                ->title("Divergência #{$id} marcada como resolvida")
                ->success()
                ->send();

            $this->loadData();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function refresh(): void
    {
        $this->loadData();

        Notification::make()
            ->title('Dados atualizados — ' . $this->lastUpdated)
            ->success()
            ->send();
    }
}
