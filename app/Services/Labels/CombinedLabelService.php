<?php

namespace App\Services\Labels;

use App\Models\Order;
use App\Services\ShippingLabelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * CombinedLabelService — NOV-096 + NOV-208.
 *
 * Gera o HTML de impressao da etiqueta combinada:
 *   [ cabecalho HubAI: foto SKU pai + qtd + SKU pai + nome ]
 *   --- linha tracejada ---
 *   [ imagem da etiqueta oficial do marketplace ]
 *
 * NOV-208 — dois layouts, sempre com o cabecalho personalizado:
 *   - 'sequence' (default): Zebra 100x150mm; etiqueta numa folha e a nota
 *     (DACE/DANFE) na folha seguinte, quando disponivel.
 *   - 'side': folha unica paisagem (A4 landscape); cabecalho no topo,
 *     etiqueta a esquerda + nota a direita.
 *   - 'footer': Zebra 100x150mm; nota compacta no rodape da mesma folha
 *     (padrao Shopee) — DACE/DANFE imagem vira faixa horizontal.
 *
 * PDFs (etiqueta ML / DANFE) sao renderizados em PNG via LabelPdfRenderer;
 * no formato novo ML paisagem a metade esquerda eh a etiqueta e a direita
 * a DACE (vendedor CPF) — o split reaproveita a nota embutida.
 *
 * NAO faz cache do HTML — a imagem da etiqueta ja tem cache via
 * ShippingLabelService::checkLabelStatus() e o render PNG em labels/render/.
 */
class CombinedLabelService
{
    public function __construct(
        private ShippingLabelService $shippingLabels,
        private LabelPdfRenderer $renderer,
        private SimplifiedDanfe $danfe,
        private MlLabelPdf $mlPdf,
    ) {
    }

    /**
     * Gera HTML pra um unico pedido.
     */
    public function generate(Order $order, string $layout = 'sequence'): string
    {
        return $this->generateBatch(collect([$order]), $layout);
    }

    /**
     * Gera HTML pra um lote de pedidos.
     *
     * @param  Collection<int,Order>|Order[]  $orders
     * @param  string  $layout  'sequence'|'side'
     */
    public function generateBatch($orders, string $layout = 'sequence'): string
    {
        $orders = $orders instanceof Collection ? $orders : collect($orders);
        $layout = in_array($layout, ['sequence', 'side', 'footer'], true) ? $layout : 'sequence';

        $etiquetas = $orders->map(fn (Order $o) => $this->buildEntry($o, $layout))->all();

        return View::make('labels.combined-label', [
            'etiquetas' => $etiquetas,
            'layout'    => $layout,
        ])->render();
    }

    /**
     * Monta o array de dados de UMA etiqueta (1 pedido).
     */
    private function buildEntry(Order $order, string $layout = 'sequence'): array
    {
        $order->loadMissing([
            'items.product.media',
            'items.productVariation.product.media',
        ]);

        $items = $order->items->map(fn ($item) => $this->buildItem($item))->all();

        [$labelImage, $labelError] = $this->resolveLabelImage($order);

        // NOV-208 v2: etiqueta ML reconstruida em HTML a partir do texto do
        // PDF (nitida, sem a faixa de recorte). Parse falhou → pipeline raster.
        $labelData = $daceData = $daceStrip = null;
        if ($labelImage && in_array($order->source, ['mercadolivre', 'ml'], true)
            && str_starts_with($labelImage, '/storage/')
            && str_ends_with(strtolower($labelImage), '.pdf')) {
            $parsed = $this->mlPdf->parse(
                Storage::disk((string) config('filesystems.labels_disk', 'public'))->path(substr($labelImage, strlen('/storage/')))
            );
            if ($parsed) {
                $labelData = $parsed['label'];
                $daceData  = $parsed['dace'];
                $daceStrip = $parsed['dace_strip'] ?? null;
            }
        }

        $invoiceImage = $invoiceData = null;
        if ($labelData) {
            $labelImage = null;
            if (!$daceData) { // vendedor CNPJ: nota via XML NF-e / DANFE
                [, $invoiceImage, $invoiceData] = $this->renderPrintableImages($order, null);
            }
        } else {
            [$labelImage, $invoiceImage, $invoiceData] = $this->renderPrintableImages($order, $labelImage);

            // layout 'footer': nota imagem retrato vira faixa horizontal
            if ($layout === 'footer' && $invoiceImage && str_starts_with($invoiceImage, '/storage/')) {
                $strip = $this->renderer->footerStrip(substr($invoiceImage, strlen('/storage/')));
                if ($strip) {
                    $invoiceImage = '/storage/' . $strip;
                }
            }
        }

        if ($labelImage) {
            $labelImage = $this->absoluteLabelUrl($labelImage);
        }
        if ($invoiceImage) {
            $invoiceImage = $this->absoluteLabelUrl($invoiceImage);
        }

        return [
            'order'         => $order,
            'items'         => $items,
            'label_image'   => $labelImage,
            'label_error'   => $labelError,
            'label_data'    => $labelData,
            'dace_data'     => $daceData,
            'dace_strip'    => $daceStrip,
            'invoice_image' => $invoiceImage,
            'invoice_data'  => $invoiceData,
        ];
    }

    /**
     * NOV-208: converte etiqueta PDF em PNG e resolve a imagem da nota.
     *
     * - Etiqueta .pdf local → split (paisagem ML: esquerda=etiqueta,
     *   direita=DACE quando presente).
     * - Sem nota embutida → busca DANFE NF-e via
     *   ShippingLabelService::ensureInvoiceDocument() e renderiza pagina 1.
     *
     * @return array{0: ?string, 1: ?string, 2: ?array} [label_image, invoice_image, invoice_data]
     */
    private function renderPrintableImages(Order $order, ?string $labelImage): array
    {
        $invoiceImage = null;
        $invoiceData  = null;

        // MUL-440: etiqueta que ja vem como imagem (o caso da Shopee, 252 de 262 em
        // 19/08/2026) tambem passa a ser recortada. Se o recorte falhar, segue a
        // original -- imprimir menor e melhor do que nao imprimir.
        if ($labelImage && str_starts_with($labelImage, '/storage/')
            && preg_match('/\.(png|jpe?g)$/i', $labelImage)) {
            $recortada = $this->renderer->trimmedImageToUrl(ltrim(substr($labelImage, 9), '/'));
            if ($recortada) {
                $labelImage = $recortada;
            }
        }

        if ($labelImage && str_starts_with($labelImage, '/storage/')
            && str_ends_with(strtolower($labelImage), '.pdf')) {
            $rel = substr($labelImage, strlen('/storage/'));
            if (in_array($order->source, ['mercadolivre', 'ml'], true)) {
                [$lbl, $inv] = $this->renderer->splitLabelPdf($rel);
                if ($lbl) {
                    $labelImage = $lbl;
                }
                $invoiceImage = $inv; // DACE embutida no PDF ML (vendedor CPF)
            } else {
                // NOV-208: etiqueta PDF de outros canais (Bling etc) — pagina inteira, sem split
                $png = $this->renderer->trimmedPageToUrl($rel);
                if ($png) {
                    $labelImage = $png;
                }
            }
        }

        // NOV-208: etiqueta Shopee (source shopee ou envio via Shopee/SPX) ja
        // embute a DANFE simplificada — gerar nota aqui duplicaria o documento.
        $shopeeLabel = $order->source === 'shopee'
            || stripos((string) $order->carrier_name, 'shopee') !== false;
        if (!$invoiceImage && !$shopeeLabel) {
            try {
                // NF-e: DANFE Simplificado (modelo etiqueta) gerado do XML;
                // DANFE A4 renderizada em PNG fica como fallback.
                $xmlRel = $this->shippingLabels->ensureInvoiceXml($order);
                if ($xmlRel) {
                    $invoiceData = $this->danfe->dataFromXml(Storage::disk('local')->path($xmlRel));
                }
                if (!$invoiceData) {
                    // MUL-359: DANFE vive no disk local; renderiza em cache
                    // privado e embute como data URI — o HTML fica autocontido
                    // e nada fiscal passa pelo /storage publico.
                    $danfe = $this->shippingLabels->ensureInvoiceDocument($order);
                    if ($danfe) {
                        $invoiceImage = $this->renderer->privatePageToDataUri($danfe);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[CombinedLabel] Falha ao resolver nota', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // MUL-359: etiqueta antiga movida pro privado — o publico nao tem mais o
        // arquivo, entao embute como data URI (mesma tecnica da nota fiscal).
        $labelImage = $this->privatizeIfMoved($labelImage);
        return [$labelImage, $invoiceImage, $invoiceData];
    }

    /**
     * MUL-359: se a imagem/PDF da etiqueta saiu do storage publico (arquivo
     * antigo protegido), resolve pelo privado e devolve data URI.
     */
    private function privatizeIfMoved(?string $img): ?string
    {
        if (!$img || !str_starts_with($img, '/storage/')) {
            return $img;
        }
        $rel = substr($img, strlen('/storage/'));
        if (is_file(Storage::disk((string) config('filesystems.labels_disk', 'public'))->path($rel))) {
            return $img; // publico ainda tem — nada muda
        }
        $priv = Storage::disk('local')->path($rel);
        if (!is_file($priv)) {
            return $img; // nao existe em lugar nenhum — deixa como esta
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $mime = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg'));
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($priv));
        }
        if ($ext === 'pdf') {
            return $this->renderer->privatePageToDataUri($rel);
        }
        return $img;
    }

    /**
     * Monta o array de dados de UM item (foto SKU pai + qtd + SKU pai + nome).
     */
    /** MUL-445d: publico -- o renderizador de imagem monta o item pelo MESMO metodo,
     *  para os dois nao divergirem no SKU pai, na foto nem no nome. */
    public function buildItem($item): array
    {
        $parentSku = $this->resolveParentSku($item);
        $imageUrl  = $this->resolveParentImage($item);
        $name      = $item->name ?: ($item->product?->name ?: 'Produto');

        return [
            'parent_sku' => $parentSku,
            'quantity'   => (int) $item->quantity,
            'image_url'  => $imageUrl,
            'name'       => $name,
        ];
    }

    /**
     * Resolve o SKU pai do item. Ordem:
     *   1) productVariation.product.sku  (item eh variacao)
     *   2) product.sku                   (item eh produto-pai direto)
     *   3) item.sku                      (fallback)
     */
    public function resolveParentSku($item): string
    {
        if ($item->productVariation && $item->productVariation->product) {
            $sku = $item->productVariation->product->sku;
            if (!empty($sku)) {
                return $sku;
            }
        }

        if ($item->product && !empty($item->product->sku)) {
            return $item->product->sku;
        }

        return $item->sku ?: ($item->variation_sku ?: '-');
    }

    /**
     * Resolve a melhor foto pro SKU pai. Ordem:
     *   1) media is_cover do produto pai (via variation.product ou direto)
     *   2) primeira media do produto pai
     *   3) product_image salva no proprio item (vinda do marketplace)
     *   4) null
     */
    public function resolveParentImage($item): ?string
    {
        $parentProduct = $item->productVariation?->product ?? $item->product;

        if ($parentProduct && $parentProduct->media && $parentProduct->media->isNotEmpty()) {
            $cover = $parentProduct->media->firstWhere('is_cover', true)
                ?? $parentProduct->media->first();
            if ($cover && $cover->url) {
                return $cover->url;
            }
        }

        if (!empty($item->product_image)) {
            return $item->product_image;
        }

        return null;
    }

    /**
     * Resolve a URL da imagem da etiqueta marketplace (com cache local
     * via ShippingLabelService).
     *
     * Retorna [url|null, errorMessage|null].
     */
    private function resolveLabelImage(Order $order): array
    {
        try {
            $status = $this->shippingLabels->checkLabelStatus($order);

            if (($status['ready'] ?? false) && !empty($status['label_url'])) {
                return [$status['label_url'], null];
            }

            return [null, $status['reason'] ?? 'Etiqueta ainda nao disponivel'];
        } catch (\Throwable $e) {
            Log::warning('[CombinedLabel] Falha ao resolver etiqueta', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return [null, 'Erro ao consultar etiqueta'];
        }
    }

    /**
     * MUL-244: label_url relativo quebra no iframe de impressao dos WLs
     * (arquivo em storage/labels muitas vezes so existe no hub).
     */
    private function absoluteLabelUrl(string $url): string
    {
        if (preg_match('#^(https?:)?//#', $url) || str_starts_with($url, 'data:')) {
            return $url;
        }
        $rel = '/' . ltrim($url, '/');
        if (str_starts_with($rel, '/storage/') && !is_file(public_path(ltrim($rel, '/')))) {
            return rtrim(config('services.hubai_federation.storage_url', 'https://api.hubai.io'), '/') . $rel;
        }
        return rtrim(config('app.url'), '/') . $rel;
    }
}
