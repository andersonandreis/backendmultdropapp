<?php

namespace App\Services\Pricing;

use App\Models\MarketplaceFee;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketplaceFeeService
{
    /**
     * Busca taxas do Mercado Livre via API autenticada.
     *
     * O endpoint GET /sites/MLB/listing_types exige Bearer token (OAuth).
     * Buscamos o token da primeira conta ML ativa que tenha access_token.
     * Se não houver conta autenticada, retorna 0 e loga aviso.
     *
     * Endpoint testado em 2026-05-09: requer autenticação — 403 sem token.
     */
    public function syncMercadoLivreFees(): int
    {
        $synced = 0;

        try {
            // Buscar conta ML ativa com token disponivel
            // ML usa ml_access_token (OAuth PKCE), nao o campo generico access_token
            $account = MarketplaceAccount::where('platform', 'mercadolivre')
                ->whereNotNull('ml_access_token')
                ->whereNull('sync_blocked_at')
                ->first();

            if (!$account) {
                Log::warning('ML fee sync: nenhuma conta Mercado Livre com ml_access_token encontrada. Use fees:sync --seed para defaults.');
                return 0;
            }

            $response = Http::withToken($account->ml_access_token)
                ->get('https://api.mercadolibre.com/sites/MLB/listing_types');

            if (!$response->successful()) {
                Log::error('ML fee sync: falha ao buscar listing types', [
                    'status'     => $response->status(),
                    'body'       => $response->body(),
                    'account_id' => $account->id,
                ]);
                return 0;
            }

            $listingTypes = $response->json();

            foreach ($listingTypes as $type) {
                $typeId = $type['id'] ?? null;
                if (!$typeId) {
                    continue;
                }

                // Buscar detalhes de cada listing type
                $detailResponse = Http::withToken($account->ml_access_token)
                    ->get("https://api.mercadolibre.com/sites/MLB/listing_types/{$typeId}");

                if (!$detailResponse->successful()) {
                    Log::warning("ML fee sync: falha ao buscar detalhe de {$typeId}", [
                        'status' => $detailResponse->status(),
                    ]);
                    continue;
                }

                $detail = $detailResponse->json();

                // sale_fee_amount pode ser percentual ou fixo dependendo do tipo
                $feePercentage = $detail['sale_fee_amount'] ?? null;

                if ($feePercentage === null) {
                    continue;
                }

                MarketplaceFee::updateOrCreate(
                    [
                        'platform'        => 'mercadolivre',
                        'listing_type_id' => $typeId,
                    ],
                    [
                        'category_name' => $type['name'] ?? $typeId,
                        'fee_percentage' => (float) $feePercentage,
                        'fixed_fee'     => 0,
                        'is_active'     => true,
                        'source'        => 'api',
                    ]
                );

                $synced++;
            }

            Log::info("ML fee sync: {$synced} listing types sincronizados via API");
        } catch (\Exception $e) {
            Log::error('ML fee sync: erro inesperado', ['error' => $e->getMessage()]);
        }

        return $synced;
    }

    /**
     * Cria defaults de taxas para todos os marketplaces suportados.
     * Usa firstOrCreate — não sobrescreve edições manuais existentes.
     * Chamado via fees:sync --seed ou fees:sync all.
     */
    public function seedDefaultFees(): int
    {
        $seeded = 0;

        $defaults = [
            // Shopee (documentacao publica — taxas comissionais por categoria)
            ['platform' => 'shopee', 'category_name' => 'Geral',           'listing_type_id' => 'standard',    'fee_percentage' => 14.0, 'fixed_fee' => 0],
            ['platform' => 'shopee', 'category_name' => 'Eletronicos',     'listing_type_id' => 'electronics', 'fee_percentage' => 12.0, 'fixed_fee' => 0],
            ['platform' => 'shopee', 'category_name' => 'Moda',            'listing_type_id' => 'fashion',     'fee_percentage' => 16.0, 'fixed_fee' => 0],
            ['platform' => 'shopee', 'category_name' => 'Casa e Decoracao','listing_type_id' => 'home',        'fee_percentage' => 14.0, 'fixed_fee' => 0],

            // Magalu
            ['platform' => 'magalu', 'category_name' => 'Geral',       'listing_type_id' => 'standard',    'fee_percentage' => 16.0, 'fixed_fee' => 0],
            ['platform' => 'magalu', 'category_name' => 'Eletronicos', 'listing_type_id' => 'electronics', 'fee_percentage' => 12.0, 'fixed_fee' => 0],

            // Amazon
            ['platform' => 'amazon', 'category_name' => 'Geral',       'listing_type_id' => 'standard',    'fee_percentage' => 15.0, 'fixed_fee' => 0],
            ['platform' => 'amazon', 'category_name' => 'Eletronicos', 'listing_type_id' => 'electronics', 'fee_percentage' =>  8.0, 'fixed_fee' => 0],
            ['platform' => 'amazon', 'category_name' => 'Moda',        'listing_type_id' => 'fashion',     'fee_percentage' => 17.0, 'fixed_fee' => 0],

            // TikTok Shop
            ['platform' => 'tiktok', 'category_name' => 'Geral', 'listing_type_id' => 'standard', 'fee_percentage' => 5.0, 'fixed_fee' => 0],

            // Mercado Livre — fallback defaults caso sync via API nao funcione
            ['platform' => 'mercadolivre', 'category_name' => 'Classico', 'listing_type_id' => 'gold_special', 'fee_percentage' => 16.0, 'fixed_fee' => 0],
            ['platform' => 'mercadolivre', 'category_name' => 'Premium',  'listing_type_id' => 'gold_pro',     'fee_percentage' => 19.0, 'fixed_fee' => 0],
        ];

        foreach ($defaults as $fee) {
            MarketplaceFee::firstOrCreate(
                [
                    'platform'        => $fee['platform'],
                    'listing_type_id' => $fee['listing_type_id'],
                ],
                array_merge($fee, [
                    'is_active' => true,
                    'source'    => 'default',
                ])
            );
            $seeded++;
        }

        return $seeded;
    }

    /**
     * Calcula o valor da taxa de marketplace para um produto.
     *
     * Prioridade de busca:
     * 1. listing_type_id + category_id exatos
     * 2. listing_type_id sem category_id
     * 3. listing_type_id = 'standard' (fallback generico da plataforma)
     *
     * Respeita faixas de preco (min_price/max_price) se configuradas.
     */
    public function calculateFee(
        string $platform,
        ?string $categoryId,
        ?string $listingType,
        float $price
    ): float {
        $base = MarketplaceFee::where('platform', $platform)
            ->where('is_active', true)
            ->where(fn($q) => $q->whereNull('min_price')->orWhere('min_price', '<=', $price))
            ->where(fn($q) => $q->whereNull('max_price')->orWhere('max_price', '>=', $price));

        $fee = null;

        // Tentativa 1: listing_type_id + category_id
        if ($listingType && $categoryId) {
            $fee = (clone $base)
                ->where('listing_type_id', $listingType)
                ->where('category_id', $categoryId)
                ->first();
        }

        // Tentativa 2: listing_type_id sem category
        if (!$fee && $listingType) {
            $fee = (clone $base)
                ->where('listing_type_id', $listingType)
                ->whereNull('category_id')
                ->orWhere(fn($q) => $q
                    ->where('platform', $platform)
                    ->where('is_active', true)
                    ->where('listing_type_id', $listingType)
                )
                ->first();
        }

        // Tentativa 3: fallback 'standard' da plataforma
        if (!$fee) {
            $fee = (clone $base)
                ->where('listing_type_id', 'standard')
                ->first();
        }

        if (!$fee) {
            return 0.0;
        }

        $feeAmount = ($price * ($fee->fee_percentage / 100)) + ((float) ($fee->fixed_fee ?? 0));

        return round($feeAmount, 2);
    }

    /**
     * Plataformas suportadas com labels em PT-BR.
     */
    public static function supportedPlatforms(): array
    {
        return [
            'mercadolivre' => 'Mercado Livre',
            'shopee'       => 'Shopee',
            'magalu'       => 'Magalu',
            'amazon'       => 'Amazon',
            'tiktok'       => 'TikTok Shop',
            'b2w'          => 'B2W / Americanas',
        ];
    }
}
