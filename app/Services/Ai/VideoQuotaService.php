<?php

namespace App\Services\Ai;

use App\Services\AiWalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-407 F2: cota de geração em REAIS + carteira.
 *
 * Regra do Ruan (30/07, literal): "o cliente que paga R$ 297 tem direito de gerar
 * mais ou menos o limite de R$ 60 de vídeos. Pode gerar enquanto quiser. Quando
 * bater R$ 50 pra mais, bloqueia. Aí ele só gera se botar saldo. Ele bota saldo
 * na carteira via Asaas e volta a gerar — a partir daí é só com crédito."
 *
 * Ou seja: a cota é em dinheiro, não em quantidade de vídeo. Cada geração custa
 * um valor; enquanto a soma do ciclo estiver abaixo da cota, é por conta do plano.
 * Passou, só desconta da carteira.
 */
class VideoQuotaService
{
    /** Cota inclusa no plano, em reais. Configurável por .env. */
    public static function cotaDoPlano(): float
    {
        return (float) (config('services.video_quota.incluso') ?: env('VIDEO_QUOTA_INCLUSA', 50.00));
    }

    /**
     * Custo de uma geração, em reais.
     *
     * ATENÇÃO: este número é premissa e precisa de confirmação do Ruan. Base do
     * cálculo: plano Kling Pro ≈ US$ 32,56/mês ≈ R$ 180 para ~150 vídeos = R$ 1,20.
     * Usei R$ 1,50 pra ter margem. Com cota de R$ 50 dá ~33 vídeos por ciclo, o que
     * bate com "pode gerar enquanto quiser" — diferente do estimateCost antigo do
     * VideoGenerationController (R$ 4/s a 1080p = R$ 48 num vídeo de 12s), que
     * estouraria a cota inteira numa única geração.
     */
    public static function custoPorVideo(): float
    {
        return (float) (config('services.video_quota.custo') ?: env('VIDEO_QUOTA_CUSTO_VIDEO', 1.50));
    }

    /** Quanto já foi gasto no ciclo atual da assinatura. */
    public static function gastoNoCiclo(int $clientId): float
    {
        $inicio = DB::table('subscriptions')
            ->where('client_id', $clientId)
            ->whereIn('status', ['active'])
            ->orderByDesc('id')
            ->value('created_at');

        // ciclo corrente = do último aniversário mensal até agora
        $ref = $inicio ? \Carbon\Carbon::parse($inicio) : now()->startOfMonth();
        while ($ref->copy()->addMonth()->isPast()) {
            $ref->addMonth();
        }

        $qtd = DB::table('ai_video_pipelines as p')
            ->join('clients as c', 'c.user_id', '=', 'p.user_id')
            ->where('c.id', $clientId)
            ->where('p.created_at', '>=', $ref)
            ->where('p.step', '!=', 'failed')
            ->count();

        return round($qtd * self::custoPorVideo(), 2);
    }

    /**
     * Decide se pode gerar e de onde sai o custo.
     * @return array{ok:bool, fonte:string, motivo:string, mensagem:string, gasto:float, cota:float, saldo:float}
     */
    /**
     * SEL-COTAILIMITADA (13/08) — planos ILIMITADOS nao tem teto em reais.
     *
     * Regra do dono, dita em 13/08: "os planos sao ilimitados; o unico que nao e
     * e o de R$29,90, que gera 1 por dia, e afiliado 3 por dia".
     *
     * Quem ja aplica essa regra corretamente e o VideoPlanLimitService:
     *      video_start (R$29,90) daily=1 | video_pro daily=null | video_ultra daily=null
     *      afiliado daily=3
     * O teto de R$50/ciclo daqui era uma PREMISSA de uma sessao anterior — o proprio
     * comentario do custoPorVideo() dizia "precisa de confirmacao do Ruan". Ela nunca
     * foi confirmada e contradizia o nome do produto: o cliente compra "Ilimitado" e
     * batia a parede no 34o video, lendo "seus videos inclusos acabaram".
     *
     * MEDIDO no dia do conserto: 346 assinantes ativos, 2 ja BLOQUEADOS por este teto
     * (um deles cliente pagante real, al.producoes133@gmail.com) e 2 a 80% dele.
     *
     * Aqui so devolvo "ilimitado" pra quem tem plano ilimitado. O limite diario
     * continua valendo e continua sendo aplicado no VideoPlanLimitService — nao mexi
     * nele, pra nao ter duas fontes de verdade pro mesmo limite.
     */
    private static function planoEhIlimitado(int $clientId): bool
    {
        $limite = \Illuminate\Support\Facades\DB::table('subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.client_id', $clientId)
            ->where('s.status', 'active')
            ->orderByDesc('s.id')
            ->value('p.ai_monthly_video_limit');

        return $limite !== null && (int) $limite >= 9999;
    }

    public static function pode(int $clientId): array
    {
        /**
         * SEL-SEM-TETO-EM-REAIS (15/08, ordem do Ruan): "de onde tu ta tirando teto de
         * geracao de video? o unico que tem limite e o 29,90, 1 video por dia".
         *
         * O teto de R$50/ciclo nunca foi decisao dele — nasceu como PREMISSA de uma
         * versao anterior minha (esta escrito ali embaixo, no custoPorVideo: "precisa de
         * confirmacao do Ruan") e ficou ligado barrando cliente. Medido: 7 tentativas
         * barradas em 30 dias (cliente limasandro129) e mais 2 clientes ja acima de 40
         * de 50, prestes a levar o mesmo bloqueio.
         *
         * O limite que EXISTE de verdade e o de 1 video/dia do plano de R$29,90, e ele
         * mora noutro lugar (VideoPlanLimitService, video_start daily=1). Aqui nao trava
         * mais ninguem. Se um dia ele QUISER um teto, e so ligar a chave — com um numero
         * dele, nao com premissa minha.
         */
        if (! config('services.video_quota.ativo', false)) {
            return ['ok' => true, 'fonte' => 'sem_teto', 'motivo' => 'teto_desligado_por_ordem_do_ruan',
                    'mensagem' => '', 'gasto' => 0.0, 'cota' => INF,
                    'saldo' => AiWalletService::getBalance($clientId)];
        }

        // SEL-COTAILIMITADA: plano ilimitado nao passa pelo teto em reais.
        if (self::planoEhIlimitado($clientId)) {
            return ['ok' => true, 'fonte' => 'plano', 'motivo' => 'plano_ilimitado', 'mensagem' => '',
                    'gasto' => self::gastoNoCiclo($clientId), 'cota' => INF,
                    'saldo' => AiWalletService::getBalance($clientId)];
        }

        $cota   = self::cotaDoPlano();
        $gasto  = self::gastoNoCiclo($clientId);
        $custo  = self::custoPorVideo();
        $saldo  = AiWalletService::getBalance($clientId);

        // ainda dentro da cota do plano
        if (($gasto + $custo) <= $cota) {
            return ['ok' => true, 'fonte' => 'plano', 'motivo' => 'dentro_da_cota', 'mensagem' => '',
                    'gasto' => $gasto, 'cota' => $cota, 'saldo' => $saldo];
        }

        // estourou a cota — só com saldo na carteira
        if ($saldo >= $custo) {
            return ['ok' => true, 'fonte' => 'carteira', 'motivo' => 'saldo_carteira', 'mensagem' => '',
                    'gasto' => $gasto, 'cota' => $cota, 'saldo' => $saldo];
        }

        return ['ok' => false, 'fonte' => 'nenhuma', 'motivo' => 'cota_esgotada_sem_saldo',
                'mensagem' => 'Seus vídeos inclusos no plano acabaram neste ciclo. '
                            . 'Adicione saldo na carteira para continuar gerando.',
                'gasto' => $gasto, 'cota' => $cota, 'saldo' => $saldo];
    }

    /**
     * SEL-414: cobra DEPOIS que o vídeo ficou pronto. Este é o ponto de cobrança
     * de verdade — o `cobrar()` abaixo nunca foi chamado por ninguém (auditoria
     * 30/07), então a carteira nunca foi debitada: `lifetime_consumed` estava
     * 0,00 com R$ 290 depositados. Quem tinha saldo gerava de graça pra sempre.
     *
     * Regras que este método respeita, e por quê:
     *
     * 1. NUNCA cobra antes de gerar. É chamado depois do step='done', então
     *    falha de geração não vira cobrança. Era o risco óbvio de chamar
     *    `cobrar()` no início do job.
     *
     * 2. NUNCA lança exceção. O vídeo já foi entregue ao cliente; se a cobrança
     *    falhar, o certo é gritar no log — não derrubar um pipeline que deu
     *    certo e mandar o cliente ver "erro" num vídeo que existe.
     *
     * 3. É idempotente por pipeline. AiVideoPipelineJob tem tries=3; sem a trava
     *    do `ref` um retry de finalize debitaria o mesmo vídeo duas vezes.
     *
     * 4. Só debita o que passou da cota. `gastoNoCiclo()` já conta este pipeline
     *    (ele está 'done' quando chegamos aqui). Se o acumulado do ciclo ainda
     *    cabe na cota, o vídeo é por conta do plano e não sai nada da carteira.
     */
    public static function cobrarPosSucesso(?int $userId, int|string $pipelineId): void
    {
        try {
            if (! $userId) {
                return;
            }

            $client = DB::table('clients')->where('user_id', $userId)->first(['id']);
            if (! $client) {
                return;
            }

            // super_admin é uso interno (Ruan testando) — não tem carteira pra debitar.
            if (DB::table('users')->where('id', $userId)->value('role') === 'super_admin') {
                return;
            }

            $ref = 'pipeline:' . $pipelineId;

            $jaCobrado = DB::table('ai_wallet_transactions')
                ->where('client_id', $client->id)
                ->where('ref', $ref)
                ->where('direction', 'debit')
                ->exists();
            if ($jaCobrado) {
                Log::info('[SEL-414] cobranca ignorada — pipeline ja debitado', ['ref' => $ref]);
                return;
            }

            $gasto = self::gastoNoCiclo((int) $client->id);
            $cota  = self::cotaDoPlano();

            if ($gasto <= $cota) {
                return; // dentro da cota do plano — nada a cobrar
            }

            $custo = self::custoPorVideo();
            $saldoAntes = AiWalletService::getBalance((int) $client->id);
            $novoSaldo  = AiWalletService::debit(
                (int) $client->id,
                $custo,
                'video_generation',
                $ref,
                'Geração de vídeo fora da cota do plano'
            );

            if ($novoSaldo === false) {
                // Saldo acabou entre o check do guard e o fim da geração. O vídeo já
                // saiu; não dá pra desfazer. Registra pra não sumir da contabilidade.
                Log::warning('[SEL-414] video entregue mas carteira sem saldo pra debitar', [
                    'client_id' => $client->id, 'ref' => $ref,
                    'custo' => $custo, 'saldo' => $saldoAntes, 'gasto_ciclo' => $gasto,
                ]);
                return;
            }

            Log::info('[SEL-414] video cobrado da carteira', [
                'client_id' => $client->id, 'ref' => $ref, 'custo' => $custo,
                'saldo_antes' => $saldoAntes, 'saldo_depois' => $novoSaldo,
                'gasto_ciclo' => $gasto, 'cota' => $cota,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SEL-414] falha ao cobrar video (video ja entregue)', [
                'user_id' => $userId, 'pipeline_id' => $pipelineId, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @deprecated SEL-414: não usar. Lança exceção e reavalia a cota, o que só
     * serve ANTES de gerar — e cobrar antes de gerar cobra por falha. A cobrança
     * real é `cobrarPosSucesso()`. Mantido só porque `pode()` compartilha a
     * mesma conta e alguém pode querer o caminho síncrono no futuro.
     */
    public static function cobrar(int $clientId, string $ref = 'video'): void
    {
        $r = self::pode($clientId);
        if (! $r['ok']) {
            throw new \RuntimeException('video_bloqueado:' . $r['motivo'] . ':' . $r['mensagem']);
        }
        if ($r['fonte'] === 'carteira') {
            AiWalletService::debit($clientId, self::custoPorVideo(), 'video_generation', $ref);
            Log::info('[SEL-407] video cobrado da carteira', [
                'client_id' => $clientId, 'custo' => self::custoPorVideo(), 'saldo_antes' => $r['saldo'],
            ]);
        }
    }
}
