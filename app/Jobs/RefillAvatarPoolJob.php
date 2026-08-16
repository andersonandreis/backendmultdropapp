<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEL-195 Gap B: Reposição automática do pool de avatares.
 *
 * Disparado após um cliente reservar um avatar (VideoAvatarController::reserve()).
 * Se o pool livre cair abaixo de POOL_MIN, gera 1 novo avatar via Kling img-gen
 * (retrato ultrarrealista fotográfico) e insere em video_avatars (is_reserved=0).
 *
 * Queue: default (workers hubaiapp-worker 4 procs).
 * Idempotente: se o pool já estiver acima do mínimo, sai silenciosamente.
 */
class RefillAvatarPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Pool mínimo de avatares livres antes de gerar novo. */
    public const POOL_MIN = 8;

    public int $timeout = 180;
    public int $tries   = 2;

    public function handle(): void
    {
        // SEL 11/08 (Ruan "tudo Google"): fal.ai REMOVIDO. Refill desligado ate
        // reimplementar com gerador de imagem do Google (Imagen/Gemini). O pool
        // existente segue servindo o cliente (ele escolhe avatar pronto, nao gera na hora).
        \Illuminate\Support\Facades\Log::info('RefillAvatarPoolJob: desligado (fal.ai removido — aguardando Imagen/Google)');
        return;
        $free = DB::table('video_avatars')
            ->where('is_active', 1)
            ->where('is_reserved', 0)
            ->count();

        if ($free >= self::POOL_MIN) {
            Log::info('RefillAvatarPoolJob: pool suficiente', ['free' => $free]);
            return;
        }

        $needed = self::POOL_MIN - $free;
        Log::info("RefillAvatarPoolJob: pool baixo ({$free}), gerando {$needed} avatar(es)");

        for ($i = 0; $i < $needed; $i++) {
            $this->generateOne();
        }
    }

    private function generateOne(): void
    {
        // Prompt ultrarrealista SEL-194/SEL-195 — retrato profissional fotorrealístico
        $genders  = ['female', 'male']; // SEL-322: enum video_avatars.gender = female/male/neutral (nao woman/man)
        $gender   = $genders[array_rand($genders)]; // female ou male para o banco
        $promptGender = $gender === 'female' ? 'woman' : 'man'; // para o prompt de IA
        $styles   = ['professional', 'casual', 'lifestyle'];
        $style    = $styles[array_rand($styles)];
        $skin     = ['fair', 'medium', 'olive', 'brown', 'dark'][array_rand(['fair', 'medium', 'olive', 'brown', 'dark'])];
        $label    = ucfirst($promptGender) . ' ' . ucfirst($style) . ' — ' . ucfirst($skin) . ' tone';

        $prompt = "Professional headshot of a {$promptGender} Brazilian person, {$style} style, {$skin} skin tone, " .
                  "visible skin pores, subsurface scattering on skin, ARRI Alexa 35mm look, film grain, " .
                  "natural imperfections, cinematic lighting, shallow depth of field, no CGI, no plastic skin, " .
                  "shot on 35mm lens, teal-orange color grade LUT, neutral studio background, " .
                  "photorealistic ultra HD portrait, direct eye contact, confident expression.";

        $negativePrompt = "cartoon, anime, CGI skin, plastic look, morphing face, distorted hands, " .
                          "extra fingers, shaky camera, stock-footage aesthetic, overexposed, flat lighting, watermark.";

        $falApiKey = env('FAL_API_KEY');
        if (empty($falApiKey)) {
            Log::warning('RefillAvatarPoolJob: FAL_API_KEY nao configurada — usando placeholder');
            $this->insertPlaceholder($label, $gender, $style, $skin);
            return;
        }

        try {
            // Kling via fal.ai: image generation (Flux Pro para retrato fotorrealístico)
            $response = Http::withToken($falApiKey)
                ->timeout(120)
                ->post('https://fal.run/fal-ai/flux-pro/v1.1', [
                    'prompt'          => $prompt,
                    'negative_prompt' => $negativePrompt,
                    'image_size'      => ['width' => 768, 'height' => 1024],
                    'num_inference_steps' => 28,
                    'guidance_scale'      => 3.5,
                    'num_images'          => 1,
                    'enable_safety_checker' => true,
                ]);

            if (!$response->successful()) {
                Log::warning('RefillAvatarPoolJob: fal.ai retornou erro', ['status' => $response->status(), 'body' => $response->body()]);
                $this->insertPlaceholder($label, $gender, $style, $skin);
                return;
            }

            $data     = $response->json();
            $imageUrl = $data['images'][0]['url'] ?? null;

            if (!$imageUrl) {
                Log::warning('RefillAvatarPoolJob: sem image_url na resposta fal.ai', ['data' => $data]);
                $this->insertPlaceholder($label, $gender, $style, $skin);
                return;
            }

            DB::table('video_avatars')->insert([
                'label'               => $label,
                'image_url'           => $imageUrl,
                'gender'              => $gender,
                'style'               => $style,
                'description'         => "{$displayGender}, {$style} style, {$skin} skin tone",
                'is_active'           => 1,
                'is_reserved'         => 0,
                'reserved_client_id'  => null,
                'reserved_at'         => null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            Log::info('RefillAvatarPoolJob: avatar gerado', ['label' => $label, 'url' => $imageUrl]);

        } catch (Throwable $e) {
            Log::error('RefillAvatarPoolJob: erro ao gerar avatar', ['error' => $e->getMessage()]);
            // Insere placeholder pra não deixar pool vazio
            $this->insertPlaceholder($label, $gender, $style, $skin);
        }
    }

    /**
     * Fallback: insere avatar placeholder (imagem de stock do Unsplash) se
     * a API de IA não estiver configurada ou falhar.
     * Isso mantém o pool funcional sem bloquear o cliente.
     */
    private function insertPlaceholder(string $label, string $gender, string $style, string $skin): void
    {
        // Unsplash source aleatória pra retrato (funciona sem API key)
        $seed     = rand(100, 999);
        $displayGender = $gender === 'female' ? 'woman' : 'man';
        $imageUrl = "https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=400&h=533&fit=crop&crop=face&seed={$seed}";

        DB::table('video_avatars')->insert([
            'label'               => $label . ' (placeholder)',
            'image_url'           => $imageUrl,
            'gender'              => $gender,
            'style'               => $style,
            'description'         => "{$displayGender}, {$style} style, {$skin} skin tone",
            'is_active'           => 1,
            'is_reserved'         => 0,
            'reserved_client_id'  => null,
            'reserved_at'         => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}
