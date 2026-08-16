<?php

namespace App\Console\Commands;

use App\Mail\ReauthRequiredMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendReauthEmailsCommand extends Command
{
    protected $signature = "multdrop:send-reauth-emails {--dry-run : Lista os usuarios sem enviar}";

    protected $description = "Envia email de reconexao para usuarios com tokens expirados (needs_reauth / disconnected)";

    public function handle(): int
    {
        $rows = DB::select(
            "SELECT DISTINCT u.email, u.name,
                GROUP_CONCAT(DISTINCT ma.platform ORDER BY ma.platform SEPARATOR \",\") as plataformas
            FROM clients c
            JOIN users u ON u.id = c.user_id
            JOIN marketplace_accounts ma ON ma.client_id = c.id
            WHERE ma.status IN (\"needs_reauth\", \"disconnected\")
            GROUP BY u.id, u.email, u.name
            ORDER BY u.email"
        );

        if (empty($rows)) {
            $this->info("Nenhum usuario com token expirado encontrado.");
            return self::SUCCESS;
        }

        $this->info("Usuarios afetados: " . count($rows));
        $this->newLine();

        $dryRun = $this->option("dry-run");
        $sent = 0;
        $failed = [];

        foreach ($rows as $row) {
            $platforms = array_map(function ($p) {
                return match (trim($p)) {
                    "mercado_livre" => "Mercado Livre",
                    "shopee"        => "Shopee",
                    default         => ucfirst($p),
                };
            }, explode(",", $row->plataformas));

            if ($dryRun) {
                $this->line("[DRY-RUN] {$row->email} ({$row->name}) -> " . implode(", ", $platforms));
                continue;
            }

            try {
                Mail::to($row->email)->send(new ReauthRequiredMail($row->name, $platforms));
                $this->line("OK  {$row->email} -> " . implode(", ", $platforms));
                $sent++;
            } catch (\Throwable $e) {
                $this->error("FAIL {$row->email}: " . $e->getMessage());
                $failed[] = $row->email;
            }
        }

        $this->newLine();
        if (!$dryRun) {
            $this->info("Enviados: {$sent} | Falhas: " . count($failed));
            if (!empty($failed)) {
                $this->warn("Emails com falha: " . implode(", ", $failed));
            }
        }

        return self::SUCCESS;
    }
}
