<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-321: Pipeline de geração de vídeo IA.
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $mode        perfect|clone
 * @property string|null $product_key
 * @property string      $step        queued|render|voice|lipsync|finalize|done|failed
 * @property array|null  $payloads    Payloads por etapa
 * @property string|null $render_task_id
 * @property string|null $voice_path
 * @property string|null $lipsync_task_id
 * @property string|null $output_url
 * @property int         $retries
 * @property string|null $error_message
 * @property bool        $dry_run
 * @property string|null $source           TOK-22: NULL = seller.global, 'tokfy' = pedido externo
 * @property string|null $external_ref     TOK-22: id do pipeline no sistema de origem
 * @property string|null $callback_url     TOK-22: pra onde avisar quando terminar
 * @property \Illuminate\Support\Carbon|null $callback_sent_at TOK-22
 */
class AiVideoPipeline extends Model
{
    protected $table = 'ai_video_pipelines';

    protected $fillable = [
        'user_id',
        'mode',
        'product_key',
        'step',
        'payloads',
        'render_task_id',
        'voice_path',
        'lipsync_task_id',
        'output_url',
        'retries',
        'error_message',
        'dry_run',
        // TOK-22: origem externa (Tokfy). NULL em tudo que vem do seller.global.
        'source',
        'external_ref',
        'callback_url',
        'callback_sent_at',
    ];

    protected $casts = [
        'payloads' => 'array',
        'dry_run'  => 'boolean',
        'retries'  => 'integer',
        'callback_sent_at' => 'datetime', // TOK-22
    ];

    /**
     * GUARDA "nunca mais done fantasma" (Ruan 11/08/2026).
     * Causa raiz: 63 pipelines viraram step=done SEM output_url e SEM render_task_id
     * (nunca renderizaram) — cliente via "concluido" e o video nunca existiu.
     * Esta rede de seguranca UNIVERSAL impede QUALQUER caminho de marcar done sem
     * video: converte pra failed com mensagem clara + alerta. dry_run passa (tem
     * output fake). Cliente nunca mais ve concluido fantasma.
     */
    protected static function booted(): void
    {
        $guardaDoneSemVideo = function ($pipeline) {
            if ($pipeline->step === "done" && empty($pipeline->output_url)) {
                $pipeline->step = "failed";
                $pipeline->error_message = "A geração não completou. Tente gerar de novo.";
                \Illuminate\Support\Facades\Log::error("[GUARD-donefantasma] bloqueado done sem video", [
                    "pipeline_id" => $pipeline->id,
                    "user_id"     => $pipeline->user_id,
                    "mode"        => $pipeline->mode,
                ]);
            }
        };
        static::creating($guardaDoneSemVideo);
        static::updating($guardaDoneSemVideo);
    }

}
