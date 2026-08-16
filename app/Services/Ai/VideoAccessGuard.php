<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-407: só quem pagou gera vídeo.
 *
 * Ruan, 30/07: "bloqueia só quem fez pagamento que consegue gerar vídeo. Se não
 * tá nessa regra, bloqueia todo mundo. Faz bloqueios pra ser impossível burlar."
 *
 * Motivo: os campos `ai_monthly_video_limit` e `ai_monthly_credits` existiam na
 * tabela `plans` e NINGUÉM os lia — só telas de admin escreviam. Na prática
 * qualquer pessoa gerava à vontade. Caso real: cliente entrou às 02:02 no teste
 * grátis de 3 dias (limite 0, créditos 0) e gerou 4 vídeos em 13 minutos,
 * consumindo crédito do Kling pago pelo Ruan.
 *
 * A checagem vive aqui e é chamada de TODOS os pontos de entrada — os jobs, não
 * só o controller — pra que chamar a API direto não contorne nada.
 */
class VideoAccessGuard
{
    /** Planos que liberam vídeo mesmo com preço mensal 0 (são anuais pagos). */
    private const SLUGS_PAGOS_ANUAIS = ['tt_shop_annual', 'drop_start', 'drop_meio', 'drop_top'];

    /** Status de assinatura que contam como paga. Trial NÃO conta. */
    private const STATUS_PAGO = ['active'];

    /**
     * SEL-ACESSO (12/08): compra reconhecida, acesso ainda NAO liberado.
     *
     * Existem 221 assinaturas nesse status (plano 100 "Vídeo IA — Ilimitado",
     * importadas em 12/08) e a string nao aparecia em NENHUM lugar do codigo —
     * ou seja, o guard tratava essa gente como "sem assinatura" e mandava
     * "Ative seu plano" pra quem JA comprou. Continua barrado (liberar e
     * decisao do dono), mas agora a mensagem diz a verdade.
     *
     * NAO entra em STATUS_PAGO de proposito: isto nao libera nada.
     */
    private const STATUS_AGUARDANDO_LIBERACAO = 'pending_release';

    /**
     * @return array{ok: bool, motivo: string, mensagem: string}
     */
    public static function pode(?int $userId): array
    {
        if (! $userId) {
            return self::nao('sem_usuario', 'Não consegui identificar sua conta. Entre de novo e tente.');
        }

        // SEL-CONVITE: trial do /convite NAO tem client (conta enxuta criada no
        // login por convite). Respeita a trava dura e a pausa global (se o Ruan
        // desligar tudo, o trial tambem para), mas dispensa client/assinatura — o
        // direito de gerar vem do proprio trial (1 video, dentro das 24h). O CLAIM
        // atomico do unico video acontece no barrarPipeline; aqui é leitura.
        $trialConvite = \App\Services\InviteTrialService::activeTrial($userId);
        if ($trialConvite) {
            if (self::travadoTotalmente()) {
                return self::nao('geracao_travada', 'A geração de vídeo está desligada no momento. Volta em breve.');
            }
            if (self::pausadoGlobalmente()) {
                return self::nao('geracao_pausada', 'A geração de vídeo está temporariamente pausada para manutenção. Voltamos em breve.');
            }
            if ($trialConvite->video_used_at) {
                return self::nao('trial_video_usado', 'Seu teste do convite permite apenas 1 vídeo. Assine para gerar mais.');
            }
            return ['ok' => true, 'motivo' => 'convite_trial', 'mensagem' => ''];
        }

        $client = DB::table('clients')->where('user_id', $userId)->first(['id']);
        if (! $client) {
            return self::nao('sem_client', 'Sua conta ainda não está completa. Fale com o suporte.');
        }

        // SEL-469 (31/07, Ruan: "trava tudo, nao gera mais nada ate eu mandar").
        // Trava DURA: fica ANTES do super_admin de proposito. A pausa do SEL-415
        // libera o admin pra poder validar conserto; esta aqui nao libera ninguem,
        // porque o pedido foi parar o consumo por completo -- inclusive o nosso,
        // que foi quem gastou os pontos do plano dele nas ultimas 24h (9 geracoes,
        // todas na conta dele, nenhuma de cliente).
        // Religar (so com ordem dele):
        //   UPDATE settings SET value='0' WHERE `group`='video_billing' AND `key`='video_generation_hard_block';
        if (self::travadoTotalmente()) {
            return self::nao('geracao_travada',
                'A geração de vídeo está desligada no momento. Volta em breve.');
        }

        // super_admin passa (uso interno)
        $role = DB::table('users')->where('id', $userId)->value('role');
        if ($role === 'super_admin') {
            return ['ok' => true, 'motivo' => 'super_admin', 'mensagem' => ''];
        }

        // SEL-415 — Ruan 30/07: "barrar todos até ajustarmos".
        //
        // Fica DEPOIS do super_admin de propósito (revisão 30/07): a pausa é pra
        // cliente, não pra quem vai consertar. Com o admin barrado junto, ninguém
        // conseguia validar o ajuste que a própria pausa estava esperando — e a
        // ordem era "até ajustarmos". Cliente continua barrado, inclusive pagante.
        //
        // Interruptor no banco, NÃO em env()/config: com config:cache ligado o
        // env() volta vazio e o interruptor simplesmente não funciona — foi
        // exatamente o que derrubou o client/status (INF-067) e o KLING_MODE
        // (SEL-411) neste mesmo servidor. Aqui a consulta é feita toda vez, de
        // propósito: memorizar em static faria o worker (processo longo) ficar
        // preso no valor antigo e ignorar o religamento.
        if (self::pausadoGlobalmente()) {
            return self::nao('geracao_pausada',
                'A geração de vídeo está temporariamente pausada para manutenção. Voltamos em breve.');
        }

        // AFILIADO e entidade PROPRIA — NAO e cliente pagante. Tem a geracao de
        // video como PERK do programa de afiliados (max_ai_videos_per_month,
        // granted_plan_slug). Acesso proprio, separado do gate de assinatura do
        // cliente (Ruan 07/08: "afiliado nao e o cliente, nao mistura").
        $afiliado = DB::table('affiliates')->where('user_id', $userId)
            ->where('approval_status', 'approved')->where('status', 'active')
            ->first(['id']);
        if ($afiliado) {
            return ['ok' => true, 'motivo' => 'afiliado', 'mensagem' => ''];
        }

        $sub = DB::table('subscriptions as s')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.client_id', $client->id)
            ->whereIn('s.status', self::STATUS_PAGO)
            ->orderByDesc('s.id')
            ->first(['s.id as sub_id', 's.status', 's.plan_id', 'p.slug', 'p.price_monthly', 'p.price_yearly']);

        if (! $sub) {
            // SEL-ACESSO: quem esta em 'pending_release' JA COMPROU — o acesso e
            // que ainda nao foi liberado. Mandar "Ative seu plano" pra essa
            // pessoa e o que vira chamado de suporte (e, pior, faz cliente pagar
            // de novo). Barrado igual, mas dizendo o que esta acontecendo.
            $aguardando = DB::table('subscriptions')
                ->where('client_id', $client->id)
                ->where('status', self::STATUS_AGUARDANDO_LIBERACAO)
                ->exists();

            if ($aguardando) {
                return self::nao('acesso_em_preparacao',
                    'Recebemos sua compra! Seu acesso ao Studio está sendo preparado e será liberado em instantes. '
                    . 'Não é preciso pagar de novo — assim que estiver pronto você recebe um e-mail avisando.');
            }

            return self::nao('sem_assinatura_ativa',
                'A geração de vídeo é exclusiva para assinantes. Ative seu plano para usar o Studio.');
        }

        if (! $sub->plan_id) {
            // Assinatura ativa sem plano vinculado — bug conhecido (39 casos no hub).
            // Não libera: sem plano não dá pra saber o que foi pago.
            Log::warning('[SEL-407] assinatura ativa sem plano — vídeo bloqueado', [
                'user_id' => $userId, 'sub_id' => $sub->sub_id,
            ]);
            return self::nao('assinatura_sem_plano',
                'Sua assinatura está ativa mas sem plano vinculado. Fale com o suporte que resolvemos rápido.');
        }

        $pago = ((float) ($sub->price_monthly ?? 0)) > 0
             || ((float) ($sub->price_yearly ?? 0)) > 0
             || in_array((string) $sub->slug, self::SLUGS_PAGOS_ANUAIS, true);

        if (! $pago) {
            return self::nao('plano_gratuito',
                'Seu plano atual não inclui geração de vídeo. Faça upgrade para liberar o Studio.');
        }

        // SEL-407 F2: assinante pagante passa pela cota em reais. Dentro da cota do
        // plano e por conta da assinatura; passou, so com saldo na carteira.
        $q = VideoQuotaService::pode((int) $client->id);
        if (! $q['ok']) {
            return self::nao($q['motivo'], $q['mensagem']);
        }

        return ['ok' => true, 'motivo' => 'assinante_' . $sub->slug . '_' . $q['fonte'], 'mensagem' => ''];
    }

    /**
     * SEL-410: ponto de entrada dos JOBS. Se o acesso for negado, marca o
     * pipeline como ESTADO FINAL com a mensagem ao cliente e devolve true
     * (o job deve dar return na hora).
     *
     * Por que existe: os jobs chamavam exigir(), que lança exceção ANTES do
     * try/catch do handle(). A exceção subia, o job caía em failed_jobs e a
     * linha em ai_video_pipelines ficava em step='queued' PARA SEMPRE — o
     * cliente via "gerando..." e nunca recebia nada nem explicação.
     * Casos reais: pipelines 217, 218, 219, 220, 221 (users 2694/2696/2697).
     *
     * O estado final é 'failed' de propósito: o SSE de progresso
     * (StudioChatController::progress) só encerra o stream em 'done' ou
     * 'failed'. Um step novo tipo 'blocked' deixaria o front girando de novo,
     * que é exatamente o bug que estamos matando.
     *
     * A REGRA NÃO AFROUXA: quem não pode continua não podendo. Muda só o que
     * acontece depois do "não".
     */
    public static function barrarPipeline(?int $pipelineId, string $origem = 'video'): bool
    {
        if (! $pipelineId) {
            return false;
        }

        $userId = DB::table('ai_video_pipelines')->where('id', $pipelineId)->value('user_id');

        // SEL-CONVITE: trial do /convite -> CLAIM atomico do 1 video, amarrado a
        // ESTE pipeline. So a 1a geracao ganha; a 2a recebe false e é barrada.
        // Job encadeado/retry do MESMO pipeline passa (video_pipeline_id bate).
        if ($userId !== null) {
            $trial = \App\Services\InviteTrialService::activeTrial((int) $userId);
            if ($trial) {
                if (self::travadoTotalmente() || self::pausadoGlobalmente()) {
                    return self::encerrarPipeline($pipelineId, $userId, 'geracao_pausada',
                        'A geração de vídeo está temporariamente indisponível. Volta em breve.', $origem);
                }
                if ((int) $trial->video_pipeline_id === (int) $pipelineId) {
                    return false; // ja reservado por ESTE pipeline
                }
                if (\App\Services\InviteTrialService::claimVideo((int) $trial->id, (int) $pipelineId)) {
                    return false; // reservou agora -> libera o unico video
                }
                return self::encerrarPipeline($pipelineId, $userId, 'trial_video_usado',
                    'Seu teste do convite permite apenas 1 vídeo. Assine para gerar mais.', $origem);
            }
        }

        $r = self::pode($userId !== null ? (int) $userId : null);

        if ($r['ok']) {
            return false;
        }

        return self::encerrarPipeline($pipelineId, $userId, $r['motivo'], $r['mensagem'], $origem);
    }

    /** SEL-410/CONVITE: encerra o pipeline como 'failed' com mensagem ao cliente. */
    private static function encerrarPipeline($pipelineId, $userId, string $motivo, string $mensagem, string $origem): bool
    {
        Log::warning('[SEL-410] geração de vídeo bloqueada — pipeline encerrado com mensagem', [
            'pipeline_id' => $pipelineId,
            'user_id'     => $userId,
            'motivo'      => $motivo,
            'origem'      => $origem,
        ]);

        DB::table('ai_video_pipelines')->where('id', $pipelineId)->update([
            'step'          => 'failed',
            'error_message' => $mensagem,
            'updated_at'    => now(),
        ]);

        return true;
    }

    /** Lança exceção — usado dentro dos jobs, onde não há resposta HTTP. */
    public static function exigir(?int $userId, string $origem = 'video'): void
    {
        $r = self::pode($userId);
        if ($r['ok']) {
            return;
        }
        Log::warning('[SEL-407] geração de vídeo bloqueada', [
            'user_id' => $userId, 'motivo' => $r['motivo'], 'origem' => $origem,
        ]);
        throw new \RuntimeException('video_bloqueado:' . $r['motivo'] . ':' . $r['mensagem']);
    }

    /**
     * SEL-415: interruptor global de geração de vídeo.
     *
     * Ligar (barra todo mundo):
     *   UPDATE settings SET value='1' WHERE `group`='video_billing' AND `key`='video_generation_paused';
     * Desligar (religa):
     *   UPDATE settings SET value='0' WHERE `group`='video_billing' AND `key`='video_generation_paused';
     *
     * Sem deploy, sem artisan, sem restart de worker — vale na chamada seguinte.
     */
    /**
     * SEL-469 — trava dura, sem excecao. Diferente de pausadoGlobalmente(), que
     * deixa o admin passar. Consulta feita toda vez, de proposito: memorizar em
     * static faria o worker (processo longo) ignorar o religamento.
     */
    public static function travadoTotalmente(): bool
    {
        try {
            $v = DB::table('settings')
                ->where('group', 'video_billing')
                ->where('key', 'video_generation_hard_block')
                ->value('value');

            return in_array((string) $v, ['1', 'true', 'on', 'sim'], true);
        } catch (\Throwable $e) {
            // banco fora do ar: nao e hora de decidir politica -- nesse cenario
            // nada gera mesmo, porque o pipeline tambem nao grava.
            Log::warning('[SEL-469] nao consegui ler a trava dura', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    public static function pausadoGlobalmente(): bool
    {
        try {
            $v = DB::table('settings')
                ->where('group', 'video_billing')
                ->where('key', 'video_generation_paused')
                ->value('value');

            return in_array((string) $v, ['1', 'true', 'on', 'sim'], true);
        } catch (\Throwable $e) {
            // Banco fora do ar: não é hora de decidir política de pausa — nesse
            // cenário nada gera mesmo, porque o pipeline também não grava.
            Log::warning('[SEL-415] nao consegui ler o interruptor de pausa', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    private static function nao(string $motivo, string $msg): array
    {
        return ['ok' => false, 'motivo' => $motivo, 'mensagem' => $msg];
    }
}
