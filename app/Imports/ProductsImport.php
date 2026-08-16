<?php

namespace App\Imports;

use App\Models\Product;
use App\Services\ImageDownloadService;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsErrors;

class ProductsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnError
{
    use SkipsErrors;

    private ImageDownloadService $imageService;

    public function __construct()
    {
        $this->imageService = new ImageDownloadService();
    }

    public function model(array $row): ?Product
    {
        $supplierId = null;

        if (Auth::user()?->role === 'supplier') {
            $supplierId = Auth::user()->supplier?->id;
        } elseif (!empty($row['supplier_id'])) {
            $supplierId = (int) $row['supplier_id'];
        }

        $imageUrl = null;
        if (!empty($row['image_url'])) {
            $path = $this->imageService->downloadFromUrl((string) $row['image_url']);
            if ($path) {
                $imageUrl = $path;
            }
        }

        return new Product([
            'name'        => $row['nome'] ?? $row['name'],
            'sku'         => $row['sku'] ?? null,
            'price'       => $row['preco'] ?? $row['price'] ?? 0,
            'stock'       => $row['estoque'] ?? $row['stock'] ?? 0,
            'status'      => in_array($row['status'] ?? 'draft', ['draft', 'active', 'archived'])
                             ? ($row['status'] ?? 'draft')
                             : 'draft',
            'category_id' => $row['category_id'] ?? null,
            'supplier_id' => $supplierId,
            'image_url'   => $imageUrl,
        ]);
    }

    public function rules(): array
    {
        return [
            '*.nome'  => ['required_without:*.name', 'string', 'max:255'],
            '*.name'  => ['required_without:*.nome', 'string', 'max:255'],
            '*.preco' => ['nullable', 'numeric', 'min:0'],
            '*.price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
