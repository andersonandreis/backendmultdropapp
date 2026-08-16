<?php

namespace App\Services\Federation;

use App\Models\Client;
use App\Models\ClientKit;
use App\Models\ClientProduct;

/**
 * MUL-236 F2 — payload canônico de kit trocado entre hub e WLs.
 *
 * Componentes viajam por hub_product_id (ID canônico do catálogo) +
 * custom_sku/custom_title do client_product — nunca por IDs locais.
 */
class KitFederationPayload
{
    public static function build(Client $client, ClientKit $kit, ?string $previousSku = null): array
    {
        $kit->loadMissing('items');
        $cpIds = $kit->items->pluck('client_product_id')->filter()->unique()->values();
        $cps = $cpIds->isEmpty()
            ? collect()
            : ClientProduct::whereIn('id', $cpIds)->get()->keyBy('id');

        return [
            'client' => [
                'legacy_id_login' => (int) $client->legacy_id_login,
            ],
            'kit' => [
                'hub_kit_id'    => $kit->id,
                'sku'           => $kit->sku,
                'previous_sku'  => ($previousSku && $previousSku !== $kit->sku) ? $previousSku : null,
                'name'          => $kit->name,
                'description'   => $kit->description,
                'price'         => $kit->price !== null ? (float) $kit->price : null,
                'is_active'     => (bool) $kit->is_active,
                'source_tenant' => $kit->source_tenant,
            ],
            'items' => $kit->items->map(function ($it) use ($cps) {
                $cp = $cps->get($it->client_product_id);

                return [
                    'hub_product_id'       => $cp?->product_id ? (int) $cp->product_id : null,
                    'custom_sku'           => $cp?->custom_sku,
                    'supplier_product_sku' => $cp?->supplier_product_sku,
                    'custom_title'         => $cp?->custom_title,
                    'quantity'             => (int) $it->quantity,
                ];
            })->values()->all(),
        ];
    }
}
