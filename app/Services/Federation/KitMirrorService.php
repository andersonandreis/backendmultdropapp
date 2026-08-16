<?php

namespace App\Services\Federation;

use App\Models\Client;
use App\Models\ClientKit;
use App\Models\ClientKitItem;
use App\Models\ClientProduct;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-236 F2 — aplica no WL o espelho de um kit vindo do hub (payload canônico
 * de KitFederationPayload). Usado na resposta síncrona do forward WL→hub e no
 * push hub→WL (/api/federation/kits/receive).
 *
 * Tradução: hub_product_id → products.hub_product_id local → client_products
 * em 2 níveis (product_id, depois custom_sku), criando registro mínimo se faltar.
 */
class KitMirrorService
{
    public function applyFromHub(array $payload): ?ClientKit
    {
        $legacy = (int) ($payload['client']['legacy_id_login'] ?? 0);
        $k      = (array) ($payload['kit'] ?? []);
        $sku    = $k['sku'] ?? null;

        if (! $legacy || ! $sku) {
            Log::warning('[KitMirror] payload sem legacy_id_login ou sku', ['payload_keys' => array_keys($payload)]);
            return null;
        }

        $client = Client::where('legacy_id_login', $legacy)->orderBy('id')->first();
        if (! $client) {
            Log::warning('[KitMirror] client local não encontrado pra legacy_id_login', ['legacy_id_login' => $legacy]);
            return null;
        }

        return DB::transaction(function () use ($client, $k, $sku, $payload) {
            $kit = null;
            if (! empty($k['previous_sku'])) {
                $kit = ClientKit::where('client_id', $client->id)->where('sku', $k['previous_sku'])->first();
            }
            $kit ??= ClientKit::where('client_id', $client->id)->where('sku', $sku)->first();

            $attrs = [
                'sku'         => $sku,
                'name'        => $k['name'] ?? ($kit->name ?? $sku),
                'description' => array_key_exists('description', $k) ? $k['description'] : ($kit->description ?? null),
                'price'       => array_key_exists('price', $k) ? $k['price'] : ($kit->price ?? null),
                'is_active'   => array_key_exists('is_active', $k) ? (bool) $k['is_active'] : (bool) ($kit->is_active ?? true),
            ];

            if ($kit) {
                $kit->update($attrs);
            } else {
                $kit = ClientKit::create(['client_id' => $client->id] + $attrs);
            }

            if (isset($payload['items']) && is_array($payload['items'])) {
                $resolved = [];
                foreach ($payload['items'] as $it) {
                    $hubPid  = (int) ($it['hub_product_id'] ?? 0);
                    $product = $hubPid
                        ? Product::withoutGlobalScopes()->where('hub_product_id', $hubPid)->orderBy('id')->first()
                        : null;

                    $cpId = null;
                    if ($product) {
                        $cpId = ClientProduct::where('client_id', $client->id)
                            ->where('product_id', $product->id)->orderBy('id')->value('id');
                    }
                    if (! $cpId && ! empty($it['custom_sku'])) {
                        $cpId = ClientProduct::where('client_id', $client->id)
                            ->where('custom_sku', $it['custom_sku'])->orderBy('id')->value('id');
                    }
                    if (! $cpId && $product) {
                        $cpId = ClientProduct::create([
                            'client_id'            => $client->id,
                            'product_id'           => $product->id,
                            'supplier_product_sku' => $it['supplier_product_sku'] ?? null,
                            'custom_sku'           => $it['custom_sku'] ?? null,
                            'custom_title'         => $it['custom_title'] ?? null,
                            'is_active'            => 1,
                        ])->id;
                    }
                    if (! $cpId) {
                        Log::warning('[KitMirror] componente sem correspondente local — item pulado', [
                            'kit_sku'        => $kit->sku,
                            'hub_product_id' => $hubPid,
                            'custom_sku'     => $it['custom_sku'] ?? null,
                        ]);
                        continue;
                    }
                    $resolved[] = ['client_product_id' => $cpId, 'quantity' => (int) ($it['quantity'] ?? 1)];
                }

                ClientKitItem::where('kit_id', $kit->id)->delete();
                foreach ($resolved as $r) {
                    ClientKitItem::create(['kit_id' => $kit->id] + $r);
                }
            }

            return $kit;
        });
    }
}
