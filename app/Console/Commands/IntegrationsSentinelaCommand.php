<?php

namespace App\Console\Commands;

use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o health check das integracoes e envia alertas via Telegram.
 *
 * Regras de notificacao:
 *   - ERRO_TOKEN / ERRO_API -> alerta Telegram imediato
 *   - OK                    -> apenas loga (sem ruido)
 *   - --daily               -> relatorio completo as 07h00
 */
class IntegrationsSentinelaCommand extends Command
{
    protected $signature = 'integrations:sentinela
                            {--daily : Envia relatorio diario completo via Telegram (usar apenas as 07h00)}';

    protected $description = 'Sentinela 24/7 das integracoes — verifica tokens, tenta renovar e notifica via Telegram';

    public function handle(): int
    {
        Log::info('[Sentinela] Iniciando ciclo do Sentinela ' . config('app.name'));

        // 1. Roda o health check (captura saida JSON)
        Artisan::call('integrations:health-check', ['--json' => true]);
        $raw = Artisan::output();

        $results = json_decode(trim($raw), true);

        if (!is_array($results)) {
            // Nenhuma conta ou saida inesperada
            Log::info('[Sentinela] Nenhuma conta para processar ou saida inesperada.', ['raw' => substr($raw, 0, 200)]);
            $this->info('[Sentinela] Nenhum resultado para processar.');
            return self::SUCCESS;
        }

        $telegram    = app(TelegramNotificationService::class);
        $alertsSent  = 0;
        $summary     = [];

        foreach ($results as $result) {
            $platform   = $result['platform']     ?? 'desconhecido';
            $accountId  = $result['account_id']   ?? '?';
            $clientId   = $result['client_id']    ?? '?';
            $accountName = $result['account_name'] ?? '-';
            $status     = $result['status']       ?? 'DESCONHECIDO';
            $detail     = $result['reason']       ?? '';

            // Agrupa para relatorio diario
            if (!isset($summary[$platform])) {
                $summary[$platform] = ['ok' => 0, 'error' => 0, 'needs_reauth' => 0];
            }

            if ($status === 'OK') {
                $summary[$platform]['ok']++;
                $this->line("  [OK] [{$platform}] conta #{$accountId} ({$accountName})");
                continue;
            }

            // ERRO_TOKEN ou ERRO_API: notifica Ruan
            $this->warn("  [ALERTA] [{$platform}] conta #{$accountId} ({$accountName}): {$status} - {$detail}");

            // Mapeia status para categoria do Telegram
            $sentinelaStatus = str_starts_with($status, 'ERRO_TOKEN') ? 'needs_reauth' : 'error';
            $summary[$platform][$sentinelaStatus === 'needs_reauth' ? 'needs_reauth' : 'error']++;

            // Cooldown 6h por conta — evita flood no Telegram
            $cacheKey = "sentinela_alerted:{$platform}:{$accountId}";
            if (cache()->has($cacheKey)) {
                $this->line("  [SKIP] [{$platform}] conta #{$accountId} - alerta suprimido (cooldown 6h)");
                continue;
            }

            // Nome de exibicao — usa account_name ou fallback
            $displayName = $accountName !== '-' ? $accountName : "Conta #{$accountId} (cliente #{$clientId})";

            $sent = $telegram->sendIntegrationAlert(
                platform:   $platform,
                clientName: $displayName,
                status:     $sentinelaStatus,
                detail:     $detail
            );

            if ($sent) {
                cache()->put($cacheKey, true, now()->addHours(6));
                $alertsSent++;
                Log::warning('[Sentinela] Alerta Telegram enviado', [
                    'platform'   => $platform,
                    'account_id' => $accountId,
                    'status'     => $status,
                ]);
            } else {
                Log::error('[Sentinela] Falha ao enviar alerta Telegram', [
                    'platform'   => $platform,
                    'account_id' => $accountId,
                ]);
            }
        }

        // 2. Relatorio diario (opcao --daily)
        if ($this->option('daily')) {
            $this->sendDailyReport($summary, $telegram);
        }

        $this->info("[Sentinela] Ciclo concluido. Contas: " . count($results) . " | Alertas enviados: {$alertsSent}");
        Log::info('[Sentinela] Ciclo concluido', [
            'total_accounts' => count($results),
            'alerts_sent'    => $alertsSent,
        ]);

        return self::SUCCESS;
    }

    protected function sendDailyReport(array $summary, TelegramNotificationService $telegram): void
    {
        if (empty($summary)) {
            $telegram->send('&#9989; <b>Sentinela ' . config('app.name') . ' - 07h00</b>
Nenhuma conta de marketplace ativa no momento.');
            return;
        }

        $sent = $telegram->sendDailyReport($summary);

        if ($sent) {
            Log::info('[Sentinela] Relatorio diario enviado via Telegram');
        } else {
            Log::error('[Sentinela] Falha ao enviar relatorio diario via Telegram');
        }
    }
}
