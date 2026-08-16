<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductsTemplateExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [
            [
                'Camiseta Exemplo',
                'CAM-001',
                '99.90',
                '50',
                'active',
                '1',
                'https://exemplo.com/imagem.jpg',
            ],
        ];
    }

    public function headings(): array
    {
        return [
            'name',
            'sku',
            'price',
            'stock',
            'status',
            'category_id',
            'image_url',
        ];
    }
}
