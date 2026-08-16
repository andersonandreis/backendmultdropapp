<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PackingSlipService
 *
 * Gera packing slip (etiqueta de conferencia interna) com foto e SKU
 * de cada item do pedido. NAO substitui etiqueta de envio do marketplace.
 *
 * PDF cacheado em storage/app/packing-slips/order-{id}.pdf.
 * Regenerado quando forcado via $force=true.
 */
class PackingSlipService
{
    /**
     * Gera o PDF e retorna o path no Storage (ou null em caso de erro).
     */
    public function generate(Order $order, bool $force = false): ?string
    {
        $path = $this->storagePath($order);

        if (!$force && Storage::exists($path)) {
            return $path;
        }

        try {
            $order->loadMissing(['items.product.media', 'items.clientProduct']);

            $items = $this->buildItemsData($order);

            $pdf = Pdf::loadView('pdf.packing-slip', [
                'order'       => $order,
                'items'       => $items,
                'generatedAt' => now()->format('d/m/Y H:i'),
            ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled'      => true,
                'isHtml5ParserEnabled' => true,
                'defaultFont'          => 'DejaVu Sans',
                'dpi'                  => 96,
                'enable_css_float'     => true,
            ]);

            Storage::put($path, $pdf->output());

            return $path;
        } catch (\Throwable $e) {
            Log::error("[PackingSlip] Erro ao gerar PDF", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Retorna URL publica do PDF (ou null se nao gerado ainda).
     */
    public function publicUrl(Order $order): ?string
    {
        $path = $this->storagePath($order);
        if (!Storage::exists($path)) {
            return null;
        }
        return Storage::url($path);
    }

    /**
     * Monta array de dados dos itens com imagem resolvida.
     */
    private function buildItemsData(Order $order): array
    {
        return $order->items->map(function ($item) {
            $imageUrl = $this->resolveItemImage($item);

            $variationLabel = null;
            if ($item->productVariation) {
                $attrs = $item->productVariation->attributes ?? [];
                if (is_array($attrs)) {
                    $parts = [];
                    foreach ($attrs as $k => $v) {
                        $parts[] = "$k: $v";
                    }
                    $variationLabel = implode(' | ', $parts);
                }
            }

            return [
                'sku'            => $item->sku ?: ($item->variation_sku ?: '-'),
                'name'           => $item->name ?: 'Produto',
                'quantity'       => (int) $item->quantity,
                'image_url'      => $imageUrl,
                'variation'      => $variationLabel,
                'external_id'    => $item->external_item_id,
            ];
        })->toArray();
    }

    /**
     * Resolve a melhor URL de imagem disponivel para o item.
     *
     * Ordem de preferencia:
     * 1. product_image (campo direto no item — vindas do marketplace)
     * 2. Capa do produto vinculado (media is_cover=true)
     * 3. Primeira media disponivel do produto
     * 4. null (sem imagem)
     */
    private function resolveItemImage($item): ?string
    {
        if (!empty($item->product_image)) {
            return $item->product_image;
        }

        if ($item->product && $item->product->media->isNotEmpty()) {
            $cover = $item->product->media->firstWhere('is_cover', true)
                ?? $item->product->media->first();
            return $cover?->url;
        }

        return null;
    }

    private function storagePath(Order $order): string
    {
        return "packing-slips/order-{$order->id}.pdf";
    }
}
