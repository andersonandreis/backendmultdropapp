<?php

namespace App\Services\Labels;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MUL-427: fallback de etiqueta pelo Bling do SELLER.
 *
 * Quando o marketplace recusa o documento (tracking_invalid / label_unavailable)
 * mas o seller tem Bling conectado, a etiqueta costuma ja estar la — o proprio
 * Bling recebe o AWB pela integracao dele com o marketplace. Receita provada
 * manualmente em 21/08/2026 (MUL-426): 3 etiquetas baixadas em producao com a
 * Shopee recusando o create_shipping_document.
 *
 * Regras aprendidas na validacao manual:
 *  - a busca por numeroLoja no Bling e FROUXA (traz vizinhos): validar sempre
 *    pelo detalhe do pedido;
 *  - rate limit do Bling = 3 req/s: pausa entre chamadas;
 *  - "nao possuem logistica" e transitorio — a etiqueta aparece quando o Bling
 *    a gera; devolver null deixa o retry natural tentar de novo;
 *  - o metodo alternativo fica REGISTRADO no pedido (manual_reason + admin_note),
 *    decisao do Ruan na MUL-426 — e quem chama encerra a fila de retentativa.
 */
class BlingSellerLabelFallback
{
    public function __construct(private BlingAuthService $auth)
    {
    }

    /** @return array{ready: bool, label_url: string}|null null = fallback nao se aplica/nao conseguiu */
    public function tentar(Order $order): ?array
    {
        if (! config('bling.seller_label_fallback', true)) {
            return null;
        }

        $sn = trim((string) ($order->marketplace_order_id ?: $order->external_order_id));
        if ($sn === '' || ! $order->client_id) {
            return null;
        }

        $acc = MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'bling')
            ->where('status', 'active')
            ->whereNotNull('bling_access_token')
            ->first();
        if (! $acc) {
            return null;
        }

        try {
            $token = $this->auth->getValidToken($acc);
            if (! $token) {
                return null;
            }

            $pedidoId = $this->acharPedidoPorSn($token, $sn);
            if (! $pedidoId) {
                return null;
            }

            usleep(500000); // rate limit Bling: 3 req/s
            $r = Http::withToken($token)->timeout(20)
                ->get('https://api.bling.com.br/Api/v3/logisticas/etiquetas?idsVendas[]=' . $pedidoId . '&formato=PDF');
            $link = $r->json()['data'][0]['link'] ?? null;
            if (! $link) {
                // inclui o "nao possuem logistica" transitorio — retry natural resolve
                Log::info('[MUL-427] Bling do seller ainda sem etiqueta', [
                    'order_id' => $order->id, 'bling_pedido' => $pedidoId, 'http' => $r->status(),
                ]);
                return null;
            }

            $bin = Http::timeout(30)->get($link); // link S3 assinado, expira em 1h
            if (! $bin->successful() || strlen($bin->body()) < 1000) {
                return null;
            }
            $corpo = $bin->body();

            // MUL-461: o Bling devolve PDF *ou* ZIP com a etiqueta termica ZPL dentro —
            // salvar o ZIP como .pdf entregava arquivo quebrado (158001, 21/08).
            // %PDF salva direto; PK extrai o ZPL e converte em PNG pelo MESMO conversor
            // do caminho Shopee; qualquer outra coisa e recusada (retry natural).
            if (str_starts_with($corpo, '%PDF')) {
                $ext = 'pdf';
                $conteudo = $corpo;
            } elseif (str_starts_with($corpo, 'PK')) {
                $svcEtq = app(\App\Services\ShippingLabelService::class);
                $zpl = $svcEtq->extractZplFromZip($corpo);
                $conteudo = $zpl ? $svcEtq->convertZplToPng($zpl) : null;
                if (! $conteudo) {
                    Log::warning('[MUL-461] ZIP do Bling sem ZPL conversivel', ['order_id' => $order->id]);
                    return null;
                }
                $ext = 'png';
            } else {
                Log::warning('[MUL-461] resposta do Bling nao e PDF nem ZIP', ['order_id' => $order->id]);
                return null;
            }

            $nome = sprintf('bling-%d-%s.%s', $order->id, substr(md5(uniqid('', true)), 0, 8), $ext);
            Storage::disk((string) config('filesystems.labels_disk', 'public'))
                ->put('labels/' . $nome, $conteudo);
            $url = '/storage/labels/' . $nome;

            $order->updateQuietly([
                'label_url'     => $url,
                'manual_reason' => 'etiqueta via metodo alternativo (Bling do seller) — marketplace nao liberou o documento (MUL-427)',
                'admin_note'    => trim(((string) $order->admin_note) . "\n" .
                    '[' . now()->format('d/m/Y H:i') . '] Etiqueta por metodo alternativo (Bling do seller, pedido Bling ' . $pedidoId . ').'),
            ]);

            Log::info('[MUL-427] etiqueta obtida no Bling do seller', [
                'order_id' => $order->id, 'bling_pedido' => $pedidoId, 'bytes' => strlen($conteudo),
            ]);

            return ['ready' => true, 'label_url' => $url];
        } catch (\Throwable $e) {
            Log::warning('[MUL-427] fallback Bling falhou', [
                'order_id' => $order->id, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Busca frouxa + validacao pelo detalhe (o filtro numeroLoja do Bling traz vizinhos). */
    private function acharPedidoPorSn(string $token, string $sn): ?int
    {
        $r = Http::withToken($token)->timeout(20)
            ->get('https://api.bling.com.br/Api/v3/pedidos/vendas', ['numeroLoja' => $sn, 'limite' => 5]);

        foreach (($r->json()['data'] ?? []) as $cand) {
            usleep(500000);
            $det = Http::withToken($token)->timeout(20)
                ->get('https://api.bling.com.br/Api/v3/pedidos/vendas/' . $cand['id']);
            if (trim((string) ($det->json()['data']['numeroLoja'] ?? '')) === $sn) {
                return (int) $cand['id'];
            }
        }

        return null;
    }
}
