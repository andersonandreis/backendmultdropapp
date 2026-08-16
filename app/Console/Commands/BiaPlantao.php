<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SEL-PLANTAO (14/08). Ruan, e ele estava certo: "como o agente recebe uma msg e
 * nao responde? ta dormindo? quero super agente atendendo: responde, guia o
 * cliente ate gerar o video, olha se esta gerando, se der erro da uma satisfacao
 * e avisa por e-mail quando acabar a manutencao, e trabalha sem parar".
 *
 * O buraco era esse: a Bia so existia DENTRO do pedido do cliente. Se ele
 * escrevia e nenhuma regra casava, ou se o video dele quebrava as 3 da manha,
 * ninguem falava com ele. Agora tem plantao: roda de minuto em minuto e vai
 * atras do cliente, em vez de esperar.
 */
class BiaPlantao extends Command
{
    protected $signature = 'bia:plantao';
    protected $description = 'Plantao da Bia: ninguem fica sem resposta, ninguem fica sem saber do proprio video';

    private const NAO_FINAIS = ['queued', 'render', 'processing', 'voice', 'lipsync', 'queued_wait'];

    public function handle(): int
    {
        $feitos = 0;
        $feitos += $this->responderQuemFicouFalando();
        $feitos += $this->irAtrasDeQuemTravou();
        $feitos += $this->avisarQuemJaPodePegar();
        $this->info("plantao: {$feitos} acao(oes)");
        return 0;
    }

    /** 1) Cliente falou e ninguem respondeu. Isso nao pode existir. */
    private function responderQuemFicouFalando(): int
    {
        $n = 0;
        $convs = DB::table('chat_conversations')
            ->where('updated_at', '>', now()->subDay())->orderByDesc('id')->limit(80)->get();

        foreach ($convs as $c) {
            $ultima = DB::table('chat_messages')->where('conversation_id', $c->id)
                ->orderByDesc('id')->first();
            if (! $ultima || $ultima->sender === 'agent') continue;
            // 2 min de folga: a resposta na hora ja saiu no proprio pedido do cliente
            if (\Carbon\Carbon::parse($ultima->created_at)->diffInMinutes(now()) < 2) continue;
            if (\Carbon\Carbon::parse($ultima->created_at)->diffInHours(now()) > 24) continue;

            $texto = $this->fichaDoCliente($c->client_id ? (int) $c->client_id : null)
                ?? 'Recebi sua mensagem e nao vou te deixar sem resposta. Me conta em uma frase o '
                   . 'que aconteceu (por exemplo: "cliquei em gerar e ficou parado") que eu resolvo com voce agora.';

            $this->falar($c->id, $texto);
            Log::info("SEL-PLANTAO respondeu conversa {$c->id} (cliente " . ($c->client_id ?: 'visitante') . ')');
            $n++;
        }
        return $n;
    }

    /** 2) Video travado: reenfileira e AVISA o cliente — sem ele precisar perguntar. */
    private function irAtrasDeQuemTravou(): int
    {
        $n = 0;
        // SEL-PLANTAO-IDEMPOTENTE (14/08) — EU CRIEI ESTE LACO HOJE E ELE SE
        // AUTO-ALIMENTAVA. Medido pelo agente de fila: 58 jobs para 6 pipelines
        // (12 a 14 jobs por video), 59% da fila apontando pra pipeline JA done ou
        // failed, +2 jobs/min sustentado com apenas 4 pedidos vivos.
        // Eram tres defeitos somados:
        //   1. dispatch sem olhar se ja existe job na fila pra essa pipeline;
        //   2. o update escrevia step='queued', e 'queued' esta dentro de NAO_FINAIS
        //      -> a pipeline nunca saia do proprio criterio de selecao, ganhando
        //      +1 job por minuto pra sempre;
        //   3. o update nao tinha guarda de step: entre o SELECT e o UPDATE o video
        //      podia ficar pronto, e o plantao sobrescrevia 'done' com 'queued' —
        //      o cliente via video pronto voltar pra "gerando" (aconteceu com o
        //      pedido 1081, do proprio Ruan, corrigido depois pelo reconcile-stuck).
        // Agora: so reenfileira quem NAO tem job vivo na fila E esta parado ha mais
        // de 10 min desde o ultimo toque, e o update so pega quem ainda e nao-final.
        $presos = DB::table('ai_video_pipelines')
            ->whereIn('step', self::NAO_FINAIS)
            ->where('created_at', '<', now()->subMinutes(45))
            ->where('updated_at', '<', now()->subMinutes(10))
            ->get();

        foreach ($presos as $p) {
            // ja tem trabalho vivo pra esse video? entao nao empilha outro.
            $jaNaFila = DB::table('jobs')
                ->whereIn('queue', ['video', 'video-priority', 'video-ruan'])
                ->where('payload', 'like', '%StudioGenerationJob%')
                ->where('payload', 'like', '%:' . (int) $p->id . ';%')
                ->exists();
            if ($jaNaFila) {
                Log::error('[SEL-PLANTAO-IDEMPOTENTE] pipeline ' . $p->id . ' ja tem job na fila — nao empilhei outro');
                continue;
            }

            try {
                \App\Jobs\StudioGenerationJob::dispatch((int) $p->id)->onQueue('video-priority');
                DB::table('ai_video_pipelines')->where('id', $p->id)
                    // guarda anti-corrida: se o video ficou pronto no meio, NAO desfaz.
                    ->whereIn('step', self::NAO_FINAIS)
                    ->update(['step' => 'queued', 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning('SEL-PLANTAO nao reenfileirou ' . $p->id . ': ' . $e->getMessage());
            }

            $conv = $this->conversaDoCliente((int) $p->user_id);
            if ($conv) {
                $min = (int) \Carbon\Carbon::parse($p->created_at)->diffInMinutes(now());
                $this->falar($conv->id,
                    "Passei pra te dar satisfacao sem voce precisar perguntar: seu video (pedido {$p->id}) "
                    . "empacou no meio, ha {$min} minutos. A culpa e nossa, nao sua.\n\n"
                    . "Ja recoloquei ele na frente da fila agora — voce nao precisa refazer nem paga de novo. "
                    . "Assim que ficar pronto ele cai na sua Galeria e eu te aviso aqui e por e-mail.");
                $n++;
            }
            Log::info("SEL-PLANTAO foi atras do pipeline {$p->id} (cliente {$p->user_id})");
        }
        return $n;
    }

    /** 3) Ficou pronto depois de ter dado problema: avisa que ja pode pegar. */
    private function avisarQuemJaPodePegar(): int
    {
        $n = 0;
        $prontos = DB::table('ai_video_pipelines')
            ->where('step', 'done')->whereNotNull('output_url')
            ->where('updated_at', '>', now()->subMinutes(20))
            ->get();

        foreach ($prontos as $p) {
            $conv = $this->conversaDoCliente((int) $p->user_id);
            if (! $conv) continue;

            // so avisa quem estava reclamando/esperando: quem tem conversa aberta e ja
            // ouviu de mim que o pedido tinha travado. Nao vira spam pra quem esta bem.
            $avisouAntes = DB::table('chat_messages')->where('conversation_id', $conv->id)
                ->where('sender', 'agent')->where('content', 'like', "%pedido {$p->id}%")->exists();
            if (! $avisouAntes) continue;

            $jaAvisouPronto = DB::table('chat_messages')->where('conversation_id', $conv->id)
                ->where('sender', 'agent')->where('content', 'like', "%{$p->id}) ficou pronto%")->exists();
            if ($jaAvisouPronto) continue;

            $this->falar($conv->id,
                "Voltei pra fechar: seu video (pedido {$p->id}) ficou pronto e ja esta na sua Galeria, "
                . "em Meus Videos, com o botao Baixar embaixo dele. Obrigada pela paciencia.");
            $this->mandarEmail((int) $p->user_id, (int) $p->id);
            $n++;
        }
        return $n;
    }

    private function conversaDoCliente(int $clientId): ?object
    {
        return DB::table('chat_conversations')->where('client_id', $clientId)
            ->orderByDesc('id')->first();
    }

    private function falar(int $convId, string $texto): void
    {
        DB::table('chat_messages')->insert([
            'conversation_id' => $convId,
            'sender'          => 'agent',
            'content'         => $texto,
            'from_chatwoot'   => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('chat_conversations')->where('id', $convId)->update(['updated_at' => now()]);
    }

    /** Ruan pediu: "avisa no e-mail quando acabar a manutencao". */
    private function mandarEmail(int $clientId, int $pipelineId): void
    {
        try {
            $u = DB::table('users')->find($clientId);
            if (! $u || ! $u->email) return;
            Mail::raw(
                "Oi! Aqui e a Bia, da Seller Global.\n\n"
                . "Seu video (pedido {$pipelineId}) teve um problema do nosso lado e ficou parado. "
                . "Ja resolvemos: ele esta PRONTO e disponivel na sua Galeria, em Meus Videos, "
                . "com o botao Baixar embaixo dele.\n\n"
                . "Nao precisou refazer nem pagar de novo. Desculpa pelo transtorno.\n\n"
                . "https://seller.global/estudio",
                fn ($m) => $m->to($u->email)->subject('Seu video ficou pronto — Seller Global')
            );
            Log::info("SEL-PLANTAO e-mail enviado pro cliente {$clientId} (pipeline {$pipelineId})");
        } catch (\Throwable $e) {
            Log::warning('SEL-PLANTAO e-mail: ' . $e->getMessage());
        }
    }

    /** Le a situacao REAL do cliente — o que o Ruan chama de "olhar antes de responder". */
    private function fichaDoCliente(?int $clientId): ?string
    {
        if (! $clientId) return null;

        $andando = DB::table('ai_video_pipelines')->where('user_id', $clientId)
            ->whereIn('step', self::NAO_FINAIS)->orderByDesc('id')->first();
        if ($andando) {
            $min = (int) \Carbon\Carbon::parse($andando->created_at)->diffInMinutes(now());
            return "Fui olhar sua conta agora: seu video (pedido {$andando->id}) esta sendo gerado "
                . "neste momento, comecou ha {$min} minuto(s). O normal e ficar pronto entre 3 e 8 "
                . "minutos e ele cai sozinho na Galeria — pode fechar a tela que nao se perde. "
                . "Fico de olho e te aviso aqui quando sair.";
        }

        $ultimo = DB::table('ai_video_pipelines')->where('user_id', $clientId)
            ->whereNotNull('output_url')->orderByDesc('id')->first();
        if ($ultimo) {
            return "Fui olhar sua conta agora: seu ultimo video (pedido {$ultimo->id}) esta PRONTO na "
                . "Galeria, em Meus Videos, com o botao Baixar embaixo. Se nao aparece na sua tela, "
                . "e versao velha carregada no navegador: aperte Ctrl+Shift+R que ele aparece. "
                . "Quer que eu te ajude a fazer o proximo?";
        }

        return "Fui olhar sua conta agora e vi que voce ainda nao gerou nenhum video — vou te levar "
            . "pelo primeiro, e rapido:\n\n1. Menu lateral > Novo Video\n2. Escolha 10s\n"
            . "3. Escolha UGC (uma pessoa apresentando o produto)\n4. Escolha o produto em Produto em alta\n"
            . "5. Clique em Gerar meu video\n\nMe diz o que voce quer vender que eu te acompanho ate sair.";
    }
}
