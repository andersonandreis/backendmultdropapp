<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class QueueHealthPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-queue-list';
    protected static ?string $title           = 'Saúde das Filas';
    protected static ?string $navigationLabel = 'Saúde das Filas';
    protected static ?string $navigationGroup = 'Operações';
    protected static ?string $slug            = 'queue-health';
    protected static ?int    $navigationSort  = 50;
    protected static string  $view            = 'filament.pages.queue-health';

    public array  $queues      = [];
    public int    $totalPending = 0;
    public int    $totalFailed  = 0;
    public string $alertLevel   = 'green';
    public string $lastUpdated  = '';

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
            $rows = DB::table('jobs')
                ->select(
                    'queue',
                    DB::raw('COUNT(*) as job_count'),
                    DB::raw('MIN(created_at) as oldest_created_at'),
                    DB::raw('MAX(created_at) as newest_created_at')
                )
                ->groupBy('queue')
                ->orderByDesc('job_count')
                ->get();

            // Most common job per queue
            $mostCommon = DB::table('jobs')
                ->selectRaw('queue, payload, COUNT(*) as cnt')
                ->groupBy('queue', 'payload')
                ->orderByDesc('cnt')
                ->get()
                ->groupBy('queue')
                ->map(fn ($group) => $group->first());

            $this->queues = $rows->map(function ($row) use ($mostCommon) {
                $ageMinutes = $row->oldest_created_at
                    ? (int) round((now()->timestamp - strtotime($row->oldest_created_at)) / 60)
                    : 0;

                $payload = $mostCommon->get($row->queue)?->payload ?? '{}';
                try {
                    $decoded = json_decode($payload, true);
                    $jobClass = $decoded['displayName'] ?? ($decoded['job'] ?? 'desconhecido');
                    // strip namespace, keep class name only
                    $jobClass = class_basename($jobClass);
                } catch (\Throwable) {
                    $jobClass = 'desconhecido';
                }

                return [
                    'queue_name'             => $row->queue,
                    'job_count'              => (int) $row->job_count,
                    'oldest_job_age_minutes' => $ageMinutes,
                    'most_common_job'        => $jobClass,
                ];
            })->toArray();

            $this->totalPending = (int) DB::table('jobs')->count();
            $this->totalFailed  = (int) DB::table('failed_jobs')->count();

            // Determine alert level based on default queue
            $defaultCount = collect($this->queues)
                ->where('queue_name', 'default')
                ->first()['job_count'] ?? $this->totalPending;

            if ($defaultCount > 50000) {
                $this->alertLevel = 'red';
            } elseif ($defaultCount > 10000) {
                $this->alertLevel = 'yellow';
            } else {
                $this->alertLevel = 'green';
            }
        } catch (\Throwable $e) {
            $this->queues       = [];
            $this->totalPending = 0;
            $this->totalFailed  = 0;
            $this->alertLevel   = 'green';
        }

        $this->lastUpdated = now()->setTimezone('America/Sao_Paulo')->format('d/m H:i:s');
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
