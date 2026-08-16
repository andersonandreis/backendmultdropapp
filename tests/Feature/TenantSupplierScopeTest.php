<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTenantContext;
use App\Models\Product;
use App\Models\Scopes\TenantSupplierScope;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TenantSupplierScopeTest — valida o isolamento de catálogo por tenant via N:N tenant_supplier.
 *
 * Arquitetura: Product.supplier_id é filtrado pelo TenantSupplierScope baseado
 * em Tenant::default_supplier_visibility ('all' = sem filtro, 'scoped' = só suppliers vinculados).
 */
class TenantSupplierScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        \DB::statement('SET FOREIGN_KEY_CHECKS=0');
        TenantSupplierScope::flushCache();
    }

    protected function tearDown(): void
    {
        \DB::statement('SET FOREIGN_KEY_CHECKS=1');
        TenantSupplierScope::flushCache();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Caso 1: tenant scoped com supplier específico (ex: mestoredrop -> supplier 25)
    // -------------------------------------------------------------------------

    public function test_scoped_tenant_sees_only_its_linked_supplier_products(): void
    {
        [$supA, $supB] = $this->createSuppliers(2);
        $tenant = $this->createTenant('mestoredrop-test', 'scoped');
        $this->linkSupplierToTenant($tenant, $supA);

        $productA = Product::withoutTenantSupplierScope()->create($this->productData($supA, 'Prod A'));
        Product::withoutTenantSupplierScope()->create($this->productData($supB, 'Prod B'));

        app()->instance('current_tenant', $tenant);
        TenantSupplierScope::flushCache();

        $results = Product::all();

        $this->assertCount(1, $results);
        $this->assertEquals('Prod A', $results->first()->name);
        $this->assertEquals($productA->id, $results->first()->id);
    }

    // -------------------------------------------------------------------------
    // Caso 2: tenant scoped com todos os suppliers vinculados (ex: fornecefy)
    // -------------------------------------------------------------------------

    public function test_scoped_tenant_with_all_suppliers_linked_sees_all_products(): void
    {
        [$supA, $supB, $supC] = $this->createSuppliers(3);
        $tenant = $this->createTenant('fornecefy-test', 'scoped');
        $this->linkSupplierToTenant($tenant, $supA);
        $this->linkSupplierToTenant($tenant, $supB);
        $this->linkSupplierToTenant($tenant, $supC);

        Product::withoutTenantSupplierScope()->create($this->productData($supA, 'F-Prod A'));
        Product::withoutTenantSupplierScope()->create($this->productData($supB, 'F-Prod B'));
        Product::withoutTenantSupplierScope()->create($this->productData($supC, 'F-Prod C'));

        app()->instance('current_tenant', $tenant);
        TenantSupplierScope::flushCache();

        $this->assertCount(3, Product::all());
    }

    // -------------------------------------------------------------------------
    // Caso 3: tenant hubai com visibilidade 'all' — sem filtro
    // -------------------------------------------------------------------------

    public function test_tenant_with_all_visibility_sees_all_products(): void
    {
        [$supA, $supB] = $this->createSuppliers(2);
        $tenant = $this->createTenant('hubai-test', 'all');

        Product::withoutTenantSupplierScope()->create($this->productData($supA, 'H-Prod A'));
        Product::withoutTenantSupplierScope()->create($this->productData($supB, 'H-Prod B'));

        app()->instance('current_tenant', $tenant);
        TenantSupplierScope::flushCache();

        $this->assertCount(2, Product::all());
    }

    // -------------------------------------------------------------------------
    // Caso 4: withoutTenantSupplierScope() retorna total real
    // -------------------------------------------------------------------------

    public function test_without_tenant_supplier_scope_returns_all_records(): void
    {
        [$supA, $supB, $supC] = $this->createSuppliers(3);
        $tenant = $this->createTenant('scoped-only-a', 'scoped');
        $this->linkSupplierToTenant($tenant, $supA);

        Product::withoutTenantSupplierScope()->create($this->productData($supA, 'WO-A'));
        Product::withoutTenantSupplierScope()->create($this->productData($supB, 'WO-B'));
        Product::withoutTenantSupplierScope()->create($this->productData($supC, 'WO-C'));

        app()->instance('current_tenant', $tenant);
        TenantSupplierScope::flushCache();

        $this->assertCount(1, Product::all());
        $this->assertCount(3, Product::withoutTenantSupplierScope()->get());
    }

    // -------------------------------------------------------------------------
    // Caso 5: middleware retorna 403 com X-Tenant-Slug: invalido
    // -------------------------------------------------------------------------

    public function test_ensure_tenant_context_middleware_rejects_invalid_slug(): void
    {
        $middleware = new EnsureTenantContext();
        $request    = \Illuminate\Http\Request::create('/api/v1/products', 'GET');
        $request->headers->set('X-Tenant-Slug', 'slug-inexistente-xyz-' . Str::random(8));

        $response = $middleware->handle($request, fn() => response()->json(['ok' => true]));

        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('tenant_not_found', $data['error']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTenant(string $slug, string $visibility): Tenant
    {
        return Tenant::create([
            'id'                          => Str::uuid(),
            'slug'                        => $slug,
            'name'                        => ucfirst($slug),
            'status'                      => 'active',
            'default_supplier_visibility' => $visibility,
        ]);
    }

    private function createSuppliers(int $count): array
    {
        $suppliers = [];
        for ($i = 1; $i <= $count; $i++) {
            $id = DB::table('suppliers')->insertGetId([
                'company_name'          => 'Supplier ' . Str::random(6),
                'display_name'          => 'Sup ' . $i,
                'type'                  => 'dropshipper',
                'is_active'             => 1,
                'user_id'               => 0,
                'document'              => '00.000.000/000' . $i . '-00',
                'allows_direct_payment' => 0,
                'pix_fee'               => 0,
                'is_factory'            => 0,
                'supports_meli_flex'    => 0,
                'flex_fee'              => 0,
                'allows_direct_deposit' => 0,
                'is_private'            => 0,
                'prefix'                => 'SUP',
                'slug'                  => 'sup-' . Str::random(8),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
            $suppliers[] = Supplier::find($id);
        }
        return $suppliers;
    }

    private function linkSupplierToTenant(Tenant $tenant, Supplier $supplier): void
    {
        DB::table('tenant_supplier')->insert([
            'tenant_id'   => $tenant->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    private function productData(Supplier $supplier, string $name): array
    {
        return [
            'supplier_id' => $supplier->id,
            'sku'         => 'SKU-' . Str::random(8),
            'name'        => $name,
            'price'       => 99.90,
            'cost'        => 50.00,
            'is_active'   => true,
        ];
    }
}
