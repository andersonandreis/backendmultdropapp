<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use App\Models\ClientProduct;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\DB;
use App\Services\ImageDownloadService;
use App\Services\Integrations\Cdn\BunnyCdnService;
use Illuminate\Support\Facades\Log;

class BlingProductSync
{
    public function __construct(
        protected BlingApiClient $client,
        protected ImageDownloadService $imageDownload,
        protected BunnyCdnService $cdn
    ) {}

    // ---------------------------------------------------------------
    // Export: App -> Bling (create or update product)
    // ---------------------------------------------------------------

    /**
     * Export a Product from HubAI to Bling.
     *
     * If the product already exists in Bling (matched by SKU), updates it via PUT.
     * Otherwise creates a new product via POST.
     *
     * Returns the Bling product ID on success, or null on failure.
     */
    public function exportProduct(ErpAccount|MarketplaceAccount $account, Product $product): ?int
    {
        try {
            $payload = $this->buildExportPayload($product, null, $account);

            // Check if product already exists in Bling by SKU
            $existingBlingId = $this->findBlingProductIdBySku($account, $product->sku);

            if ($existingBlingId) {
                // Update existing product
                $response = $this->client->updateProduct($account, $existingBlingId, $payload);

                Log::info('[BlingProductSync] Product updated in Bling', [
                    'product_id' => $product->id,
                    'sku'        => $product->sku,
                    'bling_id'   => $existingBlingId,
                ]);

                $this->linkBlingSupplier($account, $product, $existingBlingId);

                return $existingBlingId;
            }

            // Create new product
            $response = $this->client->createProduct($account, $payload);
            $blingProductId = $response['data']['id'] ?? null;

            Log::info('[BlingProductSync] Product created in Bling', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'bling_id'   => $blingProductId,
            ]);

            if ($blingProductId) {
                $this->linkBlingSupplier($account, $product, (int) $blingProductId);
            }

            return $blingProductId ? (int) $blingProductId : null;
        } catch (\Throwable $e) {
            Log::error('[BlingProductSync] Export failed', [
                'product_id' => $product->id,
                'sku'        => $product->sku,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

// ---------------------------------------------------------------
    // MUL-107: Export payload completo
    // ---------------------------------------------------------------

    protected function buildExportPayload(
        Product $product,
        ?float  $clientPrice = null,
        ErpAccount|MarketplaceAccount|null $account = null
    ): array {
        $payload = [
            'nome'       => $product->name,
            'codigo'     => $product->sku,
            'preco'      => (float) ($clientPrice ?? $product->price),
            'tipo'       => 'P',
            'situacao'   => $product->is_active ? 'A' : 'I',
            'formato'    => 'S',
            'unidade'    => 'UN',
            // MUL-214 item 16: Bling v3 IGNORA ncm/origem/tipoItem/precoCusto no
            // top-level — as estruturas reais sao tributacao{} e fornecedor{}
            // (validado por GET /produtos/{id} em conta de producao).
            // spedTipoItem '00' = Mercadoria para Revenda.
            'tributacao' => [
                'origem'       => (int) ($product->origin ?? 0),
                'ncm'          => $product->ncm ?: '00000000',
                'spedTipoItem' => '00',
            ],
        ];

        if ($product->description) {
            $payload['descricaoCurta'] = mb_substr($product->description, 0, 500);
        }

        if ($product->gtin || $product->ean) {
            $payload['gtin'] = $product->gtin ?? $product->ean;
        }

        if ($product->brand) {
            $payload['marca'] = $product->brand;
        }

        if ($product->weight_kg) {
            $payload['pesoLiquido'] = (float) $product->weight_kg;
            $payload['pesoBruto']   = (float) $product->weight_kg;
        }

        if ($product->height_cm || $product->width_cm || $product->length_cm) {
            $payload['dimensoes'] = [
                'largura'       => (float) ($product->width_cm ?? 0),
                'altura'        => (float) ($product->height_cm ?? 0),
                'profundidade'  => (float) ($product->length_cm ?? 0),
                'unidadeMedida' => 1,
            ];
        }

        if ($product->condition) {
            $payload['condicao'] = $product->condition === 'new' ? 0 : 1;
        }

        // MUL-107: fotos (URLs externas)
        $images = $product->media()
            ->where('type', 'image')
            ->orderByDesc('is_cover')
            ->limit(10)
            ->pluck('url')
            ->filter(fn($u) => str_starts_with($u, 'http'))
            ->values()
            ->toArray();

        // NOV-189: modo 'stored' = seller gerencia imagens dentro do Bling; nao enviar midia
        $imagesMode = $account?->bling_images_mode ?? 'external';
        if (! empty($images) && $imagesMode !== 'stored') {
            $payload['midia'] = [
                'imagens' => [
                    'imagensURL' => array_map(fn($url) => ['link' => $url], $images),
                ],
            ];
        }

        return $payload;
    }

    /**
     * MUL-214 item 16: o Bling v3 ignora fornecedor{} no POST/PUT /produtos —
     * o vinculo produto-fornecedor (contato + precoCusto) e um recurso separado
     * (POST /produtos/fornecedores), validado em conta de producao.
     * No Bling do LOJISTA (MarketplaceAccount) precoCusto = preco do catalogo
     * (custo do seller) — o cost interno do fornecedor nunca sai do hub.
     */
    protected function linkBlingSupplier(
        ErpAccount|MarketplaceAccount $account,
        Product $product,
        int $blingProductId
    ): void {
        if (! $product->supplier_id) {
            return;
        }

        try {
            $contactId = $this->ensureBlingSupplierContact($account, (int) $product->supplier_id);
            if (! $contactId) {
                return;
            }

            $precoCusto = $account instanceof MarketplaceAccount
                ? (float) $product->price
                : (float) ($product->cost ?? 0);

            $body = [
                'produto'     => ['id' => $blingProductId],
                'fornecedor'  => ['id' => $contactId],
                'precoCusto'  => $precoCusto,
                'precoCompra' => 0,
                'padrao'      => true,
            ];

            $existing = $this->client->get($account, '/produtos/fornecedores', [
                'idProduto' => $blingProductId,
            ]);
            $linkId = null;
            foreach (($existing['data'] ?? []) as $link) {
                if ((int) ($link['fornecedor']['id'] ?? 0) === $contactId) {
                    $linkId = (int) $link['id'];
                    break;
                }
            }

            if ($linkId) {
                $this->client->put($account, '/produtos/fornecedores/' . $linkId, $body);
            } else {
                $this->client->post($account, '/produtos/fornecedores', $body);
            }
        } catch (\Throwable $e) {
            Log::warning('[BlingProductSync] linkBlingSupplier falhou', [
                'product_id' => $product->id,
                'bling_id'   => $blingProductId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function ensureBlingSupplierContact(
        ErpAccount|MarketplaceAccount $account,
        int $supplierId
    ): ?int {
        $cachedId = $account->bling_supplier_contact_id ?? null;
        if ($cachedId) {
            return (int) $cachedId;
        }

        $supplier = \App\Models\Supplier::find($supplierId);
        if (! $supplier) {
            return null;
        }

        $cnpj = preg_replace('/\D/', '', $supplier->document ?? '');

        if ($cnpj) {
            try {
                // MUL-214 item 16: o filtro correto no v3 e numeroDocumento
                // ('cpfCnpj' e ignorado e devolvia um contato qualquer); valida o
                // doc do retorno antes de usar
                $resp     = $this->client->get($account, '/contatos', ['numeroDocumento' => $cnpj, 'limite' => 1]);
                $contacts = $resp['data'] ?? [];
                $foundDoc = preg_replace('/\D/', '', (string) ($contacts[0]['numeroDocumento'] ?? ''));
                if (! empty($contacts[0]['id']) && $foundDoc === $cnpj) {
                    $contactId = (int) $contacts[0]['id'];
                    $this->saveBlingContactIdToAccount($account, $contactId);
                    return $contactId;
                }
            } catch (\Throwable $e) {
                Log::warning('[BlingProductSync] ensureBlingSupplierContact: busca CNPJ falhou', [
                    'supplier_id' => $supplierId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // MUL-142-E #19: payload completo do fornecedor no Bling (IE, indicador ICMS,
        // fantasia, endereço completo com número e bairro)
        $contactPayload = [
            'nome'         => $supplier->company_name ?? $supplier->display_name ?? 'Fornecedor HubAI',
            'fantasia'     => $supplier->trade_name ?? null,
            'tipoPessoa'   => 'J',
            'situacao'     => 'A',
            'tipo'         => 'F',
            'contribuinte' => (int) ($supplier->indicator_icms ?? 1),
        ];

        if ($contactPayload['fantasia'] === null) {
            unset($contactPayload['fantasia']);
        }

        if ($cnpj) {
            $contactPayload['cpfCnpj'] = $cnpj;
        }

        // IE — obrigatório para contribuintes ICMS
        if (! empty($supplier->ie)) {
            $contactPayload['ie'] = preg_replace('/\D/', '', $supplier->ie);
        }

        if ($supplier->address || $supplier->city) {
            $contactPayload['endereco'] = [
                'endereco'    => $supplier->address ?? '',
                'numero'      => $supplier->address_number ?? 'S/N',
                'complemento' => $supplier->address_complement ?? '',
                'bairro'      => $supplier->neighborhood ?? '',
                'cidade'      => $supplier->city ?? '',
                'uf'          => $supplier->state ?? '',
                'cep'         => preg_replace('/\D/', '', $supplier->zipcode ?? ''),
                'pais'        => 'Brasil',
            ];
        }

        try {
            $resp      = $this->client->post($account, '/contatos', $contactPayload);
            $contactId = (int) ($resp['data']['id'] ?? 0);
            if ($contactId) {
                $this->saveBlingContactIdToAccount($account, $contactId);
                Log::info('[BlingProductSync] ensureBlingSupplierContact: criado', [
                    'supplier_id' => $supplierId,
                    'contact_id'  => $contactId,
                ]);
                return $contactId;
            }
        } catch (\Throwable $e) {
            Log::warning('[BlingProductSync] ensureBlingSupplierContact: POST falhou', [
                'supplier_id' => $supplierId,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function saveBlingContactIdToAccount(
        ErpAccount|MarketplaceAccount $account,
        int $contactId
    ): void {
        try {
            $account->update(['bling_supplier_contact_id' => $contactId]);
        } catch (\Throwable $e) {
            Log::warning('[BlingProductSync] saveBlingContactIdToAccount: falhou', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find a Bling product ID by SKU (codigo).
     */
    protected function findBlingProductIdBySku(ErpAccount|MarketplaceAccount $account, string $sku): ?int
    {
        try {
            $response = $this->client->get($account, "/produtos", [
                "codigo" => $sku,
                "limite" => 1,
            ]);

            $products = $response["data"] ?? [];
            return isset($products[0]["id"]) ? (int) $products[0]["id"] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ---------------------------------------------------------------
    // Import: Bling -> App (bulk sync)
    // ---------------------------------------------------------------

    /**
     * Sincroniza todos os produtos do Bling para a HubAI.
     * Cria/atualiza Products (catalogo do fornecedor) e vincula ao ClientProduct do seller.
     */
    public function syncAll(MarketplaceAccount $account): array
    {
        // MUL-320b: baixar catalogo e privilegio do ERP do fornecedor (syncForSupplierErp).
        // Este metodo roda com MarketplaceAccount — conta de SELLER — e cria os produtos
        // dele em `products` com o supplier_id da conta, misturando catalogo particular
        // com o catalogo do fornecedor. Medido em 03/08/2026: 12 contas de seller sendo
        // varridas a cada 6h pelo bling-products-periodic-sync.
        // Regra (Ruan, 03/08): Bling de seller SO SOBE catalogo, nunca baixa.
        \Illuminate\Support\Facades\Log::warning('[MUL-320b] syncAll bloqueado: conta de seller nao baixa catalogo do Bling', [
            'account_id'  => $account->id,
            'client_id'   => $account->client_id,
            'supplier_id' => $account->supplier_id,
        ]);

        return ['created'=>0,'updated'=>0,'skipped'=>0,'errors'=>0,'linked'=>0,'pages'=>0,'blocked'=>'MUL-320b'];

        // ---- daqui para baixo: codigo original, inalcancavel. Mantido para historico e
        // para o dia em que existir um caso legitimo de conta de seller baixar catalogo. ----

        $stats = ["created" => 0, "updated" => 0, "skipped" => 0, "errors" => 0, "linked" => 0, "draft" => 0, "pages" => 0];
        $page = 1;

        do {
            try {
                $response = $this->client->listProducts($account, $page);
                $products = $response["data"] ?? [];

                if (empty($products)) {
                    break;
                }

                foreach ($products as $blingProduct) {
                    try {
                        $result = $this->syncProduct($account, $blingProduct);
                        $stats[$result]++;
                    } catch (\Throwable $e) {
                        $stats["errors"]++;
                        Log::warning("Bling product sync error", [
                            "bling_id" => $blingProduct["id"] ?? "?",
                            "sku" => $blingProduct["codigo"] ?? "?",
                            "error" => $e->getMessage(),
                        ]);
                    }
                }

                // MUL-183: circuit breaker — criar centenas de produtos NOVOS num sync
                // indica conta/supplier errado (incidente 04/07: 2.524 criados no sup=1).
                $maxNew = (int) config("bling.max_new_products_per_sync", 200);
                if ($maxNew > 0 && $stats["created"] >= $maxNew) {
                    $stats["aborted"] = true;
                    Log::critical("Bling product sync ABORTADO por circuit breaker (MUL-183): {$stats['created']} produtos novos >= limite {$maxNew}. Provavel conta/supplier errado ou full import indevido.", [
                        "account_id"  => $account->id,
                        "supplier_id" => $account->supplier_id,
                        "stats"       => $stats,
                    ]);
                    break;
                }

                $stats["pages"]++;
                $page++;

                // Rate limit: max 3 req/s
                usleep(350000);

            } catch (\Throwable $e) {
                Log::error("Bling product sync page error", ["page" => $page, "error" => $e->getMessage()]);
                break;
            }
        } while (count($products ?? []) >= 100);

        $account->update(["last_sync_at" => now()]);

        return $stats;
    }

    /**
     * Sincroniza um produto individual do Bling.
     * Match por SKU (bling codigo = product.sku).
     * Usa updateOrCreate pra evitar duplicatas e conflitos.
     */
    /**
     * MUL-394: sync do Bling e codigo de SYNC -- regra 16 do CLAUDE.md manda respeitar
     * ProductObserver::$disableSync. Nao respeitava: apos destravar o push WL->hub, cada
     * produto tocado pelo Bling viraria um push pro hub (4.178 product.updated/dia so no
     * hub). O hub tem a propria integracao Bling e nao precisa desse eco.
     */
    protected function syncProduct(MarketplaceAccount $account, array $blingProduct): string
    {
        $flagAnterior = \App\Observers\ProductObserver::$disableSync;
        \App\Observers\ProductObserver::$disableSync = true;
        try {
            return $this->syncProductInterno($account, $blingProduct);
        } finally {
            \App\Observers\ProductObserver::$disableSync = $flagAnterior;
        }
    }

    protected function syncProductInterno(MarketplaceAccount $account, array $blingProduct): string
    {
        $sku = trim($blingProduct["codigo"] ?? "");
        $supplierId = $account->supplier_id;

        // Validacoes: SKU obrigatorio, supplier obrigatorio, nome obrigatorio
        if (empty($sku) || !$supplierId) {
            return "skipped";
        }

        $name = trim($blingProduct["nome"] ?? $blingProduct["descricao"] ?? "");
        if (empty($name)) {
            return "skipped";
        }

        // Normaliza dados com fallbacks seguros
        $price = (float) ($blingProduct["preco"] ?? 0);
        $cost = (float) ($blingProduct["precoCusto"] ?? 0);
        $condicao = $blingProduct["condicao"] ?? null;
        $situacao = $blingProduct["situacao"] ?? "";

        // NOV-152: products.sku eh UNIQUE GLOBAL (nao por supplier).
        // Se SKU jah existe em OUTRO supplier, reutilizar o Product existente (link logico)
        // em vez de tentar criar duplicata que fere o constraint UNIQUE.
        $existing = Product::where("sku", $sku)->first();
        // MUL-078: is_active NAO incluido nos attrs de UPDATE — preserva status manual definido pelo admin.
        // Bling reativa produtos automaticamente a cada sync, desfazendo desativacoes manuais (bug MUL-073 residual).
        // is_active so e definido na criacao de produto novo.
        $attrs = [
            "name" => $name,
            "description" => trim($blingProduct["descricaoCurta"] ?? $blingProduct["descricao"] ?? ""),
            "price" => $price,
            "cost" => $cost,
            "gtin" => $blingProduct["gtin"] ?? null,
            "brand" => $blingProduct["marca"] ?? null,
            "weight_kg" => isset($blingProduct["pesoLiq"]) ? (float) $blingProduct["pesoLiq"] : null,
            "condition" => ($condicao === 0 || $condicao === "0") ? "new" : "used",
        ];

        if ($existing) {
            if ($existing->supplier_id !== $supplierId) {
                // Produto jah pertence a outro supplier — nao sobrescrever, apenas linkar via ClientProduct
                Log::debug("[BlingProductSync] SKU jah pertence a outro supplier — reutilizando product", [
                    "sku" => $sku,
                    "existing_product_id" => $existing->id,
                    "existing_supplier_id" => $existing->supplier_id,
                    "bling_account_supplier_id" => $supplierId,
                ]);
                $product = $existing;
                $action = "linked";
            } else {
                // Mesmo supplier: update normal (sem is_active — preserva estado manual)
                // MUL-394: price/cost SAEM do update pelo mesmo motivo do is_active (MUL-078).
                // products.price e o que o SELLER paga (custo do fornecedor); o "preco" do
                // Bling e preco de VENDA -- conceitos diferentes. Sobrescrever desfazia o
                // preco definido pelo admin a cada sync. Na CRIACAO price/cost continuam
                // vindo do Bling (produto novo precisa nascer com algum valor).
                $attrsUpdate = $attrs;
                unset($attrsUpdate["price"], $attrsUpdate["cost"]);
                $existing->update($attrsUpdate);
                $product = $existing;
                $action = "updated";
            }
        } else {
            // SKU nao existe globalmente: cria com is_active do Bling
            $isActive = ($situacao === "A" || $situacao === "Ativo");
            $product = Product::create(array_merge($attrs, [
                "supplier_id" => $supplierId,
                "sku" => $sku,
                "is_active" => $isActive,
            ]));
            $action = "created";
        }

        // NOV-152: client_products tem UNIQUE composto (client_id, custom_sku).
        // Tenta achar por client_id+custom_sku primeiro (chave unica real), depois client_id+product_id.
        $clientId = $account->client_id;
        if ($clientId) {
            $cp = ClientProduct::where("client_id", $clientId)
                ->where(function ($q) use ($sku, $product) {
                    $q->where("custom_sku", $sku)->orWhere("product_id", $product->id);
                })
                ->first();

            $cpAttrs = [
                "client_id" => $clientId,
                "product_id" => $product->id,
                "supplier_product_sku" => $sku,
                "custom_sku" => $sku,
                "custom_title" => $product->name,
                "custom_price" => $product->price,
                "pricing_mode" => "manual",
                "sync_status" => "synced",
                "is_active" => true,
                "listing_status" => "active",
                "marketplace_account_id" => $account->id,
                "last_sync_at" => now(),
            ];

            if ($cp) {
                if ($cp->marketplace_account_id && $cp->marketplace_account_id !== $account->id) {
                    // MUL-214 item 14: cp pertence a conta de marketplace real (Shopee/ML) —
                    // nao sequestrar conta/URL do anuncio; so refresca vinculo de catalogo
                    $cp->update([
                        "product_id" => $product->id,
                        "supplier_product_sku" => $sku,
                    ]);
                } else {
                    $cp->update($cpAttrs);
                }
            } else {
                ClientProduct::create($cpAttrs);
            }
        }

        // MUL-064: salva imagens Bling
        if (isset($product)) {
            $this->saveProductImages($product, $blingProduct);
        }

        return $action;
    }
    /**
     * NOV-153: Sincroniza catálogo Bling do FORNECEDOR (ErpAccount) → Product (catálogo do supplier).
     *
     * Diferente de syncAll(MarketplaceAccount) que cria ClientProduct (vincula a um lojista),
     * aqui só atualiza a tabela `products` do fornecedor — produtos importados ficam
     * disponíveis no catálogo dele pra exposição em WLs e marketplaces depois.
     *
     * NÃO cria ClientProduct (não há cliente lojista nesse fluxo).
     * NÃO sobrescreve produto que já pertence a OUTRO supplier (UNIQUE sku global — NOV-152).
     */
    public function syncForSupplierErp(ErpAccount $account): array
    {
        $stats = ["created" => 0, "updated" => 0, "skipped" => 0, "errors" => 0, "linked" => 0, "draft" => 0, "pages" => 0];

        if (! $account->supplier_id) {
            Log::warning('[BlingProductSync] syncForSupplierErp: account sem supplier_id', ['account_id' => $account->id]);
            return $stats;
        }

        $supplierId = (int) $account->supplier_id;
        $page = 1;

        do {
            try {
                $response = $this->client->listProducts($account, $page);
                $products = $response["data"] ?? [];

                if (empty($products)) {
                    break;
                }

                foreach ($products as $blingProduct) {
                    try {
                        // MUL-212: busca produto individual pra obter o cadastro completo
                        // (tributacao/ncm, dimensoes, pesos, localizacao) — a listagem vem resumida.
                        try {
                            $fullProduct = $this->client->getProduct($account, (int)($blingProduct['id'] ?? 0));
                            if (! empty($fullProduct['data']) && is_array($fullProduct['data'])) {
                                $blingProduct = array_merge($blingProduct, $fullProduct['data']);
                            }
                        } catch (\Throwable $fullEx) {
                            // graceful: segue so com os campos da listagem
                        }
                        $result = $this->syncProductForSupplier($supplierId, $blingProduct);
                        if (isset($stats[$result])) {
                            $stats[$result]++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats["errors"]++;
                        Log::warning("[BlingProductSync] syncForSupplierErp item error", [
                            "bling_id"    => $blingProduct["id"] ?? "?",
                            "sku"         => $blingProduct["codigo"] ?? "?",
                            "supplier_id" => $supplierId,
                            "error"       => $e->getMessage(),
                        ]);
                    }
                }

                // MUL-183: circuit breaker (mesma protecao do syncAll)
                $maxNew = (int) config("bling.max_new_products_per_sync", 200);
                if ($maxNew > 0 && $stats["created"] >= $maxNew) {
                    $stats["aborted"] = true;
                    Log::critical("[BlingProductSync] syncForSupplierErp ABORTADO por circuit breaker (MUL-183): {$stats['created']} novos >= limite {$maxNew}.", [
                        "account_id"  => $account->id,
                        "supplier_id" => $supplierId,
                        "stats"       => $stats,
                    ]);
                    break;
                }

                $stats["pages"]++;
                $page++;
                usleep(350000); // rate limit Bling

            } catch (\Throwable $e) {
                Log::error("[BlingProductSync] syncForSupplierErp page error", [
                    "page"  => $page,
                    "error" => $e->getMessage(),
                ]);
                break;
            }
        } while (count($products ?? []) >= 100);

        $account->update(["last_sync_at" => now()]);

        return $stats;
    }

    /**
     * NOV-153: sincroniza um produto Bling pra Product do supplier (sem ClientProduct).
     *
     * Mesma lógica de matching SKU/UNIQUE de syncProduct() porém SEM criar ClientProduct.
     */
    /**
     * MUL-331: o codigo do Bling vem SEM o prefixo de deposito (D498-, D773-, ...), mas o
     * catalogo do sistema guarda COM. Sem isto o sync recria todo o catalogo sem prefixo,
     * duplicando cada produto — medido em 04/08/2026: 48 duplicatas em uma unica execucao.
     * So aplica onde o catalogo daquele supplier de fato usa prefixo, para nao inventar
     * prefixo em fornecedor que nunca usou.
     */
    protected function prefixoDeposito(int $supplierId, string $sku): string
    {
        if ($sku === '' || preg_match('/^D\d+-/', $sku)) {
            return $sku;
        }

        static $cache = [];
        if (! array_key_exists($supplierId, $cache)) {
            $legacyId = \Illuminate\Support\Facades\DB::table('suppliers')
                ->where('id', $supplierId)->value('legacy_id');
            $prefixo = $legacyId ? 'D' . $legacyId . '-' : null;
            $usa = $prefixo && Product::where('supplier_id', $supplierId)
                ->where('sku', 'like', $prefixo . '%')->exists();
            $cache[$supplierId] = $usa ? $prefixo : null;
        }

        return $cache[$supplierId] ? $cache[$supplierId] . $sku : $sku;
    }
    /**
     * MUL-334: o erp_sku ja existe em algum catalogo do mesmo grupo?
     * Grupo = o supplier raiz + os que apontam para ele via parent_supplier_id.
     */
    protected function grupoJaTem(int $supplierId, string $erpSku): bool
    {
        if ($erpSku === '') {
            return false;
        }

        return Product::whereIn('supplier_id', $this->catalogosDoGrupo($supplierId))
            ->where('erp_sku', $erpSku)
            ->exists();
    }

    /**
     * MUL-334: ids dos catalogos do grupo do supplier — ele proprio, o pai se houver,
     * e os irmaos. Um fornecedor pode ter N catalogos (MultDrop tem 2 e pode ter 10).
     */
    protected function catalogosDoGrupo(int $supplierId): array
    {
        static $cache = [];
        if (isset($cache[$supplierId])) {
            return $cache[$supplierId];
        }

        $raiz = (int) (\Illuminate\Support\Facades\DB::table('suppliers')
            ->where('id', $supplierId)->value('parent_supplier_id') ?: $supplierId);

        $ids = \Illuminate\Support\Facades\DB::table('suppliers')
            ->where('id', $raiz)->orWhere('parent_supplier_id', $raiz)
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        return $cache[$supplierId] = $ids ?: [$supplierId];
    }

    /**
     * MUL-334: grava/atualiza o rascunho do produto novo. Traz do Bling tudo que o
     * fornecedor nao precisa redigitar. sku_base e prices_by_supplier ficam VAZIOS —
     * quem define e ele, escolhendo os catalogos antes de publicar.
     */
    protected function registrarRascunho(int $supplierId, string $erpSku, ?string $erpFormato, array $blingProduct, array $attrs): void
    {
        $raiz = (int) (\Illuminate\Support\Facades\DB::table('suppliers')
            ->where('id', $supplierId)->value('parent_supplier_id') ?: $supplierId);

        $imagens = [];
        foreach (['internas', 'externas', 'imagensURL'] as $grupo) {
            foreach ((array) ($blingProduct['midia']['imagens'][$grupo] ?? []) as $img) {
                $url = is_array($img) ? ($img['link'] ?? $img['url'] ?? null) : $img;
                if (is_string($url) && $url !== '') {
                    $imagens[] = $url;
                }
            }
        }

        $existente = \Illuminate\Support\Facades\DB::table('supplier_product_drafts')
            ->where('supplier_id', $raiz)->where('erp_sku', $erpSku)->first(['id', 'status']);

        // rascunho ja publicado ou descartado nao volta a aparecer
        if ($existente && in_array($existente->status, ['publicado', 'descartado'], true)) {
            return;
        }

        $dados = [
            'supplier_id'    => $raiz,
            'erp_sku'        => $erpSku,
            'erp_product_id' => (string) ($blingProduct['id'] ?? ''),
            'erp_formato'    => $erpFormato,
            'name'           => $attrs['name'] ?? null,
            'description'    => $attrs['description'] ?? null,
            'erp_cost'       => $attrs['cost'] ?? null,
            'erp_price'      => $attrs['price'] ?? null,
            'gtin'           => $attrs['gtin'] ?? null,
            'ncm'            => $attrs['ncm'] ?? null,
            'weight_kg'      => $attrs['weight_kg'] ?? null,
            'dimensions'     => json_encode($blingProduct['dimensoes'] ?? null),
            'images'         => json_encode(array_values(array_unique($imagens))),
            'erp_stock'      => isset($blingProduct['estoque']['saldoVirtualTotal'])
                ? (int) $blingProduct['estoque']['saldoVirtualTotal'] : null,
            'updated_at'     => now(),
        ];

        if ($existente) {
            \Illuminate\Support\Facades\DB::table('supplier_product_drafts')
                ->where('id', $existente->id)->update($dados);
            return;
        }

        \Illuminate\Support\Facades\DB::table('supplier_product_drafts')->insert(array_merge($dados, [
            'status'        => 'novo',
            'first_seen_at' => now(),
            'created_at'    => now(),
        ]));

        Log::info('[MUL-334] produto novo do ERP registrado como rascunho', [
            'supplier_id' => $raiz,
            'erp_sku'     => $erpSku,
            'formato'     => $erpFormato,
        ]);
    }
    protected function syncProductForSupplier(int $supplierId, array $blingProduct): string
    {
        $sku = trim($blingProduct["codigo"] ?? "");
        if (empty($sku)) {
            return "skipped";
        }
        // MUL-334: o codigo cru do Bling e a identidade do produto fisico (erp_sku).
        $erpSku     = $sku;
        $erpFormato = $blingProduct['formato'] ?? null;

        // MUL-331: codigo do Bling vem sem o prefixo do deposito; o catalogo guarda com.
        $sku = $this->prefixoDeposito($supplierId, $sku);

        $name = trim($blingProduct["nome"] ?? $blingProduct["descricao"] ?? "");
        if (empty($name)) {
            return "skipped";
        }

        $price    = (float) ($blingProduct["preco"] ?? 0);
        $cost     = (float) ($blingProduct["precoCusto"] ?? $blingProduct["fornecedor"]["precoCusto"] ?? 0);
        $condicao = $blingProduct["condicao"] ?? null;
        $situacao = $blingProduct["situacao"] ?? "";

        // ERP e fonte de verdade: is_active DEVE ser atualizado no UPDATE (reflete situacao real no Bling).
        // Diferente de syncProduct() (seller) que preserva status manual do admin.
        $isActiveErp = ($situacao === "A" || $situacao === "Ativo");
        $attrs = [
            "name"        => $name,
            "description" => trim($blingProduct["descricaoCurta"] ?? $blingProduct["descricaoComplementar"] ?? $blingProduct["descricao"] ?? ""),
            "price"       => $price,
            "condition"   => ($condicao === 0 || $condicao === "0") ? "new" : "used",
            "is_active"   => $isActiveErp,
        ];

        // MUL-212: custo so atualiza quando o Bling informa valor — o custo do fornecedor
        // pode ser preenchido manualmente no sistema e nao pode ser zerado pelo sync.
        if ($cost > 0) {
            $attrs["cost"] = $cost;
        }

        // MUL-212: cadastro completo (exceto imagens). So grava campo PRESENTE no payload,
        // pra nao apagar dado existente quando getProduct falha e a listagem vem resumida.
        $trib = $blingProduct["tributacao"] ?? [];
        $dim  = $blingProduct["dimensoes"] ?? [];

        if (! empty($blingProduct["gtin"])) {
            $attrs["gtin"] = $blingProduct["gtin"];
        }
        if (! empty($blingProduct["marca"])) {
            $attrs["brand"] = $blingProduct["marca"];
        }
        if (! empty($trib["ncm"])) {
            $attrs["ncm"] = $trib["ncm"];
        }
        if (isset($trib["origem"]) && $trib["origem"] !== "") {
            $attrs["origem"] = (string) $trib["origem"]; // 0 = nacional (valor valido)
        }
        $peso = (float) ($blingProduct["pesoLiquido"] ?? $blingProduct["pesoLiq"] ?? 0);
        if ($peso > 0) {
            $attrs["weight_kg"] = $peso;
        }
        foreach (["largura" => "width_cm", "altura" => "height_cm", "profundidade" => "length_cm"] as $blingField => $col) {
            $v = (float) ($dim[$blingField] ?? 0);
            if ($v > 0) {
                $attrs[$col] = $v;
            }
        }
        if (! empty($blingProduct["estoque"]["localizacao"])) {
            $attrs["warehouse_location"] = $blingProduct["estoque"]["localizacao"];
        }

        // SKU é UNIQUE global (NOV-152).
        $existing = Product::where("sku", $sku)->first();

        if ($existing) {
            if ($existing->supplier_id !== $supplierId) {
                // Produto já existe em outro supplier — não sobrescrever.
                Log::debug("[BlingProductSync] supplier_erp: SKU pertence a outro supplier — skipping update", [
                    "sku"                      => $sku,
                    "existing_supplier_id"     => $existing->supplier_id,
                    "bling_account_supplier_id" => $supplierId,
                ]);
                return "linked";
            }
            // MUL-212: sem saveProductImages no caminho ERP do fornecedor (pedido Ruan 10/07) —
            // imagens ficam como estao; so o cadastro sincroniza.
            // MUL-334: carimba a identidade do ERP em quem ainda nao tem.
            if (empty($existing->erp_sku)) {
                $attrs['erp_sku']        = $erpSku;
                $attrs['erp_map_origem'] = 'bling';
            }
            if ($erpFormato && empty($existing->erp_formato)) {
                $attrs['erp_formato'] = $erpFormato;
            }
            $existing->update($attrs);
            return "updated";
        }

        // MUL-334: antes de considerar novo, procurar o erp_sku nos OUTROS catalogos do
        // mesmo grupo. O produto pode ja existir na matriz e faltar so na filial — nesse
        // caso nao e produto novo, e ausencia de catalogo, e quem decide isso e o
        // fornecedor na publicacao, nao o sync.
        if ($this->grupoJaTem($supplierId, $erpSku)) {
            return "skipped";
        }

        // MUL-334: produto novo NAO nasce em products. A NF de entrada cria o item no Bling
        // sem preco, sem foto e sem SKU de plataforma; entrar direto no catalogo foi o que
        // gerou 48 duplicatas por execucao ate 04/08/2026. Vai para rascunho e so entra no
        // catalogo quando o fornecedor concluir o cadastro e publicar.
        $this->registrarRascunho($supplierId, $erpSku, $erpFormato, $blingProduct, $attrs);

        return "draft";
    }

    /**
     * MUL-064: Salva imagens do campo midia.imagens de um produto Bling em product_media.
     * Suporta imagemURL, midia.imagens.externas e midia.imagens.imagensURL.
     * Idempotente: insere apenas URLs que ainda nao existem.
     */
    public function saveProductImages(Product $product, array $blingProduct): void
    {
        try {
            // MUL-085: internas = full-size do endpoint /produtos/{id} — prioridade maxima
            // MUL-082: prioridade: internas > imagensURL > externas > imagemURL (thumbnail)
            $internas   = $blingProduct['midia']['imagens']['internas'] ?? [];
            $imagensUrl = $blingProduct['midia']['imagens']['imagensURL'] ?? [];
            $externas   = $blingProduct['midia']['imagens']['externas'] ?? [];
            $imgUrl     = trim($blingProduct['imagemURL'] ?? '');

            $urls = [];
            foreach ($internas as $img) {
                $url = trim($img['link'] ?? ''); // full-size: sem /t/ no path S3
                if ($url) { $urls[] = ['url' => $url, 'is_cover' => empty($urls)]; }
            }
            if (empty($urls)) {
                foreach ($imagensUrl as $iu) {
                    $url = trim(is_string($iu) ? $iu : ($iu['url'] ?? ''));
                    if ($url) { $urls[] = ['url' => $url, 'is_cover' => empty($urls)]; }
                }
                foreach ($externas as $ext) {
                    $url = trim($ext['link'] ?? $ext['url'] ?? '');
                    if ($url) { $urls[] = ['url' => $url, 'is_cover' => empty($urls)]; }
                }
                if (empty($urls) && $imgUrl) {
                    $urls[] = ['url' => $imgUrl, 'is_cover' => true];
                }
            }
            if (empty($urls)) { return; }

            $now = now();

            // MUL-084: download + BunnyCDN para cada imagem
            // MUL-091: guard idempotencia -- pre-carregar URLs de imagens ja no CDN
            // MUL-123: indexar por urlBase (sem query string) para tratar S3 presigned URLs
            $rawCached = \Illuminate\Support\Facades\DB::table('product_media')
                ->where('product_id', $product->id)
                ->whereNotNull('local_path')
                ->get(['original_url', 'url'])
                ->all();
            $cachedMedia = [];
            foreach ($rawCached as $cm) {
                $base = $this->urlBase($cm->original_url);
                if (!$base) continue;
                if (!isset($cachedMedia[$base]) || str_contains($cm->url, 'b-cdn.net')) {
                    $cachedMedia[$base] = $cm->url;
                }
            }


            // MUL-131: guard anti-recidiva -- se o produto ja tem tantas imagens com local_path
            // quanto o Bling esta enviando, todas ja foram baixadas e estao no CDN correto.
            // Evita re-insercao de copias de origem externa (hubai-cdn, hubai-storage etc)
            // que vazam quando o hub relay propaga product_media via syncProduct MarketplaceAccount.
            if (count($rawCached) >= count($urls)) {
                Log::debug('[BlingProductSync] saveProductImages: produto ja completo, pulando insercao', [
                    'product_id'   => $product->id,
                    'cached_count' => count($rawCached),
                    'bling_urls'   => count($urls),
                ]);
                return;
            }

            $resolved = [];
            // MUL-135: content_hash helper -- md5 do arquivo baixado localmente
            $contentHashFor = function (array $item): ?string {
                $lp = $item['local_path'] ?? null;
                if (!$lp || $lp === 'cached') { return null; }
                $abs = storage_path('app/public/' . $lp);
                return is_file($abs) ? md5_file($abs) : null;
            };

            foreach ($urls as $item) {
                $itemBase = $this->urlBase($item['url']);
                if ($itemBase && isset($cachedMedia[$itemBase])) {
                    // Imagem ja no CDN -- reusar sem re-baixar
                    $resolved[] = [
                        'original_url' => $item['url'],
                        'url'          => $cachedMedia[$itemBase],
                        'local_path'   => 'cached',
                        'content_hash' => null, // MUL-135: sem hash (imagem reutilizada do CDN)
                        'is_cover'     => $item['is_cover'],
                    ];
                    continue;
                }
                $r = $this->downloadAndUploadToCdn($item['url'], $product);
                $resolved[] = [
                    'original_url' => $item['url'],
                    'url'          => $r['cdn_url'],
                    'local_path'   => $r['local_path'],
                    'content_hash' => $contentHashFor($r), // MUL-135
                    'is_cover'     => $item['is_cover'],
                ];
            }

            $coverItem = collect($resolved)->firstWhere('is_cover', true);
            if ($coverItem) {
                $updated = DB::table('product_media')
                    ->where('product_id', $product->id)
                    ->where('is_cover', 1)
                    ->update([
                        'url'          => $coverItem['url'],
                        'original_url' => $coverItem['original_url'],
                        'local_path'   => $coverItem['local_path'],
                        'updated_at'   => $now,
                    ]);
                if ($updated === 0) {
                    // MUL-206: insertOrIgnore + content_hash fallback = MD5(original_url) evita duplicata em race condition
                    DB::table('product_media')->insertOrIgnore([
                        'product_id'   => $product->id,
                        'type'         => 'image',
                        'url'          => $coverItem['url'],
                        'original_url' => $coverItem['original_url'],
                        'local_path'   => $coverItem['local_path'],
                        'is_cover'     => 1,
                        'content_hash' => $coverItem['content_hash'] ?? md5($this->urlBase($coverItem['original_url'] ?? $coverItem['url'] ?? '')), // MUL-135/MUL-206
                        'position'     => 0,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                }
            }

            // MUL-123: dedup por urlBase (sem query string) -- mesma imagem, URL S3 presigned diferente
            $rawExistingOriginals = DB::table('product_media')
                ->where('product_id', $product->id)
                ->pluck('original_url')
                ->all();
            $existingOriginals = [];
            foreach ($rawExistingOriginals as $eou) {
                $existingOriginals[$this->urlBase($eou)] = true;
            }

            $newRows = [];
            foreach ($resolved as $i => $item) {
                if ($item['is_cover']) { continue; }
                if (isset($existingOriginals[$this->urlBase($item['original_url'])])) { continue; }
                $newRows[] = [
                    'product_id'   => $product->id,
                    'type'         => 'image',
                    'url'          => $item['url'],
                    'original_url' => $item['original_url'],
                    'local_path'   => $item['local_path'],
                    'is_cover'     => 0,
                    // MUL-206: MD5(urlBase(original_url)) — ignora querystring S3 presigned
                    'content_hash' => $item['content_hash'] ?? md5($this->urlBase($item['original_url'] ?? $item['url'] ?? '')),
                    'position'     => $i,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }
            // MUL-206: insertOrIgnore + UNIQUE(product_id, content_hash) impede duplicata em race condition
            if (!empty($newRows)) { DB::table('product_media')->insertOrIgnore($newRows); }

            Log::debug('[BlingProductSync] saveProductImages: imagens processadas', [
                'product_id' => $product->id,
                'total'      => count($resolved),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[BlingProductSync] saveProductImages: erro', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * MUL-084: baixa imagem externa, faz upload pro BunnyCDN e retorna URL CDN.
     * Em caso de falha, retorna URL original como fallback gracioso.
     */
    protected function downloadAndUploadToCdn(string $externalUrl, Product $product): array
    {
        try {
            $result = $this->imageDownload->downloadAndStoreImage(
                $externalUrl,
                $product->supplier_id,
                $product->id
            );

            if (!$result) {
                return ['cdn_url' => $externalUrl, 'local_path' => null];
            }

            $localPath = $result['path']; // ex: products/30/1234/abc.jpg
            $localAbs  = storage_path('app/public/' . $localPath);

            $uploaded = $this->cdn->upload($localAbs, $localPath);

            if (!$uploaded) {
                Log::warning('[BlingProductSync] BunnyCDN upload falhou', [
                    'product_id' => $product->id,
                    'local'      => $localAbs,
                ]);
                return ['cdn_url' => $result['url'], 'local_path' => $localPath];
            }

            return [
                'cdn_url'    => $this->cdn->getUrl($localPath),
                'local_path' => $localPath,
            ];
        } catch (\Throwable $e) {
            Log::warning('[BlingProductSync] downloadAndUploadToCdn erro', [
                'product_id' => $product->id,
                'url'        => $externalUrl,
                'error'      => $e->getMessage(),
            ]);
            return ['cdn_url' => $externalUrl, 'local_path' => null];
        }
    }


    /**
     * Sincroniza estoque do Bling -> HubAI.
     *
     * Grava a quantidade em inventory (warehouse_id = supplier_id, producer_id = supplier_id)
     * para que effectiveStock (SUM inventory.quantity) seja correto e o InventoryObserver
     * dispare SyncInventoryJob automaticamente quando o estoque muda.
     *
     * Mantém virtual_stock_qty por compatibilidade com leituras legadas.
     */
    public function syncStock(MarketplaceAccount $account): array
    {
        $stats = ["updated" => 0, "skipped" => 0, "errors" => 0];
        $page = 1;
        $supplierId = $account->supplier_id;

        if (!$supplierId) {
            Log::warning("[BlingProductSync] syncStock: account sem supplier_id", ["account_id" => $account->id]);
            return $stats;
        }

        // NOV-151 GUARD: Bling /estoques/saldos retorna VALIDATION_ERROR quando nao ha produtos.
        // Verificar se ha produtos do fornecedor antes de chamar endpoint.
        $hasProducts = \App\Models\Product::where('supplier_id', $supplierId)->where('is_active', true)->exists();
        if (!$hasProducts) {
            Log::info('[BlingProductSync] syncStock: sem produtos ativos, pulando /estoques/saldos', ['account_id' => $account->id, 'supplier_id' => $supplierId]);
            return $stats;
        }

        do {
            try {
                $response = $this->client->listStock($account, $page);
                $items = $response["data"] ?? [];

                if (empty($items)) {
                    break;
                }

                foreach ($items as $stockItem) {
                    try {
                        $productId = $stockItem["produto"]["id"] ?? null;
                        $quantity  = max(0, (int) ($stockItem["saldoFisicoTotal"] ?? $stockItem["saldoVirtualTotal"] ?? 0));

                        if (!$productId) {
                            $stats["skipped"]++;
                            continue;
                        }

                        // Busca o produto Bling pelo ID pra pegar o SKU
                        $blingProduct = $this->client->getProduct($account, $productId);
                        $sku = $blingProduct["data"]["codigo"] ?? null;

                        if (!$sku) {
                            $stats["skipped"]++;
                            continue;
                        }

                        $product = Product::where("supplier_id", $supplierId)
                            ->where("sku", $sku)
                            ->first();

                        if (!$product) {
                            $stats["skipped"]++;
                            continue;
                        }

                        // -----------------------------------------------------------
                        // FIX NOV-089: escrever em inventory para effectiveStock correto
                        // warehouse_id = supplier_id (padrao do SyncLegacyCatalogJob)
                        // producer_id  = supplier_id (mesmo fornecedor e dono do estoque)
                        // O InventoryObserver detecta mudanca em quantity e dispara
                        // SyncInventoryJob automaticamente.
                        // -----------------------------------------------------------
                        $inventory = \App\Models\Inventory::firstOrNew([
                            "product_id"   => $product->id,
                            "warehouse_id" => $supplierId,
                            "producer_id"  => $supplierId,
                        ]);

                        $inventory->quantity = $quantity;
                        $inventory->save(); // dispara InventoryObserver -> SyncInventoryJob

                        // Manter virtual_stock_qty por compatibilidade (sem disparar observers)
                        $product->virtual_stock_qty = $quantity;
                        $product->saveQuietly();

                        Log::debug("[BlingProductSync] syncStock: inventory atualizado", [
                            "product_id"  => $product->id,
                            "sku"         => $sku,
                            "supplier_id" => $supplierId,
                            "new_qty"     => $quantity,
                        ]);

                        $stats["updated"]++;

                        usleep(350000); // rate limit Bling: max 3 req/s
                    } catch (\Throwable $e) {
                        $stats["errors"]++;
                        Log::warning("[BlingProductSync] syncStock: erro ao processar item", [
                            "bling_product_id" => $stockItem["produto"]["id"] ?? null,
                            "error"            => $e->getMessage(),
                        ]);
                    }
                }

                $page++;
                usleep(350000);
            } catch (\Throwable $e) {
                Log::error("[BlingProductSync] syncStock: erro de pagina", ["page" => $page, "error" => $e->getMessage()]);
                break;
            }
        } while (count($items ?? []) >= 100);

        return $stats;
    }

    /**
     * MUL-198: Sincroniza APENAS estoque via ErpAccount (supplier 30 -> inventory).
     *
     * Equivalente ao syncStock() mas aceita ErpAccount em vez de MarketplaceAccount.
     * Nao importa produtos novos, nao altera preco, nao altera is_active.
     * Respeita ProductObserver.$disableSync (nao dispara loop legado).
     *
     * Regra: sem saldo no Bling = nao atualiza (nao zera inventory sem dado certo).
     *        Somente atualiza quando produto existe no banco por SKU.
     */
    public function syncStockForErpAccount(\App\Models\ErpAccount $account): array
    {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'pages' => 0];
        $supplierId = (int) $account->supplier_id;

        if (! $supplierId) {
            \Illuminate\Support\Facades\Log::warning('[BlingProductSync] syncStockForErpAccount: account sem supplier_id', ['account_id' => $account->id]);
            return $stats;
        }

        $hasProducts = Product::where('supplier_id', $supplierId)->where('is_active', true)->exists();
        if (! $hasProducts) {
            \Illuminate\Support\Facades\Log::info('[BlingProductSync] syncStockForErpAccount: sem produtos ativos', ['supplier_id' => $supplierId]);
            return $stats;
        }

        // MUL-334: /estoques/saldos do Bling v3 EXIGE idsProdutos — sem eles o client
        // devolve vazio sem nem chamar a API, e era isso que acontecia: o sync rodava com
        // pages=0 e updated=0 desde sempre. Primeiro lista o catalogo para obter os ids.
        $idsBling = [];
        $pagCat = 1;
        while ($pagCat <= 100) {
            try {
                $resp = $this->client->listProducts($account, $pagCat);
                $linhas = $resp['data'] ?? [];
                if (! is_array($linhas) || ! $linhas) {
                    break;
                }
                foreach ($linhas as $p) {
                    if (! empty($p['id'])) {
                        $idsBling[] = (int) $p['id'];
                    }
                }
                if (count($linhas) < 100) {
                    break;
                }
                $pagCat++;
                usleep(350000);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[BlingProductSync] syncStockForErpAccount: falha ao listar catalogo', [
                    'pagina' => $pagCat, 'error' => $e->getMessage(),
                ]);
                break;
            }
        }
        $idsBling = array_values(array_unique($idsBling));
        \Illuminate\Support\Facades\Log::info('[MUL-334] syncStockForErpAccount: ids do catalogo', [
            'supplier_id' => $supplierId, 'produtos' => count($idsBling),
        ]);

        foreach (array_chunk($idsBling, 10) as $lote) {
            try {
                $response = $this->client->listStock($account, 1, $lote);
                $items    = $response['data'] ?? [];
                $stats['pages']++;

                if (empty($items)) {
                    continue;
                }

                foreach ($items as $stockItem) {
                    try {
                        $blingProductId = $stockItem['produto']['id'] ?? null;

                        // MUL-334: saldo de VENDA e o virtual, nao o fisico. Provado em
                        // 05/08/2026 varrendo os 1.127 pedidos em situacao 6 contra os saldos:
                        // virtual = fisico - quantidade em pedido aberto, exato em 122 de 123,
                        // e nenhum dos 248 produtos com fisico = virtual tinha pedido aberto.
                        // Vender pelo fisico e vender unidade que ja tem dono: 237 produtos do
                        // MultDrop anunciavam a mais, somando 2.792 unidades inexistentes.
                        $quantity       = max(0, (int) ($stockItem['saldoVirtualTotal'] ?? $stockItem['saldoFisicoTotal'] ?? 0));

                        if (! $blingProductId) {
                            $stats['skipped']++;
                            continue;
                        }

                        // MUL-334: o proprio /estoques/saldos ja devolve produto.codigo.
                        // O getProduct por item custava uma chamada extra por produto (371 a
                        // mais por execucao, a 3 req/s do Bling) para buscar o que ja veio.
                        $sku = $stockItem['produto']['codigo'] ?? null;
                        if (! $sku) {
                            $blingProductData = $this->client->getProduct($account, $blingProductId);
                            $sku = $blingProductData['data']['codigo'] ?? null;
                            usleep(350000); // rate limit Bling: max 3 req/s
                        }

                        if (! $sku) {
                            $stats['skipped']++;
                            continue;
                        }

                        // MUL-334: no Bling e UM produto com UM saldo. Os catalogos do grupo
                        // (D498 matriz, D773 filial) sao o MESMO produto fisico com precos
                        // diferentes — vendeu num, baixou no outro. Entao o saldo alcanca todas
                        // as linhas com aquele erp_sku dentro do grupo.
                        //
                        // Antes disso a filial nunca recebia estoque: ela nao tem conta ERP e o
                        // saldo dela estava congelado na importacao do legado. Medido em
                        // 05/08/2026: 256 dos 332 pares divergiam, com casos como
                        // ARRASTAMOVEL-VRM anunciando 1.000 na filial contra 200 reais.
                        //
                        // MUL-331: casar pelo SKU com prefixo de deposito para os que ainda nao
                        // tem erp_sku. Sem isto o estoque do Bling acertava 1 produto ativo de
                        // 371 e escrevia o resto em registro desativado (04/08/2026).
                        $skuPrefixado = $this->prefixoDeposito($supplierId, $sku);
                        $alvos = Product::whereIn('supplier_id', $this->catalogosDoGrupo($supplierId))
                            ->where(function ($q) use ($sku, $skuPrefixado) {
                                $q->where('erp_sku', $sku)
                                  ->orWhereIn('sku', array_unique([$skuPrefixado, $sku]));
                            })
                            ->get(['id', 'supplier_id', 'sku']);

                        if ($alvos->isEmpty()) {
                            // Produto no Bling mas nao no banco — nao importar (etapa 1 so estoque)
                            $stats['skipped']++;
                            continue;
                        }

                        foreach ($alvos as $product) {
                            // warehouse_id e o supplier DAQUELA linha, nao o da conta ERP:
                            // cada catalogo guarda o proprio inventory.
                            $inventory = \App\Models\Inventory::firstOrNew([
                                'product_id'   => $product->id,
                                'warehouse_id' => (int) $product->supplier_id,
                                'producer_id'  => (int) $product->supplier_id,
                            ]);
                            $inventory->quantity = $quantity;
                            $inventory->save();

                            $product->virtual_stock_qty = $quantity;
                            $product->saveQuietly();

                            $stats['updated']++;
                        }

                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        \Illuminate\Support\Facades\Log::warning('[BlingProductSync] syncStockForErpAccount: item error', [
                            'bling_product_id' => $stockItem['produto']['id'] ?? null,
                            'error'            => $e->getMessage(),
                        ]);
                    }
                }

                usleep(350000);

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[BlingProductSync] syncStockForErpAccount: lote com erro', [
                    'lote'  => $lote ?? null,
                    'error' => $e->getMessage(),
                ]);
                continue;   // um lote ruim nao derruba a sincronizacao inteira
            }
        }

        return $stats;
    }

    /**
     * MUL-123: Normaliza URL removendo query string (parametros S3 presigned).
     * URLs Bling S3 tem mesmo path base mas assinatura diferente a cada sync.
     * Usar sempre o path base para comparar idempotencia.
     */
    private function urlBase(?string $url): string
    {
        if (!$url) return '';
        $pos = strpos($url, '?');
        return $pos !== false ? substr($url, 0, $pos) : $url;
    }

}