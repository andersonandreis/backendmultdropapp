<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEL-361 Fase E — Historico de prompts livres por cliente.
 */
class CustomPromptHistory extends Model
{
    protected $table = 'custom_prompt_history';

    protected $fillable = [
        'user_id',
        'pipeline_id',
        'prompt',
        'gear',
        'duration_sec',
        'aspect_ratio',
        'model',
        'image_url',
        'negative_prompt',
    ];

    protected $casts = [
        'duration_sec' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AiVideoPipeline::class, 'pipeline_id');
    }
}
