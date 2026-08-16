<?php

namespace App\Jobs;

use App\Models\ClientProduct;
use App\Services\MercadoLivreService;
use App\Support\SkuDoAnuncio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PublishClientProductToMLJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * SEL-403: o ml:recover-tokens reenfileira TODO produto draft a cada ciclo.
     * Sem unicidade a fila so crescia: 2.842 jobs na frente de ~264 produtos
     * distintos, ou seja 11 copias de cada um. Agora um produto so pode ter um
     * job na fila por vez.
     */
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'publish-ml-' . $this->clientProductId;
    }

    public int $tries   = 5;
    public int $timeout = 90;

    // FOR-062: backoff exponencial com jitter +-20% para 429
    public function backoff(): array
    {
        return array_map(fn(int $s) => (int) ($s * (0.8 + lcg_value() * 0.4)), [30, 90, 300, 900, 1800]);
    }

    public function __construct(
        public readonly int $clientProductId
    ) {}

    public function handle(MercadoLivreService $mlService): void
    {
        $cp = ClientProduct::with(['product', 'marketplaceAccount'])->find($this->clientProductId);

        if (!$cp) {
            Log::warning("[PublishML] ClientProduct #{$this->clientProductId} não encontrado.");
            return;
        }

        $account = $cp->marketplaceAccount;
        if (!$account || !$account->ml_access_token) {
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => 'Conta do Mercado Livre não conectada ou sem token.',
                'last_sync_at'    => now(),
            ]);
            return;
        }

        try {
            $token = $mlService->getValidToken($account);
        } catch (\Throwable $e) {
            $cp->update([
                'sync_status'     => 'error',
                'last_sync_error' => 'Falha ao obter token: ' . $e->getMessage(),
                'last_sync_at'    => now(),
            ]);
            return;
        }

        // FOR-063: guard -- custom_price zerado/nulo causa "preco invalido" no ML (6.067 falhas/8d)
        $price = $cp->custom_price;
        if (!$price || (float) $price <= 0) {
            $reason = "Preco zerado ou invalido: custom_price={$price} — publicacao pulada";
            Log::warning("[PublishML] FOR-063 guard_price_invalid", [
                'client_product_id'   => $cp->id,
                'external_listing_id' => $cp->external_listing_id,
                'custom_price'        => $price,
            ]);
            $cp->update([
                'sync_status'     => 'invalid_price',
                'last_sync_error' => 'Preco zerado ou invalido — defina um preco positivo para publicar.',
                'last_sync_at'    => now(),
            ]);
            self::logSync($cp, 'guard_price_invalid', 'skipped', $reason);
            return; // nao retenta, nao lanca excecao
        }

        // FOR-064: guard -- conta ML suspensa por user_not_active nao tenta HTTP (520 falhas/8d)
        if ($account->status === "suspended") {
            $reason = "Conta ML suspensa (user_not_active) — publicacao bloqueada ate reativacao manual";
            Log::warning("[PublishML] FOR-064 guard_account_suspended", [
                "client_product_id"    => $cp->id,
                "marketplace_account_id" => $account->id,
                "account_name"         => $account->account_name,
            ]);
            $cp->update([
                "sync_status"     => "error",
                "last_sync_error" => "Conta ML suspensa — verifique pendencias no Mercado Livre.",
                "last_sync_at"    => now(),
            ]);
            self::logSync($cp, "guard_account_suspended", "skipped", $reason);
            return; // nao retenta, nao faz HTTP call
        }

                // FOR-062: throttle 50 req/min por conta ML (limite ML ~60/min)

        $throttleKey = 'ml:api:account:' . $account->id;
        $released    = false;

        Redis::throttle($throttleKey)->allow(50)->every(60)->then(
            function () use ($cp, $token) {
                if ($cp->external_listing_id) {
                    $this->updateItem($cp, $token);
                } else {
                    $this->createItem($cp, $token);
                }
            },
            function () use ($cp, $throttleKey, &$released) {
                // Limite atingido: libera o job para reprocessar em ~70s
                Log::info('[PublishML] FOR-062 throttled -- job released', [
                    'client_product_id' => $cp->id,
                    'throttle_key'      => $throttleKey,
                ]);
                $this->release(70);
                $released = true;
            }
        );
    }

    // -------------------------------------------------------------------------
    // Criar novo anúncio — POST /items
    // -------------------------------------------------------------------------

    protected function createItem(ClientProduct $cp, string $token): void
    {
        $product = $cp->product;

        // Título: usa custom_title ou nome do produto
        $resolvedTitle = $cp->custom_title;
        $resolvedTitle = mb_substr($resolvedTitle ?? $product?->name ?? 'Produto', 0, 60);

        // FOR-080: anuncios de catalogo ML (family_name preenchido) nao permitem edicao de titulo
        if (!empty($mlFamilyName)) {
            Log::warning("[PublishML] FOR-080 titulo nao editavel — anuncio pertence a family {$mlFamilyName}", [
                'client_product_id'   => $cp->id,
                'external_listing_id' => $itemId,
                'family_name'         => $mlFamilyName,
            ]);
        }
        $payload = [
            'title'              => $resolvedTitle,
            'price'              => (float) $cp->custom_price,
            'currency_id'        => 'BRL',
            'available_quantity' => $product ? $product->publishedStock($cp->client_id) : 1, // MUL-234: fake por seller
            'buying_mode'        => 'buy_it_now',
            'condition'          => $cp->custom_condition ?? 'new',
            'listing_type_id'    => $cp->listing_type_id ?? 'gold_special',
        ];

        // Categoria — obrigatória para criação
        if ($cp->external_category_id) {
            $payload['category_id'] = $cp->external_category_id;
        } elseif ($product?->category) {
            // Tenta usar categoria do produto original se mapeada
            $payload['category_id'] = $product->category->external_id ?? null;
        }

        // Busca automatica de categoria ML se ainda sem categoria
        if (empty($payload['category_id'])) {
            $catResp = Http::withToken($token)
                ->get("https://api.mercadolibre.com/sites/MLB/domain_discovery/search?q=" . urlencode($resolvedTitle) . "&limit=1");
            if ($catResp->successful()) {
                $catId = $catResp->json()[0]['category_id'] ?? null;
                if ($catId) {
                    $payload['category_id'] = $catId;
                    $cp->update(['external_category_id' => $catId]);
                }
            }
        }

        // Descrição — na criação, vai como plain_text dentro do payload
        if ($cp->custom_description) {
            $payload['description'] = [
                'plain_text' => mb_substr($cp->custom_description, 0, 50000),
            ];
        }

        // Imagens
        $payload['pictures'] = $this->buildPictures($cp);

        // Atributos base (BRAND, MODEL, GTIN)
        $attributes = $this->buildAttributes($cp);

        // Busca atributos obrigatorios da categoria ML e preenche com fallback
        $categoryId = $payload['category_id'] ?? null;
        if ($categoryId) {
            $catAttrsResp = Http::get("https://api.mercadolibre.com/categories/{$categoryId}/attributes");
            if ($catAttrsResp->successful()) {
                $catAttrs = $catAttrsResp->json();
                $existingIds = array_column($attributes, 'id');
                foreach ($catAttrs as $attr) {
                    $isRequired = $attr['tags']['required'] ?? false;
                    if (!$isRequired) {
                        continue;
                    }
                    $attrId = $attr['id'] ?? null;
                    if (!$attrId) {
                        continue;
                    }
                    // BRAND e MODEL ja sao tratados em buildAttributes()
                    if (in_array($attrId, ['BRAND', 'MODEL'])) {
                        continue;
                    }
                    // Se ja existe no payload, nao duplicar
                    if (in_array($attrId, $existingIds)) {
                        continue;
                    }
                    // SEL-322: atributos number_unit (LENGTH, VOLUME_CAPACITY etc) nao tem
                    // lista de values fixos -- esperam numero+unidade (ex: 1.5 m).
                    // Passar "Outros" causa item.attributes.normalizable.invalid (400).
                    // Fix: pular esses atributos (ausencia e menos pior que valor invalido).
                    $valueType = $attr['value_type'] ?? 'string';
                    $values = $attr['values'] ?? [];
                    if ($valueType === 'number_unit' && empty($values)) {
                        Log::info("[PublishML] SEL-322 skip number_unit sem valores fixos: {$attrId} (cat {$categoryId})");
                        continue;
                    }
                    $valueName = !empty($values) ? ($values[0]['name'] ?? 'Outros') : 'Outros';
                    $attributes[] = ['id' => $attrId, 'value_name' => $valueName];
                    Log::info("[PublishML] Atributo obrigatorio preenchido: {$attrId} = {$valueName} (cat {$categoryId})");
                }
            } else {
                Log::warning("[PublishML] Falha ao buscar atributos da categoria {$categoryId}: " . $catAttrsResp->status());
            }
        }

        if (!empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        // Frete/dimensões
        $shipping = $this->buildShipping($cp);
        if ($shipping) {
            $payload['shipping'] = $shipping;
        }

        // FOR-073: loop retry com auto-fix ML (max 3 tentativas)
        $maxAttempts  = 3;
        $responseData = [];
        $fixesApplied = [];
        $success      = false;
        $response     = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::withToken($token)
                ->post('https://api.mercadolibre.com/items', $payload);

            $responseData = $response->json() ?? [];

            if (!$response->failed()) {
                $success = true;
                break;
            }

            $causes = $responseData['cause'] ?? [];
            Log::info('[PublishML] FOR-073 tentativa ' . $attempt . ' falhou', [
                'client_product_id' => $cp->id,
                'http_status'       => $response->status(),
                'cause_codes'       => array_column($causes, 'code'),
                'attempt'           => $attempt,
            ]);

            // 429 e 403 nao sao corrigiveis por auto-fix — delega ao handleError
            if ($response->status() === 429 || $response->status() === 403) {
                $this->handleError($cp, $response, 'criar');
                return;
            }

            if ($attempt >= $maxAttempts) break;

            $fixed = $this->applyMLAutoFixes($payload, $causes, $cp->id, $fixesApplied);
            if (!$fixed) {
                Log::info('[PublishML] FOR-073 nenhum auto-fix aplicavel, abortando loop', [
                    'client_product_id' => $cp->id,
                ]);
                break;
            }
        }

        if (!$success) {
            $fixDesc = !empty($fixesApplied) ? ' [fixes tentados: ' . implode(', ', $fixesApplied) . ']' : '';
            Log::error('[PublishML] FOR-073 falhou apos ' . $maxAttempts . ' tentativas' . $fixDesc, [
                'client_product_id' => $cp->id,
                'cause'             => $responseData['cause'] ?? [],
            ]);
            $this->handleError($cp, $response, 'criar');
            return;
        }

        $actionLabel = !empty($fixesApplied) ? 'criar_com_autofix' : 'criar';
        $fixNote     = !empty($fixesApplied)
            ? 'auto-fix aplicado: ' . implode(', ', $fixesApplied)
            : "Anuncio criado: {$responseData['id']}";

        $cp->update([
            'external_listing_url' => $responseData['permalink'] ?? null,
            'external_listing_id'  => $responseData['id'],
            // FOR-111: ml_external_item_id estava vazio nos 25.713 anuncios em 13/08/2026 —
            // e o campo que deveria correlacionar anuncio e pedido, entao toda auditoria
            // acabava dependendo de casar TITULO, que e chute (FOR-110).
            "ml_external_item_id"  => $responseData["id"],
            'sync_status'          => 'synced',
            'last_sync_error'      => null,
            'last_sync_at'         => now(),
            'sync_attempt_count'   => 0,
        ]);

        $attemptsStr = (string) $attempt;
        $fixesStr    = implode(',', $fixesApplied ?: ['none']);
        Log::info("[PublishML] Criado item ML {$responseData['id']} para ClientProduct #{$cp->id} (attempt={$attemptsStr}, fixes={$fixesStr})");
        self::logSync($cp, $actionLabel, 'success', $fixNote);
    }

    // -------------------------------------------------------------------------
    // Atualizar anúncio existente — PUT /items/{id}
    // -------------------------------------------------------------------------

    protected function updateItem(ClientProduct $cp, string $token): void
    {
        $product  = $cp->product;
        $itemId   = $cp->external_listing_id;

        // FOR-061: guard — verificar status real do anuncio no ML antes de tentar PUT.
        // Status closed, inactive e under_review rejeitam todos os campos (400).
        // Status paused: skip conservador.
        $mlStatusResp = Http::withToken($token)
            ->timeout(10)
            ->get("https://api.mercadolibre.com/items/{$itemId}", [
                'attributes' => 'id,status,family_name', // FOR-080: detectar anuncio de catalogo
            ]);

        $mlStatus = null;
        $mlFamilyName = null; // FOR-080: nome da familia ML (catalogo)
        if ($mlStatusResp->successful()) {
            $mlStatus = $mlStatusResp->json('status');
            $mlFamilyName = $mlStatusResp->json('family_name');
            // FOR-088: persistir ml_family_name para o frontend bloquear edicao do titulo
            if ($mlFamilyName !== null && $cp->ml_family_name !== $mlFamilyName) {
                $cp->update(['ml_family_name' => $mlFamilyName]);
            }
        } else {
            Log::warning("[PublishML] FOR-061 guard: falha ao consultar status do item {$itemId}", [
                'http_status' => $mlStatusResp->status(),
            ]);
        }

        // Statuses que impedem edicao via PUT — skip conservador inclui paused
        $nonEditableStatuses = ['closed', 'inactive', 'under_review', 'paused'];

        if ($mlStatus !== null && in_array($mlStatus, $nonEditableStatuses)) {
            $reason = "anuncio nao editavel: status={$mlStatus}";
            Log::info("[PublishML] FOR-061 skip — {$reason}", [
                'client_product_id'   => $cp->id,
                'external_listing_id' => $itemId,
                'ml_status'           => $mlStatus,
            ]);
            $localStatusMap = [
                'closed'       => 'inactive',
                'inactive'     => 'inactive',
                'under_review' => 'paused',
                'paused'       => 'paused',
            ];
            $newLocalStatus = $localStatusMap[$mlStatus] ?? 'paused';
            $cp->update([
                'listing_status'  => $newLocalStatus,
                'sync_status'     => 'paused',
                'last_sync_error' => $reason,
                'last_sync_at'    => now(),
            ]);
            self::logSync($cp, 'guard_status_not_editable', 'skipped', $reason);
            return;
        }

        // Campos atualizáveis via PUT /items/{id}
        // NÃO pode alterar: buying_mode, category_id, currency_id, condition, listing_type_id, shipping.dimensions
        // Título: usa custom_title ou nome do produto
        $resolvedTitle = $cp->custom_title;
        $resolvedTitle = mb_substr($resolvedTitle ?? $product?->name ?? 'Produto', 0, 60);

        $payload = [
            'title'              => $resolvedTitle,
            'price'              => (float) $cp->custom_price,
            'available_quantity' => $product ? $product->publishedStock($cp->client_id) : 1, // MUL-234: fake por seller
        ];

        // FOR-080: remover title do payload se anuncio e de catalogo (family_name presente)
        if (!empty($mlFamilyName)) {
            unset($payload['title']);
        }

        // Imagens
        $pictures = $this->buildPictures($cp);
        if (!empty($pictures)) {
            $payload['pictures'] = $pictures;
        }

        // Atributos
        $attributes = $this->buildAttributes($cp);
        if (!empty($attributes)) {
            $payload['attributes'] = $attributes;
        }

        // NÃO inclui shipping.dimensions no update — ML não permite alterar

        // PUT /items/{id}
        $response = Http::withToken($token)
            ->put("https://api.mercadolibre.com/items/{$itemId}", $payload);

        if ($response->failed()) {
            $this->handleError($cp, $response, 'atualizar');
            return;
        }

        // Descrição é atualizada SEPARADAMENTE via PUT /items/{id}/description
        if ($cp->custom_description) {
            $descResponse = Http::withToken($token)
                ->put("https://api.mercadolibre.com/items/{$itemId}/description?api_version=2", [
                    'plain_text' => mb_substr($cp->custom_description, 0, 50000),
                ]);

            if ($descResponse->failed()) {
                Log::warning("[PublishML] Item atualizado mas descrição falhou para {$itemId}", [
                    'status' => $descResponse->status(),
                    'body'   => $descResponse->body(),
                ]);
            }
        }

        $cp->update([
            'sync_status'     => 'synced',
            'last_sync_error' => null,
            'last_sync_at'    => now(),
            'sync_attempt_count' => 0,
            // FOR-111: preenche o vinculo que faltava tambem no caminho de ATUALIZACAO,
            // para o anuncio antigo se curar ao ser republicado, sem backfill em massa.
            'ml_external_item_id' => $itemId,
        ]);

        Log::info("[PublishML] Atualizado item ML {$itemId} para ClientProduct #{$cp->id}");
        self::logSync($cp, 'atualizar', 'success', "Anúncio atualizado: {$itemId}");
    }

    // -------------------------------------------------------------------------
    // Pausar anúncio — PUT /items/{id} { "status": "paused" }
    // -------------------------------------------------------------------------

    public static function pauseItem(ClientProduct $cp, string $token): bool
    {
        $response = Http::withToken($token)
            ->put("https://api.mercadolibre.com/items/{$cp->external_listing_id}", [
                'status' => 'paused',
            ]);

        if ($response->successful()) {
            $cp->update(['sync_status' => 'paused', 'last_sync_at' => now()]);
            return true;
        }

        Log::error("[PublishML] Falha ao pausar item {$cp->external_listing_id}", [
            'body' => $response->body(),
        ]);
        return false;
    }

    // -------------------------------------------------------------------------
    // Reativar anúncio — PUT /items/{id} { "status": "active" }
    // -------------------------------------------------------------------------

    public static function activateItem(ClientProduct $cp, string $token): bool
    {
        $response = Http::withToken($token)
            ->put("https://api.mercadolibre.com/items/{$cp->external_listing_id}", [
                'status' => 'active',
            ]);

        if ($response->successful()) {
            $cp->update(['sync_status' => 'synced', 'last_sync_at' => now()]);
            return true;
        }

        Log::error("[PublishML] Falha ao reativar item {$cp->external_listing_id}", [
            'body' => $response->body(),
        ]);
        return false;
    }

    // -------------------------------------------------------------------------
    // Encerrar anúncio — PUT /items/{id} { "status": "closed" }
    // -------------------------------------------------------------------------

    public static function closeItem(ClientProduct $cp, string $token): bool
    {
        $response = Http::withToken($token)
            ->put("https://api.mercadolibre.com/items/{$cp->external_listing_id}", [
                'status' => 'closed',
            ]);

        if ($response->successful()) {
            $cp->update(['sync_status' => 'paused', 'last_sync_at' => now()]);
            return true;
        }

        Log::error("[PublishML] Falha ao encerrar item {$cp->external_listing_id}", [
            'body' => $response->body(),
        ]);
        return false;
    }

    // -------------------------------------------------------------------------
    // Excluir permanentemente — PUT /items/{id} { "deleted": "true" }
    // ATENÇÃO: Primeiro precisa estar paused ou closed. Não tem volta.
    // -------------------------------------------------------------------------

    public static function deleteItem(ClientProduct $cp, string $token): bool
    {
        // Primeiro pausa se estiver ativo
        if (!in_array($cp->sync_status, ['paused', 'closed'])) {
            self::closeItem($cp, $token);
            $cp->refresh();
        }

        $response = Http::withToken($token)
            ->put("https://api.mercadolibre.com/items/{$cp->external_listing_id}", [
                'deleted' => 'true',
            ]);

        if ($response->successful()) {
            $cp->update([
                'sync_status'         => 'draft',
                'external_listing_id' => null,
                'last_sync_at'        => now(),
                'last_sync_error'     => null,
            ]);
            return true;
        }

        Log::error("[PublishML] Falha ao excluir item {$cp->external_listing_id}", [
            'body' => $response->body(),
        ]);
        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function buildPictures(ClientProduct $cp): array
    {
        $images = [];
        $baseUrl = rtrim(config('app.url', 'https://api.hubai.io'), '/');

        $urls = collect();

        if (!empty($cp->custom_images) && is_array($cp->custom_images)) {
            $urls = collect($cp->custom_images);
        } elseif ($cp->product) {
            $urls = $cp->product->media()->pluck('url')->take(10);
        }

        foreach ($urls as $url) {
            // Converte caminhos relativos em URLs absolutas
            if ($url && !str_starts_with($url, 'http')) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }
            if ($url) {
                $images[] = ['source' => $url];
            }
        }

        return $images;
    }

    protected function buildAttributes(ClientProduct $cp): array
    {
        $attributes = [];

        // Atributos extras definidos pelo usuário (campo custom_attributes do ClientProduct)
        // Formato esperado: [ {"id": "COLOR", "value_name": "Preto"}, ... ]
        $customAttrs = $cp->custom_attributes ?? [];
        if (is_array($customAttrs) && !empty($customAttrs)) {
            // Indexa por ID para facilitar o merge
            foreach ($customAttrs as $attr) {
                if (isset($attr["id"])) {
                    $attributes[$attr["id"]] = $attr;
                }
            }
        }

        // BRAND — usa custom ou fallback "Genérico" (não sobrescreve se já veio em custom_attributes)
        if (!isset($attributes["BRAND"])) {
            $attributes["BRAND"] = ["id" => "BRAND", "value_name" => $cp->custom_brand ?: "Genérico"];
        }

        // MODEL — usa custom ou primeiros 50 chars do título
        if (!isset($attributes["MODEL"])) {
            $modelName = $cp->custom_model ?: mb_substr($cp->custom_title ?: "Produto", 0, 50);
            $attributes["MODEL"] = ["id" => "MODEL", "value_name" => $modelName];
        }

        if ($cp->custom_gtin && !isset($attributes["GTIN"])) {
            $attributes["GTIN"] = ["id" => "GTIN", "value_name" => $cp->custom_gtin];
        }

        // FOR-111: SELLER_SKU. Ate 13/08/2026 nunca foi enviado — nem na criacao nem na
        // atualizacao —, entao o anuncio nascia sem SKU e o pedido chegava identificado pelo
        // ID do anuncio (MLB123). Sem SKU nao ha vinculo, sem vinculo nao ha custo: 401 de
        // 421 itens assim ficaram sem custo (FOR-110). Este metodo roda nos dois caminhos.
        $skuDoAnuncio = $this->skuDoAnuncio($cp);
        if ($skuDoAnuncio !== "") {
            $attributes["SELLER_SKU"] = ["id" => "SELLER_SKU", "value_name" => $skuDoAnuncio];
        }

        // Retorna como lista (valores do array indexado)
        return array_values($attributes);
    }
    /**
     * FOR-111: o SKU que vai para o marketplace, e que fica gravado no anuncio.
     *
     * Preserva SKU real ja existente — anuncio no ar com SKU funcionando esta mapeado no
     * Bling e na planilha do seller, e trocar quebra o controle dele (FOR-106, NAO-TOCAR).
     * So gera quando o que existe e placeholder (`ml-`, `shopee-`, o proprio MLB, ou vazio).
     *
     * Persiste antes de enviar de proposito: se gravassemos so no ML, o banco e o marketplace
     * discordariam do SKU do mesmo anuncio, e o pedido de volta nao casaria com nada.
     */
    protected function skuDoAnuncio(ClientProduct $cp): string
    {
        $sku = SkuDoAnuncio::paraAnuncio($cp);

        if ($sku !== "" && $sku !== $cp->custom_sku) {
            $cp->forceFill(["custom_sku" => $sku])->saveQuietly();
            Log::info("[FOR-111] SKU do anuncio gerado", [
                "client_product_id" => $cp->id,
                "sku"               => $sku,
                "anterior"          => $cp->getOriginal("custom_sku"),
            ]);
        }

        return $sku;
    }

    protected function buildShipping(ClientProduct $cp): ?array
    {
        if (!$cp->custom_weight_kg && !$cp->custom_height_cm && !$cp->custom_width_cm && !$cp->custom_length_cm) {
            return null;
        }

        return [
            'mode'       => 'me2',
            'dimensions' => implode('x', [
                (int) ($cp->custom_height_cm ?? 0),
                (int) ($cp->custom_width_cm ?? 0),
                (int) ($cp->custom_length_cm ?? 0),
            ]) . ',' . (int) (($cp->custom_weight_kg ?? 0) * 1000),
        ];
    }

    protected function handleError(ClientProduct $cp, $response, string $action): void
    {
        $errorBody = $response->json() ?? [];
        $rawMsg    = $errorBody['message'] ?? $errorBody['error'] ?? $response->body() ?? 'Erro desconhecido';

        // Captura causes do ML
        $causeCodes = [];
        if (!empty($errorBody['cause'])) {
            if (is_array($errorBody['cause'])) {
                $causeCodes = collect($errorBody['cause'])
                    ->map(fn($c) => is_array($c) ? ($c['code'] ?? $c['message'] ?? $c['cause_id'] ?? '') : $c)
                    ->filter()
                    ->values()
                    ->toArray();
            } else {
                $causeCodes = [$errorBody['cause']];
            }
        }

        // Extrai atributos faltantes do cause item.attributes.missing_required
        $missingAttrs = [];
        if (!empty($errorBody["cause"]) && is_array($errorBody["cause"])) { // FOR-076: guard is_array (causa pode ser string)
            foreach ($errorBody["cause"] as $cause) {
                if (is_array($cause) && ($cause["code"] ?? "") === "item.attributes.missing_required") {
                    // Extrai [ATTR1, ATTR2] da mensagem "The attributes [X, Y] are required..."
                    if (preg_match("/\[([A-Z0-9_,\s]+)\]/", $cause["message"] ?? "", $m)) {
                        foreach (explode(",", $m[1]) as $a) {
                            $missingAttrs[] = trim($a);
                        }
                    }
                }
            }
        }

        // FOR-064: 403 user_not_active -> marca conta suspended + fail sem retry
        if ($response->status() === 403) {
            $mlCode    = $errorBody["code"] ?? "";
            $mlMessage = strtolower($errorBody["message"] ?? "");
            if ($mlCode === "forbidden" && str_contains($mlMessage, "user is not active")) {
                $account = $cp->marketplaceAccount;
                if ($account) {
                    $account->update([
                        "status"             => "suspended",
                        "sync_blocked_at"    => now(),
                        "last_error_message" => "ML 403 user is not active — conta suspensa/bloqueada pelo ML",
                    ]);
                    Log::error("[PublishML] FOR-064 conta ML suspensa detectada", [
                        "marketplace_account_id" => $account->id,
                        "account_name"           => $account->account_name,
                        "client_product_id"      => $cp->id,
                    ]);
                }
                $reason = "ML 403: user is not active — conta suspensa. Verifique pendencias no painel ML.";
                $cp->update([
                    "sync_status"     => "error",
                    "last_sync_error" => mb_substr($reason, 0, 500),
                    "last_sync_at"    => now(),
                ]);
                self::logSync($cp, "guard_account_suspended", "error", $reason, $response->body());
                $this->fail(new \RuntimeException($reason));
                return;
            }
        }

                // FOR-062: 429 -> release com Retry-After (nao marca error, reagenda job)
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 0);
            $baseDelay  = [30, 90, 300, 900, 1800][$this->attempts() - 1] ?? 90;
            $delay      = $retryAfter > 0 ? $retryAfter : $baseDelay;
            $jitter     = (int) ($delay * (0.8 + lcg_value() * 0.4));
            Log::warning('[PublishML] FOR-062 429 -- releasing job', [
                'client_product_id' => $cp->id,
                'retry_after'       => $retryAfter,
                'delay_aplicado'    => $jitter,
                'attempt'           => $this->attempts(),
            ]);
            $cp->update([
                'sync_status'     => 'pending',
                'last_sync_error' => '[throttle] 429 -- job reagendado',
                'last_sync_at'    => now(),
            ]);
            $this->release($jitter);
            return;
        }

        // Traduz o erro para mensagem amigável com instrução de correção
        if (!empty($missingAttrs)) {
            $attrList = implode(", ", array_unique($missingAttrs));
                   $firstAttr = $missingAttrs[0] ?? "ATTR";
            $translated = "Atributos obrigatórios para esta categoria: {$attrList}. Adicione via campo custom_attributes: [{\"id\": \"{$firstAttr}\", \"value_name\": \"...\"}].";
        } else {
            $translated = self::translateError($rawMsg, $causeCodes, $response->status());
        }

        Log::error("[PublishML] Falha ao {$action} ClientProduct #{$cp->id}", [
            'status'     => $response->status(),
            'body'       => $response->body(),
            'translated' => $translated,
        ]);

        $cp->increment('sync_attempt_count');
        $cp->update([
            'sync_status'     => 'error',
            'last_sync_error' => mb_substr($translated, 0, 500),
            'last_sync_at'    => now(),
        ]);

        // Registra log de sync para histórico
        self::logSync($cp, $action, 'error', $translated, $response->body());
    }

    // -------------------------------------------------------------------------
    // Tradução de erros ML → mensagem amigável
    // -------------------------------------------------------------------------

    protected static function translateError(string $rawMsg, array $causes, int $status): string
    {
        // Mapa de erros conhecidos do ML → mensagem amigável + instrução
        $knownErrors = [
            'address_pending' => [
                'msg' => 'Endereço não cadastrado na conta do Mercado Livre.',
                'fix' => 'Acesse Mercado Livre > Minha Conta > Meus Dados e complete seu endereço.',
            ],
            'seller.unable_to_list' => [
                'msg' => 'Conta não habilitada para vender no Mercado Livre.',
                'fix' => 'Complete o cadastro de vendedor em mercadolivre.com.br/registration/seller.',
            ],
            'body.required_fields' => [
                'msg' => 'Campos obrigatórios faltando no anúncio.',
                'fix' => 'Verifique se título, preço, categoria e imagens estão preenchidos.',
            ],
            'item.title.removed_chars' => [
                'msg' => 'Título contém caracteres não permitidos pelo ML.',
                'fix' => 'Remova caracteres especiais ($, !, ?) e emojis do título.',
            ],
            'item.price.invalid' => [
                'msg' => 'Preço inválido para o Mercado Livre.',
                'fix' => 'O preço deve ser um valor positivo. Verifique se não está zerado.',
            ],
            'item.pictures.empty' => [
                'msg' => 'O anúncio precisa de pelo menos uma imagem.',
                'fix' => 'Adicione imagens na seção "Fotos do Meu Anúncio".',
            ],
            'item.category_id.invalid' => [
                'msg' => 'Categoria inválida no Mercado Livre.',
                'fix' => 'Use o campo de busca de categorias para selecionar uma categoria válida.',
            ],
            'item.attributes.missing_required' => [
                'msg' => 'A categoria exige atributos obrigatórios não preenchidos.',
                'fix' => 'Preencha Marca, Modelo e Condição na seção Características.',
            ],
            'invalid_token' => [
                'msg' => 'Token de acesso expirado ou inválido.',
                'fix' => 'Reconecte sua loja ao Mercado Livre nas Configurações.',
            ],
            'forbidden' => [
                'msg' => 'Sem permissão para esta operação.',
                'fix' => 'Verifique se o app tem permissão de publicação no Mercado Livre.',
            ],
        ];

        // Busca match nos causes primeiro, depois na mensagem principal
        foreach ($causes as $cause) {
            $causeStr = strtolower(is_string($cause) ? $cause : '');
            foreach ($knownErrors as $key => $info) {
                if (str_contains($causeStr, strtolower($key)) || str_contains(strtolower($rawMsg), strtolower($key))) {
                    return "{$info['msg']} ➜ {$info['fix']}";
                }
            }
        }

        // Busca na mensagem principal
        $rawLower = strtolower($rawMsg);
        foreach ($knownErrors as $key => $info) {
            if (str_contains($rawLower, strtolower($key))) {
                return "{$info['msg']} ➜ {$info['fix']}";
            }
        }

        // Tradução genérica por status HTTP
        $statusMsgs = [
            400 => 'Dados do anúncio inválidos',
            401 => 'Token expirado — reconecte a loja ao ML',
            403 => 'Sem permissão — verifique o cadastro de vendedor no ML',
            404 => 'Anúncio não encontrado no ML',
            429 => 'Muitas requisições — tente novamente em 1 minuto',
            500 => 'Erro interno do Mercado Livre — tente novamente mais tarde',
        ];

        $statusMsg = $statusMsgs[$status] ?? "Erro {$status}";
        $causeStr  = !empty($causes) ? ' (' . implode(', ', $causes) . ')' : '';

        return "{$statusMsg}: {$rawMsg}{$causeStr}";
    }

    // -------------------------------------------------------------------------
    // Log de sync para histórico
    // -------------------------------------------------------------------------

    protected static function logSync(ClientProduct $cp, string $action, string $status, string $message, ?string $rawResponse = null): void
    {
        try {
            \DB::table('sync_logs')->insert([
                'client_product_id' => $cp->id,
                'syncable_type'     => ClientProduct::class,
                'syncable_id'       => $cp->id,
                'platform'          => 'mercadolivre',
                'direction'         => 'outbound',
                'action'            => $action,
                'status'            => $status,
                'error_message'     => $status === 'error' ? mb_substr($message, 0, 1000) : null,
                'response_payload'  => $rawResponse ? mb_substr($rawResponse, 0, 65000) : null, // FOR-078: string JSON direta (fix Array to string conversion)
                'updated_at'        => now(),
                'created_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            // FOR-060: fix schema mismatch (message->error_message, raw_response->response_payload)
            Log::warning("[PublishML] sync_logs insert failed: {$e->getMessage()}");
        }
    }
    // -------------------------------------------------------------------------
    // FOR-073: Auto-fix ML — corrige payload antes de retentar POST /items
    // Portado do ProductPublishController->applyMLAutoFixes (26/06/2026)
    // -------------------------------------------------------------------------

    private function applyMLAutoFixes(array &$payload, array $causes, int $cpId, array &$fixesApplied): bool
    {
        $fixed  = false;
        $byCode = [];
        foreach ($causes as $c) {
            if (!empty($c['code'])) $byCode[$c['code']] = $c;
        }

        // Fix 1: EAN/GTIN ja usado em outra categoria — remover
        if (isset($byCode['item.attribute.invalid_product_identifier'])) {
            $payload['attributes'] = array_values(array_filter(
                $payload['attributes'] ?? [],
                fn($a) => ($a['id'] ?? '') !== 'GTIN'
            ));
            $fixesApplied[] = 'remove_gtin';
            Log::info('[PublishML] FOR-073 Auto-fix: removeu GTIN conflitante', ['client_product_id' => $cpId]);
            $fixed = true;
        }

        // Fix 2: Atributos obrigatorios faltando
        if (isset($byCode['item.attributes.missing_required'])) {
            $msg = $byCode['item.attributes.missing_required']['message'] ?? '';
            if (preg_match('/\[([^\]]+)\]/', $msg, $m)) {
                $missingIds  = array_map('trim', explode(',', $m[1]));
                $existingIds = array_column($payload['attributes'] ?? [], 'id');
                foreach ($missingIds as $attrId) {
                    if (in_array($attrId, $existingIds, true)) continue;
                    $defaultVal = $this->getMLAttributeDefaultValue($payload['category_id'] ?? 'MLB1144', $attrId, (string) ($payload['title'] ?? $payload['family_name'] ?? ''));
                    if ($defaultVal) {
                        $payload['attributes'][] = $defaultVal;
                        $fixesApplied[] = 'attr_' . $attrId;
                        Log::info('[PublishML] FOR-073 Auto-fix: atributo ' . $attrId . ' preenchido', ['client_product_id' => $cpId]);
                        $fixed = true;
                    }
                }
            }
        }

        // Fix 3: Precisao de preco invalida
        if (isset($byCode['item.price.invalid'])) {
            $payload['price'] = round((float) $payload['price'], 2);
            $fixesApplied[] = 'round_price';
            Log::info('[PublishML] FOR-073 Auto-fix: preco arredondado', ['client_product_id' => $cpId]);
            $fixed = true;
        }

        // Fix 4: Titulo muito longo
        if (isset($byCode['item.title.max_length'])) {
            $payload['title'] = mb_substr($payload['title'], 0, 60);
            $fixesApplied[] = 'truncate_title';
            Log::info('[PublishML] FOR-073 Auto-fix: titulo truncado', ['client_product_id' => $cpId]);
            $fixed = true;
        }

        // Fix 5: body.required_fields [family_name]
        // Categoria com catalogo obrigatorio ML (ex: MLB-KITCHEN_KNIVES — facas/utensilios).
        // ML exige family_name no campo raiz em vez de title.
        if (isset($byCode['body.required_fields'])) {
            $causeMsg = $byCode['body.required_fields']['message'] ?? '';
            if (str_contains($causeMsg, 'family_name')) {
                $originalTitle = $payload['title'] ?? '';
                if ($originalTitle) {
                    $payload['family_name'] = mb_substr($originalTitle, 0, 60);
                    unset($payload['title']);
                    $fixesApplied[] = 'family_name';
                    Log::info('[PublishML] FOR-073 Auto-fix: convertido para catalogo ML (family_name)', [
                        'client_product_id' => $cpId,
                        'family_name'       => $payload['family_name'],
                    ]);
                    $fixed = true;
                }
            }
        }

        return $fixed;
    }

    private function getMLAttributeDefaultValue(string $categoryId, string $attrId, string $title = ''): ?array
    {
        $hardcoded = [
            'BRAND'             => ['id' => 'BRAND',            'value_name' => 'Sem marca'],
            'GENDER'            => ['id' => 'GENDER',           'value_name' => 'Unissex'],
            'COLOR'             => ['id' => 'COLOR',            'value_name' => 'Nao especificado'],
            'SIZE'              => ['id' => 'SIZE',             'value_name' => 'Unico'],
            'MODEL'             => ['id' => 'MODEL',            'value_name' => 'Generico'],
            'MAIN_MATERIAL'     => ['id' => 'MAIN_MATERIAL',    'value_name' => 'Aco inoxidavel'],
            'PASTRY_TIP_SHAPE'  => ['id' => 'PASTRY_TIP_SHAPE', 'value_name' => 'Redondo'],
            'ITEM_CONDITION'    => ['id' => 'ITEM_CONDITION',   'value_name' => 'Novo'],
            'ORIGIN'            => ['id' => 'ORIGIN',           'value_name' => 'China'],
            'WITH_DIGITAL_LOCK' => ['id' => 'WITH_DIGITAL_LOCK','value_name' => 'Nao'],
        ];

        if (isset($hardcoded[$attrId])) {
            return $hardcoded[$attrId];
        }

        try {
            $res = Http::timeout(5)
                ->get("https://api.mercadolibre.com/categories/{$categoryId}/attributes");
            if ($res->successful()) {
                foreach ((is_array($res->json()) ? $res->json() : []) as $attr) { // FOR-087: guard is_array (ML pode retornar int em erro)
                    if (($attr['id'] ?? '') !== $attrId) continue;
                    // SEL-322: number_unit sem values nao pode ser auto-preenchido com string generica
                    if (($attr['value_type'] ?? '') === 'number_unit' && empty($attr['values'])) {
                        $fromTitle = $this->extractNumberUnitFromTitle($title, $attr);
                        return $fromTitle ? ['id' => $attrId, 'value_name' => $fromTitle] : null; // SEL-323: extrai do titulo; sem match, pula (SEL-322)
                    }
                    if (!empty($attr['values'])) {
                        return [
                            'id'         => $attrId,
                            'value_id'   => (string) $attr['values'][0]['id'],
                            'value_name' => $attr['values'][0]['name'],
                        ];
                    }
                    return ['id' => $attrId, 'value_name' => 'Nao especificado'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[PublishML] FOR-073 Falha ao buscar atributo ' . $attrId, ['error' => $e->getMessage()]);
        }

        return null;
    }



    private function extractNumberUnitFromTitle(string $title, array $attr): ?string
    {
        $units = [];
        foreach (($attr['allowed_units'] ?? []) as $u) {
            $id = (string) ($u['id'] ?? '');
            if ($id !== '') $units[mb_strtolower($id)] = $id;
        }
        if (!$units || $title === '') return null;
        $alias = ['metro' => 'm', 'metros' => 'm', 'litro' => 'l', 'litros' => 'l', 'mililitro' => 'ml', 'mililitros' => 'ml', 'grama' => 'g', 'gramas' => 'g', 'quilo' => 'kg', 'quilos' => 'kg'];
        if (!preg_match_all('/(\\d+(?:[.,]\\d+)?)\\s*([a-zA-Z"]{1,12})/u', $title, $ms, PREG_SET_ORDER)) return null;
        foreach ($ms as $m) {
            $u = mb_strtolower($m[2]);
            $u = $alias[$u] ?? $u;
            if (isset($units[$u])) {
                return str_replace(',', '.', $m[1]) . ' ' . $units[$u];
            }
        }
        return null;
    }

}