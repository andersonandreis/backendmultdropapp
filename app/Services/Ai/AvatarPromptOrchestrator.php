<?php
namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * SEL-avatar (10/08) -- Orquestra o prompt do avatar "criado do zero".
 *
 * O cliente NUNCA escreve prompt tecnico. Ele escolhe um ARQUETIPO (card) ou
 * DESCREVE em texto livre. Aqui a gente monta o prompt realista por tras e
 * concatena o master de realismo (PromptMasterService::avatar_human -> poros,
 * anti-plastico, Canon 85mm, fenotipo BR). Motor final: Nano Banana (Flow), R$0.
 *
 * Retorna: ['prompt' => <composto>, 'seed' => <hex>, 'archetype' => <id|describe>]
 */
class AvatarPromptOrchestrator
{
    public function __construct(private PromptMasterService $master) {}

    /** Fragmentos de sujeito por arquetipo (INTERNO — cliente nunca ve). */
    private const ARCHETYPES = [
        'apresentadora_natural' => [
            'gender' => 'feminino',
            'base'   => 'Brazilian woman, 24-30, girl-next-door look, light natural makeup, wavy brown hair, casual top, warm genuine smile, standing at home near a window with soft daylight, relaxed and approachable, empty hands',
        ],
        'vendedor_carismatico' => [
            'gender' => 'masculino',
            'base'   => 'Brazilian man, 28-38, confident charismatic energy, well-groomed short beard, casual button shirt with sleeves rolled up, direct eye contact, bright persuasive smile, clean modern room background, empty hands',
        ],
        'influencer_jovem' => [
            'gender' => 'feminino',
            'base'   => 'Brazilian woman, 20-25, trendy TikTok creator aesthetic, clean glam makeup, modern styled hair, soft ring-light catchlight, stylish streetwear, playful confident vibe, minimal aesthetic bedroom background, empty hands',
        ],
        'mae_real' => [
            'gender' => 'feminino',
            'base'   => 'Brazilian woman, 32-42, real everyday mom, natural minimal makeup, warm caring expression, comfortable casual clothes, cozy home kitchen background, honest and relatable, empty hands',
        ],
        'cara_comum' => [
            'gender' => 'masculino',
            'base'   => 'Brazilian man, 28-40, ordinary relatable guy, plain t-shirt or cap, natural un-styled look, honest authentic expression, everyday home background, looks like a real customer, empty hands',
        ],
        'especialista' => [
            'gender' => 'masculino',
            'base'   => 'Brazilian man, 35-45, trustworthy expert look, neat beard with light grey, smart-casual shirt, calm authoritative but warm expression, subtle confident smile, tidy office with shelves background, empty hands',
        ],
    ];

    /** Sorteio coerente pro Coringa. */
    private const CORINGA_POOL = ['apresentadora_natural', 'vendedor_carismatico', 'influencer_jovem', 'mae_real', 'cara_comum', 'especialista'];

    /** Palavras que NAO podem virar avatar (pessoa real/menor/NSFW) -> cai no neutro. */
    private const BLOCK = ['crianc', 'criança', 'menor', 'infantil', 'bebe', 'bebê', 'nu', 'nua', 'pelad', 'sexy', 'sensual', 'nude', 'naked', 'child', 'kid'];

    public function buildForArchetype(string $archetype, ?string $genderOverride = null): array
    {
        $seed = substr(md5($archetype . microtime() . Str::uuid()), 0, 16);

        if ($archetype === 'coringa' || !isset(self::ARCHETYPES[$archetype])) {
            $archetype = self::CORINGA_POOL[array_rand(self::CORINGA_POOL)];
        }
        $def  = self::ARCHETYPES[$archetype];
        $base = $def['base'];

        // override de genero (troca o termo do sujeito)
        $g = $this->normGender($genderOverride ?: $def['gender']);
        if ($g === 'masculino' && str_starts_with($base, 'Brazilian woman')) {
            $base = 'Brazilian man' . substr($base, strlen('Brazilian woman'));
        } elseif ($g === 'feminino' && str_starts_with($base, 'Brazilian man')) {
            $base = 'Brazilian woman' . substr($base, strlen('Brazilian man'));
        }

        $subject = $base . '. Portrait, vertical 9:16, waist-up framing. Unique portrait seed: ' . $seed;
        return [
            'prompt'    => $this->master->enhance($subject, 'avatar_human'),
            'seed'      => $seed,
            'archetype' => $archetype,
        ];
    }

    public function buildForDescription(string $description, ?string $genderOverride = null): array
    {
        $seed = substr(md5('describe' . microtime() . Str::uuid()), 0, 16);
        $clean = trim(mb_substr($description, 0, 400));
        $low   = mb_strtolower($clean);

        $blocked = false;
        foreach (self::BLOCK as $b) { if (str_contains($low, $b)) { $blocked = true; break; } }

        if ($blocked || $clean === '') {
            // cai num neutro seguro conforme genero
            $g = $this->normGender($genderOverride ?: 'feminino');
            $fallback = $g === 'masculino' ? 'cara_comum' : 'apresentadora_natural';
            return $this->buildForArchetype($fallback, $g);
        }

        // Nano Banana e Gemini -> entende PT nativo. Compoe: descricao do cliente
        // como sujeito + dica de genero (se dada) + enquadramento + master.
        $genderHint = '';
        $g = $this->normGender($genderOverride);
        if ($g === 'masculino') $genderHint = ' The subject is a Brazilian man.';
        elseif ($g === 'feminino') $genderHint = ' The subject is a Brazilian woman.';

        $subject = 'Real person described by the client (render as a real Brazilian UGC presenter, empty hands, no props unless described): '
            . $clean . '.' . $genderHint
            . ' Portrait, vertical 9:16, waist-up framing. Unique portrait seed: ' . $seed;

        return [
            'prompt'    => $this->master->enhance($subject, 'avatar_human'),
            'seed'      => $seed,
            'archetype' => 'describe',
        ];
    }

    private function normGender(?string $g): string
    {
        $g = mb_strtolower((string) $g);
        if (in_array($g, ['masculino', 'homem', 'm', 'male', 'man'], true)) return 'masculino';
        if (in_array($g, ['feminino', 'mulher', 'f', 'female', 'woman'], true)) return 'feminino';
        return 'neutro';
    }
}
