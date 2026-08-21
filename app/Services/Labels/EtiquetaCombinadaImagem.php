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

    /**
     * Devolve as URLs publicas das folhas a imprimir, na ordem.
     *
     * Quase sempre e uma so: a etiqueta com o cabecalho. Pedido com mais de um item
     * ganha folhas extras com a lista (MUL-448).
     */
    public function gerar(Order $order): array
    {
        $etiqueta = $this->caminhoDaEtiqueta($order);
        if (! $etiqueta) {
            return [];
        }

        $disk = Storage::disk((string) config('filesystems.labels_disk', 'public'));

        // MUL-445b: arquivo SOLTO em labels/, sem subpasta. O painel busca a imagem
        // pelo proxy autenticado (/api/v1/proxy/storage/labels/{arquivo}), que so
        // aceita nome simples -- subpasta escaparia do padrao da rota.
        $saida = 'labels/combinada-' . $order->id . '-' . substr(md5($etiqueta . $order->updated_at), 0, 8) . '.png';

        $paginas = $this->paginasDeItens($order, $saida);

        if ($disk->exists($saida)) {
            return array_merge(['/storage/' . $saida], $paginas);
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

            return array_merge(['/storage/' . $saida], $paginas);
        } catch (\Throwable $e) {
            Log::warning('[MUL-445] falhou montar a etiqueta combinada: ' . $e->getMessage(), ['order_id' => $order->id]);
            return [];
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

        // MUL-448: com mais de um item a lista nao cabe. Duas linhas de produto
        // batiam no teto de 26mm, o cabecalho empurrava a etiqueta e ela saia
        // cortada embaixo -- o pedido 107022 perdeu ~8mm de etiqueta assim. Agora o
        // cabecalho avisa e a lista vai numa folha propria, entao a etiqueta volta a
        // ter o mesmo espaco de um pedido de item unico.
        $produtos = $order->items->count() > 1
            ? $this->avisoDeMultiplosItens($order, $largProdutos)
            : $this->colunaDeProdutos($order, $largProdutos);
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

    /**
     * MUL-448: faixa de aviso que substitui a lista quando ha mais de um item.
     *
     * Ocupa uma linha so, de proposito: e ela que devolve a altura do cabecalho ao
     * tamanho de um pedido comum e impede a etiqueta de sair cortada.
     */
    private function avisoDeMultiplosItens(Order $order, int $largura): \Imagick
    {
        $lado = (int) round(9 * self::MM);
        $alt  = $lado + (int) round(1 * self::MM);

        $canvas = new \Imagick();
        $canvas->newImage($largura, $alt, 'white');
        $canvas->setImageFormat('png');

        // Triangulo de atencao, preenchido -- le bem em impressao termica, que nao
        // tem meio-tom, e nao depende de arquivo de imagem no servidor.
        $tri = new \ImagickDraw();
        $tri->setFillColor('#000000');
        $tri->polygon([
            ['x' => $lado / 2, 'y' => 2],
            ['x' => $lado - 2, 'y' => $lado - 4],
            ['x' => 2,         'y' => $lado - 4],
        ]);
        $canvas->drawImage($tri);

        $exclamacao = new \ImagickDraw();
        $exclamacao->setFont(self::FONTE_BOLD);
        $exclamacao->setFontSize((int) round($lado * 0.5));
        $exclamacao->setFillColor('#ffffff');
        $exclamacao->setTextAlignment(\Imagick::ALIGN_CENTER);
        $canvas->annotateImage($exclamacao, (int) ($lado / 2), $lado - 8, 0, '!');

        $x = $lado + (int) round(2 * self::MM);

        $titulo = new \ImagickDraw();
        $titulo->setFont(self::FONTE_BOLD);
        $titulo->setFontSize((int) round(11 * self::CSSPX));
        $titulo->setFillColor('#000000');
        $canvas->annotateImage($titulo, $x, (int) round(11 * self::CSSPX), 0, 'PEDIDO COM MULTIPLOS ITENS');

        $sub = new \ImagickDraw();
        $sub->setFont(self::FONTE);
        $sub->setFontSize((int) round(8 * self::CSSPX));
        $sub->setFillColor('#222222');
        $canvas->annotateImage($sub, $x, (int) round(11 * self::CSSPX) + (int) round(9 * self::CSSPX), 0,
            $order->items->count() . ' itens - confira a folha de itens em anexo');

        return $canvas;
    }

    /**
     * MUL-448: folha(s) com a lista de itens, impressas depois da etiqueta.
     *
     * Mesma linha de produto do cabecalho (foto, badge de quantidade, SKU pai e
     * nome), so que sem teto de altura -- aqui a lista e o conteudo, nao o apoio.
     * Quebra em mais de uma folha se nao couber.
     */
    private function paginasDeItens(Order $order, string $chaveDaEtiqueta): array
    {
        if ($order->items->count() <= 1) {
            return [];
        }

        $disk    = Storage::disk((string) config('filesystems.labels_disk', 'public'));
        $base    = str_replace('.png', '', $chaveDaEtiqueta);
        $padH    = (int) round(4 * self::MM);
        $largura = self::LARGURA - (2 * $padH);
        $margem  = (int) round(8 * self::MM);

        $urls   = [];
        $folha  = null;
        $y      = 0;
        $numero = 1;

        $abrir = function () use (&$folha, &$y, $order, $padH) {
            $folha = new \Imagick();
            $folha->newImage(self::LARGURA, self::ALTURA, 'white');
            $folha->setImageFormat('png');
            $y = $this->tituloDaFolhaDeItens($folha, $order, $padH);
        };

        $fechar = function () use (&$folha, &$urls, &$numero, $base, $disk) {
            $nome = $base . '-itens' . $numero . '.png';
            $disk->makeDirectory('labels');
            $folha->writeImage($disk->path($nome));
            $folha->destroy();
            $folha = null;
            $urls[] = '/storage/' . $nome;
            $numero++;
        };

        $abrir();
        $primeiro = true;

        foreach ($order->items as $item) {
            if ($y > self::ALTURA - $margem - (int) round(14 * self::MM)) {
                $fechar();
                $abrir();
                $primeiro = true;
            }

            if (! $primeiro) {
                $y += (int) round(1.5 * self::MM);
                $this->linhaTracejada($folha, $y, '#999999', 2, 3, 1);
                $y += (int) round(1.5 * self::MM);
            }
            $primeiro = false;

            $linha = new \Imagick();
            $linha->newImage($largura, (int) round(20 * self::MM), 'white');
            $linha->setImageFormat('png');
            $alt = $this->desenharLinhaDeProduto($linha, $item, 0, $largura);
            $linha->cropImage($largura, max($alt, 1), 0, 0);
            $folha->compositeImage($linha, \Imagick::COMPOSITE_OVER, $padH, $y);
            $linha->destroy();

            $y += $alt;
        }

        $fechar();

        return $urls;
    }

    /** Cabecalho da folha de itens. Devolve o y onde a lista comeca. */
    private function tituloDaFolhaDeItens(\Imagick $folha, Order $order, int $padH): int
    {
        $y = (int) round(6 * self::MM);

        $titulo = new \ImagickDraw();
        $titulo->setFont(self::FONTE_BOLD);
        $titulo->setFontSize((int) round(13 * self::CSSPX));
        $titulo->setFillColor('#000000');
        $folha->annotateImage($titulo, $padH, $y, 0, 'ITENS DO PEDIDO (' . $order->items->count() . ')');

        $meta = new \ImagickDraw();
        $meta->setFont(self::FONTE);
        $meta->setFontSize((int) round(9 * self::CSSPX));
        $meta->setFillColor('#333333');
        $meta->setTextAlignment(\Imagick::ALIGN_RIGHT);
        $folha->annotateImage($meta, self::LARGURA - $padH, $y, 0,
            $order->order_number . '  ' . strtoupper((string) ($order->marketplace ?: $order->source)));

        $y += (int) round(2 * self::MM);
        $this->linhaTracejada($folha, $y, '#000000', 6, 4, 2);

        return $y + (int) round(2 * self::MM);
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

    /**
     * MUL-447: traz a etiqueta do hub para o storage local.
     *
     * Mesmo caminho do proxy autenticado (MUL-244/MUL-359): o hub e a fonte, e o
     * arquivo fica cacheado em labels/ -- entao a proxima leitura, inclusive a do
     * proprio proxy, ja acha aqui.
     */
    private function buscarNoHub(string $rel): bool
    {
        $hub = rtrim((string) config('services.hubai_federation.storage_url', 'https://api.hubai.io'), '/');

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(30)->connectTimeout(10)
                ->withHeaders([
                    'X-Federation-Tenant' => (string) config('app.tenant'),
                    'X-Federation-Secret' => (string) (config('services.hubai_federation.secret') ?: env('FEDERATION_HMAC_SECRET', '')),
                ])->get($hub . '/storage/' . $rel);
        } catch (\Throwable $e) {
            Log::warning('[MUL-447] hub nao respondeu pela etiqueta: ' . $e->getMessage(), ['arquivo' => $rel]);

            return false;
        }

        if (! $res->successful()) {
            return false;
        }

        Storage::disk((string) config('filesystems.labels_disk', 'public'))->put($rel, $res->body());

        return true;
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

            return Storage::disk((string) config('filesystems.labels_disk', 'public'))->exists($rel)
                ? Storage::disk((string) config('filesystems.labels_disk', 'public'))->get($rel)
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
        $disk = Storage::disk((string) config('filesystems.labels_disk', 'public'));

        // MUL-447: nem toda etiqueta esta em disco AQUI. Quem baixa do marketplace
        // costuma ser o hub, e esta WL serve o arquivo por proxy -- em 19/08/2026 o
        // hub tinha 223 etiquetas em disco contra 49 no multdrop. Sem este passo a
        // combinada so existia para a fatia baixada localmente: no preview do admin
        // e na impressao, todo o resto caia na etiqueta crua do marketplace.
        if (! $disk->exists($rel)) {
            // MUL-359: etiqueta antiga foi movida para o privado. Usa direto, sem
            // republicar -- so precisa ler os bytes para compor a imagem.
            if (Storage::disk('local')->exists($rel)) {
                return Storage::disk('local')->path($rel);
            }
            if (! $this->buscarNoHub($rel)) {
                return null;
            }
        }

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
