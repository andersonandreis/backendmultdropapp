<?php

namespace App\Services\Labels;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MUL-445: monta a etiqueta combinada como IMAGEM, pronta para a impressora.
 *
 * Por que nao HTML: a MUL-430 passou a mandar o modelo combinado para o QZ Tray como
 * `format: html`, que depende do renderizador embutido dele. Na maquina da bancada esse
 * componente nao responde -- a chamada nem falha, fica pendurada (MUL-442), e depois de
 * cair para a impressao de imagem o proprio QZ travava. Medido em 19/08/2026.
 *
 * Aqui o servidor faz o trabalho: desenha o cabecalho de separacao e cola a etiqueta do
 * marketplace embaixo, devolvendo um PNG. O painel manda esse PNG como imagem -- o
 * caminho que sempre funcionou, agora com o cabecalho que a operacao precisa.
 *
 * Medidas em 203 dpi (padrao das Zebra de 4x6"): 100x150mm = 800x1200 px.
 */
class EtiquetaCombinadaImagem
{
    private const LARGURA = 800;   // 100mm @ 203dpi
    private const ALTURA  = 1200;  // 150mm @ 203dpi
    private const FONTE      = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    private const FONTE_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    public function __construct(private LabelPdfRenderer $renderer) {}

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

            $lbl = new \Imagick($etiqueta);
            $lbl->setImageBackgroundColor('white');

            // A etiqueta ocupa TODO o espaco que sobra: e ela que a transportadora le.
            $espaco = self::ALTURA - $alturaCabecalho;
            $lbl->resizeImage(self::LARGURA, $espaco, \Imagick::FILTER_LANCZOS, 1, false);
            $folha->compositeImage($lbl, \Imagick::COMPOSITE_OVER, 0, $alturaCabecalho);
            $lbl->destroy();

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
     * Cabecalho de separacao. Devolve a altura ocupada.
     *
     * Teto rigido: o cabecalho e apoio, a etiqueta e o que precisa ser lido. Titulo
     * grande corta em vez de empurrar a etiqueta para fora da folha (MUL-439).
     */
    private function desenharCabecalho(\Imagick $folha, Order $order): int
    {
        $margem = 16;
        $y      = 34;
        $teto   = 210; // ~26mm

        $texto = new \ImagickDraw();
        $texto->setFillColor('black');

        foreach ($order->items->take(3) as $item) {
            if ($y > $teto - 40) {
                break;
            }

            $qtd = max(1, (int) $item->quantity);
            $sku = trim((string) ($item->sku ?: '-'));

            // Faixa "2x SKU" — negrito, sem caixa preta (economiza altura e tinta)
            $texto->setFont(self::FONTE_BOLD);
            $texto->setFontSize(26);
            $folha->annotateImage($texto, $margem, $y, 0, "{$qtd}x  {$sku}");
            $y += 30;

            // Localizacao no galpao — o dado que o separador usa para achar a peca
            $local = trim((string) ($item->product?->warehouse_location ?? ''));
            if ($local !== '') {
                $texto->setFont(self::FONTE_BOLD);
                $texto->setFontSize(24);
                $folha->annotateImage($texto, $margem, $y, 0, "Local: {$local}");
                $y += 28;
            }

            // Nome do produto, em uma linha
            $nome = trim((string) ($item->product?->name ?? $item->name ?? ''));
            if ($nome !== '') {
                $texto->setFont(self::FONTE);
                $texto->setFontSize(20);
                $folha->annotateImage($texto, $margem, $y, 0, mb_substr($nome, 0, 52));
                $y += 26;
            }

            $y += 6;
        }

        // Pedido e canal, alinhados a direita da primeira linha
        $meta = new \ImagickDraw();
        $meta->setFillColor('black');
        $meta->setFont(self::FONTE);
        $meta->setFontSize(20);
        $meta->setTextAlignment(\Imagick::ALIGN_RIGHT);
        $folha->annotateImage($meta, self::LARGURA - $margem, 34, 0, (string) $order->order_number);
        $folha->annotateImage($meta, self::LARGURA - $margem, 60, 0, strtoupper((string) $order->source));

        $altura = min($teto, max($y, 90));

        // Linha divisoria
        $linha = new \ImagickDraw();
        $linha->setStrokeColor('black');
        $linha->setStrokeWidth(2);
        $linha->line(0, $altura, self::LARGURA, $altura);
        $folha->drawImage($linha);

        return $altura + 8;
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
