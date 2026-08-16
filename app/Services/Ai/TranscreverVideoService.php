<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * SEL-TROCAPERSONAGEM (14/08) — le a FALA de um video que o cliente subiu.
 *
 * POR QUE PELO NAVEGADOR E NAO POR API: as chaves de IA do backend estao todas vazias
 * (GEMINI_API_KEY, OPENAI_API_KEY, ELEVENLABS_API_KEY — conferido no .env em 14/08) e o
 * servidor nao tem ffmpeg. Entao a transcricao vai pelo MESMO caminho do Veo/Kling: um
 * motor quente do pool no PC de render abre o Gemini, sobe o AUDIO (nao o video: subir
 * video faz o Gemini pedir aceite de termo de direitos, e aceitar termo e do dono) e
 * devolve o texto. Custo zero, sem API.
 *
 * Provado em 14/08 antes de virar servico: 6 videos entregues medidos, 100% de acerto
 * entre a fala pedida e a transcrita.
 */
class TranscreverVideoService
{
    /** @var int segundos — o ouvido leva ~60-90s; 8min e teto de seguranca */
    private const TIMEOUT = 480;

    /**
     * A chave do PC tem que ser a COPIA do usuario do site, nao a do root.
     *
     * MEDIDO na primeira chamada real (14/08): apontei pra /root/.ssh/pc-render e o
     * servico devolveu "nao consegui ouvir". O PHP roda como `apifrn0001` e a chave do
     * root e 0600 root:root — o site nao consegue nem abrir o arquivo. O resto do app ja
     * usa a copia certa (AiEnginePool::remoteHostHealthy), entao sigo a mesma convencao.
     */
    private function chave(): string
    {
        return (string) config('services.ai_video.remote.ssh_key', '/home/api.seller.global/.ssh/pc-render');
    }

    /**
     * Resposta em que o modelo fala DE SI em vez de transcrever. Lista feita de casos
     * REAIS vistos aqui, nao de imaginacao — cada linha nova entra depois de acontecer.
     */
    private function ehRecusa(string $texto): bool
    {
        $t = mb_strtolower($texto);

        $marcas = [
            // 14/08 04:05 — este escapou e virou ALERTA falso de "fala errada" no pulso:
            //   "Nao consigo te ajudar com isso. Sou so um modelo de linguagem..."
            // Eu tinha 'sou um modelo'; veio 'sou SO um modelo'. Por isso agora a marca
            // e o pedaco que nao muda ('modelo de linguagem'), nao a frase inteira.
            'modelo de linguagem', 'nao consigo te ajudar', 'não consigo te ajudar',
            'nao posso te ajudar', 'não posso te ajudar',
            'sou uma ia', 'sou um modelo', 'como ia', 'como uma ia', 'como modelo de linguagem',
            'com base em texto', 'alem das minhas capacidades', 'além das minhas capacidades',
            'nao consigo processar', 'não consigo processar', 'nao posso processar', 'não posso processar',
            'nao tenho a capacidade', 'não tenho a capacidade', 'nao sou capaz', 'não sou capaz',
            'as an ai', 'i am a text', 'beyond my capabilities', 'i cannot process', 'i can not process',
            'nao recebi nenhum', 'não recebi nenhum', 'nenhum arquivo', 'no file was', 'no audio was',
        ];
        foreach ($marcas as $m) {
            if (str_contains($t, $m)) {
                return true;
            }
        }

        // Transcricao de um video de ate 10s nunca e um texto enorme: resposta muito
        // longa quase sempre e o modelo explicando alguma coisa em vez de transcrever.
        if (mb_strlen($texto) > 600) {
            return true;
        }

        return false;
    }

    /**
     * Devolve o que e FALADO no video, ou null se nao deu (nunca lanca: o fluxo do
     * cliente nao pode morrer porque a transcricao falhou — quem chama decide o plano B).
     */
    public function daUrl(string $videoUrl, string $tarefa = 'ref'): ?string
    {
        $inicio = microtime(true);

        // A entrada vai por ARQUIVO. Aprendido na marra em 14/08: `echo '{...}' | ssh`
        // perde o JSON no encadeamento de aspas de dois sshs — o worker recebia vazio.
        $entrada = ['url' => $videoUrl, 'task' => preg_replace('/[^A-Za-z0-9_-]/', '', $tarefa)];
        $tmp     = sys_get_temp_dir() . '/ouvido_in_' . $entrada['task'] . '_' . getmypid() . '.json';
        file_put_contents($tmp, json_encode($entrada));

        $scp = sprintf(
            'scp -q -i %s -P 2200 -o StrictHostKeyChecking=no %s ruan@localhost:C:/sellerglobal-render/ouvido_in.json 2>&1',
            escapeshellarg($this->chave()),
            escapeshellarg($tmp)
        );
        shell_exec($scp);

        $cmd = sprintf(
            'timeout %d ssh -i %s -p 2200 -o StrictHostKeyChecking=no ruan@localhost '
            . '"cd C:\\\\sellerglobal-render && node ouvido_gemini.js < ouvido_in.json" 2>&1',
            self::TIMEOUT,
            escapeshellarg($this->chave())
        );
        $saida = (string) shell_exec($cmd);
        @unlink($tmp);

        // O worker imprime logs no STDERR e SO a ultima linha do STDOUT e JSON.
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $saida))));
        $res    = json_decode((string) end($linhas), true) ?: [];

        $segundos = round(microtime(true) - $inicio);

        if (empty($res['ok']) || trim((string) ($res['dito'] ?? '')) === '') {
            Log::warning('[SEL-TROCAPERSONAGEM] nao consegui ouvir o video', [
                'url'   => $videoUrl,
                'erro'  => mb_substr((string) ($res['error'] ?? 'sem resposta do PC'), 0, 200),
                'saida' => mb_substr(trim($saida), -300),   // o que o PC devolveu de verdade
                'seg'   => $segundos,
            ]);
            return null;
        }

        $dito = trim((string) $res['dito']);

        // ── GUARDA DE RECUSA (14/08) — o defeito mais perigoso deste servico ──────────
        // MEDIDO no primeiro video que gerei pelo fluxo novo: o Gemini respondeu
        // "Sou uma IA com base em texto. Isso esta alem das minhas capacidades." e o
        // codigo aceitou como se fosse a FALA. Isso viraria a fala herdada -> o
        // personagem do cliente diria essa frase no video entregue.
        // Entao: resposta que fala do MODELO em vez de transcrever nao e transcricao.
        if ($this->ehRecusa($dito)) {
            Log::warning('[SEL-TROCAPERSONAGEM] o Gemini recusou em vez de transcrever', [
                'url'      => $videoUrl,
                'resposta' => mb_substr($dito, 0, 160),
                'seg'      => $segundos,
            ]);
            return null;   // null = "nao consegui" -> quem chama devolve a reserva e avisa
        }

        // "(mudo)" e a resposta combinada com o Gemini pra video sem fala — nao e erro,
        // e uma informacao: o cliente subiu um video mudo e o fluxo de roupa/trilha serve
        // melhor que o de mesma-fala.
        if (preg_match('/^\(?mudo\)?$/iu', $dito)) {
            Log::info('[SEL-TROCAPERSONAGEM] video sem fala', ['url' => $videoUrl, 'seg' => $segundos]);
            return '';
        }

        Log::info('[SEL-TROCAPERSONAGEM] fala lida do video', [
            'motor'    => $res['motor'] ?? null,
            'seg'      => $segundos,
            'palavras' => str_word_count($dito),
            'trecho'   => mb_substr($dito, 0, 120),
        ]);

        return $dito;
    }
}
