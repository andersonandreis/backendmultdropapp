<?php

namespace App\Jobs;

use App\Models\AiGeneration;
use App\Services\Ai\AiEnginePool;
use App\Services\Ai\AvatarPromptOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-avatar (10/08) -- Job async da criacao de avatar do zero.
 *
 * Orquestra o prompt (arquetipo ou descricao livre) e gera a imagem via o POOL
 * Google Flow / Nano Banana (R$0, incluso Ultra). Persiste o avatar exclusivo em
 * client_video_avatars (source=generated_exclusive) — o ClientAvatarResolver ja
 * prioriza esse rosto na proxima geracao de video.
 *
 * Fluxo: POST /studio-chat/generate-exclusive-avatar -> 202 {task_id=ai_generations.id}
 *        GET  /ai/image/task/{id} polling ate succeeded/failed (endpoint ja existe).
 */
class GenerateAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        private int $generationId,
        private int $clientId,
        private string $mode,        // 'archetype' | 'describe'
        private ?string $archetype,
        private ?string $gender,
        private ?string $description,
        private string $label,
    ) {}

    public function handle(AvatarPromptOrchestrator $orch, AiEnginePool $pool): void
    {
        $gen = AiGeneration::find($this->generationId);
        if (!$gen) { Log::warning("[SEL-avatar] generation {$this->generationId} sumiu"); return; }

        $gen->update(['status' => 'processing']);

        try {
            $built = $this->mode === 'describe'
                ? $orch->buildForDescription((string) $this->description, $this->gender)
                : $orch->buildForArchetype((string) ($this->archetype ?: 'coringa'), $this->gender);

            $result = $pool->generateImage($built['prompt']);   // -> ['url'=>...]
            $url = $result['url'] ?? null;
            if (!$url) { throw new \RuntimeException('pool_image_no_url'); }

            $avatarId = DB::table('client_video_avatars')->insertGetId([
                'client_id'              => $this->clientId,
                'video_avatar_id'        => null,
                'custom_avatar_url'      => $url,
                'label'                  => $this->label,
                'source'                 => 'generated_exclusive',
                'is_exclusive_to_client' => true,
                'is_active'              => true,
                'generation_prompt'      => $built['prompt'],
                'generation_seed'        => $built['seed'],
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            $gen->update([
                'status'      => 'succeeded',
                'output_url'  => $url,
                'final_prompt' => $built['prompt'],
                'storage_key' => (string) $avatarId,
            ]);

            Log::info('[SEL-avatar] avatar gerado', ['client' => $this->clientId, 'avatar_id' => $avatarId, 'archetype' => $built['archetype']]);
        } catch (Throwable $e) {
            $gen->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning('[SEL-avatar] falhou', ['client' => $this->clientId, 'err' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
