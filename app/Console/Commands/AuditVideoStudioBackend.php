<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\CatalogBonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-125 audit backend Ruan 16/07: audit E2E do VideoStudio + CatalogBonus.
 *
 * Rode pra confirmar que o deploy do dia esta saudavel:
 *   1. Tabela video_studio_configs existe e tem 4 seeds Kling (SEL-119)
 *   2. Tabela video_avatars existe e tem 11+ seeds (SEL-120)
 *   3. route:list contem 8+ rotas video/avatar/config
 *   4. CatalogBonusService::statusFor($sampleClient) retorna estrutura correta
 *      (SEL-171)
 *
 * Log em storage/logs/audit-videostudio-YYYY-MM-DD.log. Codigo de retorno:
 *   0 = tudo OK
 *   1 = 1+ check falhou
 *
 * Executar: php artisan videostudio:audit
 */
class AuditVideoStudioBackend extends Command
{
    protected $signature = 'videostudio:audit {--show-routes}';
    protected $description = 'Audit E2E backend VideoStudio + CatalogBonus (SEL-125)';

    public function handle(): int
    {
        $verbose = (bool) $this->option('show-routes');
        $started = microtime(true);
        $today   = now()->format('Y-m-d');
        $logPath = storage_path("logs/audit-videostudio-{$today}.log");
        $checks  = [];

        $this->info('SEL-125 videostudio:audit iniciando...');

        // Check 1: video_studio_configs
        $c1 = ['name' => 'video_studio_configs (4 seeds Kling)', 'ok' => false, 'detail' => ''];
        try {
            if (!Schema::hasTable('video_studio_configs')) {
                $c1['detail'] = 'tabela nao existe';
            } else {
                $count = DB::table('video_studio_configs')->count();
                $c1['detail'] = "count={$count}";
                $c1['ok'] = $count >= 4;
            }
        } catch (\Throwable $e) {
            $c1['detail'] = 'exception: ' . $e->getMessage();
        }
        $checks[] = $c1;
        $this->outputCheck($c1);

        // Check 2: video_avatars (11+ seeds — pode nao existir ainda se outra sessao nao mergeou)
        $c2 = ['name' => 'video_avatars (11+ seeds)', 'ok' => false, 'detail' => ''];
        try {
            if (!Schema::hasTable('video_avatars')) {
                $c2['detail'] = 'tabela nao existe (pendente outra sessao criar migration)';
            } else {
                $count = DB::table('video_avatars')->count();
                $c2['detail'] = "count={$count}";
                $c2['ok'] = $count >= 11;
            }
        } catch (\Throwable $e) {
            $c2['detail'] = 'exception: ' . $e->getMessage();
        }
        $checks[] = $c2;
        $this->outputCheck($c2);

        // Check 3: route:list — 8+ rotas video/avatar/config
        $c3 = ['name' => 'route:list contem 8+ rotas video/avatar/config', 'ok' => false, 'detail' => ''];
        try {
            $routes = collect(Route::getRoutes())->filter(function ($route) {
                $uri = $route->uri();
                return str_contains($uri, 'video')
                    || str_contains($uri, 'avatar')
                    || str_contains($uri, 'videostudio/configs');
            });
            $count = $routes->count();
            $c3['detail'] = "count={$count}";
            $c3['ok'] = $count >= 8;
            if ($verbose) {
                foreach ($routes as $r) {
                    $this->line('   - ' . implode('|', $r->methods()) . ' ' . $r->uri());
                }
            }
        } catch (\Throwable $e) {
            $c3['detail'] = 'exception: ' . $e->getMessage();
        }
        $checks[] = $c3;
        $this->outputCheck($c3);

        // Check 4: CatalogBonusService::statusFor
        $c4 = ['name' => 'CatalogBonusService::statusFor retorna estrutura correta', 'ok' => false, 'detail' => ''];
        try {
            $sample = Client::query()->first();
            if (!$sample) {
                // Nao ha clientes ainda — cria stub em memoria pra validar contract
                $sample = new Client();
                $sample->id = 0;
                $sample->first_orders_used = 0;
                $sample->first_orders_bonus_pct = 50;
            }
            $svc = app(CatalogBonusService::class);
            $status = $svc->statusFor($sample);

            $required = ['used', 'remaining', 'bonus_pct', 'eligible'];
            $missing = array_diff($required, array_keys($status));

            if (empty($missing) && is_int($status['used']) && is_int($status['remaining']) && is_int($status['bonus_pct']) && is_bool($status['eligible'])) {
                $c4['ok'] = true;
                $c4['detail'] = 'client_id=' . ($sample->id ?? '(stub)') . ' status=' . json_encode($status);
            } else {
                $c4['detail'] = 'estrutura invalida — missing=' . implode(',', $missing)
                    . ' payload=' . json_encode($status);
            }
        } catch (\Throwable $e) {
            $c4['detail'] = 'exception: ' . $e->getMessage();
        }
        $checks[] = $c4;
        $this->outputCheck($c4);

        // Sumario
        $okCount   = count(array_filter($checks, fn ($c) => $c['ok']));
        $totalTime = round((microtime(true) - $started) * 1000, 2);
        $summary   = "Audit VideoStudio backend concluido em {$totalTime}ms — {$okCount}/" . count($checks) . ' checks OK';

        $this->newLine();
        if ($okCount === count($checks)) {
            $this->info($summary);
        } else {
            $this->warn($summary);
        }

        // Log em arquivo dedicado
        try {
            $logLine = '[' . now()->toIso8601String() . '] ' . $summary . PHP_EOL;
            foreach ($checks as $c) {
                $logLine .= '  ' . ($c['ok'] ? '[OK]  ' : '[FAIL]') . ' ' . $c['name'] . ' — ' . $c['detail'] . PHP_EOL;
            }
            @file_put_contents($logPath, $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            $this->warn('Falha ao gravar log em ' . $logPath . ': ' . $e->getMessage());
        }

        Log::info('videostudio:audit executado', [
            'ok_count' => $okCount,
            'total'    => count($checks),
            'duration_ms' => $totalTime,
            'log_path' => $logPath,
        ]);

        return $okCount === count($checks) ? self::SUCCESS : self::FAILURE;
    }

    private function outputCheck(array $c): void
    {
        $prefix = $c['ok'] ? '<info>[OK]  </info>' : '<error>[FAIL]</error>';
        $this->line($prefix . ' ' . $c['name'] . ' — ' . $c['detail']);
    }
}
