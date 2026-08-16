<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * SEL-VOZ-NOSSA (16/08) — NARRAÇÃO EM PORTUGUÊS, DENTRO DO NOSSO SERVIDOR.
 *
 * Ruan: "olha alguma ferramenta para criarmos o audio, github nao tem nada?" — tem, e é o
 * irmão exato do transcritor que já usamos. Piper: modelo aberto, roda em CPU, sem chave,
 * sem conta, sem custo por uso e sem mandar o texto do cliente pra fora.
 *
 * MEDIDO na instalação (16/08):
 *   1.120 caracteres -> 53,1s de áudio em 8,4s de CPU  =  6,3x tempo real
 *   ou seja: 1 HORA de narração em ~10 minutos. É o que a live precisa.
 *
 * PROVA DE QUE FALA CERTO (circuito fechado — gerei a fala e mandei o NOSSO ouvido
 * escutar de volta, /opt/ouvido):
 *   dito:   "...o preço começa em vinte e sete reais..."
 *   ouvido: "...o preço começa em R$ 27,00..."   (idioma pt, confiança 1.0)
 *
 * ONDE ELA ENTRA (a regra, pra não haver duas vozes brigando):
 *   - vídeo COM som  -> a pessoa do vídeo fala (áudio nativo do motor). Esta voz NÃO entra.
 *   - vídeo SEM som  -> hoje o cliente recebe um vídeo mudo e resolve o áudio por fora.
 *                       É o único buraco, e é aqui que a narração entra.
 *   - live shopping  -> a narração longa que costura os takes.
 */
class NarracaoLocalService
{
    /** onde o venv e as vozes vivem (instalado 16/08) */
    private const BIN   = '/opt/voz/bin/piper';
    private const VOZES = '/opt/voz/vozes';

    /** As duas que baixamos. `faber` é a padrão: mais encorpada, aguenta texto longo. */
    public const VOZ_PADRAO = 'pt_BR-faber-medium';

    public function disponivel(): bool
    {
        return is_file(self::BIN) && is_file(self::VOZES . '/' . self::VOZ_PADRAO . '.onnx');
    }

    /** @return array<string> ids das vozes instaladas */
    public function vozes(): array
    {
        $out = [];
        foreach (glob(self::VOZES . '/*.onnx') ?: [] as $f) {
            $out[] = basename($f, '.onnx');
        }

        return $out;
    }

    /**
     * Fala o texto e devolve o caminho do mp3. Devolve null se não der — NUNCA lança:
     * narração é um extra, não pode derrubar a entrega de um vídeo que já ficou pronto.
     */
    public function falar(string $texto, ?string $voz = null, ?string $destinoMp3 = null): ?string
    {
        $texto = trim($texto);
        if ($texto === '' || ! $this->disponivel()) {
            return null;
        }

        $voz = $voz && in_array($voz, $this->vozes(), true) ? $voz : self::VOZ_PADRAO;
        $modelo = self::VOZES . '/' . $voz . '.onnx';

        $wav = sys_get_temp_dir() . '/voz_' . bin2hex(random_bytes(6)) . '.wav';
        $mp3 = $destinoMp3 ?: storage_path('app/public/narracao/' . date('Ymd') . '/voz_' . bin2hex(random_bytes(6)) . '.mp3');

        if (! is_dir(dirname($mp3))) {
            @mkdir(dirname($mp3), 0775, true);
        }

        try {
            $t0 = microtime(true);

            // O Piper lê o texto do stdin. Texto longo (roteiro de live) vai inteiro:
            // ele mesmo quebra em frases.
            $p = new Process([self::BIN, '--model', $modelo, '--output_file', $wav], '/tmp', ['HOME' => '/tmp']);
            $p->setInput($texto);
            // 1 hora de narração leva ~10 min; o teto de 25 min cobre com folga.
            $p->setTimeout(1500);
            $p->run();

            if (! is_file($wav) || filesize($wav) < 2000) {
                Log::error('[SEL-VOZ-NOSSA] piper nao gerou audio', [
                    'chars' => mb_strlen($texto),
                    'err'   => mb_substr($p->getErrorOutput(), 0, 200),
                ]);

                return null;
            }

            // wav -> mp3 (o wav de uma hora passa de 100 MB; o mp3 fica ~30x menor)
            $conv = new Process(['ffmpeg', '-y', '-i', $wav, '-codec:a', 'libmp3lame', '-b:a', '128k', $mp3]);
            $conv->setTimeout(900);
            $conv->run();
            @unlink($wav);

            if (! is_file($mp3) || filesize($mp3) < 1000) {
                Log::error('[SEL-VOZ-NOSSA] falha ao converter pra mp3', [
                    'err' => mb_substr($conv->getErrorOutput(), 0, 200),
                ]);

                return null;
            }

            Log::error('[SEL-VOZ-NOSSA] narracao gerada', [
                'voz'      => $voz,
                'chars'    => mb_strlen($texto),
                'segundos' => round(microtime(true) - $t0, 1),
                'bytes'    => filesize($mp3),
            ]);

            return $mp3;
        } catch (\Throwable $e) {
            Log::error('[SEL-VOZ-NOSSA] erro ao narrar', ['err' => mb_substr($e->getMessage(), 0, 200)]);
            @unlink($wav);

            return null;
        }
    }

    /**
     * Põe a narração POR CIMA de um vídeo mudo e devolve o caminho do novo mp4.
     *
     * Regra de ouro: se qualquer coisa falhar, devolve null e QUEM CHAMA fica com o vídeo
     * original. Um vídeo entregue nunca pode piorar por causa de um extra — foi a lição do
     * #1305, em que uma etapa de pós-entrega transformou um vídeo pronto em "falhou".
     */
    public function porNoVideo(string $mp4, string $mp3, ?string $destino = null): ?string
    {
        if (! is_file($mp4) || ! is_file($mp3)) {
            return null;
        }

        $saida = $destino ?: preg_replace('/\.mp4$/i', '', $mp4) . '.narrado.mp4';

        try {
            // -shortest: a narração pode ser mais longa que o vídeo; o corte fica no fim.
            // O vídeo é copiado sem recompressão (-c:v copy): não perde qualidade nenhuma.
            $p = new Process([
                'ffmpeg', '-y',
                '-i', $mp4,
                '-i', $mp3,
                '-map', '0:v:0', '-map', '1:a:0',
                '-c:v', 'copy', '-c:a', 'aac', '-b:a', '128k',
                '-shortest',
                $saida,
            ]);
            $p->setTimeout(900);
            $p->run();

            if (! is_file($saida) || filesize($saida) < 100000) {
                Log::error('[SEL-VOZ-NOSSA] nao consegui juntar narracao e video', [
                    'err' => mb_substr($p->getErrorOutput(), 0, 200),
                ]);

                return null;
            }

            return $saida;
        } catch (\Throwable $e) {
            Log::error('[SEL-VOZ-NOSSA] erro ao juntar', ['err' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }
}
