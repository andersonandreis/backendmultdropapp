<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configurações de IA por WL/supplier (MUL-142-H).
 *
 * Cada administrador de whitelabel configura a própria chave OpenAI
 * e os prompts de geração de conteúdo por marketplace.
 */
class SupplierAiSetting extends Model
{
    protected $fillable = [
        'supplier_id',
        'openai_api_key',
        'openai_model',
        'system_prompt_base',
        'system_prompts_marketplace',
        'ai_enabled',
    ];

    protected $casts = [
        'openai_api_key'             => 'encrypted',
        'system_prompts_marketplace' => 'array',
        'ai_enabled'                 => 'boolean',
    ];

    /** @var list<string> Campos sensíveis — nunca serializar */
    protected $hidden = ['openai_api_key'];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Retorna o prompt para um marketplace específico, com fallback para base. */
    public function promptForMarketplace(string $marketplace): string
    {
        $prompts = $this->system_prompts_marketplace ?? [];
        return $prompts[$marketplace] ?? $this->system_prompt_base ?? '';
    }

    /** True quando a chave está configurada e IA está habilitada. */
    public function isReady(): bool
    {
        return $this->ai_enabled && ! empty($this->openai_api_key);
    }
}
