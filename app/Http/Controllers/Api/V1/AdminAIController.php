<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEL-040 admin: gestão de features AI por plano + usuário + relatórios de uso.
 * Rotas admin: /api/v1/admin/ai/*
 */
class AdminAIController extends Controller
{
    /** 15 features + labels — visíveis pra admin liberar/bloquear */
    public static function catalog(): array
    {
        return [
            ['id' => 'script',          'label' => 'Roteiro Mágico',         'provider' => 'openai',     'category' => 'text'],
            ['id' => 'analyze_image',   'label' => 'Analisar imagem',        'provider' => 'openai',     'category' => 'vision'],
            ['id' => 'image',           'label' => 'Gerar Imagem (DALL-E)',  'provider' => 'openai',     'category' => 'image'],
            ['id' => 'tts_openai',      'label' => 'Narração OpenAI',        'provider' => 'openai',     'category' => 'audio'],
            ['id' => 'transcribe',      'label' => 'Transcrever áudio',      'provider' => 'openai',     'category' => 'audio'],
            ['id' => 'video_seedance',  'label' => 'Vídeo Seedance',         'provider' => 'seedance',   'category' => 'video'],
            ['id' => 'animar_foto',     'label' => 'Animar Foto',            'provider' => 'seedance',   'category' => 'video'],
            ['id' => 'video_do_zero',   'label' => 'Vídeo do Zero',          'provider' => 'seedance',   'category' => 'video'],
            ['id' => 'trocar_pessoa',   'label' => 'Trocar Pessoa (avatar)', 'provider' => 'kling',      'category' => 'video'],
            ['id' => 'virtual_try_on',  'label' => 'Provador Virtual',       'provider' => 'kling',      'category' => 'image'],
            ['id' => 'efeitos_virais',  'label' => 'Efeitos Virais',         'provider' => 'kling',      'category' => 'video'],
            ['id' => 'lip_sync',        'label' => 'Fazer Falar (lip sync)', 'provider' => 'kling',      'category' => 'video'],
            // SEL-CATALOGO-SO-O-QUE-EXISTE (16/08): aqui havia 4 features de audio da
            // ElevenLabs — narracao, efeitos sonoros, dublagem e "Clonar voz". As quatro
            // eram liberaveis por plano e NENHUMA funcionava: a chave da ElevenLabs esta
            // vazia neste backend desde 12/08, e a clonagem nunca existiu em codigo
            // (`grep voices/add` = zero). Anunciar o que nao existe e a versao admin do
            // botao morto. Conferido: 0 referencias a esses ids em plans/settings.
            //
            // No lugar entra a unica que funciona de verdade, testada em circuito fechado
            // (geramos a fala e o nosso proprio transcritor entendeu de volta, pt 1.0):
            // Piper local em /opt/voz, voz brasileira, sem chave, sem conta, sem custo por
            // uso, a 6,3x tempo real — 1 hora de narracao em ~10 min de CPU.
            ['id' => 'narracao_local',  'label' => 'Narração em português (voz do sistema)', 'provider' => 'local', 'category' => 'audio'],
            ['id' => 'ebook_de_fotos',  'label' => 'Ebook de Fotos',         'provider' => 'openai',     'category' => 'image'],
        ];
    }

    /** GET /api/v1/admin/ai/catalog — lista de features disponíveis */
    public function catalogList()
    {
        return response()->json(['features' => self::catalog()]);
    }

    /** GET /api/v1/admin/ai/plans — planos com features liberadas */
    public function plansList()
    {
        $plans = DB::table('plans')
            ->select('id', 'name', 'slug', 'price_monthly', 'ai_features', 'ai_monthly_video_limit', 'ai_monthly_credits')
            ->orderBy('price_monthly')
            ->get();
        return response()->json(['plans' => $plans]);
    }

    /** PUT /api/v1/admin/ai/plans/{id} — atualiza features/limites do plano */
    public function planUpdate(Request $r, int $id)
    {
        $v = $r->validate([
            'ai_features' => 'nullable|array',
            'ai_features.*' => 'string',
            'ai_monthly_video_limit' => 'nullable|integer|min:0',
            'ai_monthly_credits' => 'nullable|integer|min:0',
        ]);
        DB::table('plans')->where('id', $id)->update([
            'ai_features' => isset($v['ai_features']) ? json_encode(array_values($v['ai_features'])) : null,
            'ai_monthly_video_limit' => $v['ai_monthly_video_limit'] ?? 0,
            'ai_monthly_credits' => $v['ai_monthly_credits'] ?? 0,
        ]);
        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/admin/ai/users — lista usuários com features/creditos */
    public function usersList(Request $r)
    {
        $q = DB::table('users')
            ->select('id', 'name', 'email', DB::raw('(select s.plan_id from clients c join subscriptions s on s.client_id = c.id where c.user_id = users.id order by s.id desc limit 1) as plan_id'), 'ai_features_extra', 'ai_features_blocked', 'ai_credits_balance')
            ->orderByDesc('id');
        if ($s = $r->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('email', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            });
        }
        return response()->json($q->paginate((int) $r->query('per_page', 20)));
    }

    /** PUT /api/v1/admin/ai/users/{id} — atualiza permissões individuais + créditos */
    public function userUpdate(Request $r, int $id)
    {
        $v = $r->validate([
            'ai_features_extra' => 'nullable|array',
            'ai_features_extra.*' => 'string',
            'ai_features_blocked' => 'nullable|array',
            'ai_features_blocked.*' => 'string',
            'ai_credits_balance' => 'nullable|integer|min:0',
        ]);
        DB::table('users')->where('id', $id)->update(array_filter([
            'ai_features_extra' => isset($v['ai_features_extra']) ? json_encode(array_values($v['ai_features_extra'])) : null,
            'ai_features_blocked' => isset($v['ai_features_blocked']) ? json_encode(array_values($v['ai_features_blocked'])) : null,
            'ai_credits_balance' => $v['ai_credits_balance'] ?? null,
        ], fn($x) => $x !== null));
        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/admin/ai/usage — Raio-X: custo, cobrado, margem, clientes ativos */
    public function usage(Request $r)
    {
        $range = $r->query('range', 'today');
        $from = match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->startOfDay(),
        };
        $rows = AiGeneration::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('service, provider, count(*) as total, sum(credits_debited) as credits, sum(cost_usd) as cost')
            ->groupBy('service', 'provider')
            ->get();
        $totalCost = (float) $rows->sum('cost');
        $totalCredits = (int) $rows->sum('credits');
        $creditPriceBRL = 0.25 * 5.4; // 1 crédito ≈ $0.25 USD → BRL ~ R$1.35
        $revenue = $totalCredits * $creditPriceBRL;
        $costBRL = $totalCost * 5.4;
        $margin = $revenue > 0 ? (($revenue - $costBRL) / $revenue) * 100 : 0;
        $activeClients = AiGeneration::query()->where('created_at', '>=', $from)->distinct('user_id')->count('user_id');
        return response()->json([
            'range' => $range,
            'summary' => [
                'cost_brl' => round($costBRL, 2),
                'revenue_brl' => round($revenue, 2),
                'margin_pct' => round($margin, 1),
                'margin_alert' => $margin < 50,
                'total_generations' => (int) $rows->sum('total'),
                'active_clients' => $activeClients,
            ],
            'breakdown' => $rows,
        ]);
    }

    /** GET /api/v1/admin/ai/generations — histórico completo */
    public function generations(Request $r)
    {
        $q = AiGeneration::query()->orderByDesc('created_at');
        if ($r->query('service')) $q->where('service', $r->query('service'));
        if ($r->query('user_id')) $q->where('user_id', $r->query('user_id'));
        if ($r->query('status')) $q->where('status', $r->query('status'));
        return response()->json($q->paginate((int) $r->query('per_page', 20)));
    }

    /** GET /api/v1/admin/ai/keys — status dos API keys do sistema (mascarado) */
    public function keys()
    {
        return response()->json([
            'keys' => [
                ['id' => 'ARK_API_KEY',         'label' => 'Seedance (BytePlus)', 'set' => !empty(config('services.ark.api_key')),         'category' => 'video'],
                ['id' => 'KLING_ACCESS_KEY',    'label' => 'Kling Access',        'set' => !empty(config('services.kling.access_key')),    'category' => 'video'],
                ['id' => 'KLING_SECRET_KEY',    'label' => 'Kling Secret',        'set' => !empty(config('services.kling.secret_key')),    'category' => 'video'],
                ['id' => 'ELEVENLABS_API_KEY',  'label' => 'ElevenLabs',          'set' => !empty(config('services.elevenlabs.api_key')),  'category' => 'audio'],
                ['id' => 'OPENAI_API_KEY',      'label' => 'OpenAI',              'set' => !empty(config('services.openai.api_key')),      'category' => 'text/image'],
            ],
        ]);
    }
}
