<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEL-456 -- Quota tracking diário por engine de IA.
 *
 * Um registro por (engine_id, date). O AiEnginePool cria ou incrementa
 * atomicamente via upsert antes de reservar o engine.
 *
 * @property int         $id
 * @property int         $engine_id
 * @property string      $date              (YYYY-MM-DD, UTC)
 * @property int         $generated_count   gerações concluídas hoje
 * @property int         $reserved_count    gerações em andamento agora
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $reset_at
 */
class AiEngineUsage extends Model
{
    protected $table = 'ai_engine_usage';

    protected $fillable = [
        'engine_id',
        'date',
        'generated_count',
        'reserved_count',
        'last_used_at',
        'reset_at',
    ];

    protected $casts = [
        'generated_count' => 'integer',
        'reserved_count'  => 'integer',
        'last_used_at'    => 'datetime',
        'reset_at'        => 'datetime',
    ];

    public function engine(): BelongsTo
    {
        return $this->belongsTo(AiEngine::class, 'engine_id');
    }

    /**
     * Retorna o registro de uso de hoje (UTC) para o engine, criando se não existir.
     */
    public static function todayFor(int $engineId): static
    {
        return static::firstOrCreate(
            ['engine_id' => $engineId, 'date' => now()->utc()->toDateString()],
            ['generated_count' => 0, 'reserved_count' => 0],
        );
    }

    /**
     * Total de gerações "consumidas" hoje: concluídas + em andamento.
     * Usado para comparar com a quota diária.
     */
    public function totalToday(): int
    {
        return $this->generated_count + $this->reserved_count;
    }
}
