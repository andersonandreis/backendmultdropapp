<?php

namespace App\Services\Labels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * NOV-208 — renderiza PDFs de etiqueta/nota em PNG pra embutir no HTML
 * de impressao combinada (browser nao renderiza PDF dentro de <img>).
 *
 * Ghostscript + Imagick, cache em labels/render/ no disk public.
 */
class LabelPdfRenderer
{
    private const GS  = '/usr/bin/gs';
    private const DPI = 150;

    /**
     * Separa a pagina 1 do PDF de etiqueta ML em [etiqueta, nota]:
     * - paisagem (formato novo ML): bloco de conteudo da esquerda = etiqueta;
     *   blocos seguintes = DACE/declaracao (vendedor CPF). O corte eh feito
     *   no VAO em branco entre os blocos (corte fixo na metade clipava a
     *   DACE, que comeca antes do meio da pagina).
     * - retrato ou bloco unico: pagina inteira/bloco = etiqueta, nota = null.
     *
     * @return array{0: ?string, 1: ?string} URLs /storage/... ou null
     */
    public function splitLabelPdf(string $relPath): array
    {
        $png = $this->pageToPng($relPath, 1);
        if (!$png) {
            return [null, null];
        }

        $leftRel  = $this->cachePath($relPath, 1, 'left');
        $rightRel = $this->cachePath($relPath, 1, 'right');
        $disk     = Storage::disk('public');

        // cache: se a metade esquerda ja existe, o split ja foi feito
        if ($disk->exists($leftRel)) {
            return [
                '/storage/' . $leftRel,
                $disk->exists($rightRel) ? '/storage/' . $rightRel : null,
            ];
        }

        try {
            $im = new \Imagick($disk->path($png));
            $w  = $im->getImageWidth();
            $h  = $im->getImageHeight();

            if ($w <= $h) { // retrato: pagina inteira eh a etiqueta
                $im->destroy();
                return ['/storage/' . $png, null];
            }

            $blocks = $this->contentBlocks($im);

            if (count($blocks) < 2) {
                // Sem nota embutida (CNPJ): recorta so a regiao da etiqueta
                $end = $blocks ? min($w, $blocks[0][1] + 20) : intdiv($w, 2);
                $left = clone $im;
                $left->cropImage($end, $h, 0, 0);
                $left->trimImage(0);
                $left->setImagePage(0, 0, 0, 0);
                $left->setImageFormat('png');
                $disk->put($leftRel, $left->getImageBlob());
                $left->destroy();
                $im->destroy();
                return ['/storage/' . $leftRel, null];
            }

            // etiqueta: do inicio da pagina ate o meio do vao antes da nota
            $split = intdiv($blocks[0][1] + $blocks[1][0], 2);

            $left = clone $im;
            $left->cropImage($split, $h, 0, 0);
            $left->trimImage(0);
            $left->setImagePage(0, 0, 0, 0);
            $left->setImageFormat('png');
            $disk->put($leftRel, $left->getImageBlob());
            $left->destroy();

            // nota: recorte JUSTO nos blocos restantes (sem sobra branca a
            // direita — na folha Zebra 100x150 a sobra encolheria a DACE)
            $last     = $blocks[count($blocks) - 1];
            $noteFrom = max(0, $blocks[1][0] - 10);
            $noteTo   = min($w, $last[1] + 10);

            $right = clone $im;
            $right->cropImage($noteTo - $noteFrom, $h, $noteFrom, 0);
            $right->trimImage(0);
            $right->setImagePage(0, 0, 0, 0);
            $right->setImageFormat('png');
            $disk->put($rightRel, $right->getImageBlob());
            $right->destroy();
            $im->destroy();

            return ['/storage/' . $leftRel, '/storage/' . $rightRel];
        } catch (\Throwable $e) {
            Log::warning("[LabelRender] Falha ao separar {$relPath}: " . $e->getMessage());
            return ['/storage/' . $png, null];
        }
    }

    /**
     * NOV-208: pagina renderizada com as margens brancas recortadas — etiqueta
     * Bling costuma vir pequena num canto da folha A4; sem o trim ela sai
     * ilegivel quando a folha inteira eh escalada pra Zebra 100x150.
     */
    public function trimmedPageToUrl(string $relPath, int $page = 1): ?string
    {
        $out  = $this->cachePath($relPath, $page, 'trim');
        $disk = Storage::disk('public');
        if ($disk->exists($out)) {
            return '/storage/' . $out;
        }
        $png = $this->pageToPng($relPath, $page);
        if (!$png) {
            return null;
        }
        try {
            $im = new \Imagick($disk->path($png));
            $im->setImageBackgroundColor('white');
            $im->trimImage(0.1 * \Imagick::getQuantum());
            $im->setImagePage(0, 0, 0, 0);
            $im->borderImage('white', 12, 12);
            $im->writeImage($disk->path($out));
            $im->destroy();
            return '/storage/' . $out;
        } catch (\Throwable $e) {
            Log::warning("[LabelRender] trim falhou pra {$relPath}: " . $e->getMessage());
            return '/storage/' . $png;
        }
    }

    /**
     * MUL-440: recorta a borda branca de uma etiqueta que JA e imagem (PNG/JPG).
     *
     * O trimmedPageToUrl acima so atende PDF, porque comeca rasterizando a pagina.
     * Medido em 19/08/2026: das etiquetas em aberto, 252 sao PNG e 10 sao PDF -- ou
     * seja, a esmagadora maioria nunca passou por recorte e era impressa com a folga
     * que a Shopee ja embute na imagem. Com a folha de 100x150mm, essa folga dupla
     * espremia o codigo de barras.
     *
     * A borda de 8px que sobra existe de proposito: impressora termica corta um fio
     * na extremidade, e sem nenhuma folga o codigo pode sair mordido.
     */
    public function trimmedImageToUrl(string $relPath): ?string
    {
        $disk = Storage::disk('public');
        $out  = $this->cachePath($relPath, 1, 'imgtrim');

        if ($disk->exists($out)) {
            return '/storage/' . $out;
        }
        // MUL-440b: nesta instalacao /storage/labels e uma rota de PROXY para o hub
        // (routes/web.php), entao o arquivo pode nao existir no disco local. Sem baixar
        // antes, o Imagick nao tem o que abrir e o recorte falhava calado -- a etiqueta
        // seguia sendo impressa pequena, com a folga branca da Shopee.
        $origem = $disk->exists($relPath) ? $disk->path($relPath) : null;
        $temporario = null;

        if (! $origem) {
            try {
                $r = \Illuminate\Support\Facades\Http::timeout(20)
                    ->get(rtrim((string) config('app.url'), '/') . '/storage/' . $relPath);

                if (! $r->successful() || strlen($r->body()) < 512) {
                    return null;
                }

                $temporario = sys_get_temp_dir() . '/lbl-' . md5($relPath) . '.img';
                file_put_contents($temporario, $r->body());
                $origem = $temporario;
            } catch (\Throwable $e) {
                Log::warning("[LabelRender] nao consegui baixar a etiqueta {$relPath}: " . $e->getMessage());
                return null;
            }
        }

        try {
            $im = new \Imagick($origem);
            $im->setImageBackgroundColor('white');
            $im->trimImage(0.1 * \Imagick::getQuantum());
            $im->setImagePage(0, 0, 0, 0);
            $im->borderImage('white', 8, 8);
            $im->writeImage($disk->path($out));
            $im->destroy();

            return '/storage/' . $out;
        } catch (\Throwable $e) {
            Log::warning("[LabelRender] trim de imagem falhou pra {$relPath}: " . $e->getMessage());
            return null; // sem recorte o chamador segue com a imagem original
        }
    }

    /** Renderiza pagina 1 do PDF e devolve URL /storage/... (nota/DANFE). */
    public function pageToUrl(string $relPath, int $page = 1): ?string
    {
        $png = $this->pageToPng($relPath, $page);
        return $png ? '/storage/' . $png : null;
    }

    /**
     * Renderiza UMA pagina do PDF em PNG com cache.
     * Retorna path relativo ao disk public ou null.
     */
    public function pageToPng(string $relPath, int $page = 1): ?string
    {
        $out  = $this->cachePath($relPath, $page);
        $disk = Storage::disk('public');
        if ($disk->exists($out)) {
            return $out;
        }
        $in = $disk->path($relPath);
        if (!is_file($in)) {
            return null;
        }
        $disk->makeDirectory('labels/render');
        $outAbs = $disk->path($out);
        $cmd = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r%d -dFirstPage=%d -dLastPage=%d -o %s %s 2>&1',
            self::GS,
            self::DPI,
            $page,
            $page,
            escapeshellarg($outAbs),
            escapeshellarg($in)
        );
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($outAbs)) {
            Log::warning("[LabelRender] gs falhou ({$code}) pra {$relPath}", ['out' => array_slice($output, -3)]);
            return null;
        }
        return $out;
    }

    /**
     * Blocos verticais de conteudo [inicioX, fimX] separados por vaos
     * totalmente brancos. Perfil de tinta por coluna: escala a imagem
     * (negativa, em tons de cinza) pra 1px de altura — a media da coluna
     * vira fracao de tinta; vao real tem tinta exatamente zero.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function contentBlocks(\Imagick $im): array
    {
        $w = $im->getImageWidth();

        $profile = clone $im;
        $profile->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
        $profile->negateImage(false);
        $profile->resizeImage($w, 1, \Imagick::FILTER_BOX, 1);
        $px = $profile->exportImagePixels(0, 0, $w, 1, 'I', \Imagick::PIXEL_FLOAT);
        $profile->destroy();

        // 0.02 = 2% de tinta na coluna: acima disso eh conteudo real.
        // Vaos entre documentos tem residuo de ~0.007 (linha de corte
        // pontilhada) — medido no PDF ML real (order 142761).
        $minGutter = max(15, (int) round($w * 0.015));
        $blocks    = [];
        $start     = null;
        $gap       = 0;
        $end       = 0;

        foreach ($px as $x => $ink) {
            if ($ink > 0.02) {
                if ($start === null) {
                    $start = $x;
                }
                $end = $x;
                $gap = 0;
                continue;
            }
            if ($start !== null && ++$gap >= $minGutter) {
                $blocks[] = [$start, $end];
                $start = null;
                $gap   = 0;
            }
        }
        if ($start !== null) {
            $blocks[] = [$start, $end];
        }

        return $blocks;
    }

    /**
     * NOV-208 layout 'footer': converte a nota retrato (DACE/DANFE PNG) em
     * faixa horizontal compacta. Recorte inteligente (padrao Shopee): campos
     * em 2 colunas + QR + chave de acesso ampliados, descartando o texto
     * legal fixo do ICMS. Se a geometria nao bater (layout diferente do ML),
     * cai no recorte simples em 2 metades lado a lado.
     *
     * Retorna path relativo ao disk public ou null.
     */
    public function footerStrip(string $relPath): ?string
    {
        $disk = Storage::disk('public');
        if (!$disk->exists($relPath)) {
            return null;
        }
        $out = 'labels/render/' . pathinfo($relPath, PATHINFO_FILENAME) . '-strip2.png';
        if ($disk->exists($out)) {
            return $out;
        }

        try {
            $im = new \Imagick($disk->path($relPath));
            $w  = $im->getImageWidth();
            $h  = $im->getImageHeight();

            if ($h <= $w) { // ja eh horizontal
                $im->destroy();
                return $relPath;
            }

            $split = $this->rowGapNearMiddle($im);

            $fields = clone $im; // metade de cima: titulo + campos
            $fields->cropImage($w, $split, 0, 0);
            $fields->trimImage(0);
            $fields->setImagePage(0, 0, 0, 0);

            $bottom = clone $im; // metade de baixo: texto legal + QR + chave
            $bottom->cropImage($w, $h - $split, 0, $split);
            $bottom->trimImage(0);
            $bottom->setImagePage(0, 0, 0, 0);
            $im->destroy();

            $canvas = $this->composeSmartStrip($fields, $bottom)
                ?? $this->composeHalves($fields, $bottom);
            $disk->put($out, $canvas->getImageBlob());

            $fields->destroy();
            $bottom->destroy();
            $canvas->destroy();

            return $out;
        } catch (\Throwable $e) {
            Log::warning("[LabelRender] Falha na faixa rodape de {$relPath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Recorte inteligente: campos (2 colunas) na linha de cima; QR + chave
     * de acesso ampliados na linha de baixo. O bloco de texto legal do ICMS
     * (boilerplate fixo que roubava ~40% da faixa) eh descartado.
     * Null se a geometria esperada nao for encontrada.
     */
    private function composeSmartStrip(\Imagick $fields, \Imagick $bottom): ?\Imagick
    {
        $parts = $this->extractQrAndChave($bottom);
        if (!$parts) {
            return null;
        }
        [$qr, $chave] = $parts;

        // campos em 2 colunas (corta num vao de linha perto do meio)
        $fw   = $fields->getImageWidth();
        $fh   = $fields->getImageHeight();
        $fcut = $this->rowGapNearMiddle($fields);

        $fa = clone $fields;
        $fa->cropImage($fw, $fcut, 0, 0);
        $fa->trimImage(0);
        $fa->setImagePage(0, 0, 0, 0);

        $fb = clone $fields;
        $fb->cropImage($fw, $fh - $fcut, 0, $fcut);
        $fb->trimImage(0);
        $fb->setImagePage(0, 0, 0, 0);

        $gap   = 18;
        $pad   = 8;
        $row1H = max($fa->getImageHeight(), $fb->getImageHeight());
        $row1W = $fa->getImageWidth() + $gap + $fb->getImageWidth();

        // linha 2: QR + chave, ampliados pra ocupar a largura da faixa
        $row2H = max(110, (int) round($row1H * 0.55));
        $qrS   = $row2H / max(1, $qr->getImageHeight());
        $qr->resizeImage(
            max(1, (int) round($qr->getImageWidth() * $qrS)),
            $row2H,
            \Imagick::FILTER_LANCZOS,
            1
        );

        $chaveMaxW = max(1, $row1W - $qr->getImageWidth() - $gap);
        $chaveS    = min($chaveMaxW / max(1, $chave->getImageWidth()), $row2H / max(1, $chave->getImageHeight()), 2.2);
        $chave->resizeImage(
            max(1, (int) round($chave->getImageWidth() * $chaveS)),
            max(1, (int) round($chave->getImageHeight() * $chaveS)),
            \Imagick::FILTER_LANCZOS,
            1
        );

        $tw = $row1W + $pad * 2;
        $th = $row1H + $gap + $row2H + $pad * 2;

        $canvas = new \Imagick();
        $canvas->newImage($tw, $th, 'white', 'png');
        $canvas->compositeImage($fa, \Imagick::COMPOSITE_OVER, $pad, $pad + intdiv($row1H - $fa->getImageHeight(), 2));
        $canvas->compositeImage($fb, \Imagick::COMPOSITE_OVER, $pad + $fa->getImageWidth() + $gap, $pad + intdiv($row1H - $fb->getImageHeight(), 2));

        $y2 = $pad + $row1H + $gap;
        $canvas->compositeImage($qr, \Imagick::COMPOSITE_OVER, $pad, $y2 + intdiv($row2H - $qr->getImageHeight(), 2));
        $cx = $pad + $qr->getImageWidth() + $gap + max(0, intdiv($chaveMaxW - $chave->getImageWidth(), 2));
        $canvas->compositeImage($chave, \Imagick::COMPOSITE_OVER, $cx, $y2 + intdiv($row2H - $chave->getImageHeight(), 2));

        $fa->destroy();
        $fb->destroy();
        $qr->destroy();
        $chave->destroy();

        return $canvas;
    }

    /** Recorte simples original: 2 metades lado a lado (fallback). */
    private function composeHalves(\Imagick $top, \Imagick $bottom): \Imagick
    {
        $gap = 14;
        $pad = 6;
        $tw  = $top->getImageWidth() + $bottom->getImageWidth() + $gap + $pad * 2;
        $th  = max($top->getImageHeight(), $bottom->getImageHeight()) + $pad * 2;

        $canvas = new \Imagick();
        $canvas->newImage($tw, $th, 'white', 'png');
        $canvas->compositeImage($top, \Imagick::COMPOSITE_OVER, $pad, $pad);
        $canvas->compositeImage($bottom, \Imagick::COMPOSITE_OVER, $pad + $top->getImageWidth() + $gap, $pad);

        return $canvas;
    }

    /**
     * Na metade de baixo da DACE (texto legal + QR + chave), isola o QR e o
     * bloco da chave de acesso via perfis de tinta. Null se a geometria nao
     * bater com o layout esperado.
     *
     * @return array{0: \Imagick, 1: \Imagick}|null [qr, chave]
     */
    private function extractQrAndChave(\Imagick $bottom): ?array
    {
        $w = $bottom->getImageWidth();
        $h = $bottom->getImageHeight();
        if ($w < 100 || $h < 100) {
            return null;
        }

        $profile = clone $bottom;
        $profile->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
        $profile->negateImage(false);
        $profile->resizeImage(1, $h, \Imagick::FILTER_BOX, 1);
        $px = $profile->exportImagePixels(0, 0, 1, $h, 'I', \Imagick::PIXEL_FLOAT);
        $profile->destroy();

        // blocos de linhas com tinta, separados por vaos >= 9px
        $runs  = [];
        $start = null;
        $end   = 0;
        $gapN  = 0;
        foreach ($px as $y => $ink) {
            if ($ink > 0.008) {
                if ($start === null) {
                    $start = $y;
                }
                $end  = $y;
                $gapN = 0;
                continue;
            }
            if ($start !== null && ++$gapN >= 9) {
                $runs[] = [$start, $end];
                $start  = null;
            }
        }
        if ($start !== null) {
            $runs[] = [$start, $end];
        }
        if (count($runs) < 3) {
            return null;
        }

        // chave = ultimo bloco (digitos); inclui o rotulo acima se for
        // baixinho ("Chave de Acesso DC-e") e proximo
        $last     = $runs[count($runs) - 1];
        $prev     = $runs[count($runs) - 2];
        $chaveTop = $last[0];
        if (($last[0] - $prev[1]) < (int) round($h * 0.08) && ($prev[1] - $prev[0]) < (int) round($h * 0.06)) {
            $chaveTop = $prev[0];
        }
        if ($chaveTop < (int) round($h * 0.55)) {
            return null; // chave nao esta no pe da metade — layout diferente
        }

        $cTop  = max(0, $chaveTop - 4);
        $chave = clone $bottom;
        $chave->cropImage($w, min($h, $last[1] + 4) - $cTop, 0, $cTop);
        $chave->trimImage(0);
        $chave->setImagePage(0, 0, 0, 0);

        // regiao acima da chave: QR (coluna estreita) + texto legal (larga)
        $upper = clone $bottom;
        $upper->cropImage($w, max(1, $chaveTop - 8), 0, 0);
        $upper->trimImage(0);
        $upper->setImagePage(0, 0, 0, 0);
        $uw = $upper->getImageWidth();
        $uh = $upper->getImageHeight();

        $profile = clone $upper;
        $profile->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
        $profile->negateImage(false);
        $profile->resizeImage($uw, 1, \Imagick::FILTER_BOX, 1);
        $cols = $profile->exportImagePixels(0, 0, $uw, 1, 'I', \Imagick::PIXEL_FLOAT);
        $profile->destroy();

        // vao vertical em branco mais largo separando QR do texto legal
        $bands  = [];
        $bStart = null;
        foreach ($cols as $x => $ink) {
            if ($ink <= 0.004) {
                $bStart ??= $x;
                continue;
            }
            if ($bStart !== null) {
                $bands[] = [$bStart, $x - 1];
                $bStart  = null;
            }
        }
        if ($bStart !== null) {
            $bands[] = [$bStart, $uw - 1];
        }
        $band = null;
        foreach ($bands as $b) {
            $center = intdiv($b[0] + $b[1], 2);
            if ($b[1] - $b[0] >= 8 && $center > (int) round($uw * 0.1) && $center < (int) round($uw * 0.8)) {
                if ($band === null || ($b[1] - $b[0]) > ($band[1] - $band[0])) {
                    $band = $b;
                }
            }
        }
        if ($band === null) {
            $chave->destroy();
            $upper->destroy();
            return null;
        }

        $cut  = intdiv($band[0] + $band[1], 2);
        $left = clone $upper;
        $left->cropImage($cut, $uh, 0, 0);
        $left->trimImage(0);
        $left->setImagePage(0, 0, 0, 0);

        $right = clone $upper;
        $right->cropImage($uw - $cut, $uh, $cut, 0);
        $right->trimImage(0);
        $right->setImagePage(0, 0, 0, 0);
        $upper->destroy();

        // QR = a coluna mais estreita (o texto legal eh o bloco largo)
        if ($left->getImageWidth() <= $right->getImageWidth()) {
            $qr = $left;
            $right->destroy();
        } else {
            $qr = $right;
            $left->destroy();
        }

        $qw = $qr->getImageWidth();
        $qh = $qr->getImageHeight();
        if ($qw < 40 || $qh < 40 || $qw / max(1, $qh) < 0.5 || $qw / max(1, $qh) > 2.0) {
            $qr->destroy();
            $chave->destroy();
            return null;
        }

        return [$qr, $chave];
    }

    /**
     * Linha (Y) com menos tinta na faixa central da imagem — ponto de corte
     * seguro pra dividir a nota retrato em 2 metades sem rachar texto.
     */
    private function rowGapNearMiddle(\Imagick $im): int
    {
        $h = $im->getImageHeight();

        $profile = clone $im;
        $profile->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
        $profile->negateImage(false);
        $profile->resizeImage(1, $h, \Imagick::FILTER_BOX, 1);
        $px = $profile->exportImagePixels(0, 0, 1, $h, 'I', \Imagick::PIXEL_FLOAT);
        $profile->destroy();

        $mid  = intdiv($h, 2);
        $from = (int) round($h * 0.35);
        $to   = (int) round($h * 0.65);
        $best = $mid;
        $min  = PHP_FLOAT_MAX;
        for ($y = $from; $y <= $to; $y++) {
            // prefere menos tinta; empate resolve pela proximidade do meio
            $score = $px[$y] * 10000 + abs($y - $mid);
            if ($score < $min) {
                $min  = $score;
                $best = $y;
            }
        }

        return $best;
    }

    private function cachePath(string $relPath, int $page, ?string $side = null): string
    {
        $base   = pathinfo($relPath, PATHINFO_FILENAME);
        $suffix = $side ? "-{$side}" : '';
        return "labels/render/{$base}-p{$page}{$suffix}.png";
    }

    /**
     * MUL-359: renderiza pagina de PDF do disk LOCAL (labels-private/) em PNG
     * no cache privado e devolve data URI pronto para <img src>. Documento
     * fiscal nunca toca o storage publico — nem como cache de render.
     */
    public function privatePageToDataUri(string $localRel, int $page = 1): ?string
    {
        $disk = Storage::disk('local');
        $in   = $disk->path($localRel);
        if (!is_file($in)) {
            return null;
        }
        $out = 'labels-private/render/' . pathinfo($localRel, PATHINFO_FILENAME) . "-p{$page}.png";
        if (!$disk->exists($out)) {
            $disk->makeDirectory('labels-private/render');
            $outAbs = $disk->path($out);
            $cmd = sprintf(
                '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r%d -dFirstPage=%d -dLastPage=%d -o %s %s 2>&1',
                self::GS, self::DPI, $page, $page,
                escapeshellarg($outAbs), escapeshellarg($in)
            );
            exec($cmd, $o, $code);
            if ($code !== 0 || !is_file($outAbs)) {
                \Illuminate\Support\Facades\Log::warning('[LabelPdf] gs falhou (privado)', ['rel' => $localRel, 'code' => $code]);
                return null;
            }
        }
        return 'data:image/png;base64,' . base64_encode($disk->get($out));
    }
}
