<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia mensagens ao Telegram do Ruan via bot @winpc_iabot.
 * Usado pelo Sentinela para alertas de integracao (tenant-aware: APP_NAME).
 */
class TelegramNotificationService
{
    private string $botToken;
    private string $chatId;

    public function __construct()
    {
        // SEL-411: o token vinha CHUMBADO aqui, e estava MORTO — a API
        // respondia Unauthorized e o alerta morria num Log::warning que ninguem lia.
        // Credencial nao mora em repo compartilhado por 7 backends: agora vem de
        // config/services.php. E config(), nao env(): com bootstrap/cache/config.php
        // env() devolve null (5 dos 7 backends ja rodam cacheados).
        $this->botToken = (string) config('services.telegram.bot_token', '');
        $this->chatId   = (string) config('services.telegram.chat_id', '');
    }

    /** Ha token e destino configurados? */
    public function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->chatId !== '';
    }

    /**
     * Envia e LANCA se nao conseguir. Use onde o alarme e o proprio produto
     * (health check, verificacao) e falhar calado nao serve.
     */
    public function sendOrFail(string $message): void
    {
        if (! $this->send($message)) {
            throw new \RuntimeException(
                'TelegramNotificationService: nao foi possivel entregar o alerta. '
                . ($this->isConfigured()
                    ? 'Token/chat configurados, mas a API do Telegram recusou — ver storage/logs.'
                    : 'TELEGRAM_BOT_TOKEN e/ou TELEGRAM_CHAT_ID_RUAN ausentes no .env deste backend.')
            );
        }
    }

    /**
     * Envia mensagem de texto (HTML parse mode).
     */
    public function send(string $message): bool
    {
        // SEL-411: alarme mudo e pior que nao ter alarme. Antes isso passava batido;
        // agora grita no log em nivel ERROR, e com TELEGRAM_STRICT=true chega a lancar.
        if (! $this->isConfigured()) {
            $faltando = [];
            if ($this->botToken === '') { $faltando[] = 'TELEGRAM_BOT_TOKEN'; }
            if ($this->chatId === '')   { $faltando[] = 'TELEGRAM_CHAT_ID_RUAN'; }
            $erro = '[TelegramNotification] ALARME MUDO — alerta NAO enviado por falta de '
                . implode(' e ', $faltando) . ' no .env de ' . config('app.name');
            Log::error($erro, ['preview' => mb_substr($message, 0, 120)]);
            if (config('services.telegram.strict')) {
                throw new \RuntimeException($erro);
            }

            return false;
        }

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$this->botToken}/sendMessage",
                [
                    'chat_id'    => $this->chatId,
                    'text'       => $message,
                    'parse_mode' => 'HTML',
                ]
            );

            if ($response->failed()) {
                // SEL-411: era warning. Alerta que nao chega e ERRO — foi assim que o
                // token morto passou despercebido (a API respondia Unauthorized).
                Log::error('[TelegramNotification] ALARME MUDO — Telegram recusou o envio', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'app'    => config('app.name'),
                ]);
                if (config('services.telegram.strict')) {
                    throw new \RuntimeException(
                        '[TelegramNotification] Telegram recusou o envio: HTTP ' . $response->status()
                    );
                }

                return false;
            }

            Log::info('[TelegramNotification] Mensagem enviada', ['preview' => mb_substr($message, 0, 80)]);
            return true;
        } catch (\Exception $e) {
            Log::error('[TelegramNotification] Excecao', ['message' => $e->getMessage()]);
            // SEL-411: em strict a excecao tem que SUBIR — senao este catch engole
            // justamente o aviso de que o alarme esta mudo.
            if (config('services.telegram.strict')) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Alerta de integracao quebrada.
     *
     * @param  string  $platform   shopee | mercadolivre
     * @param  string  $clientName Nome do lojista
     * @param  string  $status     needs_reauth | error
     * @param  string  $detail     Mensagem de erro ou motivo
     */
    public function sendIntegrationAlert(
        string $platform,
        string $clientName,
        string $status,
        string $detail
    ): bool {
        $platformLabel = match (strtolower($platform)) {
            'shopee'       => 'Shopee',
            'mercadolivre' => 'Mercado Livre',
            default        => ucfirst($platform),
        };

        $statusLabel = $status === 'needs_reauth'
            ? 'Token expirado - precisa reconectar'
            : 'Erro desconhecido';

        $action = $status === 'needs_reauth'
            ? 'Cliente acessa painel ' . config('app.name') . ' &gt; Integracoes &gt; Reconectar ' . $platformLabel
            : 'Investigar: ssh root@66.94.100.155 && cd /home/api.' . str_replace('-', '.', env('APP_TENANT', 'hubai')) . '/public_html && tail -100 storage/logs/laravel.log';

        $message = implode("\n", [
            '&#128308; <b>Sentinela ' . config('app.name') . '</b>',
            "Integracao: <b>{$platformLabel}</b>",
            "Cliente: <b>" . htmlspecialchars($clientName, ENT_XML1) . "</b>",
            "Status: {$statusLabel}",
            "Detalhe: " . htmlspecialchars($detail, ENT_XML1),
            "Acao necessaria: {$action}",
        ]);

        return $this->send($message);
    }

    /**
     * Relatorio diario OK.
     *
     * @param  array  $summary  ['shopee' => ['ok' => 3, 'renewed' => 1], 'mercadolivre' => [...]]
     */
    public function sendDailyReport(array $summary): bool
    {
        $lines = ['&#9989; <b>Sentinela ' . config('app.name') . ' - Relatorio 07h00</b>'];

        foreach ($summary as $platform => $counts) {
            $label   = match ($platform) {
                'shopee'       => 'Shopee',
                'mercadolivre' => 'Mercado Livre',
                default        => ucfirst($platform),
            };
            $ok      = $counts['ok']           ?? 0;
            $renewed = $counts['renewed']       ?? 0;
            $errors  = $counts['error']         ?? 0;
            $reauth  = $counts['needs_reauth']  ?? 0;

            $detail = "{$ok} conta(s) OK";
            if ($renewed) $detail .= ", {$renewed} renovada(s) automaticamente";
            if ($errors)  $detail .= ", &#9888; {$errors} com erro";
            if ($reauth)  $detail .= ", &#128308; {$reauth} precisam reconectar";

            $lines[] = "<b>{$label}:</b> {$detail}";
        }

        return $this->send(implode("\n", $lines));
    }
}
