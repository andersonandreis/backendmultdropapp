<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemMonitorPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $title = 'Monitor do Sistema';
    protected static ?string $navigationLabel = 'Monitor do Sistema';
    protected static ?string $navigationGroup = 'Operações';
    protected static ?int $navigationSort = 99;
    protected static string $view = 'filament.pages.system-monitor';

    public array $stats24h   = ['error' => 0, 'warning' => 0, 'info' => 0];
    public array $stats7d    = ['error' => 0, 'warning' => 0, 'info' => 0];
    public array $byChannel  = [];
    public array $topEvents  = [];
    public array $health     = [
        'failed_jobs'          => 0,
        'queue_size'           => 0,
        'log_size_mb'          => 0,
        'last_error'           => '—',
        'auto_listing_pending' => 0,
        'auto_listing_failed'  => 0,
    ];
    public array $recentErrors = [];
    public bool $tableReady    = false;
    public ?string $loadError  = null;

    public function mount(): void
    {
        $this->loadStats();
    }

    public function loadStats(): void
    {
        try {
            if (! Schema::hasTable('app_logs')) {
                $this->tableReady = false;
                $this->loadError  = 'Tabela app_logs ainda não foi criada. Aguardando migration do backend.';
                $this->loadHealthOnly();
                return;
            }

            $this->tableReady = true;
            $this->loadError  = null;

            foreach (['error', 'warning', 'info'] as $level) {
                $this->stats24h[$level] = DB::table('app_logs')
                    ->where('level', $level)
                    ->where('created_at', '>=', now()->subDay())
                    ->count();

                $this->stats7d[$level] = DB::table('app_logs')
                    ->where('level', $level)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();
            }

            $this->byChannel = DB::table('app_logs')
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('channel, COUNT(*) as total')
                ->groupBy('channel')
                ->orderByDesc('total')
                ->pluck('total', 'channel')
                ->toArray();

            $this->topEvents = DB::table('app_logs')
                ->where('level', 'error')
                ->where('created_at', '>=', now()->subDay())
                ->selectRaw('event, COUNT(*) as total')
                ->groupBy('event')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn($r) => (array) $r)
                ->toArray();

            $lastError = DB::table('app_logs')
                ->where('level', 'error')
                ->latest('created_at')
                ->first(['created_at']);

            $this->recentErrors = DB::table('app_logs')
                ->where('level', 'error')
                ->latest('created_at')
                ->limit(10)
                ->get(['id', 'channel', 'event', 'message', 'created_at', 'context'])
                ->map(fn($r) => (array) $r)
                ->toArray();

            $this->health['last_error'] = $lastError
                ? \Carbon\Carbon::parse($lastError->created_at)
                    ->setTimezone('America/Sao_Paulo')
                    ->format('d/m H:i')
                : '—';

        } catch (\Throwable $e) {
            $this->tableReady = false;
            $this->loadError  = 'Erro ao carregar dados: ' . $e->getMessage();
        }

        $this->loadHealthOnly();
    }

    private function loadHealthOnly(): void
    {
        try {
            $this->health['failed_jobs'] = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $this->health['failed_jobs'] = 0;
        }

        try {
            $this->health['queue_size'] = DB::table('jobs')->count();
        } catch (\Throwable) {
            $this->health['queue_size'] = 0;
        }

        $logPath = storage_path('logs/laravel.log');
        $this->health['log_size_mb'] = file_exists($logPath)
            ? round(filesize($logPath) / 1048576, 1)
            : 0;

        try {
            $this->health['auto_listing_pending'] = DB::table('auto_listing_queue_items')
                ->where('status', 'pending')->count();
        } catch (\Throwable) {
            $this->health['auto_listing_pending'] = 0;
        }

        try {
            $this->health['auto_listing_failed'] = DB::table('auto_listing_queue_items')
                ->where('status', 'failed')->count();
        } catch (\Throwable) {
            $this->health['auto_listing_failed'] = 0;
        }
    }

    public function refresh(): void
    {
        $this->loadStats();

        Notification::make()
            ->title('Dados atualizados')
            ->success()
            ->send();
    }
}
