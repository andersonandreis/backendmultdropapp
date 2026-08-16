<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FederationPushKitJob;
use App\Models\Client;
use App\Models\ClientKit;
use App\Models\ClientKitItem;
use App\Models\ClientProduct;
use App\Services\Federation\KitFederationPayload;
use App\Services\Federation\KitMirrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KitController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) abort(403, 'Usuario nao possui perfil de lojista.');
        return $client;
    }

    // ------------------------------------------------------------------
    // MUL-236 F2 — hub e o dono do cadastro de kits; WL encaminha writes
    // ------------------------------------------------------------------

    /** WL com KITS_HUB_AUTHORITY=true encaminha writes de kit pro hub */
    private function kitsHubAuthority(): bool
    {
        return config('federation.tenant') !== 'hubai'
            && config('federation.kits_hub_authority')
            && config('federation.hub_url')
            && config('federation.hub_token');
    }

    private function forwardKitToHub(string $path, array $payload): array
    {
        $resp = Http::withToken(config('federation.hub_token'))
            ->timeout(20)
            ->post(rtrim(config('federation.hub_url'), '/') . $path, $payload);

        if ($resp->failed()) {
            Log::error('[KitController] hub rejeitou write de kit', [
                'path'   => $path,
                'status' => $resp->status(),
                'body'   => substr((string) $resp->body(), 0, 500),
            ]);
            $msg = $resp->json('message') ?: 'Hub indisponivel pra salvar o kit — tente novamente.';
            abort($resp->status() >= 500 ? 502 : $resp->status(), $msg);
        }

        return (array) $resp->json('data');
    }

    /** Traduz items locais (client_product_id do WL) pro payload canonico do hub */
    private function buildHubItemsPayload($client, array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $cp = ClientProduct::where('id', $it['client_product_id'])
                ->where('client_id', $client->id)
                ->with('product:id,sku,hub_product_id')
                ->first();
            $hubPid = $cp?->product?->hub_product_id;
            if (! $hubPid) {
                $label = $cp?->custom_sku ?: ($cp?->product?->sku ?: ('#' . $it['client_product_id']));
                abort(422, "Produto {$label} ainda nao sincronizado com o hub — nao pode compor kit.");
            }
            $out[] = [
                'hub_product_id'       => (int) $hubPid,
                'custom_sku'           => $cp->custom_sku,
                'supplier_product_sku' => $cp->supplier_product_sku,
                'custom_title'         => $cp->custom_title,
                'quantity'             => (int) $it['quantity'],
            ];
        }
        return $out;
    }

    private function legacyIdOrFail($client): int
    {
        if (! $client->legacy_id_login) {
            abort(422, 'Cliente sem ID canonico (legacy_id_login) — nao e possivel sincronizar kit com o hub.');
        }
        return (int) $client->legacy_id_login;
    }

    /** Enriquece os items de um kit com nome/sku/preco/imagem do produto */
    private function enrichItems(ClientKit $kit): void
    {
        $cpIds = $kit->items->pluck('client_product_id')->filter()->unique()->values();
        if ($cpIds->isEmpty()) return;

        $cps = ClientProduct::whereIn('id', $cpIds)
            ->with(['product:id,name,sku,price,cost', 'product.media:id,product_id,url,type'])
            ->get()
            ->keyBy('id');

        $prodIds = $cps->pluck('product.id')->filter()->unique()->values();
        $invSums = $prodIds->isEmpty() ? collect() : DB::table('inventory')
            ->whereIn('product_id', $prodIds)
            ->select('product_id', DB::raw('COALESCE(SUM(quantity),0) as qty'))
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $kit->items->each(function (ClientKitItem $item) use ($cps, $invSums) {
            $cp = $cps->get($item->client_product_id);
            if (!$cp) return;

            $product = $cp->product;
            $name  = $cp->custom_title  ?: ($product?->name  ?? '');
            $sku   = $cp->custom_sku    ?: ($product?->sku   ?? '');
            $price = (float) ($cp->custom_price ?? 0);
            if ($price <= 0) { $price = (float) ($product?->price ?? 0); }
            if ($price <= 0) { $price = (float) ($product?->cost ?? 0); }
            $image = $cp->image_url
                ?? ($cp->custom_images[0] ?? null)
                ?? ($product?->media->where('type', 'image')->first()?->url ?? null);

            $item->setAttribute('product_name',  $name);
            $item->setAttribute('product_sku',   $sku);
            $item->setAttribute('product_price', (float) $price);
            $item->setAttribute('product_image', $image);
            $item->setAttribute('product_stock', $product ? (int) ($invSums[$product->id] ?? 0) : 0);
        });
        // MUL-214 item 15: enriquecer com dados da variação se aplicável
        $varIds = $kit->items->pluck('product_variation_id')->filter()->unique()->values();
        if (!$varIds->isEmpty()) {
            $vars = \App\Models\ProductVariation::whereIn('id', $varIds)->get()->keyBy('id');
            $kit->items->each(function (ClientKitItem $item) use ($vars) {
                if (! $item->product_variation_id) return;
                $v = $vars->get($item->product_variation_id);
                if (!$v) return;
                $item->setAttribute('variation_sku', $v->sku);
                $item->setAttribute('variation_name', $v->name);
                $item->setAttribute('variation_price', (float) $v->price);
                $item->setAttribute('variation_stock', (int) ($v->virtual_stock_qty ?? 0));
            });
        }
    }

    /** Gera SKU automatico no formato KIT-<SLUG>-<SEQ> */
    private function generateSku(string $name, int $clientId): string
    {
        $base = Str::upper(Str::slug(Str::limit($name, 10, ''), ''));
        $base = preg_replace('/[^A-Z0-9]/', '', $base) ?: 'KIT';
        $prefix = 'KIT-' . $base . '-';

        $last = ClientKit::where('client_id', $clientId)
            ->where('sku', 'LIKE', $prefix . '%')
            ->orderByDesc('id')
            ->value('sku');

        $seq = 1;
        if ($last) {
            $parts = explode('-', $last);
            $seq = (int) end($parts) + 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $kits = ClientKit::where('client_id', $client->id)
            ->with('items')
            ->latest()
            ->get();

        $kits->each(fn($k) => $this->enrichItems($k));

        // MUL-214 item 28: vinculo com o Product proprio do kit (por sku) + estoque derivado
        $skus = $kits->pluck('sku')->filter()->unique()->values();
        $prodBySku = $skus->isEmpty() ? collect() : DB::table('products')
            ->whereIn('sku', $skus)->orderBy('id')->pluck('id', 'sku');
        $kits->each(function ($k) use ($prodBySku) {
            $k->setAttribute('product_id', isset($prodBySku[$k->sku]) ? (int) $prodBySku[$k->sku] : null);
            $stocks = $k->items->map(fn($it) => intdiv((int) ($it->variation_stock ?? $it->product_stock ?? 0), max(1, (int) $it->quantity)));
            $k->setAttribute('kit_stock', $k->items->isEmpty() ? 0 : (int) $stocks->min());
        });

        return response()->json(['data' => $kits]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $kit = ClientKit::where('id', $id)->where('client_id', $client->id)
            ->with('items')
            ->firstOrFail();

        $this->enrichItems($kit);

        return response()->json(['data' => $kit]);
    }

    /** MUL-214 item 28: garante o Product proprio do kit (cria on-demand) e devolve dados pro fluxo de cadastro */
    public function ensureProduct(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $kit = ClientKit::where('id', $id)->where('client_id', $client->id)
            ->with('items')
            ->firstOrFail();
        if ($kit->items->isEmpty()) {
            abort(422, 'Kit sem componentes.');
        }
        $this->enrichItems($kit);

        $kitStock = (int) $kit->items
            ->map(fn($it) => intdiv((int) ($it->variation_stock ?? $it->product_stock ?? 0), max(1, (int) $it->quantity)))
            ->min();

        $product = \App\Models\Product::where('sku', $kit->sku)->orderBy('id')->first();
        if (!$product) {
            $cpIds = $kit->items->pluck('client_product_id')->filter()->unique()->values();
            $cps = ClientProduct::whereIn('id', $cpIds)->with('product:id,supplier_id')->get();
            $supplierId = $cps->first(fn($cp) => $cp->product?->supplier_id)?->product?->supplier_id;
            $cost = $kit->items->reduce(
                fn($s, $it) => $s + ((float) ($it->product_price ?? 0)) * max(1, (int) $it->quantity), 0.0
            );

            $product = \App\Models\Product::create([
                'sku'         => $kit->sku,
                'name'        => $kit->name,
                'description' => $kit->description,
                'price'       => round($cost, 2),
                'cost'        => round($cost, 2),
                'supplier_id' => $supplierId,
                'is_active'   => 0,
            ]);

            $pos = 0;
            foreach ($kit->items as $it) {
                $url = $it->product_image ?? null;
                if (!$url) continue;
                DB::table('product_media')->insert([
                    'product_id' => $product->id,
                    'type'       => 'image',
                    'url'        => $url,
                    'position'   => $pos,
                    'is_cover'   => $pos === 0 ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (++$pos >= 8) break;
            }

            // inventory snapshot do estoque derivado (min floor(estoque_componente/qtd));
            // recalculo continuo de estoque de kit e feature separada (mesmo modelo snapshot do legado)
            if ($kitStock > 0) {
                $prodIds = $cps->pluck('product.id')->filter()->values();
                $ref = $prodIds->isEmpty() ? null : DB::table('inventory')->whereIn('product_id', $prodIds)->first();
                if ($ref) {
                    DB::table('inventory')->insert([
                        'warehouse_id' => $ref->warehouse_id,
                        'product_id'   => $product->id,
                        'producer_id'  => $ref->producer_id,
                        'quantity'     => $kitStock,
                        'reserved'     => 0,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }
        }

        $image = $kit->items->firstWhere('product_image', '!=', null)?->product_image;

        return response()->json(['data' => [
            'product_id' => $product->id,
            'name'       => $kit->name,
            'sku'        => $kit->sku,
            'price'      => (float) ($product->price ?? 0),
            'stock'      => $kitStock,
            'image'      => $image,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $data = $request->validate([
            'name'                      => 'required|string|max:200',
            'sku'                       => 'nullable|string|max:100',
            'description'               => 'nullable|string|max:1000',
            'price'                     => 'nullable|numeric|min:0',
            'is_active'                 => 'nullable|boolean',
            'items'                     => 'required|array|min:1',
            'items.*.client_product_id' => 'required|integer|exists:client_products,id',
            'items.*.product_variation_id' => 'nullable|integer|exists:product_variations,id',
            'items.*.quantity'          => 'required|integer|min:1',
        ]);

        $cpIds = collect($data['items'])->pluck('client_product_id');
        $owned = ClientProduct::whereIn('id', $cpIds)->where('client_id', $client->id)->count();
        if ($owned !== $cpIds->count()) {
            abort(422, 'Um ou mais produtos nao pertencem a este lojista.');
        }

        // MUL-236 F2: WL nao grava — hub e a fonte de verdade; espelho local
        // vem da resposta sincrona do hub (sem janela de inconsistencia)
        if ($this->kitsHubAuthority()) {
            $user = $request->user();
            $hubKit = $this->forwardKitToHub('/api/federation/kits/upsert', [
                'client' => [
                    'legacy_id_login' => $this->legacyIdOrFail($client),
                    'email'           => $user->email,
                    'name'            => $user->name,
                    'company_name'    => $client->company_name,
                    'document'        => $client->document,
                ],
                'kit' => [
                    'sku'         => $data['sku'] ?? null,
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? null,
                    'price'       => $data['price'] ?? null,
                    'is_active'   => $data['is_active'] ?? true,
                ],
                'items' => $this->buildHubItemsPayload($client, $data['items']),
            ]);

            $kit = app(KitMirrorService::class)->applyFromHub($hubKit);
            if (! $kit) abort(500, 'Kit salvo no hub, mas falhou o espelho local — ver logs.');
            $kit->load('items');
            $this->enrichItems($kit);

            return response()->json(['data' => $kit], 201);
        }

        return DB::transaction(function () use ($data, $client) {
            $sku = !empty($data['sku'])
                ? $data['sku']
                : $this->generateSku($data['name'], $client->id);

            $kit = ClientKit::create([
                'client_id'   => $client->id,
                'name'        => $data['name'],
                'sku'         => $sku,
                'description' => $data['description'] ?? null,
                'price'       => $data['price'] ?? null,
                'is_active'   => $data['is_active'] ?? true,
            ]);

            foreach ($data['items'] as $it) {
                ClientKitItem::create([
                    'kit_id'               => $kit->id,
                    'client_product_id'    => $it['client_product_id'],
                    'product_variation_id' => $it['product_variation_id'] ?? null,
                    'quantity'             => $it['quantity'],
                ]);
            }

            $kit->load('items');
            $this->enrichItems($kit);

            return response()->json(['data' => $kit], 201);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $kit = ClientKit::where('id', $id)->where('client_id', $client->id)->firstOrFail();

        $data = $request->validate([
            'name'                      => 'nullable|string|max:200',
            'sku'                       => 'nullable|string|max:100',
            'description'               => 'nullable|string|max:1000',
            'price'                     => 'nullable|numeric|min:0',
            'is_active'                 => 'nullable|boolean',
            'items'                     => 'nullable|array',
            'items.*.client_product_id' => 'required_with:items|integer|exists:client_products,id',
            'items.*.product_variation_id' => 'nullable|integer|exists:product_variations,id',
            'items.*.quantity'          => 'required_with:items|integer|min:1',
        ]);

        if (!empty($data['items'])) {
            $cpIds = collect($data['items'])->pluck('client_product_id');
            $owned = ClientProduct::whereIn('id', $cpIds)->where('client_id', $client->id)->count();
            if ($owned !== $cpIds->count()) {
                abort(422, 'Um ou mais produtos nao pertencem a este lojista.');
            }
        }

        // MUL-236 F2: edicao na WL vai pro hub; espelho local vem da resposta
        if ($this->kitsHubAuthority()) {
            $kitPayload = ['original_sku' => $kit->sku];
            foreach (['name', 'sku', 'description', 'price', 'is_active'] as $f) {
                if (array_key_exists($f, $data)) $kitPayload[$f] = $data[$f];
            }
            $payload = [
                'client' => ['legacy_id_login' => $this->legacyIdOrFail($client)],
                'kit'    => $kitPayload,
            ];
            if (isset($data['items'])) {
                $payload['items'] = $this->buildHubItemsPayload($client, $data['items']);
            }

            $hubKit = $this->forwardKitToHub('/api/federation/kits/upsert', $payload);

            $mirrored = app(KitMirrorService::class)->applyFromHub($hubKit);
            if (! $mirrored) abort(500, 'Kit salvo no hub, mas falhou o espelho local — ver logs.');
            $mirrored->load('items');
            $this->enrichItems($mirrored);

            return response()->json(['data' => $mirrored]);
        }

        $previousSku = $kit->sku;

        return DB::transaction(function () use ($data, $kit, $previousSku) {
            $kit->update(collect($data)->only(['name', 'sku', 'description', 'price', 'is_active'])->toArray());

            if (isset($data['items'])) {
                ClientKitItem::where('kit_id', $kit->id)->delete();
                foreach ($data['items'] as $it) {
                    ClientKitItem::create([
                        'kit_id'               => $kit->id,
                        'client_product_id'    => $it['client_product_id'],
                        'product_variation_id' => $it['product_variation_id'] ?? null,
                        'quantity'             => $it['quantity'],
                    ]);
                }
            }

            // MUL-236 F2: edicao feita no proprio hub sincroniza a WL de origem
            if (config('federation.tenant') === 'hubai' && $kit->source_tenant) {
                FederationPushKitJob::dispatch($kit->id, $previousSku)->afterCommit();
            }

            $kit->load('items');
            $this->enrichItems($kit);

            return response()->json(['data' => $kit]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $kit = ClientKit::where('id', $id)->where('client_id', $client->id)->firstOrFail();

        if ($this->kitsHubAuthority()) {
            $this->forwardKitToHub('/api/federation/kits/deactivate', [
                'client' => ['legacy_id_login' => $this->legacyIdOrFail($client)],
                'kit'    => ['sku' => $kit->sku],
            ]);
        }

        // MUL-236 F2 (Ruan 19/07): NUNCA apagar a linha — historico de explosao
        // (order_items.client_kit_id) referencia o kit. is_active=0 some da tela
        // e para de explodir, historico fica integro.
        $kit->update(['is_active' => false]);

        if (config('federation.tenant') === 'hubai' && $kit->source_tenant) {
            FederationPushKitJob::dispatch($kit->id);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ------------------------------------------------------------------
    // MUL-236 F2 — endpoints de federacao (hub, auth.federation Bearer)
    // ------------------------------------------------------------------

    /** Resolve client do hub por legacy_id_login; cria registro MINIMO (id/email/nome) se faltar */
    private function resolveOrCreateClient(array $c): Client
    {
        $legacy = (int) $c['legacy_id_login'];
        $client = Client::where('legacy_id_login', $legacy)->orderBy('id')->first();
        if ($client) return $client;

        if (empty($c['email']) || empty($c['name'])) {
            abort(422, "Cliente legacy {$legacy} sem correspondente no hub e payload sem email/nome pra criar registro minimo.");
        }

        $user = \App\Models\User::where('email', $c['email'])->orderBy('id')->first();
        if (! $user) {
            $user = \App\Models\User::create([
                'name'     => $c['name'],
                'email'    => $c['email'],
                'password' => bcrypt(bin2hex(random_bytes(24))),
            ]);
        }

        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        return Client::create([
            'user_id'         => $user->id,
            'document'        => $c['document'] ?? null,
            'legacy_id_login' => $legacy,
        ]);
    }

    /**
     * POST /api/federation/kits/upsert — WL encaminha create/update de kit.
     * Hub grava (fonte de verdade) e devolve o payload canonico pro espelho.
     */
    public function upsertFromFederation(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');

        $data = $request->validate([
            'client.legacy_id_login'       => 'required|integer|min:1',
            'client.email'                 => 'nullable|email|max:190',
            'client.name'                  => 'nullable|string|max:200',
            'client.company_name'          => 'nullable|string|max:200',
            'client.document'              => 'nullable|string|max:50',
            'kit.sku'                      => 'nullable|string|max:100',
            'kit.original_sku'             => 'nullable|string|max:100',
            'kit.name'                     => 'nullable|string|max:200',
            'kit.description'              => 'nullable|string|max:1000',
            'kit.price'                    => 'nullable|numeric|min:0',
            'kit.is_active'                => 'nullable|boolean',
            'items'                        => 'nullable|array',
            'items.*.hub_product_id'       => 'required_with:items|integer|min:1',
            'items.*.custom_sku'           => 'nullable|string|max:190',
            'items.*.supplier_product_sku' => 'nullable|string|max:190',
            'items.*.custom_title'         => 'nullable|string|max:255',
            'items.*.quantity'             => 'required_with:items|integer|min:1',
        ]);

        $client = $this->resolveOrCreateClient($data['client']);
        $k = $data['kit'] ?? [];

        return DB::transaction(function () use ($data, $client, $k, $tenant) {
            $lookupSku = $k['original_sku'] ?? ($k['sku'] ?? null);
            $kit = $lookupSku
                ? ClientKit::where('client_id', $client->id)->where('sku', $lookupSku)->first()
                : null;

            if ($kit) {
                $upd = ['source_tenant' => $tenant];
                foreach (['name', 'description', 'price'] as $f) {
                    if (array_key_exists($f, $k)) $upd[$f] = $k[$f];
                }
                if (array_key_exists('is_active', $k)) $upd['is_active'] = (bool) $k['is_active'];
                if (! empty($k['sku'])) $upd['sku'] = $k['sku'];
                $kit->update($upd);
            } else {
                if (empty($k['name'])) {
                    abort(422, 'Nome do kit obrigatorio na criacao.');
                }
                $kit = ClientKit::create([
                    'client_id'     => $client->id,
                    'name'          => $k['name'],
                    'sku'           => ! empty($k['sku']) ? $k['sku'] : $this->generateSku($k['name'], $client->id),
                    'description'   => $k['description'] ?? null,
                    'price'         => $k['price'] ?? null,
                    'is_active'     => $k['is_active'] ?? true,
                    'source_tenant' => $tenant,
                ]);
            }

            if (isset($data['items'])) {
                $resolved = [];
                foreach ($data['items'] as $it) {
                    $pid = (int) $it['hub_product_id'];
                    if (! DB::table('products')->where('id', $pid)->exists()) {
                        abort(422, "Produto hub {$pid} inexistente — kit nao salvo.");
                    }
                    $cpId = ClientProduct::where('client_id', $client->id)
                        ->where('product_id', $pid)->orderBy('id')->value('id');
                    if (! $cpId && ! empty($it['custom_sku'])) {
                        $cpId = ClientProduct::where('client_id', $client->id)
                            ->where('custom_sku', $it['custom_sku'])->orderBy('id')->value('id');
                    }
                    if (! $cpId) {
                        $cpId = ClientProduct::create([
                            'client_id'            => $client->id,
                            'product_id'           => $pid,
                            'supplier_product_sku' => $it['supplier_product_sku'] ?? null,
                            'custom_sku'           => $it['custom_sku'] ?? null,
                            'custom_title'         => $it['custom_title'] ?? null,
                            'is_active'            => 1,
                        ])->id;
                    }
                    $resolved[] = ['client_product_id' => $cpId, 'quantity' => (int) $it['quantity']];
                }

                ClientKitItem::where('kit_id', $kit->id)->delete();
                foreach ($resolved as $r) {
                    ClientKitItem::create(['kit_id' => $kit->id] + $r);
                }
            }

            $kit->load('items');

            Log::info('[KitController] upsert via federation', [
                'tenant'  => $tenant,
                'kit_id'  => $kit->id,
                'kit_sku' => $kit->sku,
                'client'  => $client->id,
            ]);

            return response()->json(['data' => KitFederationPayload::build($client, $kit, $lookupSku)]);
        });
    }

    /**
     * POST /api/federation/kits/deactivate — WL encaminha "excluir" de kit.
     * Regra Ruan 19/07: is_active=0, nunca delete (historico de explosao).
     */
    public function deactivateFromFederation(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('federation_tenant');

        $data = $request->validate([
            'client.legacy_id_login' => 'required|integer|min:1',
            'kit.sku'                => 'required|string|max:100',
        ]);

        $client = Client::where('legacy_id_login', (int) $data['client']['legacy_id_login'])->orderBy('id')->first();
        if (! $client) {
            return response()->json(['data' => ['deactivated' => false, 'reason' => 'client_not_found']]);
        }

        $kit = ClientKit::where('client_id', $client->id)->where('sku', $data['kit']['sku'])->first();
        if (! $kit) {
            return response()->json(['data' => ['deactivated' => false, 'reason' => 'kit_not_found']]);
        }

        $kit->update(['is_active' => false, 'source_tenant' => $kit->source_tenant ?: $tenant]);

        Log::info('[KitController] deactivate via federation', [
            'tenant'  => $tenant,
            'kit_id'  => $kit->id,
            'kit_sku' => $kit->sku,
        ]);

        return response()->json(['data' => ['deactivated' => true, 'kit' => KitFederationPayload::build($client, $kit)]]);
    }

    /**
     * GET /api/v1/kits/catalog
     * Lista produtos do catalogo do seller para picker do kit.
     */
    public function catalog(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $search  = $request->input('q', '');
        $perPage = min((int) $request->input('per_page', 50), 200);

        // MUL-214 item 15: variacoes de produto pra kit
        $query = ClientProduct::where('client_id', $client->id)
            ->where('is_active', true)
            ->with([
                'product:id,name,sku,price,cost',
                'product.media:id,product_id,url,type',
                'product.variations:id,product_id,sku,name,price,virtual_stock_qty,is_active',
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', fn($pq) => $pq->where('name', 'LIKE', "%{$search}%")->orWhere('sku', 'LIKE', "%{$search}%"))
                  ->orWhere('custom_title', 'LIKE', "%{$search}%")
                  ->orWhere('custom_sku', 'LIKE', "%{$search}%");
            });
        }

        $items = $query->latest()->paginate($perPage);

        $mapped = $items->getCollection()->map(function (ClientProduct $cp) {
            $product = $cp->product;
            return [
                'id'    => $cp->id,
                'name'  => $cp->custom_title  ?: ($product?->name  ?? ''),
                'sku'   => $cp->custom_sku    ?: ($product?->sku   ?? ''),
                'price' => max((float) ($cp->custom_price ?? 0), 0) > 0 ? (float) $cp->custom_price : (((float) ($product?->price ?? 0)) > 0 ? (float) $product->price : (float) ($product?->cost ?? 0)),
                'image' => $cp->image_url
                    ?? ($cp->custom_images[0] ?? null)
                    ?? ($product?->media->where('type', 'image')->first()?->url ?? null),
                // MUL-214 item 15: variacoes ativas pro seller escolher
                'variations' => $product?->variations?->where('is_active', 1)->map(fn($v) => [
                    'id'                => (int) $v->id,
                    'sku'               => (string) $v->sku,
                    'name'              => (string) $v->name,
                    'price'             => (float) $v->price,
                    'virtual_stock_qty' => (int) ($v->virtual_stock_qty ?? 0),
                ])->values()->all() ?? [],
            ];
        });

        return response()->json([
            'data' => $mapped,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }
}
