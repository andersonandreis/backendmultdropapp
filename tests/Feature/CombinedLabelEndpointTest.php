<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * NOV-096 — Feature test do endpoint de etiqueta combinada.
 *
 * Valida APENAS o guard de autenticacao (sanctum + requireSupplierAdmin).
 * O comportamento de renderizacao eh testado em
 * Tests\Unit\Services\Labels\CombinedLabelServiceTest.
 */
class CombinedLabelEndpointTest extends TestCase
{
    public function test_combined_label_get_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/supplier-admin/orders/999999/combined-label');

        $this->assertContains($response->getStatusCode(), [401, 403], 'Esperava 401/403 sem auth, recebeu ' . $response->getStatusCode());
    }

    public function test_print_batch_combined_post_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/supplier-admin/picking/print-batch-combined', [
            'order_ids' => [1, 2, 3],
        ]);

        $this->assertContains($response->getStatusCode(), [401, 403], 'Esperava 401/403 sem auth, recebeu ' . $response->getStatusCode());
    }
}
