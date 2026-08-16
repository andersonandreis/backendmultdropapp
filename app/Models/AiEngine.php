<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-429 -- Modelo universal de motor IA.
 *
 * Cobre todos os tipos de tool DICloak:
 *   video     = VEO3/Flow (geracao de video)
 *   image     = Midjourney/DALL-E/gpt-image
 *   llm       = GPT/Claude (texto, chat, scripts)
 *   scraping  = Kalodata/Viral (analise de mercado)
 *   viral     = Deteccao de trends virais
 *   flow      = Automacao de fluxo generica
 *   other     = Outros
 *
 * O AiEnginePool seleciona engines por tool_type em ordem de priority,
 * com circuit-breaker por cooldown_until.
 */
class AiEngine extends Model
{
    protected $table = 'ai_engines';

    protected $fillable = [
        'name', 'provider', 'tool_type', 'config_json', 'priority',
        'is_active', 'healthy_at', 'last_failure_at',
        'cooldown_until', 'success_rate_24h', 'last_error',
    ];

    protected $casts = [
        'config_json'      => 'array',
        'priority'         => 'integer',
        'is_active'        => 'boolean',
        'healthy_at'       => 'datetime',
        'last_failure_at'  => 'datetime',
        'cooldown_until'   => 'datetime',
        'success_rate_24h' => 'float',
    ];

    /**
     * Engines disponiveis para um tipo especifico de tool.
     * Filtra: is_active=true + sem cooldown ativo.
     * Ordenados por priority ASC (menor = tentado primeiro).
     */
    public static function availableFor(string $toolType): \Illuminate\Database\Eloquent\Collection
    {
        $engines = static::where('tool_type', $toolType)
            ->where('is_active', true)
            ->where(fn($q) => $q->whereNull('cooldown_until')
                ->orWhere('cooldown_until', '<', now()))
            ->orderBy('priority')
            ->get();
        // SAUDE-PRIMEIRO (09/08 item2): dentro da mesma priority, reserva a engine
        // mais SAUDAVEL (success_rate_24h) primeiro -> a concorrencia NAO cai nos
        // perfis ruins/instaveis. Empate de saude (ex.: todos 100%) -> maior saldo
        // (equilibra carga, nao esgota uma conta so -- distribuidor CREDITO #14).
// SEL-rodizio v2 (12/08, Ruan): as contas NAO podem ficar paradas — sempre tem que ter
// uma gerando. Uso de HOJE numa consulta so, fora do laco de comparacao.
$usoHoje = \DB::table('ai_engine_usage')
    ->whereDate('date', now()->toDateString())
    ->pluck(\DB::raw('COALESCE(generated_count,0) + COALESCE(reserved_count,0)'), 'engine_id')
    ->all();

// "Nota" so vale se a falha for RECENTE. Falha de dias atras (ex.: o incidente do Flow
// em 10/08) marcava 3 contas boas com 85% PRA SEMPRE e as jogava pro fim da fila.
$notaValida = function ($e): float {
    $ultimaFalha = $e->last_failure_at ? \Illuminate\Support\Carbon::parse($e->last_failure_at) : null;
    if (!$ultimaFalha || $ultimaFalha->lt(now()->subHours(6))) {
        return 100.0; // sem falha recente = conta boa, ponto
    }
    return (float) ($e->success_rate_24h ?? 100);
};

return $engines->sort(function ($a, $b) use ($usoHoje, $notaValida) {
    if ((int) $a->priority !== (int) $b->priority) {
        return (int) $a->priority <=> (int) $b->priority;
    }
    // conta com problema AGORA (falha nas ultimas 6h) vai pro fim
    $sa = $notaValida($a);
    $sb = $notaValida($b);
    if (abs($sa - $sb) > 5.0) {
        return $sb <=> $sa;
    }
    // RODIZIO: entre contas saudaveis, a que gerou MENOS hoje vai primeiro
    $ua = (int) ($usoHoje[$a->id] ?? 0);
    $ub = (int) ($usoHoje[$b->id] ?? 0);
    if ($ua !== $ub) {
        return $ua <=> $ub;
    }
    // empate real: a que esta parada ha mais tempo entra (nenhuma fica encostada)
    $la = $a->healthy_at ? strtotime($a->healthy_at) : 0;
    $lb = $b->healthy_at ? strtotime($b->healthy_at) : 0;
    return $la <=> $lb;})->values();
    }

    /**
     * Todos engines disponiveis (backward compat para VideoEnginePool).
     * Equivale a availableFor('video').
     */
    public static function available(): \Illuminate\Database\Eloquent\Collection
    {
        return static::availableFor('video');
    }

    public function recordFailure(string $error, int $cooldownMinutes = 5): void
    {
        $cd = $this->config_json['cooldown_minutes'] ?? $cooldownMinutes;
        // SAUDE (09/08 item2): falha DERRUBA a media movel de saude (queda de 15%)
        // -> availableFor passa a preferir engines saudaveis DEPOIS que o cooldown
        // expira. recordSuccess recupera gradualmente (assimetrico: falha d01 mais
        // que 1 sucesso cura). Sem isto o success_rate ficava 100 eterno e a
        // ordenacao por saude nunca discriminava.
        $prevRate = $this->success_rate_24h ?? 100.0;
        $data = [
            'last_failure_at'  => now(),
            'cooldown_until'   => now()->addMinutes($cd),
            'last_error'       => mb_substr($error, 0, 500),
            'success_rate_24h' => round($prevRate * 0.85, 2),
        ];
        // AUTO-CURA (Camada 3): erro de sessao = conta deslogou ("Desconectada").
        // Tira do pool ate reconectar + marca pro painel avisar. As outras seguem.
        if (preg_match('/session_expired|login.?wall|sessao Google expirada|Desconectada|invalid.?authentication|deslogada|401/i', $error)) {
            $cfg = $this->config_json ?? [];
            $cfg['precisa_reconectar'] = true;
            $cfg['deslogou_em'] = now()->toDateTimeString();
            $data['is_active'] = 0;
            $data['config_json'] = $cfg;
        }
        $this->update($data);

        // SEL-motivo-falha (12/08, Ruan "registra o motivo da falha"): antes o motivo vivia
        // so em last_error — e recordSuccess() apagava. Resultado: conta com nota baixa e
        // NINGUEM sabia por que. Agora todo evento vira linha de historico.
        $this->registrarEvento(
            preg_match('/session_expired|login.?wall|Desconectada|deslogada|401/i', $error) ? 'deslogou' : 'falha',
            $error,
            $prevRate,
            round($prevRate * 0.85, 2),
            (int) $cd
        );
    }

    /** Historico por conta: o que aconteceu, quando e POR QUE. Nunca sobrescreve. */
    public function registrarEvento(string $tipo, ?string $motivo = null, ?float $antes = null, ?float $depois = null, ?int $cooldownMin = null): void
    {
        try {
            \DB::table('ai_engine_events')->insert([
                'engine_id'    => $this->id,
                'tipo'         => mb_substr($tipo, 0, 24),
                'motivo'       => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
                'nota_antes'   => $antes,
                'nota_depois'  => $depois,
                'cooldown_min' => $cooldownMin,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // historico nunca pode derrubar geracao de video
        }
    }

    public function recordSuccess(): void
    {
        $prev = $this->success_rate_24h ?? 100.0;
        $rate = round($prev * 0.95 + 100 * 0.05, 2);

        // SEL-motivo-falha (12/08): limpar last_error aqui era o que apagava a explicacao.
        // Continua limpando (o campo e "erro ATUAL"), mas o motivo fica salvo no historico.
        $this->registrarEvento('sucesso', $this->last_error, $prev, $rate, null);

        $this->update([
            'healthy_at'       => now(),
            'cooldown_until'   => null,
            'last_failure_at'  => null,
            'success_rate_24h' => $rate,
            'last_error'       => null,
        ]);
    }

    public function isOnCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    public function toolTypeLabel(): string
    {
        return match ($this->tool_type) {
            'video'    => 'Video',
            'image'    => 'Imagem',
            'llm'      => 'LLM / Texto',
            'scraping' => 'Scraping',
            'viral'    => 'Viral',
            'flow'     => 'Flow',
            default    => 'Outro',
        };
    }
}
