<?php

namespace App\Console\Commands;

use App\Services\Logging\MigracaoLogger;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Resumo Telegram dos eventos dos clientes em migracao na ultima hora.
 * Anti-spam: 1 alerta detalhado por (email + tipo_erro) por hora.
 * MUL-029 (2026-06-23)
 */
class MigracaoSummary extends Command
{
    protected $signature = "migracao:summary {--hours=1} {--force} {--dry}";
    protected $description = "Resumo Telegram de eventos dos clientes em migracao";

    public function handle(): int
    {
        $hours = (int) $this->option("hours");
        $force = (bool) $this->option("force");
        $dry = (bool) $this->option("dry");

        $emails = MigracaoLogger::getEmails();
        if (empty($emails)) {
            $this->warn("Lista de migrados vazia.");
            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $files = [
            storage_path("logs/migracao-" . now()->subDay()->format("Y-m-d") . ".log"),
            storage_path("logs/migracao-" . now()->format("Y-m-d") . ".log"),
        ];

        $events = [];
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                // Formato Monolog: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: evento {json}
                if (!preg_match("#^\[([0-9 :-]+)\] [a-z]+\.(INFO|ERROR|WARNING|DEBUG): ([^ ]+) (.*)\$#i", $line, $m)) continue;
                $ts = strtotime($m[1]);
                if ($ts < $cutoff) continue;
                $payload = json_decode($m[4], true) ?: [];
                $events[] = [
                    "ts" => $m[1],
                    "level" => strtoupper($m[2]),
                    "evento" => $m[3],
                    "email" => $payload["email"] ?? null,
                    "payload" => $payload,
                ];
            }
        }

        if (empty($events)) {
            $this->info("Nenhum evento na janela de {$hours}h.");
            return self::SUCCESS;
        }

        $byEmail = [];
        foreach ($events as $e) {
            if (!$e["email"]) continue;
            $byEmail[$e["email"]][] = $e;
        }

        $lines = ["<b>Migracao MultDrop — Resumo {$hours}h</b>"];
        $totalErr = 0;
        $totalOk = 0;
        $alertsToSend = [];

        foreach ($byEmail as $email => $evs) {
            $errors = array_filter($evs, fn($e) => $e["level"] === "ERROR");
            $oks = array_filter($evs, fn($e) => $e["level"] !== "ERROR");
            $totalErr += count($errors);
            $totalOk += count($oks);

            $eventTypes = array_count_values(array_map(fn($e) => $e["evento"], $evs));
            $top = array_slice($eventTypes, 0, 3, true);

            $line = "• <b>" . htmlspecialchars($email) . "</b>: " . count($evs) . " eventos";
            if (count($errors) > 0) $line .= " (<b>" . count($errors) . " erros</b>)";
            $lines[] = $line;
            foreach ($top as $evento => $cnt) {
                $lines[] = "   - <code>" . htmlspecialchars($evento) . "</code> x" . $cnt;
            }

            // Anti-spam: 1 alerta por (email + tipo) por hora
            foreach ($errors as $err) {
                $key = "migracao_alert:" . md5($email . "|" . $err["evento"]);
                if ($force || !Cache::has($key)) {
                    Cache::put($key, true, 3600);
                    $alertsToSend[] = [
                        "email" => $email,
                        "evento" => $err["evento"],
                        "msg" => $err["payload"]["message"] ?? "(sem mensagem)",
                        "url" => $err["payload"]["url"] ?? "",
                    ];
                }
            }
        }

        $lines[] = "";
        $lines[] = "Totais: <b>{$totalOk}</b> OK / <b>{$totalErr}</b> erros / " . count($byEmail) . " clientes";

        $message = implode("\n", $lines);

        if ($dry) {
            $this->line($message);
            $this->info("DRY-RUN — nao enviou Telegram. " . count($alertsToSend) . " alertas detalhados.");
            return self::SUCCESS;
        }

        $tg = app(TelegramNotificationService::class);
        $tg->send($message);

        foreach ($alertsToSend as $a) {
            $alertMsg = "<b>Erro novo migracao</b>\n";
            $alertMsg .= "Email: <code>" . htmlspecialchars($a["email"]) . "</code>\n";
            $alertMsg .= "Evento: <code>" . htmlspecialchars($a["evento"]) . "</code>\n";
            $alertMsg .= "Msg: " . htmlspecialchars(mb_substr($a["msg"], 0, 300)) . "\n";
            if ($a["url"]) $alertMsg .= "URL: " . htmlspecialchars(mb_substr($a["url"], 0, 200));
            $tg->send($alertMsg);
        }

        $this->info("Resumo enviado: {$totalOk} OK, {$totalErr} erros, " . count($byEmail) . " clientes, " . count($alertsToSend) . " alertas.");
        return self::SUCCESS;
    }
}
