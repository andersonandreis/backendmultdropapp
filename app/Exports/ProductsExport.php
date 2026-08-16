<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        $query = Product::with(['supplier', 'category', 'media']);

        if (auth()->user()?->role === 'supplier') {
            $supplierId = auth()->user()->supplier?->id;
            if ($supplierId) {
                $query->where('supplier_id', $supplierId);
            }
        }

        return $query;
    }

    public function headings(): array
    {
        $headings = ['ID', 'Nome', 'SKU', 'Preço', 'Estoque', 'Status', 'Categoria', 'Imagem URL', 'Criado em'];

        if (auth()->user()?->role === 'super_admin') {
            array_splice($headings, 6, 0, ['Fornecedor ID', 'Fornecedor']);
        }

        return $headings;
    }

    public function map($product): array
    {
        $row = [
            $product->id,
            $product->name,
            $product->sku,
            $product->price,
            $product->stock ?? 0,
            $product->status,
            $product->category?->name ?? '',
            $product->media()->first()?->url ?? '',
            $product->created_at?->format('Y-m-d H:i:s'),
        ];

        if (auth()->user()?->role === 'super_admin') {
            array_splice($row, 6, 0, [
                $product->supplier_id,
                $product->supplier?->company_name ?? '',
            ]);
        }

        return $row;
    }
}
