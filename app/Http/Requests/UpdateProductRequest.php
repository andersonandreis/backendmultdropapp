<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            // Campos base
            'sku'               => "sometimes|string|max:100|unique:products,sku,{$productId}",
            'name'              => 'sometimes|string|max:120',  // Shopee: 120 chars
            'price'             => 'sometimes|numeric|min:0.01', // MUL-198: price nunca zero
            'cost'              => 'sometimes|numeric|min:0',
            'description'       => 'sometimes|nullable|string',

            // Identificadores
            'gtin'              => 'sometimes|nullable|string|max:14',
            'ean'               => 'sometimes|nullable|string|max:20',

            // Produto
            'category_id'       => 'sometimes|nullable|integer|exists:categories,id',
            'brand'             => 'sometimes|nullable|string|max:100',
            'model'             => 'sometimes|nullable|string|max:100',
            'model_name'        => 'sometimes|nullable|string|max:100',
            'condition'         => 'sometimes|nullable|string|in:new,used,refurbished',

            // Dimensões e peso
            'weight_kg'         => 'sometimes|nullable|numeric|min:0',
            'length_cm'         => 'sometimes|nullable|numeric|min:0',
            'width_cm'          => 'sometimes|nullable|numeric|min:0',
            'height_cm'         => 'sometimes|nullable|numeric|min:0',

            // Garantia
            'warranty_type'     => 'sometimes|nullable|string|max:50',
            'warranty_days'     => 'sometimes|nullable|integer|min:0',
            'warranty_months'   => 'sometimes|nullable|integer|min:0',

            // Marketplace
            'video_url'         => 'sometimes|nullable|url|max:500',
            'attributes'        => 'sometimes|nullable|array',
            'attributes.*'      => 'nullable',

            // Estoque virtual
            'is_active'             => 'sometimes|boolean',
            'virtual_stock_qty'     => 'sometimes|nullable|integer|min:0',
            'safety_margin_stock'   => 'sometimes|nullable|integer|min:0',
            'zero_out_margin_stock' => 'sometimes|nullable|integer|min:0',

            // AI
            'ai_title'          => 'sometimes|nullable|string|max:100',
            'ai_description'    => 'sometimes|nullable|string',
            'ai_bullet_points'  => 'sometimes|nullable|array',
            'ai_bullet_points.*'=> 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'      => 'O nome do produto deve ter no máximo 120 caracteres (limite Shopee).',
            'sku.unique'    => 'Este SKU já está em uso.',
            'condition.in'  => 'Condição inválida. Use: new, used ou refurbished.',
            'price.min'     => 'O preço do produto deve ser maior que zero (MUL-198).',
            'video_url.url' => 'A URL do vídeo deve ser uma URL válida (ex: https://youtube.com/...).',
        ];
    }
}
