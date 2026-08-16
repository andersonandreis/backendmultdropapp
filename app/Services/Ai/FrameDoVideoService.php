<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SEL-FRAMEREF (14/08) — tira um FRAME de um video e devolve como imagem nossa.
 *
 * POR QUE EXISTE: medido assistindo o pipeline 1043 — mandando so uma DESCRICAO de cena
 * em texto, o modelo faz "uma cozinha", nao A cozinha do video de referencia (a original
 * tinha armario de madeira e janela; a gerada veio com azulejo branco). O modelo nunca
 * ve o video. Com um FRAME de verdade como imagem de entrada, ele passa a ver.
 *
 * Serve os dois casos:
 *   - trocar personagem "mesma fala" -> frame do MEIO (cenario e enquadramento)
 *   - continuar video (parte 2)      -> frame ~1s ANTES DO FIM. Medido: o ultimo frame
 *     de um video que fechou costuma ser o close do produto, SEM a pessoa — pegar o
 *     final exato perderia justamente o personagem que se quer manter.
 *
 * O ffmpeg vive no PC de render (o servidor nao tem). Mesmo caminho do ouvido.
 */
class FrameDoVideoService
{
    private const TIMEOUT = 180;

    private function chave(): string
    {
        return (string) config('services.ai_video.remote.ssh_key', '/home/api.seller.global/.ssh/pc-render');
    }

    /**
     * @param  string $videoUrl  o mp4 (publico, do nosso storage)
     * @param  string $quando    'meio' | 'antes_do_fim'
     * @return string|null       URL publica do JPG, ou null se nao deu (nunca lanca)
     */
    public function extrair(string $videoUrl, string $quando = 'meio'): ?string
    {
        $tag = bin2hex(random_bytes(6));

        // -sseek relativo ao FIM ('antes_do_fim') exige saber a duracao; como todos os
        // nossos videos fecham em ~8s (medido: 23 de 25), 7s cobre "1s antes do fim" e
        // 4s cobre o meio. Se um dia a duracao mudar, muda aqui — e um numero so.
        $segundo = $quando === 'antes_do_fim' ? '7' : '4';

        // ⚠️ NADA de escapeshellarg nos argumentos que atravessam pro WINDOWS.
        // MEDIDO 14/08: escapeshellarg envolve em aspas SIMPLES; o cmd do Windows nao
        // remove aspas simples, entao o Node recebia   'https://...'   com as aspas
        // dentro da string e o fetch falhava. O comando rodava, voltava JSON de erro, e
        // o servico devolvia null sem motivo aparente.
        // No lugar do escape: valido a URL e so aceito o NOSSO storage — assim nao ha
        // como injetar comando por aqui.
        if (! filter_var($videoUrl, FILTER_VALIDATE_URL)
            || ! preg_match('#^https://(api\.seller\.global|api\.tokfy\.io)/storage/[A-Za-z0-9/._-]+$#', $videoUrl)) {
            Log::warning('[SEL-FRAMEREF] url recusada (fora do nosso storage)', ['url' => mb_substr($videoUrl, 0, 120)]);
            return null;
        }

        $remoto = sprintf(
            'timeout %d ssh -i %s -p 2200 -o StrictHostKeyChecking=no ruan@localhost '
            . '"cd C:\\\\sellerglobal-render && node frame_do_video.js %s %s" 2>&1',
            self::TIMEOUT,
            escapeshellarg($this->chave()),   // este fica no LINUX, escape vale
            $videoUrl,
            $segundo
        );

        $saida  = (string) shell_exec($remoto);
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $saida))));
        $res    = json_decode((string) end($linhas), true) ?: [];

        if (empty($res['ok']) || empty($res['jpg_base64'])) {
            Log::warning('[SEL-FRAMEREF] nao consegui tirar o frame', [
                'url'   => $videoUrl,
                'erro'  => mb_substr((string) ($res['error'] ?? 'sem resposta'), 0, 160),
                'saida' => mb_substr(trim($saida), -200),
            ]);
            return null;
        }

        $bin = base64_decode((string) $res['jpg_base64'], true);
        if ($bin === false || strlen($bin) < 2000) {
            Log::warning('[SEL-FRAMEREF] frame veio vazio ou corrompido', ['bytes' => $bin === false ? 0 : strlen($bin)]);
            return null;
        }

        $caminho = 'frames-ref/' . date('Ymd') . '/' . $tag . '.jpg';
        Storage::disk('public')->put($caminho, $bin);
        $url = Storage::disk('public')->url($caminho);

        Log::info('[SEL-FRAMEREF] frame extraido', ['de' => $videoUrl, 'quando' => $quando, 'kb' => round(strlen($bin) / 1024), 'url' => $url]);

        return $url;
    }
}
