<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class QueueHealthWidget extends Widget
{
    protected static string $view = 'filament.widgets.queue-health-widget';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 2;
    protected static ?string $heading = 'Saude das Filas';

    public array $queues = [];
    public int $totalFailed = 0;
    public int $totalPending = 0;
    public string $lastUpdated = '';

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $pending = DB::table('jobs')
                ->selectRaw('queue, COUNT(*) as total')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');

            $processing = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->selectRaw('queue, COUNT(*) as total')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');

            $failed = DB::table('failed_jobs')
                ->selectRaw('queue, COUNT(*) as total')
                ->groupBy('queue')
                ->get()
                ->keyBy('queue');

            $allQueues = collect(array_unique(array_merge(
                $pending->keys()->toArray(),
                $failed->keys()->toArray(),
                ['default']
            )));

            $this->queues = $allQueues->map(function ($name) use ($pending, $processing, $failed) {
                return [
                    'name'       => $name,
                    'pending'    => $pending->get($name)?->total ?? 0,
                    'processing' => $processing->get($name)?->total ?? 0,
                    'failed'     => $failed->get($name)?->total ?? 0,
                ];
            })->filter(fn ($q) => $q['pending'] > 0 || $q['failed'] > 0 || $q['processing'] > 0 || $q['name'] === 'default')
            ->values()
            ->toArray();

            $this->totalFailed  = (int) DB::table('failed_jobs')->count();
            $this->totalPending = (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            $this->queues       = [];
            $this->totalFailed  = 0;
            $this->totalPending = 0;
        }

        $this->lastUpdated = now()->format('H:i:s');
    }

    public function clearOldFailed(): void
    {
        try {
            $deleted = DB::table('failed_jobs')
                ->where('failed_at', '<', now()->subDays(7))
                ->delete();

            Notification::make()
                ->title("Removidos {$deleted} failed_jobs com mais de 7 dias")
                ->success()
                ->send();

            $this->loadData();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Erro ao limpar: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
}