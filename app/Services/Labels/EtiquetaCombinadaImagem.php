<?php

namespace App\Services\Labels;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MUL-445: monta a etiqueta combinada como IMAGEM, pronta para a impressora.
 *
 * Por que nao HTML: a MUL-430 passou a mandar o modelo combinado para o QZ Tray como
 * `format: html`, que depende do renderizador embutido dele. Na maquina da bancada esse
 * componente nao responde -- a chamada nem falha, fica pendurada (MUL-442), e depois de
 * cair para a impressao de imagem o proprio QZ travava. Medido em 19/08/2026.
 *
 * ESTA CLASSE E UM RENDERIZADOR, NAO UM MODELO NOVO.
 *
 * O modelo e o `resources/views/labels/combined-label.blade.php`, ajustado pelo Ruan ao
 * longo da NOV-096/NOV-208/MUL-439/MUL-440/MUL-441. Aqui so se troca o motor de desenho:
 * cada medida abaixo cita a regra de CSS que esta reproduzindo. Mudou o blade? Muda aqui
 * tambem -- e o contrario tambem vale. Nao invente campo, tamanho nem posicao: a operacao
 * ja treinou o olho no modelo que existe (MUL-445d, depois de a primeira versao ter
 * redesenhado o cabecalho por conta propria).
 *
 * Conversao de unidades: a folha e 100x150mm em 203dpi (Zebra 4x6") = 800x1200 px, entao
 * 1mm = 8 px. O CSS mede fonte em px de 96dpi, entao 1 px de CSS = 2,1167 px de imagem.
 */
class EtiquetaCombinadaImagem
{
    /** Folha: `@page { size: 100mm 150mm }` e `.etiqueta { width/height }`. */
    private const LARGURA = 800;
    private const ALTURA  = 1200;

    /** 1mm em px de imagem. */
    private const MM = 8.0;

    /** 1 px de CSS (96dpi) em px de imagem (203dpi). */
    private const CSSPX = 2.1167;

    /**
     * Arial e a primeira da pilha `font-family` do modelo. Liberation Sans e
     * metric-compativel com ela -- mesma largura de glifo, entao o texto quebra nos
     * mesmos pontos que quebrava no HTML.
     */
    private const FONTE      = '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf';
    private const FONTE_BOLD = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';

    public function __construct(
        private LabelPdfRenderer $renderer,
        private CombinedLabelService $modelo,
    ) {}

    /** Devolve a URL publica do PNG combinado, ou null se nao houver etiqueta. */
    public function gerar(Order $order): ?string
    {
        $etiqueta = $this->caminhoDaEtiqueta($order);
        if (! $etiqueta) {
            return null;
        }

        $disk = Storage::disk('public');

        // MUL-445b: arquivo SOLTO em labels/, sem subpasta. O painel busca a imagem
        // pelo proxy autenticado (/api/v1/proxy/storage/labels/{arquivo}), que so
        // aceita nome simples -- subpasta escaparia do padrao da rota.
        $saida = 'labels/combinada-' . $order->id . '-' . substr(md5($etiqueta . $order->updated_at), 0, 8) . '.png';

        if ($disk->exists($saida)) {
            return '/storage/' . $saida;
        }

        try {
            $folha = new \Imagick();
            $folha->newImage(self::LARGURA, self::ALTURA, 'white');
            $folha->setImageFormat('png');

            $alturaCabecalho = $this->desenharCabecalho($folha, $order);

            // `.etiqueta-marketplace { margin-top: 1mm }` + `.cabecalho { margin-bottom: 1.5mm }`
            $topo = $alturaCabecalho + (int) round(2.5 * self::MM);

            // MUL-441: `width: 98mm; height: 130mm` -- fixos, nao "auto". A etiqueta
            // ocupa a area inteira reservada a ela em vez de encolher para manter a
            // proporcao; e isso que deixa o codigo de barras no maior tamanho possivel.
            $largura = (int) round(98 * self::MM);
            $altura  = (int) round(130 * self::MM);

            $lbl = new \Imagick($etiqueta);
            $lbl->setImageBackgroundColor('white');
            $lbl->resizeImage($largura, $altura, \Imagick::FILTER_LANCZOS, 1, false);

            // `.etiqueta-marketplace { text-align: center }`
            $folha->compositeImage($lbl, \Imagick::COMPOSITE_OVER, (int) ((self::LARGURA - $largura) / 2), $topo);
            $lbl->destroy();

            // `.etiqueta { overflow: hidden }` -- o que passar de 150mm e cortado,
            // exatamente como o HTML corta hoje.
            $folha->cropImage(self::LARGURA, self::ALTURA, 0, 0);

            $disk->makeDirectory('labels');
            $folha->writeImage($disk->path($saida));
            $folha->destroy();

            return '/storage/' . $saida;
        } catch (\Throwable $e) {
            Log::warning('[MUL-445] falhou montar a etiqueta combinada: ' . $e->getMessage(), ['order_id' => $order->id]);
            return null;
        }
    }

    /**
     * `.cabecalho` -- devolve a altura ocupada (ja com a borda tracejada desenhada).
     *
     * Duas colunas em `display: table`: `.cab-produtos` a esquerda e `.cabecalho-meta`
     * (40mm) a direita.
     */
    private function desenharCabecalho(\Imagick $folha, Order $order): int
    {
        $padV = (int) round(1.5 * self::MM);   // .cabecalho padding vertical
        $padH = (int) round(2 * self::MM);     // .cabecalho padding horizontal
        $teto = (int) round(26 * self::MM);    // MUL-439: max-height: 26mm

        $largMeta     = (int) round(40 * self::MM);          // .cabecalho-meta width
        $largProdutos = self::LARGURA - (2 * $padH) - $largMeta;

        $produtos = $this->colunaDeProdutos($order, $largProdutos);
        $meta     = $this->colunaDeMeta($order, $largMeta - (int) round(2 * self::MM));

        $conteudo = max($produtos->getImageHeight(), $meta->getImageHeight());
        $altura   = min($teto, $conteudo + (2 * $padV));

        $folha->compositeImage($produtos, \Imagick::COMPOSITE_OVER, $padH, $padV);
        $folha->compositeImage($meta, \Imagick::COMPOSITE_OVER, self::LARGURA - $padH - $meta->getImageWidth(), $padV);
        $produtos->destroy();
        $meta->destroy();

        // `.cabecalho { max-height: 26mm; overflow: hidden }` -- limpa o que vazou.
        $branco = new \ImagickDraw();
        $branco->setFillColor('white');
        $branco->rectangle(0, $altura, self::LARGURA, $altura + (int) round(20 * self::MM));
        $folha->drawImage($branco);

        // `border-bottom: 1px dashed #000`
        $this->linhaTracejada($folha, $altura, '#000000', 6, 4, 2);

        return $altura;
    }

    /** `.cab-produtos` -- uma `.produto-row` por item. */
    private function colunaDeProdutos(Order $order, int $largura): \Imagick
    {
        // Sobra de altura de proposito: o corte por overflow acontece no chamador,
        // igual ao CSS -- linha cortada no meio sai cortada no meio.
        $canvas = new \Imagick();
        $canvas->newImage($largura, (int) round(60 * self::MM), 'white');
        $canvas->setImageFormat('png');

        $y = 0;
        $primeiro = true;

        foreach ($order->items as $item) {
            if ($y > (int) round(26 * self::MM)) {
                break;
            }

            if (! $primeiro) {
                // `.item-extra { margin-top: 1mm; padding-top: 1mm; border-top: 1px dotted #999 }`
                $y += (int) round(1 * self::MM);
                $this->linhaTracejada($canvas, $y, '#999999', 2, 3, 1);
                $y += (int) round(1 * self::MM);
            } else {
                // `.produto-row { margin-top: 1mm }`
                $y += (int) round(1 * self::MM);
            }
            $primeiro = false;

            $y += $this->desenharLinhaDeProduto($canvas, $item, $y, $largura);
        }

        $canvas->cropImage($largura, max($y, 1), 0, 0);

        return $canvas;
    }

    /** Uma `.produto-row`: `.produto-foto` (12mm) + `.produto-info`, ambas centradas. */
    private function desenharLinhaDeProduto(\Imagick $canvas, $item, int $y, int $largura): int
    {
        $dados = $this->modelo->buildItem($item);

        $celulaFoto = (int) round(12 * self::MM);           // .produto-foto width
        $ladoFoto   = (int) round(11 * self::MM);           // .produto-foto img max
        $recuoInfo  = (int) round(2 * self::MM);            // .produto-info padding-left
        $xInfo      = $celulaFoto + $recuoInfo;
        $largInfo   = $largura - $xInfo;

        // --- .produto-info -----------------------------------------------------
        $info = new \Imagick();
        $info->newImage($largInfo, (int) round(20 * self::MM), 'white');

        $fonte = (int) round(8 * self::CSSPX);              // 8px em todos: MUL-439

        // `.produto-qtd` -- badge preto. MUL-439: 8px (igual ao nome), destaque pelo
        // fundo preto e nao pelo tamanho, que era o que empurrava o cabecalho.
        $badge = new \ImagickDraw();
        $badge->setFont(self::FONTE_BOLD);
        $badge->setFontSize($fonte);
        $texto  = $dados['quantity'] . 'x';
        $medida = $info->queryFontMetrics($badge, $texto);
        $padBadge = (int) round(0.8 * self::MM);            // MUL-439: padding: 0 0.8mm
        $altBadge = (int) round($fonte * 1.25);             // line-height: 1.25
        $largBadge = (int) round($medida['textWidth']) + (2 * $padBadge);

        $caixa = new \ImagickDraw();
        $caixa->setFillColor('#000000');
        $raio = (int) round(1 * self::MM);                  // border-radius: 1mm
        $caixa->roundRectangle(0, 0, $largBadge, $altBadge, $raio, $raio);
        $info->drawImage($caixa);

        $badge->setFillColor('#ffffff');
        $badge->setTextAlignment(\Imagick::ALIGN_CENTER);
        $info->annotateImage($badge, (int) ($largBadge / 2), (int) round($altBadge * 0.78), 0, $texto);

        // `.produto-sku` -- 8px bold, na mesma linha do badge, sem quebrar (nowrap)
        $sku = new \ImagickDraw();
        $sku->setFont(self::FONTE_BOLD);
        $sku->setFontSize($fonte);
        $sku->setFillColor('#000000');
        $xSku = $largBadge + (int) round(0.8 * self::MM);   // margin-right: 0.8mm
        $info->annotateImage($sku, $xSku, (int) round($altBadge * 0.78), 0, (string) $dados['parent_sku']);

        $yInfo = $altBadge + (int) round(0.5 * self::MM);   // .produto-nome margin-top

        // `.produto-nome` -- 8px, #222, ate 2 linhas (max-height: 4.4mm)
        $nome = new \ImagickDraw();
        $nome->setFont(self::FONTE);
        $nome->setFontSize($fonte);
        $nome->setFillColor('#222222');
        $entrelinha = (int) round($fonte * 1.15);           // line-height: 1.15
        $linhas = $this->quebrar($info, $nome, (string) Str::limit($dados['name'], 60), $largInfo, 2);
        foreach ($linhas as $i => $linha) {
            $info->annotateImage($nome, 0, $yInfo + ($i * $entrelinha) + (int) round($fonte * 0.85), 0, $linha);
        }
        $altInfo = $yInfo + (count($linhas) * $entrelinha);
        $info->cropImage($largInfo, max($altInfo, 1), 0, 0);

        // --- altura da linha e centragem vertical (vertical-align: middle) ------
        $foto     = $this->fotoDoProduto($dados['image_url'], $ladoFoto);
        $altLinha = max($foto->getImageHeight(), $info->getImageHeight());

        $canvas->compositeImage(
            $foto,
            \Imagick::COMPOSITE_OVER,
            (int) (($celulaFoto - $foto->getImageWidth()) / 2),          // text-align: center
            $y + (int) (($altLinha - $foto->getImageHeight()) / 2),
        );
        $canvas->compositeImage(
            $info,
            \Imagick::COMPOSITE_OVER,
            $xInfo,
            $y + (int) (($altLinha - $info->getImageHeight()) / 2),
        );

        $foto->destroy();
        $info->destroy();

        return $altLinha;
    }

    /**
     * `.produto-foto img` (borda 1px #ccc) ou `.placeholder` ("sem foto", tracejado).
     */
    private function fotoDoProduto(?string $url, int $lado): \Imagick
    {
        $canvas = new \Imagick();
        $canvas->newImage($lado, $lado, 'white');
        $canvas->setImageFormat('png');

        $bytes = $this->baixarImagem($url);

        if ($bytes !== null) {
            try {
                $img = new \Imagick();
                $img->readImageBlob($bytes);
                $img->setImageBackgroundColor('white');
                $img = $img->flattenImages();
                // `max-width/max-height: 11mm` -- cabe dentro, mantendo a proporcao
                $img->resizeImage($lado - 2, $lado - 2, \Imagick::FILTER_LANCZOS, 1, true);
                $canvas->compositeImage(
                    $img,
                    \Imagick::COMPOSITE_OVER,
                    (int) (($lado - $img->getImageWidth()) / 2),
                    (int) (($lado - $img->getImageHeight()) / 2),
                );
                $img->destroy();

                $borda = new \ImagickDraw();
                $borda->setStrokeColor('#cccccc');
                $borda->setStrokeWidth(1);
                $borda->setFillOpacity(0);
                $borda->rectangle(0, 0, $lado - 1, $lado - 1);
                $canvas->drawImage($borda);

                return $canvas;
            } catch (\Throwable) {
                // cai no placeholder
            }
        }

        // `.placeholder { background: #f3f3f3; border: 1px dashed #aaa }`
        $fundo = new \ImagickDraw();
        $fundo->setFillColor('#f3f3f3');
        $fundo->rectangle(0, 0, $lado - 1, $lado - 1);
        $canvas->drawImage($fundo);
        $this->retanguloTracejado($canvas, $lado, '#aaaaaa');

        $txt = new \ImagickDraw();
        $txt->setFont(self::FONTE);
        $txt->setFontSize((int) round(8 * self::CSSPX));
        $txt->setFillColor('#777777');
        $txt->setTextAlignment(\Imagick::ALIGN_CENTER);
        $canvas->annotateImage($txt, (int) ($lado / 2), (int) ($lado / 2) + 5, 0, 'sem foto');

        return $canvas;
    }

    /**
     * `.cabecalho-meta` -- 8px, #333, alinhado a direita:
     *   **Pedido #X** · CANAL · <transportadora, azul>
     *   Prazo de envio: **dd/mm/aaaa hh:mm**
     *   Gerado em dd/mm/aaaa hh:mm
     */
    private function colunaDeMeta(Order $order, int $largura): \Imagick
    {
        $canvas = new \Imagick();
        $canvas->newImage($largura, (int) round(30 * self::MM), 'white');
        $canvas->setImageFormat('png');

        $fonte      = (int) round(8 * self::CSSPX);
        $entrelinha = (int) round($fonte * 1.3);            // line-height: 1.3

        // Linha 1 flui inline no HTML, entao pode quebrar dentro dos 40mm. Cada pedaco
        // carrega o proprio estilo (negrito no pedido, azul na transportadora).
        $pedacos = [['Pedido #' . $order->order_number, true, '#000000']];

        $canal = $order->marketplace ?: $order->source;
        if ($canal) {
            $pedacos[] = ['· ' . strtoupper((string) $canal), false, '#333333'];
        }
        if ($order->carrier_name) {
            $pedacos[] = ['· ' . $order->carrier_name, true, '#0000cc'];
        }

        $y = $this->escreverInlineADireita($canvas, $pedacos, $largura, 0, $fonte, $entrelinha);

        $prazo = $order->shipping_deadline ?: ($order->paid_at ? Carbon::parse($order->paid_at)->addHours(48) : null);
        if ($prazo) {
            $y = $this->escreverInlineADireita(
                $canvas,
                [['Prazo de envio: ', false, '#333333'], [Carbon::parse($prazo)->format('d/m/Y H:i'), true, '#000000']],
                $largura,
                $y,
                $fonte,
                $entrelinha,
            );
        }

        $y = $this->escreverInlineADireita(
            $canvas,
            [['Gerado em ' . now()->format('d/m/Y H:i'), false, '#333333']],
            $largura,
            $y,
            $fonte,
            $entrelinha,
        );

        $canvas->cropImage($largura, max($y, 1), 0, 0);

        return $canvas;
    }

    /**
     * Escreve pedacos inline com estilos diferentes, alinhados a direita, quebrando
     * por palavra quando nao cabem na largura. Devolve o y depois da ultima linha.
     */
    private function escreverInlineADireita(\Imagick $canvas, array $pedacos, int $largura, int $y, int $fonte, int $entrelinha): int
    {
        // Vira uma lista de palavras carregando estilo, para quebrar como o HTML quebra.
        $palavras = [];
        foreach ($pedacos as [$texto, $negrito, $cor]) {
            foreach (preg_split('/\s+/u', trim($texto)) as $palavra) {
                if ($palavra !== '') {
                    $palavras[] = [$palavra, $negrito, $cor];
                }
            }
        }

        $medir = function (string $texto, bool $negrito) use ($canvas, $fonte): float {
            $d = new \ImagickDraw();
            $d->setFont($negrito ? self::FONTE_BOLD : self::FONTE);
            $d->setFontSize($fonte);

            return $canvas->queryFontMetrics($d, $texto)['textWidth'];
        };

        $espaco = $medir(' ', false);
        $linhas = [[]];
        $atual  = 0.0;

        foreach ($palavras as [$palavra, $negrito, $cor]) {
            $larg = $medir($palavra, $negrito);
            $comEspaco = $linhas[count($linhas) - 1] ? $larg + $espaco : $larg;

            if ($atual + $comEspaco > $largura && $linhas[count($linhas) - 1]) {
                $linhas[] = [];
                $atual = 0.0;
                $comEspaco = $larg;
            }

            $linhas[count($linhas) - 1][] = [$palavra, $negrito, $cor, $larg];
            $atual += $comEspaco;
        }

        foreach ($linhas as $linha) {
            if (! $linha) {
                continue;
            }

            $total = 0.0;
            foreach ($linha as $i => [, , , $larg]) {
                $total += $larg + ($i > 0 ? $espaco : 0);
            }

            $x = $largura - $total;
            foreach ($linha as [$palavra, $negrito, $cor, $larg]) {
                $d = new \ImagickDraw();
                $d->setFont($negrito ? self::FONTE_BOLD : self::FONTE);
                $d->setFontSize($fonte);
                $d->setFillColor($cor);
                $canvas->annotateImage($d, (int) round($x), $y + (int) round($fonte * 0.85), 0, $palavra);
                $x += $larg + $espaco;
            }

            $y += $entrelinha;
        }

        return $y;
    }

    /** Quebra por palavra respeitando a largura, com teto de linhas (overflow: hidden). */
    private function quebrar(\Imagick $canvas, \ImagickDraw $draw, string $texto, int $largura, int $maxLinhas): array
    {
        $linhas = [];
        $atual  = '';

        foreach (preg_split('/\s+/u', trim($texto)) as $palavra) {
            if ($palavra === '') {
                continue;
            }
            $teste = $atual === '' ? $palavra : $atual . ' ' . $palavra;
            if ($canvas->queryFontMetrics($draw, $teste)['textWidth'] > $largura && $atual !== '') {
                $linhas[] = $atual;
                $atual = $palavra;
                if (count($linhas) >= $maxLinhas) {
                    return $linhas;
                }
            } else {
                $atual = $teste;
            }
        }

        if ($atual !== '' && count($linhas) < $maxLinhas) {
            $linhas[] = $atual;
        }

        return $linhas ?: [''];
    }

    /** `border-*: 1px dashed|dotted <cor>` na horizontal. */
    private function linhaTracejada(\Imagick $canvas, int $y, string $cor, int $traco, int $vao, int $espessura): void
    {
        $d = new \ImagickDraw();
        $d->setStrokeColor($cor);
        $d->setStrokeWidth($espessura);

        for ($x = 0; $x < $canvas->getImageWidth(); $x += $traco + $vao) {
            $d->line($x, $y, min($x + $traco, $canvas->getImageWidth()), $y);
        }

        $canvas->drawImage($d);
    }

    /** `border: 1px dashed` nos quatro lados (placeholder de foto). */
    private function retanguloTracejado(\Imagick $canvas, int $lado, string $cor): void
    {
        $d = new \ImagickDraw();
        $d->setStrokeColor($cor);
        $d->setStrokeWidth(1);

        for ($i = 0; $i < $lado; $i += 6) {
            $fim = min($i + 3, $lado - 1);
            $d->line($i, 0, $fim, 0);
            $d->line($i, $lado - 1, $fim, $lado - 1);
            $d->line(0, $i, 0, $fim);
            $d->line($lado - 1, $i, $lado - 1, $fim);
        }

        $canvas->drawImage($d);
    }

    /** Foto do SKU pai: caminho local, data URI ou URL remota. */
    private function baixarImagem(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_starts_with($url, 'data:')) {
            $pos = strpos($url, ',');

            return $pos === false ? null : (base64_decode(substr($url, $pos + 1)) ?: null);
        }

        if (str_starts_with($url, '/storage/')) {
            $rel = ltrim(substr($url, 9), '/');

            return Storage::disk('public')->exists($rel)
                ? Storage::disk('public')->get($rel)
                : null;
        }

        if (! str_starts_with($url, 'http')) {
            return null;
        }

        // Teto curto: a etiqueta sai com placeholder em vez de segurar a bancada
        // esperando a CDN do marketplace.
        try {
            $bytes = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 4],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]));

            return $bytes === false ? null : $bytes;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Caminho local da etiqueta do marketplace, ja recortada quando possivel. */
    private function caminhoDaEtiqueta(Order $order): ?string
    {
        $url = (string) $order->label_url;
        if ($url === '' || ! str_starts_with($url, '/storage/')) {
            return null;
        }

        $rel  = ltrim(substr($url, 9), '/');
        $disk = Storage::disk('public');

        if (preg_match('/\.(png|jpe?g)$/i', $rel)) {
            $recortada = $this->renderer->trimmedImageToUrl($rel);
            if ($recortada) {
                $rel = ltrim(substr($recortada, 9), '/');
            }
        } elseif (str_ends_with(strtolower($rel), '.pdf')) {
            $png = $this->renderer->trimmedPageToUrl($rel);
            if (! $png) {
                return null;
            }
            $rel = ltrim(substr($png, 9), '/');
        }

        return $disk->exists($rel) ? $disk->path($rel) : null;
    }
}
