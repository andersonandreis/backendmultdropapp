<?php

namespace App\Services;

use App\Models\Order;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShippingLabelService
{
    /**
     * Verifica se a etiqueta de envio está disponível para um pedido.
     *
     * Fluxo:
     * 1. Se o pedido já tem etiqueta local → retorna direto
     * 2. Se source=mercadolivre → busca via ML API
     * 3. Se source=bling e tem legacy_id → checa legacy DB (url_img) e baixa localmente
     * 4. Outros → retorna pendente com retry
     */
    /** MUL-354: de onde veio a chamada — 'tracking_update' habilita o caminho rapido. */
    protected ?string $trigger = null;

    public function comTrigger(?string $trigger): self
    {
        $this->trigger = $trigger;
        return $this;
    }

    public function checkLabelStatus(Order $order): array
    {
        // Se já tem etiqueta salva localmente, retorna direto
        if ($order->label_url
            && !str_contains($order->label_url, 'mock.hubai.io')
            && !str_contains($order->label_url, 'sistemagrupoonline')
            && !str_contains($order->label_url, 'goolhub.io')) { // NOV-090: goolhub.io = URL externa, precisa ser baixada
            return ['ready' => true, 'label_url' => $order->label_url];
        }

        // NOV-090: se o pedido tem URL externa do legado (goolhub.io/sistemagrupoonline)
        // e tem legacy_id, prioriza checkBlingLabel para baixar do DB legado, independente
        // do source. Isso cobre pedidos ML/Shopee importados via legado com url_img preenchido.
        $hasExternalLegacyLabel = $order->label_url
            && (str_contains($order->label_url, 'goolhub.io')
                || str_contains($order->label_url, 'sistemagrupoonline'));
        if ($hasExternalLegacyLabel && $order->legacy_id) {
            return $this->checkBlingLabel($order);
        }

        if ($order->source === 'mercadolivre') {
            return $this->checkMLLabel($order);
        }

        if ($order->source === 'bling' && $order->legacy_id) {
            return $this->checkBlingLabel($order);
        }

        // MUL-463: pedido importado da API do Bling (varredura MUL-413) — sem legacy_id,
        // com bling_order_id. Busca a etiqueta direto no Bling do seller (ex.: Amazon DBA).
        if ($order->source === 'bling' && $order->bling_order_id) {
            return $this->checkBlingApiLabel($order);
        }

        if ($order->source === 'shopee') {
            return $this->checkShopeeLabel($order);
        }

        // Outros marketplaces — por enquanto retorna pendente
        return [
            'ready'            => false,
            'reason'           => 'Etiqueta ainda não disponível. O marketplace está processando o envio.',
            'retry_in_minutes' => 10,
        ];
    }

    /**
     * Verifica etiqueta para pedidos Bling importados do sistema legado.
     *
     * O sistema legado (goolhub/tudoonline_production) gera as etiquetas via
     * workers_get_etiquetas_bling e armazena a URL em pedidos.url_img.
     * Este método checa o campo diretamente e baixa para storage local,
     * sem esperar pelo ciclo de 5 min do SyncLegacyOrdersJob.
     */
    protected function checkBlingLabel(Order $order): array
    {
        try {
            $legacy = DB::connection('legacy')
                ->table('pedidos')
                ->where('id', $order->legacy_id)
                ->select('url_img')
                ->first();
        } catch (\Throwable $e) {
            Log::warning("[Label/Bling] Falha ao consultar DB legado para Order #{$order->id}", [
                'error' => $e->getMessage(),
            ]);
            return [
                'ready'            => false,
                'reason'           => 'Não foi possível consultar o sistema legado.',
                'retry_in_minutes' => 10,
            ];
        }

        if (!$legacy || !$legacy->url_img) {
            return [
                'ready'            => false,
                'reason'           => 'Etiqueta Bling ainda não gerada. O sistema legado está processando.',
                'retry_in_minutes' => 15,
            ];
        }

        // Legado tem etiqueta — baixa para storage local
        $localUrl = $this->downloadLabelFromUrl($legacy->url_img, $order);
        if ($localUrl) {
            return ['ready' => true, 'label_url' => $localUrl];
        }

        // Baixou mas não salvou — retorna URL externa do legado diretamente
        Log::warning("[Label/Bling] Falha ao baixar etiqueta do legado, usando URL externa", [
            'order_id'   => $order->id,
            'legacy_url' => $legacy->url_img,
        ]);
        // Download falhou -- agendar retry em vez de salvar URL legada envenenada
        return [
            'ready'            => false,
            'reason'           => 'Falha ao baixar etiqueta do sistema legado. Tentando novamente.',
            'retry_in_minutes' => 5,
        ];
    }

    /**
     * FOR-101 (MUL-359 Fase A no Fornecefy): disk de gravacao da etiqueta.
     *
     * O label_url continua sendo '/storage/labels/...' -- e um IDENTIFICADOR,
     * nao promessa de URL publica. Quem le (label-file, proxyStorageLabel, a
     * rota /storage/labels com segredo de federacao e o CombinedLabelService)
     * ja procura no privado desde 46d6a107.
     */
    protected function labelsDisk(): string
    {
        return (string) config('filesystems.labels_disk', 'public');
    }

    /**
     * Baixa um arquivo de etiqueta de qualquer URL e salva em storage local.
     * Retorna o caminho local (/storage/labels/...) ou null em caso de falha.
     */
    protected function downloadLabelFromUrl(string $url, Order $order): ?string
    {
        $hash = md5($url);
        $disk = Storage::disk($this->labelsDisk()); // FOR-101

        // Verifica se ja foi baixado antes (nos DOIS disks: arquivo antigo pode
        // ter ficado no publico antes do fechamento -- FOR-101)
        foreach (['pdf', 'jpg', 'png', 'webp', 'gif'] as $ext) {
            $path = 'labels/' . $hash . '.' . $ext;
            if ($disk->exists($path) || Storage::disk('public')->exists($path)) {
                $local = '/storage/' . $path;
                $order->updateQuietly(['label_url' => $local]);
                return $local;
            }
        }

        // Sistemagrupoonline.com.br is behind Cloudflare — bypass to origin via HTTP port 80
        $isSistema = str_contains($url, 'sistemagrupoonline');
        $fetchUrl  = $isSistema
            ? str_replace(['https://', ' '], ['http://', '%20'], $url)
            : str_replace(' ', '%20', $url);

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible)',
            CURLOPT_SSL_VERIFYPEER => false,
        ];
        if ($isSistema) {
            $legacyIp   = env('LEGACY_WEB_IP', '217.216.82.83');
            $legacyHost = env('LEGACY_WEB_HOST', 'www.sistemagrupoonline.com.br');
            $curlOpts[CURLOPT_RESOLVE] = [
                "{$legacyHost}:80:{$legacyIp}",
                "sistemagrupoonline.com.br:80:{$legacyIp}",
            ];
        }

        // Baixa o arquivo
        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, $curlOpts);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (!$body || $info['http_code'] < 200 || $info['http_code'] >= 300 || strlen($body) > 20971520) {
            Log::warning("[Label] Falha ao baixar URL de etiqueta", [
                'url'    => $url,
                'status' => $info['http_code'] ?? 0,
                'size'   => strlen((string) $body),
            ]);
            return null;
        }

        $ext = $this->detectLabelExt($body, $info['content_type'] ?? '');
        if (!$ext) {
            Log::warning("[Label] Tipo de arquivo desconhecido", [
                'url'          => $url,
                'content_type' => $info['content_type'] ?? '',
            ]);
            return null;
        }

        $filename = 'labels/' . $hash . '.' . $ext;
        $disk->put($filename, $body);

        $local = '/storage/' . $filename;
        $order->updateQuietly(['label_url' => $local]);

        Log::info("[Label] Etiqueta baixada do legado: {$filename} para Order #{$order->id}");

        return $local;
    }

    /**
     * Detecta extensão via magic bytes ou Content-Type header.
     */
    private function detectLabelExt(string $bytes, string $contentType): ?string
    {
        if (strlen($bytes) >= 4) {
            if (substr($bytes, 0, 4) === '%PDF') return 'pdf';
            if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return 'jpg';
            if (substr($bytes, 0, 4) === "\x89PNG") return 'png';
            if (substr($bytes, 0, 4) === 'GIF8') return 'gif';
            if (substr($bytes, 0, 4) === 'RIFF' && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') return 'webp';
        }
        $ct = strtolower(trim(explode(';', $contentType)[0]));
        return match ($ct) {
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            default           => null,
        };
    }

    /**
     * Busca etiqueta do Mercado Livre via API de shipments.
     */
    protected function checkMLLabel(Order $order): array
    {
        // Encontra a conta ML vinculada a este pedido
        $account = $this->findMLAccount($order);
        if (!$account) {
            return [
                'ready'  => false,
                'reason' => 'Conta do Mercado Livre não encontrada para este pedido.',
            ];
        }

        try {
            $service = app(MercadoLivreService::class);
            $token   = $service->getValidToken($account);
        } catch (\Throwable $e) {
            return [
                'ready'  => false,
                'reason' => 'Erro ao obter token ML: ' . $e->getMessage(),
            ];
        }

        // Se não tem shipping_id salvo, busca na order do ML
        $shippingId = $order->external_shipping_id;
        if (!$shippingId && $order->external_order_id) {
            $shippingId = $this->fetchShippingIdFromML($token, $order->external_order_id);
            if ($shippingId) {
                $order->update(['external_shipping_id' => $shippingId]);
            }
        }

        if (!$shippingId) {
            return [
                'ready'            => false,
                'reason'           => 'O envio ainda não foi criado pelo Mercado Livre. Aguarde o processamento.',
                'retry_in_minutes' => 5,
            ];
        }

        // Verifica status do shipment
        $shipment = $this->getShipmentDetails($token, $shippingId);
        if (!$shipment) {
            return [
                'ready'            => false,
                'reason'           => 'Não foi possível consultar o envio no Mercado Livre.',
                'retry_in_minutes' => 5,
            ];
        }

        $shipStatus    = $shipment['status'] ?? 'unknown';
        $shipSubstatus = $shipment['substatus'] ?? null;

        // FOR-053-C: substatus explicito antes do download.
        // ML pode retornar status=ready_to_ship mas etiqueta ainda bloqueada por
        // regra do marketplace (mais comum: invoice_pending = documento fiscal ausente).
        // FOR-053-D: reason varia por tipo de vendedor — CPF emite DC-e, CNPJ emite NF-e.
        if ($shipSubstatus === 'invoice_pending') {
            $idType = $account->identification_type ?? null;
            if ($idType === 'CPF') {
                $reason = 'DC-e pendente. Emita a Declaração de Conteúdo Eletrônica no painel do Mercado Livre para liberar a etiqueta.';
            } elseif ($idType === 'CNPJ') {
                $reason = 'NF-e pendente. Emita a nota fiscal de saída deste pedido e envie para o Mercado Livre liberar a etiqueta.';
            } else {
                $reason = 'Documento fiscal pendente. Emita o documento (NF-e se CNPJ / DC-e se CPF) no painel do Mercado Livre para liberar a etiqueta.';
            }
            return [
                'ready'            => false,
                'reason'           => $reason,
                'retry_in_minutes' => 30,
            ];
        }
        if ($shipSubstatus === 'regenerating_label') {
            return [
                'ready'            => false,
                'reason'           => 'Mercado Livre regenerando etiqueta. Tente novamente em alguns minutos.',
                'retry_in_minutes' => 10,
            ];
        }
        if ($shipStatus === 'handling') {
            return [
                'ready'            => false,
                'reason'           => 'Envio em preparação no Mercado Livre. Aguardando liberação da etiqueta.',
                'retry_in_minutes' => 15,
            ];
        }

        // Etiqueta só fica disponível em certos status
        if (!in_array($shipStatus, ['ready_to_ship', 'shipped', 'delivered'])) {
            return [
                'ready'            => false,
                'reason'           => "Envio em status '{$shipStatus}'. Etiqueta ainda não liberada pelo Mercado Livre.",
                'retry_in_minutes' => 10,
            ];
        }

        // Tenta baixar o PDF da etiqueta
        $labelUrl = $this->downloadLabel($token, $shippingId, $order);
        if ($labelUrl) {
            // NOV-208: garante a nota fiscal junto da etiqueta (nunca bloqueia o fluxo)
            if ($account->ml_user_id && !Storage::disk('local')->exists("labels-private/danfe-{$order->id}.pdf")) {
                try {
                    $this->downloadMlInvoice($token, (string) $account->ml_user_id, $order);
                } catch (\Throwable $e) {
                    Log::warning("[Label] Falha ao baixar DANFE Order #{$order->id}: " . $e->getMessage());
                }
            }
            return ['ready' => true, 'label_url' => $labelUrl];
        }

        // MUL-358: envio ja ENTREGUE e o download recusou — terminal, igual ao
        // tratamento Shopee da MUL-354. O ML responde SHPLAB0200 "status is
        // delivered" com retry:false, e o codigo caia no generico de 5 min para
        // sempre: medido em 08/08/2026, 3 pedidos importados de vendas antigas
        // somaram 20 tentativas num unico dia, e a fila tinha 350 pendentes de
        // pedidos com 2+ dias. Entregue nao volta a imprimir — encerra aqui.
        if ($shipStatus === 'delivered') {
            return [
                'ready'            => false,
                'reason'           => 'Pedido ja entregue no Mercado Livre — a etiqueta nao sera liberada.',
                'reason_code'      => 'already_shipped',
                'skip_permanently' => true,
            ];
        }

        return [
            'ready'            => false,
            'reason'           => 'Etiqueta não disponível para download ainda. Tente novamente em alguns minutos.',
            'retry_in_minutes' => 5,
        ];
    }

    /**
     * Busca shipping_id na API de orders do ML.
     */
    protected function fetchShippingIdFromML(string $token, string $mlOrderId): ?string
    {
        $response = Http::withToken($token)
            ->get("https://api.mercadolibre.com/orders/{$mlOrderId}");

        if ($response->failed()) return null;

        $shipping = $response->json()['shipping'] ?? [];
        $id = $shipping['id'] ?? null;

        return $id ? (string) $id : null;
    }

    /**
     * Busca detalhes do shipment.
     */
    protected function getShipmentDetails(string $token, string $shippingId): ?array
    {
        $response = Http::withToken($token)
            ->get("https://api.mercadolibre.com/shipments/{$shippingId}");

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Baixa o PDF da etiqueta do ML e salva localmente.
     */
    protected function downloadLabel(string $token, string $shippingId, Order $order): ?string
    {
        $response = Http::withToken($token)
            ->get("https://api.mercadolibre.com/shipment_labels", [
                'shipment_ids'  => $shippingId,
                'response_type' => 'pdf',
            ]);

        if ($response->failed()) {
            Log::warning("[Label] Falha ao baixar etiqueta ML shipment #{$shippingId}", [
                'status' => $response->status(),
                'body'   => Str::limit($response->body(), 200),
            ]);
            return null;
        }

        // Salva o PDF
        $filename = "labels/order-{$order->id}-{$shippingId}.pdf";
        Storage::disk($this->labelsDisk())->put($filename, $response->body()); // FOR-101

        $url = '/storage/' . $filename;

        // Atualiza a order com a URL da etiqueta
        $order->update(['label_url' => $url]);

        Log::info("[Label] Etiqueta baixada: {$filename} para Order #{$order->id}");

        return $url;
    }

    /**
     * NOV-208: garante a nota fiscal (DANFE NF-e) do pedido ML disponivel
     * localmente. Convencao: labels/danfe-{order_id}.pdf (sem coluna nova).
     * Vendedor CPF sem NF-e: a DC-e ja vem embutida no PDF da etiqueta ML.
     */
    public function ensureInvoiceDocument(Order $order): ?string
    {
        // MUL-359: DANFE tem CPF — vive fora do storage publico. Retorna o
        // caminho RELATIVO no disk local; o unico consumidor
        // (CombinedLabelService) le pelo disk, nunca por URL.
        $filename = "labels-private/danfe-{$order->id}.pdf";
        if (Storage::disk('local')->exists($filename)) {
            return $filename;
        }
        if ($order->source !== 'mercadolivre' || !$order->external_order_id) {
            return null;
        }
        $account = $this->findMLAccount($order);
        if (!$account || !$account->ml_user_id) {
            return null;
        }
        try {
            $token = app(MercadoLivreService::class)->getValidToken($account);
        } catch (\Throwable $e) {
            return null;
        }
        return $this->downloadMlInvoice($token, (string) $account->ml_user_id, $order);
    }

    /**
     * NOV-208: garante o XML da NF-e autorizada em labels/nfe-{id}.xml
     * (attributes.xml_location) pra gerar o DANFE Simplificado.
     * Retorna o path relativo ao disk public ou null.
     */
    public function ensureInvoiceXml(Order $order): ?string
    {
        // MUL-359: XML da NF-e carrega CPF e endereco — disk local.
        $filename = "labels-private/nfe-{$order->id}.xml";
        if (Storage::disk('local')->exists($filename)) {
            return $filename;
        }
        if ($order->source === 'bling') {
            return $this->downloadBlingInvoiceXml($order, $filename);
        }
        if ($order->source !== 'mercadolivre' || !$order->external_order_id) {
            return null;
        }
        $account = $this->findMLAccount($order);
        if (!$account || !$account->ml_user_id) {
            return null;
        }
        try {
            $token = app(MercadoLivreService::class)->getValidToken($account);
        } catch (\Throwable $e) {
            return null;
        }
        $res = Http::withToken($token)
            ->get("https://api.mercadolibre.com/users/{$account->ml_user_id}/invoices/orders/{$order->external_order_id}");
        if ($res->failed()) {
            return null;
        }
        $invoice = $res->json();
        if (($invoice['status'] ?? null) !== 'authorized') {
            return null;
        }
        $xmlPath = $invoice['attributes']['xml_location'] ?? null;
        if (!$xmlPath) {
            return null;
        }
        $xml = Http::withToken($token)->get('https://api.mercadolibre.com' . $xmlPath);
        if ($xml->failed() || !str_contains($xml->body(), '<infNFe')) {
            return null;
        }
        Storage::disk((string) config('filesystems.labels_disk', 'public'))->put($filename, $xml->body());
        Log::info("[Label] XML NF-e baixado: {$filename} para Order #{$order->id}");
        return $filename;
    }

    /**
     * NOV-208: XML da NF-e de pedido Bling via API v3 —
     * pedido de venda -> notaFiscal.id -> GET /nfe/{id} -> link do XML.
     * Bling nao tem DACE; a nota vira DANFE Simplificada no modelo combinado.
     */
    protected function downloadBlingInvoiceXml(Order $order, string $filename): ?string
    {
        if (!$order->bling_order_id) {
            return null;
        }
        $account = null;
        if ($order->marketplace_account_id) {
            $account = MarketplaceAccount::where('id', $order->marketplace_account_id)
                ->where('platform', 'bling')
                ->first();
        }
        if (!$account || empty($account->bling_access_token)) {
            $account = MarketplaceAccount::where('supplier_id', $order->supplier_id)
                ->where('platform', 'bling')
                ->whereNotNull('bling_access_token')
                ->orderByDesc('updated_at')
                ->first();
        }
        if (!$account) {
            return null;
        }
        try {
            $client = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);
            $pedido = $client->getOrder($account, (int) $order->bling_order_id);
            $nfeId  = $pedido['data']['notaFiscal']['id'] ?? null;
            if (!$nfeId) {
                return null;
            }
            $nfe    = $client->getNfe($account, (int) $nfeId);
            $xmlUrl = $nfe['data']['xml'] ?? null;
            if (!$xmlUrl) {
                return null;
            }
            $xml = Http::get($xmlUrl);
            if ($xml->failed() || !str_contains($xml->body(), '<infNFe')) {
                return null;
            }
            Storage::disk('local')->put($filename, $xml->body());
            Log::info("[Label] XML NF-e Bling baixado: {$filename} para Order #{$order->id}");
            return $filename;
        } catch (\Throwable $e) {
            Log::warning("[Label] NF-e Bling falhou pra Order #{$order->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Baixa a DANFE autorizada via API fiscal do ML (attributes.danfe_location).
     */
    protected function downloadMlInvoice(string $token, string $mlUserId, Order $order): ?string
    {
        $res = Http::withToken($token)
            ->get("https://api.mercadolibre.com/users/{$mlUserId}/invoices/orders/{$order->external_order_id}");
        if ($res->failed()) {
            return null;
        }
        $invoice = $res->json();
        if (($invoice['status'] ?? null) !== 'authorized') {
            return null;
        }
        $danfePath = $invoice['attributes']['danfe_location'] ?? null;
        if (!$danfePath) {
            return null;
        }
        $pdf = Http::withToken($token)->get('https://api.mercadolibre.com' . $danfePath);
        if ($pdf->failed() || !str_starts_with($pdf->body(), '%PDF')) {
            return null;
        }
        $filename = "labels-private/danfe-{$order->id}.pdf";
        Storage::disk('local')->put($filename, $pdf->body());
        Log::info("[Label] DANFE baixada (privada): {$filename} para Order #{$order->id}");
        return $filename;
    }

    /**
     * Encontra a MarketplaceAccount vinculada ao pedido.
     */
    protected function findMLAccount(Order $order): ?MarketplaceAccount
    {
        // FOR-043: prioridade absoluta e a conta ML explicita do pedido
        // (era ignorada; retornava a conta do fornecedor via items->clientProduct,
        // causando "envio nao criado" porque o token era de outro dono ML).
        if ($order->marketplace_account_id) {
            $explicit = MarketplaceAccount::where('id', $order->marketplace_account_id)
                ->where('platform', 'mercadolivre')
                ->whereNotNull('ml_access_token')
                ->first();
            if ($explicit) return $explicit;
        }

        // Tenta via item do pedido (fluxo B2B onde a conta ML vem do fornecedor)
        $item = $order->items()->first();
        if ($item?->clientProduct?->marketplaceAccount) {
            return $item->clientProduct->marketplaceAccount;
        }

        // Fallback: busca pelo supplier_id
        return MarketplaceAccount::where('supplier_id', $order->supplier_id)
            ->where('platform', 'mercadolivre')
            ->whereNotNull('ml_access_token')
            ->first();
    }
    // =========================================================================
    // SHOPEE -- Download etiqueta THERMAL_AIR_WAYBILL (ZPL -> PNG)
    // =========================================================================

    /**
     * Obtém a etiqueta de envio para pedidos Shopee.
     *
     * Fluxo:
     * 1. Encontra MarketplaceAccount via marketplace_account_id ou supplier_id
     * 2. Solicita criacao do documento THERMAL_AIR_WAYBILL (idempotente)
     * 3. Verifica status via get_shipping_document_result
     * 4. Se READY: baixa ZIP binario, extrai ZPL, converte para PNG, salva no storage
     * 5. Se FAILED ou ainda PROCESSING: retorna pendente com retry
     *
     * @param  Order $order
     * @return array{ready: bool, label_url?: string, reason?: string, retry_in_minutes?: int}
     */
    /**
     * MUL-379 — transportadora e rastreio, lidos do marketplace.
     *
     * QUANDO a transportadora aparece: no instante em que a logistica e arranjada, que e
     * o mesmo instante em que a etiqueta passa a existir. Esse evento JA e recebido e
     * processado aqui — a Shopee empurra o code 4 (tracking_update) e o
     * ShopeeWebhookController despacha o FetchShippingLabelJob; ML e Bling caem no mesmo
     * job por outros gatilhos. Por isso a leitura mora aqui e nao num processo proprio:
     * cada ramo reusa a chamada que este servico ja faz para achar a etiqueta.
     *
     * Quem grava e um lugar so (FetchShippingLabelJob), para todos os canais.
     * Canal que nao expoe a informacao devolve array vazio, sem quebrar nada.
     */
    public function logisticaDoMarketplace(Order $order): array
    {
        try {
            return match ($order->source) {
                'shopee'       => $this->logisticaShopee($order),
                'mercadolivre' => $this->logisticaML($order),
                default        => [],
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MUL-379] falha ao ler logistica do marketplace', [
                'order_id' => $order->id,
                'source'   => $order->source,
                'error'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** Shopee: o mesmo package_list que ja usamos para o package_number traz o shipping_carrier. */
    private function logisticaShopee(Order $order): array
    {
        $account = $this->findShopeeAccount($order);
        $orderSn = $order->marketplace_order_id ?? $order->order_number;
        if (! $account || ! $orderSn) {
            return [];
        }

        $shopee = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);
        $token  = $shopee->getValidAccessToken($account);
        if (! $token) {
            return [];
        }

        $det = $shopee->getOrderDetail((int) $account->shop_id, $token, [(string) $orderSn]);
        $d   = $det['response']['order_list'][0] ?? [];

        return [
            'carrier'  => $d['package_list'][0]['shipping_carrier'] ?? $d['shipping_carrier'] ?? null,
            'tracking' => $d['tracking_no'] ?? null,
        ];
    }

    /** ML: o shipment que ja consultamos para saber se a etiqueta saiu diz a transportadora. */
    private function logisticaML(Order $order): array
    {
        $account = $this->findMLAccount($order);
        if (! $account) {
            return [];
        }

        $service = app(MercadoLivreService::class);
        $token   = $service->getValidToken($account);
        if (! $token) {
            return [];
        }

        $shippingId = $order->external_shipping_id
            ?: ($order->external_order_id ? $this->fetchShippingIdFromML($token, (string) $order->external_order_id) : null);
        if (! $shippingId) {
            return [];
        }

        $shipment = $this->getShipmentDetails($token, (string) $shippingId);
        if (! $shipment) {
            return [];
        }

        // tracking_method e a transportadora de fato (Correios, Loggi, Mercado Envios);
        // shipping_option.name descreve o servico e serve de segunda opcao.
        return [
            'carrier'  => $shipment['tracking_method'] ?? ($shipment['shipping_option']['name'] ?? null),
            'tracking' => $shipment['tracking_number'] ?? null,
        ];
    }

    protected function checkShopeeLabel(Order $order): array
    {
        $account = $this->findShopeeAccount($order);
        if (! $account) {
            return [
                'ready'  => false,
                'reason' => 'Conta Shopee nao encontrada para este pedido.',
            ];
        }

        $orderSn = $order->marketplace_order_id ?? $order->order_number;
        if (! $orderSn) {
            return [
                'ready'  => false,
                'reason' => 'Numero do pedido Shopee (order_sn) nao disponivel.',
            ];
        }

        try {
            $shopeeService = app(\App\Services\Integrations\Marketplaces\ShopeeService::class);

            // MUL-354: o package_number e obrigatorio nas chamadas de documento. Sem ele a
            // Shopee responde shipping_document_should_print_first mesmo com o documento
            // pronto. Cascata: o que ja temos -> package_list do get_order_detail.
            $packageNumber = $order->external_shipping_id ?: null;

            if (! $packageNumber) {
                $token = $shopeeService->getValidAccessToken($account);
                if ($token) {
                    $det = $shopeeService->getOrderDetail((int) $account->shop_id, $token, [$orderSn]);
                    $packageNumber = $det['response']['order_list'][0]['package_list'][0]['package_number'] ?? null;
                    if ($packageNumber) {
                        $order->updateQuietly(['external_shipping_id' => $packageNumber]);
                    }
                }
            }

            // MUL-354: a ORDEM dos webhooks ja diz o que fazer.
            //
            // O TRACKING_UPDATE (code 4) so chega quando a logistica foi arranjada — e ai
            // o documento existe. Medido em 08/08: 16s e 32s da venda quando a emissao e
            // automatica (Bling), 10min e 41min quando alguem gera manualmente. Em todos
            // os casos o documento ja estava READY: 12 de 12 na amostra.
            //
            // Entao, vindo desse evento, nao se pergunta o estado — baixa direto. Perguntar
            // seria gastar uma chamada para confirmar o que o evento ja informou.
            //
            // Ressalva: tracking existe sem documento se o seller usa o emissor da Shopee e
            // nunca clica. Nesse caso o download falha e o fluxo cai no create logo abaixo,
            // que e o comportamento correto. Por isso nao se assume cegamente.
            $veioDoEvento = $this->trigger === 'tracking_update';
            $statusPrevio = null;

            if (! $veioDoEvento) {
                // backfill, manual ou pedido novo sem evento: aqui nao ha sinal previo,
                // entao perguntar antes evita um create que seria recusado.
                $statusPrevio = $shopeeService->getShippingDocumentStatus($account, $orderSn, $packageNumber);
            }

            // MUL-457: organizar o envio (ship_order) e PRE-REQUISITO do documento e
            // roda AQUI, proativo, na mesma execucao — nao se espera o create falhar
            // (a Shopee aceita o create sem erro mesmo sem envio organizado e o
            // documento fica "em geracao" pra sempre; medido no 158057 em 21/08).
            // E o mesmo passo que o Bling faria por fora; "ja organizado" custa 1 GET.
            if (! $veioDoEvento && $statusPrevio !== 'READY') {
                $arr = $shopeeService->arrangeShipment($account, $orderSn);
                if (! empty($arr['ok']) && empty($arr['already'])) {
                    Log::warning('[Label/Shopee] Envio organizado pelo sistema (ship_order) — MUL-457 proativo', [
                        'order_id' => $order->id, 'order_sn' => $orderSn,
                    ]);
                    $order->updateQuietly([
                        'admin_note' => trim((string) ($order->admin_note ? $order->admin_note . "\n" : '')
                            . '[' . now()->format('d/m/Y H:i') . '] Envio organizado na Shopee pelo sistema (ship_order) — libera a geracao da etiqueta (MUL-457)'),
                    ]);
                }
            }
            if ($veioDoEvento || $statusPrevio === 'READY') {
                Log::info('[Label/Shopee] Documento disponivel — pulando create', [
                    'order_id' => $order->id, 'order_sn' => $orderSn,
                    'origem'   => $veioDoEvento ? 'evento tracking_update' : 'consulta READY',
                ]);
                $create = ['ok' => true, 'error' => null, 'message' => null];
            } else {
                $create = $shopeeService->createShippingDocument($account, $orderSn, $packageNumber);
            }

            // Passo 1 (original): solicita criacao do documento (idempotente)
            // SEL-413: a resposta desta chamada e quem sabe se a etiqueta e possivel.
            // Antes ela era descartada e o pedido caia no generico "ainda processando",
            // mesmo quando a Shopee ja tinha dito que a encomenda saiu.
            if (! $create['ok']) {
                $failMsg = strtolower((string) ($create['message'] ?? ''));

                // MUL-456: create recusado quase sempre significa que NINGUEM organizou o
                // envio (ship_order) — o Bling faria isso por fora e, quando falha, falha em
                // silencio. Medido 21/08: 10 pedidos com invoice "valid" e shipping_parameter
                // "organizavel" presos horas em awaiting_marketplace. Organizar e idempotente
                // ("ja organizado/despachado" volta already). Se organizou AGORA, o documento
                // entra em geracao — retry curto resolve, sem depender do Bling do seller.
                if (! str_contains($failMsg, 'has been shipped')) {
                    $arr = $shopeeService->arrangeShipment($account, $orderSn);
                    if (! empty($arr['ok']) && empty($arr['already'])) {
                        Log::warning('[Label/Shopee] Envio organizado pelo sistema (ship_order) — MUL-456', [
                            'order_id' => $order->id, 'order_sn' => $orderSn,
                        ]);
                        $order->updateQuietly([
                            'admin_note' => trim((string) ($order->admin_note ? $order->admin_note . "\n" : '')
                                . '[' . now()->format('d/m/Y H:i') . '] Envio organizado na Shopee pelo sistema (ship_order) — libera a geracao da etiqueta (MUL-456)'),
                        ]);
                        return [
                            'ready'            => false,
                            'reason'           => 'Envio organizado agora (ship_order) — documento de etiqueta em geracao.',
                            'reason_code'      => 'awaiting_marketplace',
                            'retry_in_minutes' => 5,
                        ];
                    }
                }

                // Encomenda ja despachada — etiqueta nunca mais vai sair. Nao adianta retentar.
                if (str_contains($failMsg, 'has been shipped')) {
                    return [
                        'ready'            => false,
                        'reason'           => 'A encomenda ja foi despachada no marketplace.',
                        'reason_code'      => 'already_shipped',
                        'skip_permanently' => true,
                    ];
                }

                // Rastreio invalidado. MUL-372: a Shopee devolve este MESMO erro pra pedido
                // NOVO cujo rastreio ainda nao foi alocado (padrao SEL-413 de erro ambiguo).
                // Terminal so pra pedido velho (prazo de envio vencido); pedido jovem
                // retenta em 5 min — antes ficava orfao esperando o varredor de 30 min,
                // que era o delay de 30-40 min ate etiqueta/autopay reclamado em 12/08.
                if ($create['error'] === 'logistics.tracking_number_invalid') {
                    if ($order->created_at && $order->created_at->diffInHours(now()) < 48) {
                        return [
                            'ready'            => false,
                            'reason'           => 'Shopee ainda nao alocou o rastreio (pedido novo) — retentando.',
                            'reason_code'      => 'tracking_invalid',
                            'retry_in_minutes' => 5,
                        ];
                    }
                    return [
                        'ready'            => false,
                        'reason'           => 'A Shopee invalidou o codigo de rastreio deste pedido.',
                        'reason_code'      => 'tracking_invalid',
                        'skip_permanently' => true,
                    ];
                }

                // SEL-413 (04/08): a Shopee usa 'package_can_not_print' para dois casos
                // OPOSTOS. Com "not yet ready" e temporario — o documento esta sendo gerado
                // e vale retentar. Sem esse detalhe, e recusa seca: ela nao diz o motivo e
                // nao vai liberar. Medido no pedido 1398, que ontem respondia
                // tracking_number_invalid e hoje responde "The package can not print now. "
                // sem detalhe nenhum. Cair no generico faz a tela dizer "aguarde o
                // marketplace" para sempre, que e exatamente o que esta tarefa existe
                // para eliminar.
                if ($create['error'] === 'logistics.package_can_not_print'
                    && ! str_contains($failMsg, 'not yet ready')) {
                    // MUL-428: a recusa seca tambem e ambigua em pedido NOVO (mesma
                    // familia SEL-413/MUL-372). Em 21/08 dois pedidos foram marcados
                    // terminais no MINUTO 1 de vida e a etiqueta saiu horas depois —
                    // so o varredor de 10min os manteve vivos. Jovem (<48h) retenta;
                    // cada retentativa tambem passa pelo fallback Bling (MUL-427).
                    if ($order->created_at && $order->created_at->diffInHours(now()) < 48) {
                        return [
                            'ready'            => false,
                            'reason'           => 'Marketplace recusou o documento (pedido novo) — retentando.',
                            'reason_code'      => 'label_unavailable',
                            'retry_in_minutes' => 15,
                        ];
                    }
                    return [
                        'ready'            => false,
                        'reason'           => 'O marketplace nao libera a etiqueta deste pedido.',
                        'reason_code'      => 'label_unavailable',
                        'skip_permanently' => true,
                    ];
                }

                // "not yet ready" e o caso saudavel: documento em geracao, vale retentar.
                if (! str_contains($failMsg, 'not yet ready')) {
                    Log::warning('[Label/Shopee] create_shipping_document recusou', [
                        'order_id' => $order->id,
                        'order_sn' => $orderSn,
                        'error'    => $create['error'],
                        'message'  => $create['message'],
                    ]);
                }
            }

            // Passo 2: verifica status
            $docStatus = ($veioDoEvento || $statusPrevio === 'READY')
                ? 'READY'
                : $shopeeService->getShippingDocumentStatus($account, $orderSn, $packageNumber);

            if ($docStatus === 'FAILED') {
                Log::warning('[Label/Shopee] Documento FAILED', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                ]);
                return [
                    'ready'            => false,
                    'reason'           => 'A Shopee falhou ao gerar o documento de envio. Tente novamente.',
                    'retry_in_minutes' => 15,
                ];
            }

            if ($docStatus !== 'READY') {
                return [
                    'ready'            => false,
                    'reason'           => 'Documento de envio Shopee ainda sendo processado.',
                    'retry_in_minutes' => 5,
                ];
            }

            // Passo 3: baixa ZIP binario
            $zipContent = $shopeeService->downloadShippingDocumentRaw($account, $orderSn, $packageNumber);

            // MUL-354: download falhou — o documento nao existe para o tipo que pedimos
            // (THERMAL_AIR_WAYBILL). Cria e deixa o retry buscar de novo.
            //
            // Vale para QUALQUER origem, nao so para o evento. Medido em 08/08: com
            // trigger 'fallback' o get_shipping_document_status responde READY e o download
            // recusa com should_print_first — READY se refere a existir algum documento, nao
            // ao nosso tipo. Sem chamar o create, esse caminho nunca descobria que o pedido
            // era terminal e repetia "aguardando marketplace" de 5 em 5 minutos para sempre.
            // A resposta do create e a unica fonte que distingue "ainda vem" de "nao vem mais".
            if (! $zipContent) {
                Log::info('[Label/Shopee] Download falhou — criando documento', [
                    'order_id' => $order->id, 'order_sn' => $orderSn,
                ]);
                $recria = $shopeeService->createShippingDocument($account, $orderSn, $packageNumber);

                // MUL-354: a recusa aqui e definitiva do mesmo jeito que na criacao inicial.
                // Sem ler a resposta, pedido com rastreio invalidado ou ja despachado ficaria
                // retentando de 5 em 5 minutos para sempre, e o painel mostraria "aguardando
                // marketplace" quando a Shopee ja disse que nao vai liberar.
                if (! $recria['ok']) {
                    $msgRecria = strtolower((string) ($recria['message'] ?? ''));

                    if ($recria['error'] === 'logistics.tracking_number_invalid') {
                        // MUL-372: mesmo tratamento por idade da criacao (ver acima)
                        if ($order->created_at && $order->created_at->diffInHours(now()) < 48) {
                            return [
                                'ready'            => false,
                                'reason'           => 'Shopee ainda nao alocou o rastreio (pedido novo) — retentando.',
                                'reason_code'      => 'tracking_invalid',
                                'retry_in_minutes' => 5,
                            ];
                        }
                        return [
                            'ready'            => false,
                            'reason'           => 'A Shopee invalidou o codigo de rastreio deste pedido.',
                            'reason_code'      => 'tracking_invalid',
                            'skip_permanently' => true,
                        ];
                    }

                    if (str_contains($msgRecria, 'has been shipped')) {
                        return [
                            'ready'            => false,
                            'reason'           => 'A encomenda ja foi despachada no marketplace.',
                            'reason_code'      => 'already_shipped',
                            'skip_permanently' => true,
                        ];
                    }

                    if ($recria['error'] === 'logistics.package_can_not_print'
                        && ! str_contains($msgRecria, 'not yet ready')) {
                        return [
                            'ready'            => false,
                            'reason'           => 'O marketplace nao libera a etiqueta deste pedido.',
                            'reason_code'      => 'label_unavailable',
                            'skip_permanently' => true,
                        ];
                    }
                }

                return [
                    'ready'            => false,
                    'reason'           => 'Documento solicitado a Shopee. Aguardando ficar pronto.',
                    'retry_in_minutes' => 5,
                ];
            }


            // Passo 4: extrai ZPL do ZIP
            $zpl = $this->extractZplFromZip($zipContent);
            if (! $zpl) {
                // Pode ser PDF diretamente -- tenta salvar como PDF
                $localUrl = $this->saveLabelContent($zipContent, $order, $orderSn, 'pdf');
                if ($localUrl) {
                    return ['ready' => true, 'label_url' => $localUrl];
                }
                return [
                    'ready'  => false,
                    'reason' => 'Nao foi possivel extrair o conteudo ZPL do arquivo da Shopee.',
                ];
            }

            // Passo 5: converte ZPL -> PNG
            $pngContent = $this->convertZplToPng($zpl);
            if (! $pngContent) {
                return [
                    'ready'  => false,
                    'reason' => 'Falha ao converter etiqueta ZPL para PNG.',
                ];
            }

            // Passo 6: salva PNG no storage publico
            $localUrl = $this->saveLabelContent($pngContent, $order, $orderSn, 'png');
            if (! $localUrl) {
                return [
                    'ready'  => false,
                    'reason' => 'Falha ao salvar etiqueta no servidor.',
                ];
            }

            return ['ready' => true, 'label_url' => $localUrl];

        } catch (\Throwable $e) {
            // Pedido removido da Shopee (cancelado/expirado ha muito tempo) — nao retentar
            if (str_contains($e->getMessage(), 'order_not_exists') || str_contains($e->getMessage(), 'logistics.order_not')) {
                Log::warning('[Label/Shopee] Pedido nao existe na Shopee, erro permanente', [
                    'order_id' => $order->id, 'order_sn' => $orderSn,
                ]);
                return ['ready' => false, 'reason' => 'Pedido nao existe na Shopee.', 'skip_permanently' => true];
            }
            Log::error('[Label/Shopee] Excecao ao buscar etiqueta', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'error'    => $e->getMessage(),
            ]);
            return [
                'ready'            => false,
                'reason'           => 'Erro interno ao processar etiqueta Shopee.',
                'retry_in_minutes' => 10,
            ];
        }
    }

    /**
     * Encontra a MarketplaceAccount Shopee vinculada ao pedido.
     */
    protected function findShopeeAccount(Order $order): ?MarketplaceAccount
    {
        // Tenta via marketplace_account_id direto
        if ($order->marketplace_account_id) {
            $account = MarketplaceAccount::find($order->marketplace_account_id);
            if ($account && $account->platform === 'shopee') {
                return $account;
            }
        }

        // Tenta via item do pedido
        $item = $order->items()->with('clientProduct.marketplaceAccount')->first();
        if ($item?->clientProduct?->marketplaceAccount?->platform === 'shopee') {
            return $item->clientProduct->marketplaceAccount;
        }

        // Fallback: busca pela conta Shopee ativa do supplier_id
        return MarketplaceAccount::where('supplier_id', $order->supplier_id)
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->first();
    }

    /**
     * Extrai o conteudo ZPL de um arquivo ZIP em memoria.
     * Procura por arquivos contendo 'zpl' ou 'shipping_label' no nome.
     *
     * @param  string $zipContent  Bytes binarios do ZIP
     * @return string|null  Conteudo ZPL ou null se nao encontrado
     */
    public function extractZplFromZip(string $zipContent): ?string
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'shopee_label_') . '.zip';

        try {
            file_put_contents($tmpZip, $zipContent);

            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                Log::warning('[Label/Shopee] Falha ao abrir ZIP', ['size' => strlen($zipContent)]);
                return null;
            }

            $zpl      = null;
            $fallback = null;
            $lastName = '';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name    = strtolower($zip->getNameIndex($i));
                $content = $zip->getFromIndex($i);
                $lastName = $name;

                // Prioridade: arquivo com "zpl" no nome
                if (str_contains($name, 'zpl') || str_ends_with($name, '.zpl')) {
                    $zpl = $content;
                    break;
                }

                // Fallback: arquivo com "shipping_label" ou "label" no nome
                if (str_contains($name, 'shipping_label') || str_contains($name, 'label')) {
                    $fallback = $content;
                }
            }

            $zip->close();

            $result = $zpl ?? $fallback;

            if ($result) {
                // Aceita ZPL que começa com ^XA (padrao) OU com ~DGR/~DF/^XZ (blocos de dados
                // comprimidos em Z64 que a Shopee usa antes do ^XA principal).
                // Nao validar por magic string -- basta o arquivo estar no ZIP com nome correto.
                Log::info('[Label/Shopee] ZPL extraido do ZIP', [
                    'filename' => $lastName,
                    'length'   => strlen($result),
                    'preview'  => mb_substr(ltrim($result), 0, 30),
                ]);
                return $result;
            }

            Log::warning('[Label/Shopee] Nenhum arquivo ZPL/label encontrado no ZIP');
            return null;

        } finally {
            if (file_exists($tmpZip)) {
                unlink($tmpZip);
            }
        }
    }

    /**
     * Converte conteudo ZPL em PNG usando o servico ZPLPrinter interno (legado).
     * Fallback para Labelary se o servico interno nao responder ou PNG for pequeno demais.
     *
     * @param  string $zpl  Conteudo ZPL
     * @return string|null  Bytes PNG ou null em falha
     */
    public function convertZplToPng(string $zpl): ?string
    {
        // Servico interno (mesmo do legado goolhub) -- 8 dots/mm = 400 DPI
        try {
            $response = Http::timeout(30)->post('http://147.182.160.250:8080/ZPLPrinter', [
                'zpl'  => $zpl,
                'dpmm' => 8,
            ]);

            if ($response->successful() && strlen($response->body()) > 7000) {
                Log::info('[Label/Shopee] ZPL convertido via ZPLPrinter interno', [
                    'png_bytes' => strlen($response->body()),
                ]);
                return $response->body();
            }

            Log::warning('[Label/Shopee] ZPLPrinter interno falhou ou retornou imagem pequena', [
                'status'    => $response->status(),
                'png_bytes' => strlen($response->body()),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Label/Shopee] ZPLPrinter interno indisponivel', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: Labelary (4x6 inches, 8 dots/mm)
        try {
            $response = Http::timeout(30)
                ->withBody($zpl, 'application/x-www-form-urlencoded')
                ->post('http://api.labelary.com/v1/printers/8dpmm/labels/4x6/0/');

            if ($response->successful() && strlen($response->body()) > 0) {
                Log::info('[Label/Shopee] ZPL convertido via Labelary', [
                    'png_bytes' => strlen($response->body()),
                ]);
                return $response->body();
            }

            Log::warning('[Label/Shopee] Labelary falhou', ['status' => $response->status()]);
        } catch (\Throwable $e) {
            Log::warning('[Label/Shopee] Labelary indisponivel', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Salva conteudo binario de etiqueta no storage publico e atualiza o pedido.
     *
     * @param  string $content   Bytes do arquivo
     * @param  Order  $order     Pedido a atualizar
     * @param  string $orderSn   Numero do pedido para nomear o arquivo
     * @param  string $ext       Extensao do arquivo (png, pdf)
     * @return string|null  URL publica /storage/... ou null em falha
     */
    protected function saveLabelContent(string $content, Order $order, string $orderSn, string $ext): ?string
    {
        if (empty($content)) {
            return null;
        }

        $safe     = preg_replace('/[^a-zA-Z0-9_-]/', '', $orderSn);
        $filename = "labels/shopee-{$safe}-{$order->id}.{$ext}";
        $disk     = Storage::disk($this->labelsDisk()); // FOR-101

        $disk->put($filename, $content);

        $url = '/storage/' . $filename;
        $order->updateQuietly(['label_url' => $url]);

        Log::info('[Label/Shopee] Etiqueta salva', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
            'filename' => $filename,
            'ext'      => $ext,
        ]);

        return $url;
    }



    /**
     * MUL-463: etiqueta de pedido importado da API do Bling (sem legado).
     *
     * O proprio Bling recebe a etiqueta do marketplace (ex.: Amazon DBA) e a expoe em
     * logisticas/etiquetas por id do pedido de venda. Conteudo pode ser PDF ou ZIP com
     * ZPL termico (MUL-461) — sniffing por magic bytes. "Nao possuem logistica" e
     * transitorio: o Bling gera quando o marketplace emite; retry de 30 min.
     */
    protected function checkBlingApiLabel(Order $order): array
    {
        $aguardando = [
            'ready'            => false,
            'reason'           => 'Etiqueta ainda nao disponivel no Bling do seller.',
            'reason_code'      => 'awaiting_marketplace',
            'retry_in_minutes' => 30,
        ];

        // MUL-463c: pedido ja despachado/coletado nao tem mais etiqueta no Bling —
        // apos a coleta o 404 e permanente (medido em DBA 21/08). Nao insistir.
        // Para os demais, o fetch normal segue: o Bling LIBERA a etiqueta DBA na
        // janela da Amazon ("Aguardando" -> "disponivel") e o retry pega na hora.
        if (in_array($order->canonical_status, ['shipped', 'delivered', 'completed'], true)) {
            return [
                'ready'            => false,
                'reason'           => 'Pedido ja despachado — etiqueta nao mais disponivel no Bling.',
                'reason_code'      => 'already_shipped',
                'skip_permanently' => true,
            ];
        }

        $acc = null;
        if ($order->marketplace_account_id) {
            $acc = MarketplaceAccount::where('id', $order->marketplace_account_id)
                ->where('platform', 'bling')->first();
        }
        if (! $acc && $order->client_id) {
            $acc = MarketplaceAccount::where('client_id', $order->client_id)
                ->where('platform', 'bling')->where('status', 'active')
                ->whereNotNull('bling_access_token')->first();
        }
        if (! $acc) {
            return ['ready' => false, 'reason' => 'Conta Bling do pedido nao encontrada.', 'reason_code' => 'missing_marketplace_account', 'retry_in_minutes' => 120];
        }

        try {
            $token = app(\App\Services\Integrations\Erps\Bling\BlingAuthService::class)->getValidToken($acc);
            if (! $token) {
                return ['ready' => false, 'reason' => 'Token Bling invalido — reconectar.', 'reason_code' => 'token_error', 'retry_in_minutes' => 120];
            }

            // MUL-464: aproveita o ciclo pra preencher numero/serie/chave da NF (1x)
            if (empty($order->invoice_number)) {
                $this->preencherNfDoBling($order, $token);
            }

            usleep(400000); // rate limit Bling 3 req/s
            $r = \Illuminate\Support\Facades\Http::withToken($token)->timeout(20)
                ->get(config('bling.api_base', 'https://api.bling.com.br/Api/v3') . '/logisticas/etiquetas?idsVendas[]=' . $order->bling_order_id . '&formato=PDF');
            $link = $r->json()['data'][0]['link'] ?? null;
            if (! $link) {
                return $aguardando; // inclui "nao possuem logistica" (transitorio)
            }

            $bin = \Illuminate\Support\Facades\Http::timeout(30)->get($link);
            if (! $bin->successful() || strlen($bin->body()) < 1000) {
                return $aguardando;
            }
            $corpo = $bin->body();
            if (str_starts_with($corpo, '%PDF')) {
                $ext = 'pdf';
            } elseif (str_starts_with($corpo, 'PK')) {
                $etq = $this->extrairEtiquetaDeZip($corpo); // MUL-463d: PDF (Amazon) ou ZPL (Shopee)
                if (! $etq) {
                    return $aguardando;
                }
                $ext = $etq['ext'];
                $corpo = $etq['conteudo'];
            } else {
                return $aguardando;
            }

            $nome = sprintf('bling-%d-%s.%s', $order->id, substr(md5(uniqid('', true)), 0, 8), $ext);
            Storage::disk((string) config('filesystems.labels_disk', 'public'))
                ->put('labels/' . $nome, $corpo);

            $url = '/storage/labels/' . $nome;

            // MUL-465: PDF (DBA/Amazon) e folha A4 com a etiqueta num canto + paginas
            // de romaneio — cru no painel fica ilegivel. Recorta a etiqueta (pagina 1
            // + trim) e serve o PNG limpo; se o render falhar, fica o PDF.
            if ($ext === 'pdf') {
                try {
                    $png = app(\App\Services\Labels\LabelPdfRenderer::class)->trimmedPageToUrl('labels/' . $nome, 1);
                    if ($png) {
                        $url = $png;
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[MUL-465] recorte do PDF de etiqueta falhou', [
                        'order_id' => $order->id, 'erro' => $e->getMessage(),
                    ]);
                }
            }

            // MUL-463e: como nos demais ramos, quem persiste o label_url e o proprio check.
            $order->updateQuietly(['label_url' => $url]);

            return ['ready' => true, 'label_url' => $url];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MUL-463] checkBlingApiLabel falhou', [
                'order_id' => $order->id, 'erro' => $e->getMessage(),
            ]);
            return $aguardando;
        }
    }

    /**
     * MUL-463d: extrai a etiqueta de um ZIP — PDF (Amazon DBA) ou ZPL/.txt (Shopee),
     * ZPL convertido em PNG pelo conversor interno. Null = nada utilizavel.
     * @return array{ext: string, conteudo: string}|null
     */
    public function extrairEtiquetaDeZip(string $zipContent): ?array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'etq');
        file_put_contents($tmp, $zipContent);
        $za = new \ZipArchive();
        if ($za->open($tmp) !== true) {
            @unlink($tmp);
            return null;
        }
        $pdf = null;
        $zpl = null;
        for ($i = 0; $i < $za->numFiles; $i++) {
            $nome = strtolower((string) $za->getNameIndex($i));
            $conteudo = $za->getFromIndex($i);
            if ($conteudo === false || $conteudo === '') {
                continue;
            }
            if (str_ends_with($nome, '.pdf') || str_starts_with($conteudo, '%PDF')) {
                $pdf = $conteudo;
                break;
            }
            if (str_ends_with($nome, '.zpl') || str_ends_with($nome, '.txt') || str_starts_with(ltrim($conteudo), '^XA')) {
                $zpl = $conteudo;
            }
        }
        $za->close();
        @unlink($tmp);

        if ($pdf !== null) {
            return ['ext' => 'pdf', 'conteudo' => $pdf];
        }
        if ($zpl !== null) {
            $png = $this->convertZplToPng($zpl);
            if ($png) {
                return ['ext' => 'png', 'conteudo' => $png];
            }
        }

        return null;
    }

    /**
     * MUL-464: preenche invoice_* (numero/serie/chave/emissao) a partir da NF vinculada
     * ao pedido de venda no Bling. Roda 1x por pedido (guard: invoice_number vazio);
     * pedido ainda sem NF no Bling e ignorado silenciosamente (proximo ciclo tenta).
     */
    protected function preencherNfDoBling(Order $order, string $token): void
    {
        if (! $order->bling_order_id) {
            return;
        }
        try {
            usleep(400000); // rate limit Bling 3 req/s
            $det = \Illuminate\Support\Facades\Http::withToken($token)->timeout(20)
                ->get(config('bling.api_base', 'https://api.bling.com.br/Api/v3') . '/pedidos/vendas/' . $order->bling_order_id)
                ->json()['data'] ?? [];
            // MUL-464b: mesmo payload tras rastreio e servico logistico — preenche o
            // que faltar (nunca sobrescreve valor existente).
            $vol = $det['transporte']['volumes'][0] ?? [];
            $extras = array_filter([
                'tracking_number' => empty($order->tracking_number) ? ($vol['codigoRastreamento'] ?? null) : null,
                'carrier_name'    => empty($order->carrier_name) ? ($vol['servico'] ?? null) : null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($extras !== []) {
                $order->updateQuietly($extras);
            }

            $nfeId = $det['notaFiscal']['id'] ?? null;
            if (! $nfeId) {
                return;
            }
            usleep(400000);
            $nf = \Illuminate\Support\Facades\Http::withToken($token)->timeout(20)
                ->get(config('bling.api_base', 'https://api.bling.com.br/Api/v3') . '/nfe/' . $nfeId)
                ->json()['data'] ?? null;
            if (! is_array($nf) || empty($nf['numero'])) {
                return;
            }
            $order->updateQuietly(array_filter([
                'invoice_number'     => $nf['numero'] ?? null,
                'invoice_series'     => isset($nf['serie']) ? (string) $nf['serie'] : null,
                'invoice_access_key' => $nf['chaveAcesso'] ?? null,
                'invoice_issued_at'  => $nf['dataEmissao'] ?? null,
                'invoice_status'     => in_array((int) ($nf['situacao'] ?? 0), [5, 6, 7], true) ? 'authorized' : null,
            ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MUL-464] preencherNfDoBling falhou', [
                'order_id' => $order->id, 'erro' => $e->getMessage(),
            ]);
        }
    }
}
