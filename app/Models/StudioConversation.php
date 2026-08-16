<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * SEL-360 — Studio Chat: conversa entre o cliente e o Studio (diretor de vídeo IA).
 */
class StudioConversation extends Model
{
    protected $fillable = ['uuid', 'user_id', 'tenant_id', 'status', 'context'];

    protected $casts = ['context' => 'array'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function messages()
    {
        return $this->hasMany(StudioMessage::class, 'conversation_id');
    }
}
