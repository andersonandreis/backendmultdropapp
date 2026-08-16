<?php

namespace App\Console\Commands;

use App\Services\Ai\VideoPromptMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * APRENDIZADO DA MEMORIA DE PROMPT — SEL-MEMORIA (12/08/2026).
 *
 * O script memoria-prompt.php (manual) so fotografa o passado. Este comando faz a
 * memoria APRENDER SOZINHA, de minuto em minuto, a partir do laudo do conferente
 * (conferente-video.php escreve payloads.qa — este comando so LE, nunca mexe nele):
 *
 *   - laudo 'ok'  numa geracao FEITA DO ZERO  -> vira receita (ou promove a que
 *     estava como 'ressalva'). Geracao que ja NASCEU de uma receita nao sobrescreve
 *     a receita — senao a variacao (angulo/luz) ia se acumulando a cada reuso.
 *   - laudo 'reprovado' numa geracao que USOU receita -> invalida a receita: 'ok'
 *     cai pra 'ressalva'; se ja estava em 'ressalva', a receita e APAGADA (deu
 *     errado duas vezes, nao merece continuar sendo reaproveitada).
 *   - usos/acertos/ultimo_uso sao RECALCULADOS a partir das geracoes que carregam
 *     _receita_id no payload -> da pra saber quais receitas realmente funcionam.
 *
 * Idempotente: pode rodar de novo sem inflar contador (recalcula, nao incrementa).
 * Fail-open: erro em uma pipeline nao derruba o resto nem afeta geracao nenhuma.
 */
class VideoMemoriaAprendeCommand extends Command
{
    protected $signature = 'video:memoria-aprende
        {--dias=3 : janela de geracoes a considerar}
        {--limit=500 : teto de geracoes por rodada}
        {--dry : so mostra o que faria}';

    protected $description = 'Memoria de prompt: aprende com o laudo do conferente (SEL-MEMORIA)';

    public function handle(): int
    {
        $dias  = max(1, (int) $this->option('dias'));
        $limit = max(1, (int) $this->option('limit'));
        $dry   = (bool) $this->option('dry');

        if (! VideoPromptMemory::ligada()) {
            $this->warn('memoria de prompt desligada (services.memoria_prompt.enabled=false)');

            return self::SUCCESS;
        }

        $novas = 0; $promovidas = 0; $invalidadas = 0; $apagadas = 0; $vistas = 0;

        $pipes = DB::table('ai_video_pipelines')
            ->where('created_at', '>=', now()->subDays($dias))
            ->where('payloads', 'like', '%"qa"%')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'product_key', 'mode', 'payloads', 'created_at']);

        foreach ($pipes as $p) {
            try {
                $pl = json_decode((string) ($p->payloads ?: '{}'), true);
                if (! is_array($pl) || empty($pl['qa'])) {
                    continue;
                }
                $vistas++;
                $laudo     = (string) ($pl['qa']['veredito'] ?? '');
                $receitaId = (int) ($pl['_receita_id'] ?? 0);
                $estilo    = (string) ($pl['estilo'] ?? preg_replace('/^studio_(long|opcoes)_/', '', (string) $p->mode));
                $duracao   = (int) ($pl['duration'] ?? 0);
                $beats     = array_values((array) ($pl['narration_beats'] ?? []));
                $shots     = array_values(array_filter((array) ($pl['clip_prompts'] ?? [])));
                $chave     = (string) $p->product_key;

                // ── reprovado: a receita usada nao presta mais ────────────────────
                if ($laudo === 'reprovado') {
                    if ($receitaId) {
                        $r = DB::table(VideoPromptMemory::TABELA)->where('id', $receitaId)->first();
                        // so age se a reprovacao e MAIS NOVA que a ultima mexida na receita
                        // (senao ficaria rebaixando a mesma receita toda rodada).
                        if ($r && strtotime((string) $p->created_at) > strtotime((string) $r->updated_at)) {
                            if ($dry) {
                                $this->line("  [dry] invalidaria receita {$receitaId} (laudo reprovado na geracao {$p->id})");
                            } elseif ($r->veredito === 'ok') {
                                DB::table(VideoPromptMemory::TABELA)->where('id', $receitaId)
                                    ->update(['veredito' => 'ressalva', 'updated_at' => now()]);
                                $invalidadas++;
                                Log::warning('[SEL-MEMORIA] receita rebaixada pra ressalva (video reprovado)', [
                                    'receita' => $receitaId, 'geracao' => $p->id,
                                ]);
                            } else {
                                DB::table(VideoPromptMemory::TABELA)->where('id', $receitaId)->delete();
                                $apagadas++;
                                Log::warning('[SEL-MEMORIA] receita apagada (reprovou duas vezes)', [
                                    'receita' => $receitaId, 'geracao' => $p->id,
                                ]);
                            }
                        }
                    }

                    continue;
                }

                // ── aprovado/ressalva: vira receita, se tiver material e se NAO
                //    tiver nascido de uma receita (evita realimentar a variacao) ──
                if ($laudo !== 'ok' && $laudo !== 'ressalva') {
                    continue;
                }
                if ($receitaId || ! $beats || ! $shots || $chave === '' || $duracao < 1) {
                    continue;
                }
                // PRIVACIDADE: geracao cuja fala saiu do texto do PROPRIO cliente nunca
                // vira receita. Senao o roteiro pessoal de um ("meu ape nao tem gas...")
                // sairia falado no video de outro cliente. Receita so nasce de fala
                // montada pelo sistema.
                if (! empty($pl['_fala_do_cliente'])) {
                    continue;
                }

                $ex = DB::table(VideoPromptMemory::TABELA)
                    ->where('product_key', $chave)->where('estilo', $estilo)->where('duracao', $duracao)
                    ->first();

                $dados = [
                    'pipeline_origem' => (int) $p->id,
                    'beats'           => json_encode($beats, JSON_UNESCAPED_UNICODE),
                    'clip_prompts'    => json_encode($shots, JSON_UNESCAPED_UNICODE),
                    'narration_text'  => mb_substr((string) ($pl['narration_text'] ?? ''), 0, 1000),
                    'veredito'        => $laudo,
                    'updated_at'      => now(),
                ];

                if (! $ex) {
                    if ($dry) {
                        $this->line("  [dry] gravaria receita nova {$chave}|{$estilo}|{$duracao}s da geracao {$p->id} (laudo {$laudo})");
                    } else {
                        DB::table(VideoPromptMemory::TABELA)->insert($dados + [
                            'product_key' => $chave, 'estilo' => $estilo,
                            'duracao' => $duracao, 'created_at' => now(),
                        ]);
                        $novas++;
                        Log::info('[SEL-MEMORIA] receita nova aprendida', [
                            'produto' => $chave, 'estilo' => $estilo, 'duracao' => $duracao,
                            'geracao' => $p->id, 'laudo' => $laudo,
                        ]);
                    }
                } elseif ($ex->veredito !== 'ok' && $laudo === 'ok'
                    // so promove com geracao MAIS NOVA que a ultima mexida na receita.
                    // Sem isto, uma geracao aprovada ANTIGA ressuscita uma receita que
                    // acabou de ser invalidada por reprovacao (ou rebaixada na mao) —
                    // aconteceu no teste: a receita 3 voltou pra 'ok' sozinha.
                    && strtotime((string) $p->created_at) > strtotime((string) $ex->updated_at)) {
                    if ($dry) {
                        $this->line("  [dry] promoveria receita {$ex->id} pra ok (geracao {$p->id})");
                    } else {
                        DB::table(VideoPromptMemory::TABELA)->where('id', $ex->id)->update($dados);
                        $promovidas++;
                        Log::info('[SEL-MEMORIA] receita promovida pra ok', [
                            'receita' => $ex->id, 'geracao' => $p->id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[SEL-MEMORIA] aprendizado pulou uma geracao', [
                    'pipeline' => $p->id ?? null, 'err' => $e->getMessage(),
                ]);
            }
        }

        // ── placar: quantas vezes cada receita foi reaproveitada e quantas deram
        //    video aprovado. Recalculado (idempotente) a partir dos payloads. ────
        $placar = [];
        foreach (DB::table('ai_video_pipelines')
            ->where('payloads', 'like', '%_receita_id%')
            ->orderByDesc('id')->limit(5000)
            ->get(['id', 'payloads', 'created_at']) as $p) {
            $pl = json_decode((string) ($p->payloads ?: '{}'), true);
            $id = (int) ($pl['_receita_id'] ?? 0);
            if (! $id) {
                continue;
            }
            $placar[$id]['usos']   = ($placar[$id]['usos'] ?? 0) + 1;
            $placar[$id]['ok']     = ($placar[$id]['ok'] ?? 0) + ((($pl['qa']['veredito'] ?? '') === 'ok') ? 1 : 0);
            $placar[$id]['ultimo'] = max($placar[$id]['ultimo'] ?? '', (string) $p->created_at);
        }
        $placarAtualizado = 0;
        foreach ($placar as $id => $n) {
            $r = DB::table(VideoPromptMemory::TABELA)->where('id', $id)->first();
            if (! $r) {
                continue;
            }
            // monotonico: nunca DIMINUI o contador (limpeza de pipeline antiga nao apaga historia)
            $usos    = max((int) $r->usos, (int) $n['usos']);
            $acertos = max((int) $r->acertos, (int) ($n['ok'] ?? 0));
            if ($usos !== (int) $r->usos || $acertos !== (int) $r->acertos) {
                if ($dry) {
                    $this->line("  [dry] receita {$id}: usos {$r->usos}->{$usos} acertos {$r->acertos}->{$acertos}");
                } else {
                    DB::table(VideoPromptMemory::TABELA)->where('id', $id)->update([
                        'usos' => $usos, 'acertos' => $acertos,
                        'ultimo_uso' => $n['ultimo'] ?: $r->ultimo_uso,
                        'updated_at' => DB::raw('updated_at'),
                    ]);
                    $placarAtualizado++;
                }
            }
        }

        $this->info(sprintf(
            'memoria: geracoes com laudo=%d | receitas novas=%d promovidas=%d invalidadas=%d apagadas=%d | placar atualizado=%d | total na memoria=%d',
            $vistas, $novas, $promovidas, $invalidadas, $apagadas, $placarAtualizado,
            DB::table(VideoPromptMemory::TABELA)->count()
        ));

        return self::SUCCESS;
    }
}
