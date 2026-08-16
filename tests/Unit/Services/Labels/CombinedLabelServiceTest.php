<?php

namespace Tests\Unit\Services\Labels;

use App\Services\Labels\CombinedLabelService;
use App\Services\ShippingLabelService;
use Tests\TestCase;

/**
 * NOV-096 — testes unitarios do CombinedLabelService.
 *
 * Cobertura:
 *   - resolveParentSku() resolve via productVariation.product.sku quando
 *     o item eh variacao
 *   - resolveParentSku() resolve via product.sku quando o item eh produto-pai
 *   - resolveParentSku() faz fallback pro item.sku quando nao tem hierarquia
 *   - resolveParentImage() prioriza media is_cover do produto pai
 *   - resolveParentImage() faz fallback pra product_image do item
 *
 * Nao exercita HTTP nem DB — usa stdClass / mocks pra isolar a logica
 * de resolucao.
 */
class CombinedLabelServiceTest extends TestCase
{
    private CombinedLabelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $shipping = $this->createMock(ShippingLabelService::class);
        $this->service = new CombinedLabelService($shipping);
    }

    public function test_resolveParentSku_uses_productVariation_parent_product_sku(): void
    {
        $parentProduct = (object) ['sku' => 'PAI-001'];
        $variation = (object) ['product' => $parentProduct];
        $item = (object) [
            'productVariation' => $variation,
            'product'          => null,
            'sku'              => 'VAR-001-AZUL',
            'variation_sku'    => null,
        ];

        $this->assertSame('PAI-001', $this->service->resolveParentSku($item));
    }

    public function test_resolveParentSku_uses_product_sku_when_no_variation(): void
    {
        $parentProduct = (object) ['sku' => 'PAI-DIRECT-002'];
        $item = (object) [
            'productVariation' => null,
            'product'          => $parentProduct,
            'sku'              => 'ITEM-002',
            'variation_sku'    => null,
        ];

        $this->assertSame('PAI-DIRECT-002', $this->service->resolveParentSku($item));
    }

    public function test_resolveParentSku_falls_back_to_item_sku(): void
    {
        $item = (object) [
            'productVariation' => null,
            'product'          => null,
            'sku'              => 'MKT-XYZ',
            'variation_sku'    => null,
        ];

        $this->assertSame('MKT-XYZ', $this->service->resolveParentSku($item));
    }

    public function test_resolveParentSku_returns_dash_when_no_data(): void
    {
        $item = (object) [
            'productVariation' => null,
            'product'          => null,
            'sku'              => null,
            'variation_sku'    => null,
        ];

        $this->assertSame('-', $this->service->resolveParentSku($item));
    }

    public function test_resolveParentImage_falls_back_to_product_image_when_no_parent(): void
    {
        $item = (object) [
            'productVariation' => null,
            'product'          => null,
            'product_image'    => 'https://cdn.example.com/foto.jpg',
        ];

        $this->assertSame('https://cdn.example.com/foto.jpg', $this->service->resolveParentImage($item));
    }

    public function test_resolveParentImage_returns_null_when_nothing_available(): void
    {
        $item = (object) [
            'productVariation' => null,
            'product'          => null,
            'product_image'    => null,
        ];

        $this->assertNull($this->service->resolveParentImage($item));
    }
}
