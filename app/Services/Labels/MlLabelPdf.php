<?php

namespace App\Services\Labels;

use Illuminate\Support\Facades\Log;

/**
 * NOV-208 v4 — recorte NITIDO da etiqueta do Mercado Livre + faixa DACE
 * RESUMIDA estilo Shopee.
 *
 * A pagina 1 do PDF do ML traz a etiqueta e (vendedor CPF) a DACE, cada uma
 * dentro de uma moldura retangular, mais a faixa "Recorte essa parte" fora
 * delas. Este servico interpreta o content stream so o bastante pra achar as
 * molduras (pares de rects even-odd), os textos (campos da DACE) e as imagens
 * (QR da DACE), rasteriza a pagina inteira a 300dpi com Ghostscript e recorta
 * cada moldura como PNG — visual pixel-identico ao PDF original.
 *
 * Alem do recorte inteiro da DACE (layouts de 2 folhas / lado a lado), monta
 * uma faixa compacta "DACE RESUMIDA" no formato que a Shopee usa no rodape
 * das etiquetas dela: QR + numero/serie/emissao/modalidade/CPFs/chave.
 *
 * Zero dependencia composer (vendor compartilhado 7 backends). Qualquer coisa
 * fora do esperado retorna null e o chamador cai no pipeline raster antigo —
 * etiqueta nunca deixa de imprimir.
 */
class MlLabelPdf
{
    private const DPI = 300;

    /**
     * @return ?array{label: string, dace: ?string, dace_strip: ?string}
     */
    public function parse(string $absPath): ?array
    {
        try {
            $pdf = @file_get_contents($absPath);
            if (!$pdf) {
                return null;
            }

            $streams = $this->streams($pdf);
            $content = null;
            foreach ($streams as $st) {
                if (!$st['image'] && str_contains($st['data'], 'Despachar')) {
                    $content = $st['data'];
                    break;
                }
            }
            if ($content === null) {
                return null; // nao eh etiqueta ML (Shopee, Correios etc.)
            }

            $els    = $this->interpret($content);
            $frames = array_values(array_filter($els, fn ($e) => $e['type'] === 'frame'));
            if (!$frames) {
                return null;
            }
            usort($frames, fn ($a, $b) => $a['x'] <=> $b['x']);
            $label = $frames[0];

            if (!isset($frames[1])) {
                // muito texto a direita da etiqueta sem segunda moldura = DACE
                // existe mas nao foi reconhecida: raster antigo garante a nota
                $right = 0;
                foreach ($els as $e) {
                    if ($e['type'] === 'run' && $e['x'] > $label['x'] + $label['w'] + 10) {
                        $right++;
                    }
                }
                if ($right > 20) {
                    return null;
                }
            }

            $page = $this->rasterize($absPath);
            if (!$page) {
                return null;
            }

            $labelHtml = $this->frameImgHtml($page, $label);
            $daceHtml  = isset($frames[1]) ? $this->frameImgHtml($page, $frames[1]) : null;
            $strip     = isset($frames[1]) ? $this->daceStripHtml($page, $frames[1], $els, $label) : null;
            $page['im']->clear();
            if (!$labelHtml || (isset($frames[1]) && !$daceHtml)) {
                return null;
            }

            return ['label' => $labelHtml, 'dace' => $daceHtml, 'dace_strip' => $strip];
        } catch (\Throwable $e) {
            Log::warning('[MlLabelPdf] Falha no parse de ' . basename($absPath) . ': ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // PDF de baixo nivel
    // ------------------------------------------------------------------

    /**
     * Todos os streams FlateDecode do PDF, ja descomprimidos.
     *
     * @return array<int, array{dict: string, data: string, image: bool}>
     */
    private function streams(string $pdf): array
    {
        $out = [];
        $off = 0;
        $len = strlen($pdf);
        while (($s = strpos($pdf, 'stream', $off)) !== false) {
            if ($s > 0 && $pdf[$s - 1] === 'd') { // 'endstream'
                $off = $s + 6;
                continue;
            }
            $back = substr($pdf, max(0, $s - 4096), min(4096, $s));
            $dict = $back;
            if (preg_match_all('/(\d+)\s+0\s+obj\b/', $back, $om, PREG_OFFSET_CAPTURE)) {
                $dict = substr($back, end($om[0])[1]);
            }

            $dataStart = $s + 6;
            if ($dataStart < $len && $pdf[$dataStart] === "\r") {
                $dataStart++;
            }
            if ($dataStart < $len && $pdf[$dataStart] === "\n") {
                $dataStart++;
            }
            $e = strpos($pdf, 'endstream', $dataStart);
            if ($e === false) {
                break;
            }
            $off = $e + 9;

            if (!str_contains($dict, '/FlateDecode')) {
                continue;
            }
            $raw  = rtrim(substr($pdf, $dataStart, $e - $dataStart), "\r\n");
            $data = @gzuncompress($raw);
            if ($data === false) {
                continue;
            }

            $out[] = [
                'dict'  => $dict,
                'data'  => $data,
                'image' => str_contains($dict, '/Image'),
            ];
        }

        return $out;
    }

    /**
     * Mini-interprete do content stream: retangulos (re + fill, par
     * outer+inner even-odd = moldura), CTM de escala/translacao (q/Q/cm),
     * runs de texto com posicao (campos da DACE) e XObjects Do (QR).
     * Strings sao consumidas atomicamente: um "ET" dentro de "REMETENTE"
     * nao fecha bloco.
     *
     * @return array<int, array>
     */
    private function interpret(string $content): array
    {
        $els   = [];
        $ctm   = [1.0, 1.0, 0.0, 0.0]; // a (escala x), d (escala y), e, f
        $stack = [];
        $tx    = 0.0;
        $ty    = 0.0;
        $pend  = [];

        $re = '/\[((?:\((?:[^()\\\\]|\\\\.)*\)|[^\[\]])*)\]\s*TJ'
            . '|\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj'
            . '|(-?[\d.]+)\s+(-?[\d.]+)\s+Td'
            . '|(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+cm'
            . '|(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+re'
            . '|\/(\w+)\s+Do'
            . '|\b(BT|f\*|f|S|n|q|Q)\b/s';

        preg_match_all($re, $content, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            $isTJ = str_ends_with($op[0], 'TJ') && isset($op[1]) && trim($op[1]) !== '';
            $isTj = isset($op[2]) && $op[2] !== '' && str_ends_with($op[0], 'Tj');
            if ($isTJ || $isTj) {
                $raw = $isTj ? $op[2] : null;
                if ($raw === null) {
                    preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $op[1], $mm);
                    $raw = implode('', array_map(fn ($s) => substr($s, 1, -1), $mm[0]));
                }
                $els[] = [
                    'type' => 'run',
                    't'    => $this->decodeStr($raw),
                    'x'    => $ctm[0] * $tx + $ctm[2],
                    'y'    => $ctm[1] * $ty + $ctm[3],
                ];
            } elseif (isset($op[3]) && $op[3] !== '' && str_ends_with($op[0], 'Td')) {
                $tx += (float) $op[3];
                $ty += (float) $op[4];
            } elseif (isset($op[5]) && $op[5] !== '' && str_ends_with($op[0], 'cm')) {
                [$a, $b, $c, $d, $e, $f] = array_map('floatval', array_slice($op, 5, 6));
                // b/c (rotacao) ignorados: nao ocorrem nos elementos uteis do ML
                $ctm = [
                    $ctm[0] * $a,
                    $ctm[1] * $d,
                    $ctm[0] * $e + $ctm[2],
                    $ctm[1] * $f + $ctm[3],
                ];
            } elseif (isset($op[11]) && $op[11] !== '' && str_ends_with($op[0], 're')) {
                $pend[] = [
                    $ctm[0] * (float) $op[11] + $ctm[2],
                    $ctm[1] * (float) $op[12] + $ctm[3],
                    $ctm[0] * (float) $op[13],
                    $ctm[1] * (float) $op[14],
                ];
            } elseif (isset($op[15]) && $op[15] !== '' && str_ends_with($op[0], 'Do')) {
                // CTM unitario desenha o XObject num quadrado 1x1 em (e,f)
                $els[] = [
                    'type' => 'img',
                    'name' => $op[15],
                    'x'    => $ctm[2],
                    'y'    => $ctm[3],
                    'w'    => $ctm[0],
                    'h'    => $ctm[1],
                ];
            } else {
                switch ($op[0]) {
                    case 'BT':
                        $tx = 0.0;
                        $ty = 0.0;
                        break;
                    case 'q':
                        $stack[] = $ctm;
                        break;
                    case 'Q':
                        if ($stack) {
                            $ctm = array_pop($stack);
                        }
                        break;
                    case 'n':
                        $pend = [];
                        break;
                    case 'f':
                    case 'f*':
                        if (count($pend) === 2
                            && abs($pend[0][0] - $pend[1][0]) < 3 && abs($pend[0][1] - $pend[1][1]) < 3) {
                            // par outer+inner (even-odd) = moldura
                            $o = $pend[0][2] * $pend[0][3] >= $pend[1][2] * $pend[1][3] ? $pend[0] : $pend[1];
                            $i = $o === $pend[0] ? $pend[1] : $pend[0];
                            if ($i[2] > 150 && $i[3] > 250) {
                                $els[] = [
                                    'type' => 'frame',
                                    'x'    => $i[0],
                                    'y'    => $i[1],
                                    'w'    => $i[2],
                                    'h'    => $i[3],
                                    'bw'   => max(0.4, ($o[3] - $i[3]) / 2),
                                ];
                            }
                        }
                        $pend = [];
                        break;
                    case 'S':
                        $pend = [];
                        break;
                }
            }
        }

        return $els;
    }

    /**
     * String literal PDF -> UTF-8. As fontes do ML usam encoding MacRoman
     * (Ç=0x82, Ã=0xCC, º=0xBC...).
     */
    private function decodeStr(string $s): string
    {
        $out = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c !== '\\') {
                $out .= $c;
                continue;
            }
            if (++$i >= $n) {
                break;
            }
            $e = $s[$i];
            if ($e >= '0' && $e <= '7') {
                $oct = $e;
                for ($j = 0; $j < 2 && $i + 1 < $n && $s[$i + 1] >= '0' && $s[$i + 1] <= '7'; $j++) {
                    $oct .= $s[++$i];
                }
                $out .= chr(octdec($oct));
            } else {
                $out .= match ($e) {
                    'n' => "\n", 'r' => "\r", 't' => "\t",
                    default => $e,
                };
            }
        }

        $utf = @iconv('MACINTOSH', 'UTF-8//IGNORE', $out);

        return $utf !== false ? $utf : preg_replace('/[^\x20-\x7E]/', '', $out);
    }

    // ------------------------------------------------------------------
    // Raster 300dpi + recorte das molduras
    // ------------------------------------------------------------------

    /**
     * Rasteriza a pagina 1 com Ghostscript (Imagick nao le PDF: policy do
     * ImageMagick bloqueia o coder PDF no servidor).
     *
     * @return ?array{im: \Imagick, k: float, hPt: float}
     */
    private function rasterize(string $absPath): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mlpdf') . '.png';
        $cmd = sprintf(
            'gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=1'
            . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile=%s %s 2>&1',
            self::DPI,
            escapeshellarg($tmp),
            escapeshellarg($absPath)
        );
        exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($tmp) || !filesize($tmp)) {
            @unlink($tmp);
            Log::warning('[MlLabelPdf] gs falhou (rc=' . $rc . '): ' . implode(' | ', array_slice($out, -3)));
            return null;
        }

        $im = new \Imagick($tmp);
        @unlink($tmp);
        $k = self::DPI / 72.0;

        return ['im' => $im, 'k' => $k, 'hPt' => $im->getImageHeight() / $k];
    }

    /**
     * Recorta a moldura (rect outer, borda inclusa) do raster e devolve um
     * container .ml-frame com a imagem — largura em pt igual a do PDF, entao
     * os zooms por contexto do combined-label.blade.php seguem valendo.
     */
    private function frameImgHtml(array $page, array $frame): ?string
    {
        $k  = $page['k'];
        $bw = $frame['bw'];
        $png = $this->cropPng(
            $page,
            $frame['x'] - $bw,
            $page['hPt'] - $frame['y'] - $frame['h'] - $bw,
            $frame['w'] + 2 * $bw,
            $frame['h'] + 2 * $bw
        );
        if (!$png) {
            return null;
        }

        return sprintf(
            '<div class="ml-frame" style="width:%.1fpt"><img src="data:image/png;base64,%s"'
            . ' style="display:block;width:100%%" alt=""></div>',
            $frame['w'] + 2 * $bw,
            base64_encode($png)
        );
    }

    /**
     * Recorte generico do raster em coordenadas de pagina (pt, origem no
     * topo-esquerda). Retorna blob PNG ou null se sair dos limites.
     */
    private function cropPng(array $page, float $xPt, float $yPt, float $wPt, float $hPt): ?string
    {
        $k = $page['k'];
        $x = (int) floor($xPt * $k);
        $y = (int) floor($yPt * $k);
        $w = (int) ceil($wPt * $k);
        $h = (int) ceil($hPt * $k);
        if ($x < 0 || $y < 0
            || $x + $w > $page['im']->getImageWidth() + 2
            || $y + $h > $page['im']->getImageHeight() + 2) {
            return null;
        }

        $im = clone $page['im'];
        $im->cropImage($w, $h, $x, $y);
        $im->setImageFormat('png');
        $png = $im->getImageBlob();
        $im->clear();

        return $png ?: null;
    }

    // ------------------------------------------------------------------
    // Faixa DACE RESUMIDA (formato Shopee) pro layout de folha unica
    // ------------------------------------------------------------------

    /**
     * Le os campos da DACE (runs de texto dentro da moldura), recorta o QR
     * do raster e monta uma faixa horizontal compacta no formato que a
     * Shopee imprime no rodape das etiquetas dela. Qualquer campo essencial
     * ausente -> null (o chamador usa a DACE inteira).
     */
    private function daceStripHtml(array $page, array $dace, array $els, array $label): ?string
    {
        // agrupa runs da moldura em linhas (y com tolerancia 2pt), texto
        // concatenado por x — labels servem de ancora pros regex
        $rows = [];
        foreach ($els as $e) {
            if ($e['type'] !== 'run'
                || $e['x'] < $dace['x'] - 5 || $e['x'] > $dace['x'] + $dace['w'] + 5
                || $e['y'] < $dace['y'] - 5 || $e['y'] > $dace['y'] + $dace['h'] + 5) {
                continue;
            }
            $key = (string) (int) round($e['y'] / 2);
            $rows[$key][] = $e;
        }
        $lines = [];
        foreach ($rows as $runs) {
            usort($runs, fn ($a, $b) => $a['x'] <=> $b['x']);
            $lines[] = ['y' => $runs[0]['y'], 't' => implode('', array_column($runs, 't'))];
        }
        usort($lines, fn ($a, $b) => $b['y'] <=> $a['y']);

        $num = $serie = $emissao = $modal = null;
        $rem = $dest = ['doc' => null, 'nome' => null, 'cid' => null];
        $chave = '';
        $chaveOn = false;
        foreach ($lines as $i => $l) {
            $t = $l['t'];
            if ($chaveOn) {
                $chave .= preg_replace('/\D/', '', $t);
                continue;
            }
            if (preg_match('/Chave de Acesso/iu', $t)) {
                $chaveOn = true;
            } elseif (preg_match('/N[ºo°]?\s*(\d+)\s*S[ÉE]RIE\s*(\d+)/u', $t, $m)) {
                [, $num, $serie] = $m;
            } elseif (preg_match('/EMISS[ÃA]O:\s*(.+)/u', $t, $m)) {
                $emissao = trim($m[1]);
            } elseif (preg_match('/MODALIDADE DO TRANSPORTE:\s*(.+)/u', $t, $m)) {
                $modal = trim($m[1]);
                if (isset($lines[$i + 1]) && !str_contains($lines[$i + 1]['t'], ':')) {
                    $modal .= ' ' . trim($lines[$i + 1]['t']);
                }
            } elseif (preg_match('/CNPJ\/CPF REMETENTE:\s*([\d.\/-]+)/u', $t, $m)) {
                $rem['doc'] = $m[1];
            } elseif (preg_match('/NOME REMETENTE:\s*(.+)/u', $t, $m)) {
                $rem['nome'] = trim($m[1]);
            } elseif (preg_match('/CIDADE-UF REMETENTE:\s*(.+)/u', $t, $m)) {
                $rem['cid'] = trim($m[1]);
            } elseif (preg_match('/CNPJ\/CPF DESTINAT[ÁA]RIO:\s*([\d.\/-]+)/u', $t, $m)) {
                $dest['doc'] = $m[1];
            } elseif (preg_match('/NOME DESTINAT[ÁA]RIO:\s*(.+)/u', $t, $m)) {
                $dest['nome'] = trim($m[1]);
            } elseif (preg_match('/CIDADE-UF DESTINAT[ÁA]RIO:\s*(.+)/u', $t, $m)) {
                $dest['cid'] = trim($m[1]);
            }
        }

        if (!$num || strlen($chave) !== 44 || !$rem['doc'] || !$dest['doc']) {
            return null;
        }

        // QR: XObject quadrado dentro da moldura
        $qr = null;
        foreach ($els as $e) {
            if ($e['type'] === 'img' && $e['w'] > 30 && abs($e['w'] - $e['h']) < 2
                && $e['x'] >= $dace['x'] - 5 && $e['x'] <= $dace['x'] + $dace['w']
                && $e['y'] >= $dace['y'] - 5 && $e['y'] <= $dace['y'] + $dace['h']) {
                $qr = $this->cropPng($page, $e['x'], $page['hPt'] - $e['y'] - $e['h'], $e['w'], $e['h']);
                break;
            }
        }

        $esc = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $f = [];
        $f[] = '<b>N.º</b> ' . $esc($num) . ' &nbsp;<b>Série</b> ' . $esc($serie)
            . ' &nbsp;<b>Emissão</b> ' . $esc($emissao);
        if ($modal) {
            $f[] = '<b>Modalidade de Transporte</b> ' . $esc($modal);
        }
        $f[] = '<b>Remetente</b> ' . $esc(trim($rem['nome'])) . ' &middot; CPF/CNPJ ' . $esc($rem['doc'])
            . ($rem['cid'] ? ' &middot; ' . $esc($rem['cid']) : '');
        $f[] = '<b>Destinatário</b> ' . $esc(trim($dest['nome'])) . ' &middot; CPF/CNPJ ' . $esc($dest['doc'])
            . ($dest['cid'] ? ' &middot; ' . $esc($dest['cid']) : '');
        $f[] = '<b>Chave de Acesso DC-e</b> <span style="letter-spacing:.2pt">' . $esc($chave) . '</span>';

        $wPt = $label['w'] + 2 * $label['bw']; // mesma largura da etiqueta
        $qrHtml = $qr
            ? '<img src="data:image/png;base64,' . base64_encode($qr) . '"'
                . ' style="width:64pt;height:64pt;flex:0 0 auto;margin-right:5pt" alt="">'
            : '';

        return '<div class="dace-strip" style="width:' . sprintf('%.1f', $wPt) . 'pt;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#000;border-top:1.2pt solid #000;padding-top:2pt">'
            . '<div style="text-align:center;font-weight:bold;font-size:6.8pt;margin:0 0 2pt">'
            . 'DACE RESUMIDA - Declaração Auxiliar de Conteúdo Eletrônica</div>'
            . '<div style="display:flex;align-items:center">' . $qrHtml
            . '<div style="font-size:5.4pt;line-height:1.5;text-align:left">'
            . implode('<br>', $f)
            . '</div></div></div>';
    }
}
