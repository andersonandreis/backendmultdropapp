<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-040: registro de cada geracao IA (video/imagem/audio/roteiro).
 */
class AiGeneration extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'service', 'provider', 'provider_model', 'provider_task_id',
        'wizard_payload', 'final_prompt', 'status', 'output_url', 'storage_key',
        'credits_debited', 'cost_usd', 'error_message', 'expires_at',
    ];

    protected $casts = [
        'wizard_payload' => 'array',
        'expires_at' => 'datetime',
        'cost_usd' => 'decimal:4',
    ];
}
