<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL (07/08, Ruan): auto-cura do painel TikTok Shop / Kalodata.
 *
 * O scrape diário às vezes grava um snapshot VAZIO/QUEBRADO (ex.: sessão do
 * Kalodata expirada -> 1 linha, 2 itens em vez de ~215 linhas / centenas de
 * itens). O painel lê o snapshot mais recente e "some tudo". Este comando
 * remove os snapshots quebrados do topo, por tipo, ate sobrar um SAUDAVEL —
 * assim o painel cai sempre no ultimo dado bom ARMAZENADO (nunca fica vazio).
 *
 * Roda de hora em hora (Kernel/schedule). Idempotente e seguro: so apaga
 * snapshot cujo total de itens esta abaixo do piso (lixo de scrape).
 */
class HealKalodataSnapshots extends Command
{
    protected $signature = 'tiktok:heal-kalodata {--min=15 : itens mínimos pro snapshot ser considerado saudável} {--dry : só mostra, não apaga}';

    protected $description = 'Remove snapshots quebrados/vazios do kalodata_raw pro painel nunca ficar vazio (auto-cura)';

    private const TYPES = ['products', 'creators', 'lives', 'shops', 'videos'];

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('kalodata_raw')) {
            $this->warn('kalodata_raw não existe — nada a fazer.');
            return self::SUCCESS;
        }

        $min = max(1, (int) $this->option('min'));
        $dry = (bool) $this->option('dry');
        $totalRemovido = 0;

        foreach (self::TYPES as $type) {
            // até 10 saltos pra trás no máximo (evita loop infinito)
            for ($i = 0; $i < 10; $i++) {
                $ultima = DB::table('kalodata_raw')->where('type', $type)->max('snapshot_date');
                if (! $ultima) {
                    break;
                }
                $itens = (int) DB::table('kalodata_raw')
                    ->where('type', $type)->where('snapshot_date', $ultima)
                    ->sum(DB::raw('JSON_LENGTH(payload)'));

                if ($itens >= $min) {
                    // topo saudável — nada a curar neste tipo
                    if ($i === 0) {
                        $this->line("OK   {$type}: {$ultima} ({$itens} itens)");
                    } else {
                        $this->info("CURADO {$type}: agora usa {$ultima} ({$itens} itens)");
                    }
                    break;
                }

                // snapshot quebrado no topo -> remove (cai no anterior)
                $qtd = DB::table('kalodata_raw')->where('type', $type)->where('snapshot_date', $ultima)->count();
                $this->warn("QUEBRADO {$type}: {$ultima} só {$itens} itens ({$qtd} linhas) — " . ($dry ? 'apagaria' : 'apagando'));
                if (! $dry) {
                    DB::table('kalodata_raw')->where('type', $type)->where('snapshot_date', $ultima)->delete();
                    $totalRemovido += $qtd;
                    Log::warning('[heal-kalodata] snapshot quebrado removido', [
                        'type' => $type, 'snapshot_date' => $ultima, 'itens' => $itens, 'linhas' => $qtd,
                    ]);
                }
            }
        }

        $this->info(($dry ? '[DRY] ' : '') . "Concluído. Linhas removidas: {$totalRemovido}");
        return self::SUCCESS;
    }
}
