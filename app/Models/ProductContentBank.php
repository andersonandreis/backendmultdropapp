<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-XXX (04/08): banco de conteudo IA (titulo/descricao/bullets) com rotacao.
 * Ver migration 2026_08_04_150000_sel_create_product_content_bank_tables.php.
 */
class ProductContentBank extends Model
{
    protected $table = 'product_content_bank';

    protected $fillable = [
        'product_key',
        'title',
        'description',
        'bullet_points',
        'times_used',
        'max_uses',
        'retired_at',
        'source_batch',
    ];

    protected $casts = [
        'bullet_points' => 'array',
        'retired_at'    => 'datetime',
        'times_used'    => 'integer',
        'max_uses'      => 'integer',
    ];
}
