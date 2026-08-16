<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class ServerHealthWidget extends Widget
{
    protected static string $view = 'filament.widgets.server-health-widget';
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 2;
    protected static ?string $heading = 'Saude do Servidor';

    public array $serverData = [];
    public string $lastUpdated = '';

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $this->serverData = Cache::remember('centro_comando_server_health', 30, function () {
                $data = [];

                // CPU via /proc/stat
                try {
                    $stat1 = file_get_contents('/proc/stat');
                    usleep(500000);
                    $stat2 = file_get_contents('/proc/stat');

                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat1, $m1);
                    preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat2, $m2);

                    if ($m1 && $m2) {
                        $idle1  = $m1[4];
                        $total1 = array_sum(array_slice($m1, 1));
                        $idle2  = $m2[4];
                        $total2 = array_sum(array_slice($m2, 1));
                        $idleDelta  = $idle2 - $idle1;
                        $totalDelta = $total2 - $total1;
                        $data['cpu'] = $totalDelta > 0 ? round((1 - $idleDelta / $totalDelta) * 100, 1) : 0;
                    } else {
                        $data['cpu'] = 0;
                    }
                } catch (\Throwable) {
                    $data['cpu'] = 0;
                }

                // RAM via /proc/meminfo
                try {
                    $meminfo = file_get_contents('/proc/meminfo');
                    preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
                    preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $avail);
                    if ($total && $avail) {
                        $totalKb = (int) $total[1];
                        $availKb = (int) $avail[1];
                        $data['ram_pct']   = round((($totalKb - $availKb) / $totalKb) * 100, 1);
                        $data['ram_total'] = round($totalKb / 1048576, 1);
                        $data['ram_used']  = round(($totalKb - $availKb) / 1048576, 1);
                    } else {
                        $data['ram_pct'] = 0; $data['ram_total'] = 0; $data['ram_used'] = 0;
                    }
                } catch (\Throwable) {
                    $data['ram_pct'] = 0; $data['ram_total'] = 0; $data['ram_used'] = 0;
                }

                // Disco via disk_free_space
                try {
                    $total = disk_total_space('/');
                    $free  = disk_free_space('/');
                    $data['disk_pct']   = $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0;
                    $data['disk_total'] = round($total / 1073741824, 1);
                    $data['disk_used']  = round(($total - $free) / 1073741824, 1);
                } catch (\Throwable) {
                    $data['disk_pct'] = 0; $data['disk_total'] = 0; $data['disk_used'] = 0;
                }

                // Uptime
                try {
                    $uptimeRaw = (float) explode(' ', file_get_contents('/proc/uptime'))[0];
                    $days  = (int) floor($uptimeRaw / 86400);
                    $hours = (int) floor(($uptimeRaw % 86400) / 3600);
                    $data['uptime'] = $days > 0 ? "{$days}d {$hours}h" : "{$hours}h";
                } catch (\Throwable) {
                    $data['uptime'] = '?';
                }

                return $data;
            });
        } catch (\Throwable $e) {
            $this->serverData = [
                'cpu' => 0, 'ram_pct' => 0, 'ram_total' => 0, 'ram_used' => 0,
                'disk_pct' => 0, 'disk_total' => 0, 'disk_used' => 0, 'uptime' => '?',
            ];
        }

        $this->lastUpdated = now()->format('H:i:s');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
}