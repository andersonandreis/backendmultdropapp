<?php

namespace App\Services\Integrations;

use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Centraliza alertas Telegram para falhas de refresh de token de marketplace.
 *
 * Regras de disparo:
 *   - >= 5 contas falham refresh no mesmo ciclo
 *   - Nova conta entra em needs_reauth
 *   - Refresh token Shopee expira em < 7 dias
 *   - Conta bloqueada pelo circuit breaker (3 falhas consecutivas)
 */
class TokenRefreshAlertService
{
    private TelegramNotificationService $telegram;

    /** Acumula falhas do ciclo corrente antes de decidir se alerta */
    private array $cycleFailures = [];

    public function __construct(TelegramNotificationService $telegram)
    {
        $this->telegram = $telegram;
    }

    // --- chamadas durante o ciclo de refresh ---------------------------------

    /**
     * Registra falha de refresh de uma conta.
     * Se >= 5 acumuladas, dispara alerta de volume imediatamente.
     */
    public function recordFailure(string $platform, int $accountId, string $accountName, string $reason): void
    {
        $this->cycleFailures[] = compact('platform', 'accountId', 'accountName', 'reason');

        if (count($this->cycleFailures) >= 5) {
            $this->flushCycleAlert();
        }
    }

    /**
     * Envia alertas restantes ao fim do ciclo (< 5 falhas acumuladas).
     * Chame uma vez ao final de refreshExpiringTokens().
     */
    public function flushCycleAlerts(): void
    {
        if (count($this->cycleFailures) > 0) {
            $this->flushCycleAlert();
        }
    }

    // --- alertas pontuais ----------------------------------------------------

    /**
     * Conta nova entrou em needs_reauth - dispara alerta imediato.
     */
    public function alertNeedsReauth(string $platform, int $accountId, string $accountName, string $reason): void
    {
        $platformLabel = $this->platformLabel($platform);
        $msg = "&#128308; <b>Token expirado - reauth necessaria</b>\n"
             . "Plataforma: <b>{$platformLabel}</b>\n"
             . "Conta: <b>{$accountName}</b> (#ID {$accountId})\n"
             . "Motivo: " . htmlspecialchars($reason, ENT_XML1) . "\n"
             . "Acao: painel HubAI &gt; Integracoes &gt; Reconectar {$platformLabel}";

        Log::warning("[TokenRefreshAlert] needs_reauth: {$platform} #{$accountId} - {$reason}");
        $this->telegram->send($msg);
    }

    /**
     * Circuit breaker ativado (3 falhas consecutivas).
     */
    public function alertCircuitBreaker(string $platform, int $accountId, string $accountName, string $reason): void
    {
        $platformLabel = $this->platformLabel($platform);
        $msg = "&#9889; <b>Circuit breaker ativado</b>\n"
             . "Plataforma: <b>{$platformLabel}</b>\n"
             . "Conta: <b>{$accountName}</b> (#ID {$accountId})\n"
             . "Conta bloqueada apos 3 falhas consecutivas.\n"
             . "Ultimo erro: " . htmlspecialchars($reason, ENT_XML1) . "\n"
             . "Acao: investigar logs e reconectar se necessario.";

        Log::critical("[TokenRefreshAlert] circuit_breaker: {$platform} #{$accountId} - {$reason}");
        $this->telegram->send($msg);
    }

    /**
     * Refresh token Shopee expirando em < 7 dias (usuario deve reconectar).
     */
    public function alertShopeeRefreshTokenExpiring(int $accountId, string $accountName, int $daysLeft): void
    {
        $msg = "&#9888; <b>Refresh token Shopee expira em {$daysLeft} dia(s)</b>\n"
             . "Conta: <b>{$accountName}</b> (#ID {$accountId})\n"
             . "Acao URGENTE: cliente acessa painel HubAI &gt; Integracoes &gt; Reconectar Shopee.\n"
             . "Apos expiracao, sincronizacao para ate nova autorizacao.";

        Log::warning("[TokenRefreshAlert] Shopee refresh_token expirando: #{$accountId} - {$daysLeft}d");
        $this->telegram->send($msg);
    }

    // --- privado -------------------------------------------------------------

    private function flushCycleAlert(): void
    {
        if (empty($this->cycleFailures)) {
            return;
        }

        $count = count($this->cycleFailures);
        $lines = ["&#128308; <b>Refresh de tokens: {$count} conta(s) falharam neste ciclo</b>"];

        foreach ($this->cycleFailures as $f) {
            $label = $this->platformLabel($f['platform']);
            $reason = htmlspecialchars(mb_substr($f['reason'], 0, 80), ENT_XML1);
            $lines[] = "  - <b>{$label}</b> #{$f['accountId']} ({$f['accountName']}): {$reason}";
        }

        $lines[] = "Verifique: ssh root@66.94.100.155 | tail -200 /home/api.hubai.io/public_html/storage/logs/laravel.log";

        $this->telegram->send(implode("\n", $lines));
        $this->cycleFailures = [];
    }

    private function platformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'mercadolivre' => 'Mercado Livre',
            'shopee'       => 'Shopee',
            'magalu'       => 'Magalu',
            'tiktok'       => 'TikTok',
            'bling'        => 'Bling',
            default        => ucfirst($platform),
        };
    }
}