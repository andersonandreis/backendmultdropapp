<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Inventory;
use App\Models\Supplier;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class CatalogoProdutos extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Catálogo & Produtos';
    protected static ?string $navigationLabel = 'Catálogo';
    protected static ?string $title = 'Catálogo de Produtos';
    protected static ?string $slug = 'catalogo-produtos';
    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.catalogo-produtos';

    public ?int $selectedSupplier = null;
    public string $search = '';
    public int $page = 1;
    public int $perPage = 25;
    public string $viewMode = 'grid'; // grid | list

    public function getSuppliers(): array
    {
        return Supplier::withCount(['activeProducts as products_count'])
            ->where('is_active', true)
            ->having('products_count', '>', 0)
            ->orderByDesc('products_count')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->company_name,
                'count' => $s->products_count,
                'legacy_id' => $s->legacy_id,
            ])
            ->toArray();
    }

    public function getProducts(): array
    {
        if (!$this->selectedSupplier) return [];

        $query = Product::where('supplier_id', $this->selectedSupplier)
            ->where('is_active', true);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%')
                  ->orWhere('ean', 'like', '%' . $this->search . '%');
            });
        }

        $total = $query->count();
        $lastPage = max(1, ceil($total / $this->perPage));
        if ($this->page > $lastPage) $this->page = $lastPage;

        $products = $query->orderBy('name')
            ->skip(($this->page - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();

        $ids = $products->pluck('id');

        $images = ProductMedia::whereIn('product_id', $ids)
            ->where('type', 'image')
            ->orderBy('position')
            ->get()
            ->groupBy('product_id');

        $inventory = Inventory::whereIn('product_id', $ids)
            ->get()
            ->keyBy('product_id');

        $items = $products->map(function ($p) use ($images, $inventory) {
            $img = $images->get($p->id, collect());
            $stock = $inventory->get($p->id);
            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'description' => Str::limit($p->description, 80),
                'price' => number_format($p->price, 2, ',', '.'),
                'cost' => number_format($p->cost, 2, ',', '.'),
                'image' => $img->first()?->url,
                'images_count' => $img->count(),
                'stock' => $stock?->quantity ?? 0,
                'brand' => $p->brand,
                'ean' => $p->ean,
                'edit_url' => route('filament.admin.resources.produtos.edit', $p->id),
            ];
        })->toArray();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $this->page,
            'lastPage' => $lastPage,
            'perPage' => $this->perPage,
        ];
    }

    public function selectSupplier(int $id): void
    {
        $this->selectedSupplier = $id;
        $this->page = 1;
        $this->search = '';
    }

    public function clearSupplier(): void
    {
        $this->selectedSupplier = null;
        $this->search = '';
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function previousPage(): void
    {
        if ($this->page > 1) $this->page--;
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function goToPage(int $p): void
    {
        $this->page = $p;
    }

    public function toggleView(): void
    {
        $this->viewMode = $this->viewMode === 'grid' ? 'list' : 'grid';
    }
}
