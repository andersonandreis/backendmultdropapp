<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * SEL-254 — A/B rígido gpt-image-2 vs gpt-image-1 (v1 em prod = SEL-251).
 *
 * Diferença vs v1:
 *  - model: 'gpt-image-2' (era 'gpt-image-1')
 *  - filename: avatar-v2-{id}-{ts}.png (NÃO sobrescreve o v1 no disco)
 *  - NÃO faz UPDATE em video_avatars.image_url (só salva o PNG e imprime URL)
 *
 * Prompt (HEAD + SPEC + TAIL) e todos outros params idênticos ao v1 pra A/B justo.
 * gpt-image-2 GA confirmado em developers.openai.com/api/docs/guides/image-generation.
 * Custo: $0.165/img HD 1024x1536 (era $0.25 no v1 = -34%).
 *
 * Uso:
 *   php artisan avatars:regenerate-v2 --id=1
 *   php artisan avatars:regenerate-v2 --all-v2   (só depois de aprovar id=1)
 */
class RegenerateVideoAvatarsCommandV2 extends Command
{
    protected $signature = 'avatars:regenerate-v2 {--id= : só um avatar específico} {--all-v2 : rodar os 11 com gpt-image-2}';
    protected $description = 'SEL-254 A/B gpt-image-2 vs v1 — gera SEM sobrescrever o v1 no banco';

    private const MASTER_HEAD =
        'Natural unretouched portrait photograph of a ';

    private const MASTER_TAIL =
        '. Soft directional key light from camera-left at 45 degrees, warm 4300K, subtle '
        . 'fill on right cheek, sharp crisp catchlight at 10 o clock position in each iris. '
        . 'Shot on Canon EOS R5, Canon EF 85mm f/1.4L at f/2.0, ISO 400, 1/200s. '
        . 'Raw pore-level skin texture with visible T-zone pores, peach fuzz on cheeks, '
        . 'realistic subsurface scattering, natural specular highlights, individual eyelashes, '
        . 'detailed iris texture with limbal ring. '
        . 'Genuine warm Duchenne smile with visible white teeth, eyes crinkled slightly, '
        . 'direct connection with camera, confident approachable Brazilian TikTok Shop '
        . 'presenter energy. '
        . 'Soft off-white paper backdrop with subtle vignette, minimal shadow at base. '
        . 'Kodak Portra 400 film emulation, unretouched Peter Lindbergh natural portrait '
        . 'style, half-body composition, editorial documentary photography, photorealistic.';

    private const SPECS = [
        1 => [
            'name' => 'Ana Souza',
            'desc' => '22-year-old Brazilian young woman, morena clara sun-kissed skin, long wavy shiny chestnut brown hair with natural highlights, expressive hazel green eyes with long dark lashes, oval face, high cheekbones, subtle light freckles across nose, natural glowy makeup, wearing a fresh white cotton t-shirt, soft butterfly lighting front-above, cheerful vlogger energy',
        ],
        2 => [
            'name' => 'Marina Lima',
            'desc' => '28-year-old Brazilian Black woman with beautiful morena retinta complexion and radiant skin glow, gorgeous voluminous natural curly afro shoulder-length, bright expressive dark brown almond eyes with long lashes, defined cheekbones, perfect symmetric face, glossy nude lipstick, wearing an elegant cream turtleneck, soft Rembrandt lighting from left, fashion influencer energy',
        ],
        3 => [
            'name' => 'Carla Diniz',
            'desc' => '30-year-old Brazilian woman with bronzed sun-kissed skin, long straight silky honey-blonde hair with beach waves, striking sky-blue eyes with long lashes, defined jawline, high cheekbones, natural pink lip gloss, minimal elegant makeup, wearing a chic beige silk blouse, split lighting softened, luxury lifestyle influencer energy',
        ],
        4 => [
            'name' => 'Bruna Reis',
            'desc' => '25-year-old Brazilian woman with olive tan athletic build, medium-length wavy dark chocolate brown hair with caramel highlights, sparkling forest green eyes, square face with defined cheekbones, healthy pink cheeks, dewy no-makeup makeup, wearing a fitted grey athletic tank top revealing toned shoulders, soft frontal loop lighting, fitness influencer energy',
        ],
        5 => [
            'name' => 'Ricardo Alves',
            'desc' => '24-year-old Brazilian man moreno claro with sun-kissed skin, well-groomed 3-day stubble, thick shiny black hair in modern textured crop cut, warm expressive dark brown eyes with defined brows, sharp masculine jawline, straight nose, wearing a plain black crew neck t-shirt showing broad shoulders, dramatic 45-degree side lighting from left, tech vlogger energy',
        ],
        6 => [
            'name' => 'Pedro Silva',
            'desc' => '35-year-old Brazilian executive man with fair skin and light tan, distinguished salt-and-pepper hair combed neatly with texture, well-groomed short salt-pepper beard, striking clear blue eyes with confident gaze, oval face with attractive smile lines, straight nose, wearing a crisp white dress shirt with unbuttoned collar showing chest, classic clamshell lighting, silver fox executive energy',
        ],
        7 => [
            'name' => 'Lucas Ferreira',
            'desc' => '26-year-old Brazilian Black man with beautiful morena retinta complexion glowing skin, fresh shape-up haircut with clean geometric line design, sparkling warm dark brown eyes with defined brows, strong squared masculine jaw, straight nose, muscular V-shape athletic body in a fitted white ribbed tank top, high-contrast Rembrandt lighting, sports influencer energy',
        ],
        8 => [
            'name' => 'Rafael Costa',
            'desc' => '28-year-old Brazilian moreno claro fashion influencer with sun-tanned skin, medium-length wavy chestnut brown hair styled loose and messy stylish, well-groomed full soft beard, striking grey-green eyes with intense gaze, defined cheekbones, straight nose, wearing an open light denim shirt with rolled sleeves over white t-shirt, soft loop lighting from front-left, street fashion influencer energy',
        ],
        9 => [
            'name' => 'Julia Nogueira',
            'desc' => '30-year-old Brazilian woman morena media entrepreneur with radiant golden skin, medium-length rich chocolate brown curls with caramel highlights framing face, warm honey brown eyes with long lashes, heart-shaped face with dimples, straight white teeth in bright warm smile, subtle professional makeup with nude pink lips, wearing a chic navy blazer over cream silk shell, soft Rembrandt lighting, boss babe energy',
        ],
        10 => [
            'name' => 'Camila Torres',
            'desc' => '26-year-old Brazilian ruiva woman with fair porcelain skin dusted with cute cinnamon freckles across nose and cheekbones, natural bright copper red long hair with subtle waves, striking emerald green eyes with copper lashes, oval face with delicate features, glossy nude pink lips, wearing a soft cream cashmere knit sweater, bright frontal butterfly lighting, lifestyle blogger energy',
        ],
        11 => [
            'name' => 'Diego Santos',
            'desc' => '29-year-old Brazilian pardo man with warm golden brown skin, short curly dark brown hair styled with texture, warm expressive medium brown eyes with kind gaze, round friendly face with defined jawline, light stubble, straight white teeth in genuine bright smile showing charming dimples, wearing a fitted charcoal grey henley shirt showing collarbones, soft loop lighting from front-right, boy-next-door energy',
        ],
    ];

    public function handle(): int
    {
        $key = env('OPENAI_API_KEY');
        if (!$key) {
            $this->error('OPENAI_API_KEY missing');
            return self::FAILURE;
        }

        $target = null;
        if ($idOpt = $this->option('id')) {
            $target = [(int) $idOpt];
        } elseif ($this->option('all-v2')) {
            $target = array_keys(self::SPECS);
        } else {
            $this->error('Use --id=N ou --all-v2');
            return self::FAILURE;
        }

        $storageDir = storage_path('app/public/avatars');
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $baseUrl = rtrim(env('APP_URL', 'https://api.seller.global'), '/');

        foreach ($target as $id) {
            $spec = self::SPECS[$id] ?? null;
            if (!$spec) {
                $this->warn("id=$id sem spec, pulando");
                continue;
            }

            $prompt = self::MASTER_HEAD . $spec['desc'] . self::MASTER_TAIL;
            $this->info("[V2 $id] {$spec['name']} — gerando com gpt-image-2…");

            try {
                $resp = Http::withHeaders(['Authorization' => 'Bearer ' . $key])
                    ->timeout(180)
                    ->post('https://api.openai.com/v1/images/generations', [
                        'model' => 'gpt-image-2',          // SEL-254 — troca única vs v1
                        'prompt' => $prompt,
                        'size' => '1024x1536',
                        'quality' => 'high',
                        'moderation' => 'low',
                        'output_format' => 'png',
                        'n' => 1,
                    ]);

                if (!$resp->successful()) {
                    $this->error("[V2 $id] HTTP {$resp->status()}: " . substr($resp->body(), 0, 400));
                    continue;
                }

                $b64 = $resp->json('data.0.b64_json');
                if (!$b64) {
                    $this->error("[V2 $id] sem b64_json na resposta");
                    continue;
                }

                $filename = "avatar-v2-{$id}-" . time() . ".png";
                $path = $storageDir . '/' . $filename;
                file_put_contents($path, base64_decode($b64));

                $publicUrl = $baseUrl . '/storage/avatars/' . $filename;
                $this->info("[V2 $id] ✅ salvo " . filesize($path) . "b → $publicUrl (NÃO gravado no banco — só disco)");
            } catch (\Throwable $e) {
                $this->error("[V2 $id] exception: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
