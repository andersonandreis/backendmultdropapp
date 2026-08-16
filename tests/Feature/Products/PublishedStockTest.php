<?php

namespace Tests\Feature\Products;

use Tests\TestCase;
use App\Models\Product;

/**
 * MUL-234 — publishedStock fake por seller (dinamico e deterministico)
 *
 * Testes unitarios puros (sem tocar banco). O publishedStock depende de:
 *   - $this->effective_stock (accessor) — mockado via classe anonima
 *   - $this->id, virtual_stock_qty, safety_margin_stock, zero_out_margin_stock
 */
class PublishedStockTest extends TestCase
{
    /**
     * Instancia Product sem persistir; sobrescreve getEffectiveStockAttribute
     * pra retornar valor fixo do teste.
     */
    private function makeProduct(int $real, ?int $virtual = null, ?int $safety = null, ?int $zeroOut = null, int $id = 100): Product
    {
        $p = new class extends Product {
            public int $fakeReal = 0;
            public function getEffectiveStockAttribute() { return $this->fakeReal; }
            public function isKit(): bool { return false; }
        };

        $p->id = $id;
        $p->fakeReal = $real;
        $p->virtual_stock_qty     = $virtual;
        $p->safety_margin_stock   = $safety;
        $p->zero_out_margin_stock = $zeroOut;

        return $p;
    }

    /** @test */
    public function real_abaixo_de_zero_out_retorna_zero(): void
    {
        $p = $this->makeProduct(real: 8, virtual: 1000, safety: 20, zeroOut: 10);
        $this->assertSame(0, $p->publishedStock(42));
    }

    /** @test */
    public function real_no_limite_do_zero_out_retorna_zero(): void
    {
        $p = $this->makeProduct(real: 10, virtual: 1000, safety: 20, zeroOut: 10);
        $this->assertSame(0, $p->publishedStock(42));
    }

    /** @test */
    public function real_entre_zero_e_safety_retorna_real(): void
    {
        $p = $this->makeProduct(real: 15, virtual: 1000, safety: 20, zeroOut: 10);
        $this->assertSame(15, $p->publishedStock(42));
    }

    /** @test */
    public function real_acima_de_safety_retorna_fake_dentro_do_intervalo(): void
    {
        $p = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        $fake = $p->publishedStock(42);
        $this->assertGreaterThanOrEqual(0, $fake);
        $this->assertLessThanOrEqual(500, $fake);
    }

    /** @test */
    public function fake_e_deterministico_por_seller(): void
    {
        $p = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        $v1 = $p->publishedStock(42);
        $v2 = $p->publishedStock(42);
        $v3 = $p->publishedStock(42);
        $this->assertSame($v1, $v2);
        $this->assertSame($v1, $v3);
    }

    /** @test */
    public function sellers_diferentes_recebem_valores_diferentes(): void
    {
        $p = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        $sellers = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];
        $valores = array_map(fn($c) => $p->publishedStock($c), $sellers);
        $unicos = array_unique($valores);
        $this->assertGreaterThanOrEqual(5, count($unicos),
            'Esperava variedade de valores fake entre sellers. Recebeu: ' . implode(',', $valores));
    }

    /** @test */
    public function seed_rotaciona_quando_virtual_muda(): void
    {
        $p1 = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        $p2 = $this->makeProduct(real: 500, virtual: 2000, safety: 20, zeroOut: 10);
        $antes  = $p1->publishedStock(42);
        $depois = $p2->publishedStock(42);
        $this->assertNotSame($antes, $depois,
            "Fake deveria mudar quando virtual passa de 1000 pra 2000. antes=$antes depois=$depois");
    }

    /** @test */
    public function client_id_null_retorna_real_cru(): void
    {
        $p = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        $this->assertSame(500, $p->publishedStock(null));
        $this->assertSame(500, $p->publishedStock());
    }

    /** @test */
    public function sem_virtual_retorna_real_mesmo_com_client_id(): void
    {
        $p = $this->makeProduct(real: 500, virtual: null, safety: null, zeroOut: null);
        $this->assertSame(500, $p->publishedStock(42));
    }

    /** @test */
    public function mt_rand_global_nao_e_afetado_apos_chamada(): void
    {
        $p = $this->makeProduct(real: 500, virtual: 1000, safety: 20, zeroOut: 10);
        mt_srand(12345);
        $antes = mt_rand();
        $p->publishedStock(42);
        mt_srand(12345);
        $depois = mt_rand();
        $this->assertSame($antes, $depois, 'publishedStock nao deve poluir mt_rand global');
    }
}
