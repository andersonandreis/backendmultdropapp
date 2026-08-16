<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Campos obrigatórios
            'supplier_id'       => 'required|integer|exists:suppliers,id',
            'sku'               => 'required|string|max:100|unique:products,sku',
            'name'              => 'required|string|max:120',   // Shopee: 120 chars
            'price'             => 'required|numeric|min:0.01', // MUL-198: price nunca zero
            'cost'              => 'required|numeric|min:0',

            // Identificadores
            'gtin'              => 'nullable|string|max:14',    // EAN-13 / GTIN-14
            'ean'               => 'nullable|string|max:20',    // EAN alternativo ML

            // Produto
            'description'       => 'nullable|string',
            'category_id'       => 'nullable|integer|exists:categories,id',
            'brand'             => 'nullable|string|max:100',
            'model'             => 'nullable|string|max:100',
            'model_name'        => 'nullable|string|max:100',   // Nome comercial do modelo
            'condition'         => 'nullable|string|in:new,used,refurbished',

            // Dimensões e peso (ML e Shopee exigem)
            'weight_kg'         => 'nullable|numeric|min:0',
            'length_cm'         => 'nullable|numeric|min:0',
            'width_cm'          => 'nullable|numeric|min:0',
            'height_cm'         => 'nullable|numeric|min:0',

            // Garantia
            'warranty_type'     => 'nullable|string|max:50',
            'warranty_days'     => 'nullable|integer|min:0',
            'warranty_months'   => 'nullable|integer|min:0',

            // Marketplace
            'video_url'         => 'nullable|url|max:500',      // YouTube — ML apenas
            'attributes'        => 'nullable|array',
            'attributes.*'      => 'nullable',

            // Estoque virtual
            'is_active'             => 'boolean',
            'virtual_stock_qty'     => 'nullable|integer|min:0',
            'safety_margin_stock'   => 'nullable|integer|min:0',
            'zero_out_margin_stock' => 'nullable|integer|min:0',

            // AI
            'ai_title'          => 'nullable|string|max:100',
            'ai_description'    => 'nullable|string',
            'ai_bullet_points'  => 'nullable|array',
            'ai_bullet_points.*'=> 'string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'    => 'O nome do produto deve ter no máximo 120 caracteres (limite Shopee).',
            'sku.unique'  => 'Este SKU já está em uso.',
            'condition.in'=> 'Condição inválida. Use: new, used ou refurbished.',
            'price.min'   => 'O preço do produto deve ser maior que zero (MUL-198).',
            'video_url.url' => 'A URL do vídeo deve ser uma URL válida (ex: https://youtube.com/...).',
        ];
    }
}
