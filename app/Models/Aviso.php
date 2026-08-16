<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SEL-264 — canal de notícias/dicas/alertas que dispara push automático.
 */
class Aviso extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'titulo', 'body_push', 'conteudo_markdown', 'categoria', 'prioridade',
        'published_at', 'cta_label', 'cta_url', 'cover_url', 'requires_plan',
        'push_sent_at', 'created_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'push_sent_at' => 'datetime',
    ];

    public function reads()
    {
        return $this->hasMany(AvisoRead::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($q)
    {
        return $q->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopePendingPush($q)
    {
        return $q->whereNull('push_sent_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
