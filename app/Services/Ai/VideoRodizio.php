<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-ECOSSISTEMA (12/08) — REVEZAMENTO ENTRE CLIENTES.
 *
 * O QUE ESTAVA ERRADO, MEDIDO HOJE.
 * A frota ja se distribuia bem entre MOTORES (AiEnginePool::ordenarParaDistribuir:
 * falha recente por ultimo -> quem gerou menos hoje -> mais tempo ocioso). O que
 * nao existia era distribuicao entre CLIENTES. Como a fila e FIFO, quem manda 7
 * pedidos de uma vez ocupa 7 motores e todo mundo que chegou depois espera a fila
 * inteira dele. Medido nas ultimas 6h de 12/08:
 *
 *     u1233: 20 pedidos      u2964:  5
 *     u592 : 17 pedidos      u1848:  4
 *     u2955:  8              u2942:  3
 *     u659 :  7              u1877:  1
 *     u645 :  7              u3054:  1
 *
 * Dois clientes = 37 dos 73 pedidos. Quem pediu 1 video ficou atras de 37.
 *
 * A REGRA. Cada cliente tem direito a uma FATIA da frota, recalculada a cada
 * pedido: cota = motores_uteis / clientes_na_roda (minimo 1). Enquanto o cliente
 * ja estiver ocupando a sua cota, o proximo pedido DELE espera; a vaga que vagar
 * vai pra quem ainda nao tem nenhuma. Isso da exatamente "um pedido de cada
 * cliente por rodada, depois o segundo de cada", e se ajusta sozinho: com 3
 * clientes e 11 motores cada um leva 3 de uma vez; com 11 clientes, 1 cada.
 *
 * PORQUE ISSO NAO TRAVA NINGUEM.
 *  - a cota nunca e menor que 1: cliente sozinho na fila nunca fica esperando;
 *  - quando o cliente esta acima da cota, o pedido VOLTA PRA FILA (capacidade),
 *    nao morre: ele nao segura slot de worker enquanto espera;
 *  - qualquer erro de leitura aqui e FAIL-OPEN (libera). Uma trava de justica
 *    nunca pode ser motivo de video nao sair.
 */
class VideoRodizio
{
    /** Passos que significam "este pedido esta com um motor na mao AGORA". */
    private const PASSOS_OCUPANDO = ['render'];

    /** Alem disso, so conta quem deu sinal de vida neste tempo (segundos). */
    private const VIVO_S = 240;

    /** Janela pra considerar um cliente "na roda" (esperando ou gerando). */
    private const RODA_MIN = 20;

    /**
     * E a vez deste cliente? Devolve o retrato da decisao — sempre, pra virar log
     * e pra aparecer no painel. `permitido=false` significa "espera a sua vez".
     */
    public static function vez(int $userId, int $pipelineId): array
    {
        $base = [
            'permitido' => true,
            'user_id'   => $userId,
            'pipeline'  => $pipelineId,
            'motivo'    => 'liberado',
        ];

        try {
            $motores  = self::motoresUteis();
            $clientes = self::clientesNaRoda();
            $meus     = self::ocupadosPor($userId, $pipelineId);
            $ocupados = self::ocupadosTotal($pipelineId);

            // Cota proporcional, nunca menor que 1.
            $cota   = max(1, intdiv($motores, max(1, $clientes)));
            $livres = max(0, $motores - $ocupados);

            $ret = $base + [
                'motores'  => $motores,
                'clientes' => $clientes,
                'meus'     => $meus,
                'cota'     => $cota,
                'ocupados' => $ocupados,
                'livres'   => $livres,
            ];

            // ══ ESTOURA A COTA QUANDO HA MOTOR OCIOSO ═════════════════════════
            //
            // A cota existe pra impedir que um cliente empurre os outros pra tras,
            // NAO pra deixar motor parado. Se sobra vaga na frota, ninguem esta
            // sendo empurrado — segurar o pedido aqui seria desperdicio puro:
            // frota ociosa de um lado, cliente esperando do outro.
            //
            // A justica so entra em cena quando ha DISPUTA (livres = 0). Ai sim o
            // proximo motor que vagar vai pra quem tem menos, e nao pra quem
            // chegou primeiro na fila.
            //
            // Corrida aqui e inofensiva: se dois pedidos lerem "tem vaga" ao mesmo
            // tempo, quem decide de verdade e o lock do motor (e o da conta). Este
            // portao e de ADMISSAO, nao de exclusao.
            if ($meus >= $cota) {
                if ($livres > 0) {
                    $ret['motivo'] = 'acima_da_cota_mas_ha_motor_ocioso';
                    return $ret;
                }
                $ret['permitido'] = false;
                $ret['motivo']    = 'cota_do_cliente_cheia';
                return $ret;
            }

            return $ret;
        } catch (\Throwable $e) {
            // FAIL-OPEN: justica nunca bloqueia producao.
            Log::warning('[SEL-ECOSSISTEMA][rodizio] nao consegui calcular a vez, liberando', [
                'user' => $userId, 'pipeline' => $pipelineId,
                'err'  => mb_substr($e->getMessage(), 0, 160),
            ]);
            return $base + ['motivo' => 'falha_no_calculo_liberado'];
        }
    }

    /**
     * Quantos motores estao de fato utilizaveis agora.
     *
     * Le os MESMOS sinais que o AiEnginePool usa pra reservar (pc_status do
     * pool.json + veredito da sonda de sessao), pra a cota nao ser calculada em
     * cima de uma frota imaginaria. Conservador de proposito: na duvida, conta.
     */
    public static function motoresUteis(): int
    {
        $n = 0;
        foreach (DB::table('ai_engines')->where('tool_type', 'video')->where('is_active', 1)->get(['config_json']) as $e) {
            $c = json_decode($e->config_json ?? '{}', true) ?: [];

            if (! empty($c['fora_do_ar_motivo']) || ! empty($c['desligado_manual'])) { continue; }
            if (! empty($c['precisa_reconectar'])) { continue; }

            // Mesmo portao do pool: motor onde o worker acabou de ver tela de login
            // nao entra na conta da frota (senao a cota e calculada em cima de
            // motores que so sabem falhar, e todo mundo fica com cota alta demais).
            if (! empty($c['worker_loginwall_em'])) {
                try {
                    if (\Illuminate\Support\Carbon::parse($c['worker_loginwall_em'])->gt(now()->subMinutes(10))) { continue; }
                } catch (\Throwable $e) { /* fail-open */ }
            }

            $sondaViva = ! empty($c['sessao_valida']);
            $status    = $c['pc_status'] ?? null;

            // Mesma regra do pool (SEL-SONDAMANDA): a sonda vence o retrato velho do PC.
            if ($sondaViva && in_array($status, ['login_wall', 'reconnect'], true)) { $status = 'ready'; }
            if (is_string($status) && $status !== '' && $status !== 'ready') { continue; }
            if (array_key_exists('sessao_valida', $c) && $c['sessao_valida'] === false) { continue; }

            $n++;
        }

        return $n;
    }

    /** Clientes distintos com pedido esperando ou gerando na janela da roda. */
    public static function clientesNaRoda(): int
    {
        return (int) DB::table('ai_video_pipelines')
            ->whereNotIn('step', ['done', 'failed', 'canceled'])
            ->where('updated_at', '>', now()->subMinutes(self::RODA_MIN))
            ->distinct()
            ->count('user_id');
    }

    /**
     * Quantos motores este cliente ja esta ocupando agora (fora o pedido atual).
     *
     * "Ocupando" = pipeline em render com batida recente. Sem o corte de tempo a
     * conta inflaria com pipeline zumbi e o cliente ficaria eternamente sem vez —
     * o oposto do que a trava quer.
     */
    public static function ocupadosPor(int $userId, int $exceto = 0): int
    {
        return (int) DB::table('ai_video_pipelines')
            ->where('user_id', $userId)
            ->where('id', '<>', $exceto)
            ->whereIn('step', self::PASSOS_OCUPANDO)
            ->where('updated_at', '>', now()->subSeconds(self::VIVO_S))
            ->count();
    }

    /**
     * Quantos motores a frota INTEIRA esta ocupando agora. E o que diz se existe
     * disputa de verdade — sem isso a cota puniria cliente com a frota vazia.
     */
    public static function ocupadosTotal(int $exceto = 0): int
    {
        return (int) DB::table('ai_video_pipelines')
            ->where('id', '<>', $exceto)
            ->whereIn('step', self::PASSOS_OCUPANDO)
            ->where('updated_at', '>', now()->subSeconds(self::VIVO_S))
            ->count();
    }

    /**
     * Retrato do revezamento pro painel: quem esta na roda, quanto cada um tem e
     * quanto pode ter. E a resposta visual pra "quem esta esperando e por que".
     */
    public static function retrato(): array
    {
        try {
            $motores  = self::motoresUteis();
            $clientes = self::clientesNaRoda();
            $cota     = max(1, intdiv($motores, max(1, $clientes)));
            $ocupados = self::ocupadosTotal();
            $livres   = max(0, $motores - $ocupados);

            $linhas = [];
            $rows = DB::table('ai_video_pipelines')
                ->selectRaw('user_id, sum(step = "render") ocupando, count(*) na_roda, min(created_at) mais_antigo')
                ->whereNotIn('step', ['done', 'failed', 'canceled'])
                ->where('updated_at', '>', now()->subMinutes(self::RODA_MIN))
                ->groupBy('user_id')
                ->orderByDesc('na_roda')
                ->get();

            foreach ($rows as $r) {
                $linhas[] = [
                    'user_id'     => (int) $r->user_id,
                    'ocupando'    => (int) $r->ocupando,
                    'na_roda'     => (int) $r->na_roda,
                    'cota'        => $cota,
                    'no_limite'   => (int) $r->ocupando >= $cota,
                    // Acima da cota mas seguindo em frente porque sobra motor: o
                    // painel tem que mostrar que isso e DECISAO, nao furo da regra.
                    'estourando_por_ociosidade' => ((int) $r->ocupando >= $cota) && $livres > 0,
                    'mais_antigo' => $r->mais_antigo,
                ];
            }

            return [
                'motores_uteis' => $motores,
                'clientes'      => $clientes,
                'cota_por_cliente' => $cota,
                'motores_ocupados' => $ocupados,
                'motores_livres'   => $livres,
                'regra_agora'      => $livres > 0 ? 'guloso (ha motor ocioso)' : 'cota justa (frota em disputa)',
                'clientes_detalhe' => $linhas,
            ];
        } catch (\Throwable $e) {
            return ['erro' => mb_substr($e->getMessage(), 0, 160)];
        }
    }
}
