<?php
namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEngine;
use App\Services\Ai\DicloakVeoAdapter;
use App\Services\Ai\DicloakNotConfiguredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SEL-429 -- CRUD + health do pool universal de motores IA.
 *
 * Evolucao do VideoEnginesController (SEL-428) para cobrir todos os tool_types.
 * Rotas (prefixo /api/v1/admin/ai-engines):
 *   GET    /                      lista engines (com filtro ?tool_type=)
 *   POST   /                      cria engine
 *   PATCH  /{id}                  atualiza name, priority, is_active, tool_type, config_json
 *   DELETE /{id}                  remove engine
 *   POST   /{id}/reset-cooldown   limpa cooldown imediatamente
 *   GET    /dicloak/profiles      lista perfis DICloak (requer DICLOAK_TUNNEL_URL)
 *
 * Camuflagem: nenhum endpoint expoe provider/cost ao cliente.
 * So acessivel por super_admin (middleware na rota).
 *
 * Rotulos camuflados (para UI cliente-facing - SEL-429):
 *   dicloak-flow     -> "Renderizacao de Video"
 *   mac-flow         -> "Reserva de Video"
 *   dicloak-gpt      -> "IA de Texto"
 *   openai-direct    -> "IA de Texto Reserva"
 *   dicloak-image    -> "IA de Imagem"
 *   dicloak-scraping -> "Analise de Mercado"
 */
class AiEnginesController extends Controller
{
    private const PROVIDER_LABELS = [
        'dicloak-flow'     => 'Renderizacao de Video',
        'mac-flow'         => 'Reserva de Video',
        'dicloak-gpt'      => 'IA de Texto',
        'openai-direct'    => 'IA de Texto Reserva',
        'dicloak-image'    => 'IA de Imagem',
        'dicloak-scraping' => 'Analise de Mercado',
        'dicloak-viral'    => 'Deteccao Viral',
        'dicloak-flow-gen' => 'Renderizacao Alternativa',
        'seedance'         => 'Renderizacao de Video (API)',
    ];

    private const VALID_PROVIDERS = [
        'dicloak-flow',
        'mac-flow',
        'dicloak-gpt',
        'openai-direct',
        'dicloak-image',
        'dicloak-scraping',
        'dicloak-viral',
        'dicloak-flow-gen',
        'seedance',
        'other',
    ];

    private const VALID_TOOL_TYPES = [
        'video', 'image', 'llm', 'scraping', 'viral', 'flow', 'other',
    ];

    /**
     * Lista engines com filtro opcional por tool_type.
     * GET /api/v1/admin/ai-engines?tool_type=video
     */
    public function index(Request $request): JsonResponse
    {
        $query = AiEngine::orderBy('tool_type')->orderBy('priority');

        if ($toolType = $request->query('tool_type')) {
            $query->where('tool_type', $toolType);
        }

        $engines = $query->get()->map(fn($e) => $this->format($e));

        return response()->json([
            'data'      => $engines,
            'tool_types' => self::VALID_TOOL_TYPES,
            'providers'  => self::VALID_PROVIDERS,
        ]);
    }

    /**
     * Cria novo engine.
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'        => 'required|string|max:80',
            'provider'    => 'required|in:' . implode(',', self::VALID_PROVIDERS),
            'tool_type'   => 'required|in:' . implode(',', self::VALID_TOOL_TYPES),
            'priority'    => 'integer|min:1|max:9999',
            'is_active'   => 'boolean',
            'config_json' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $engine = AiEngine::create([
            'name'        => $request->input('name'),
            'provider'    => $request->input('provider'),
            'tool_type'   => $request->input('tool_type', 'video'),
            'priority'    => $request->input('priority', 100),
            'is_active'   => $request->boolean('is_active', false),
            'config_json' => $request->input('config_json', []),
        ]);

        Log::info('[SEL-429][Admin] engine criado', [
            'id'        => $engine->id,
            'name'      => $engine->name,
            'tool_type' => $engine->tool_type,
        ]);

        return response()->json(['data' => $this->format($engine)], 201);
    }

    /**
     * Atualiza engine.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $engine = AiEngine::findOrFail($id);

        $v = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:80',
            'tool_type'   => 'sometimes|in:' . implode(',', self::VALID_TOOL_TYPES),
            'priority'    => 'sometimes|integer|min:1|max:9999',
            'is_active'   => 'sometimes|boolean',
            'config_json' => 'sometimes|array',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $engine->fill($request->only(['name', 'tool_type', 'priority', 'is_active', 'config_json']))->save();

        Log::info('[SEL-429][Admin] engine atualizado', [
            'id'      => $engine->id,
            'changes' => $request->only(['name', 'tool_type', 'priority', 'is_active']),
        ]);

        return response()->json(['data' => $this->format($engine)]);
    }

    /**
     * Remove engine (nao permite remover mac-flow -- ultimo fallback de video).
     */
    public function destroy(int $id): JsonResponse
    {
        $engine = AiEngine::findOrFail($id);

        if ($engine->provider === 'mac-flow') {
            return response()->json([
                'error' => 'O engine Mac-Flow nao pode ser removido -- e o fallback de ultimo recurso para video. Desative com is_active=false se necessario.',
            ], 422);
        }

        Log::info('[SEL-429][Admin] engine removido', ['id' => $engine->id, 'name' => $engine->name]);
        $engine->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Limpa cooldown de um engine imediatamente.
     */
    public function resetCooldown(int $id): JsonResponse
    {
        $engine = AiEngine::findOrFail($id);
        $engine->update(['cooldown_until' => null, 'last_failure_at' => null, 'last_error' => null]);

        Log::info('[SEL-429][Admin] cooldown resetado', ['id' => $engine->id, 'name' => $engine->name]);

        return response()->json(['data' => $this->format($engine)]);
    }

    /**
     * Lista perfis DICloak disponiveis.
     */
    public function dicloakProfiles(): JsonResponse
    {
        try {
            $adapter  = new DicloakVeoAdapter();
            $profiles = $adapter->listVeo3Profiles();
            return response()->json(['profiles' => $profiles]);

        } catch (DicloakNotConfiguredException $e) {
            return response()->json([
                'error'   => 'DICloak nao configurado',
                'detail'  => 'Configure DICLOAK_TUNNEL_URL no .env apos INF-072 concluir.',
                'status'  => 'pending_inf072',
            ], 503);

        } catch (\Throwable $e) {
            Log::warning('[SEL-429][Admin] dicloakProfiles erro', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao consultar DICloak: ' . mb_substr($e->getMessage(), 0, 200)], 502);
        }
    }

    private function format(AiEngine $e): array
    {
        $isOnCooldown = $e->isOnCooldown();
        $secondsLeft  = $isOnCooldown
            ? max(0, (int) now()->diffInSeconds($e->cooldown_until, false) * -1)
            : 0;

        return [
            'id'               => $e->id,
            'name'             => $e->name,
            'provider'         => $e->provider,
            'provider_label'   => self::PROVIDER_LABELS[$e->provider] ?? $e->provider,
            'tool_type'        => $e->tool_type,
            'tool_type_label'  => $e->toolTypeLabel(),
            'priority'         => $e->priority,
            'is_active'        => $e->is_active,
            'status'           => $isOnCooldown ? 'cooldown' : ($e->is_active ? 'available' : 'disabled'),
            'cooldown_until'   => $e->cooldown_until?->toIso8601String(),
            'cooldown_seconds_remaining' => $secondsLeft,
            'healthy_at'       => $e->healthy_at?->toIso8601String(),
            'last_failure_at'  => $e->last_failure_at?->toIso8601String(),
            'success_rate_24h' => $e->success_rate_24h,
            'last_error'       => $e->last_error,
            'config_json'      => $e->config_json,
            'updated_at'       => $e->updated_at?->toIso8601String(),
        ];
    }

    /**
     * SEL-ENGRENAGEM (12/08) — RETRATO AO VIVO DA FROTA DE VIDEO.
     *
     * Serve o JSON que o `video:frota-sync` publica a cada minuto em
     * storage/app/frota-video.json: motor a motor (conta, estado livre/gerando/caido,
     * porta CDP, modelo, se e gratis, quantas gerou hoje, ha quanto tempo esta
     * ocioso), o que esta na fila agora, as ultimas entregas com o tempo de cada
     * uma, e o resumo de 24h. Junto vem o que os monitores do Ruan ja publicavam
     * (vigia-saldo / sentinela ponta-a-ponta / conferente), so LIDO -- este endpoint
     * nao escreve em nenhum deles.
     *
     * `?fresco=1` forca um sync na hora (custa um SSH ate o PC, ~2-5s) em vez de
     * servir o ultimo retrato. Sem isso, responde o arquivo, que nunca tem mais de
     * 1 minuto de idade.
     */
    public function frota(\Illuminate\Http\Request $request)
    {
        $arquivo = storage_path('app/' . \App\Console\Commands\VideoFrotaSync::RETRATO);

        if ($request->boolean('fresco')) {
            try {
                \Illuminate\Support\Facades\Artisan::call('video:frota-sync');
            } catch (\Throwable $e) {
                // fail-open: serve o retrato anterior em vez de derrubar o painel
            }
        }

        if (! is_readable($arquivo)) {
            return response()->json([
                'erro'   => 'retrato ainda nao gerado',
                'dica'   => 'rode: php artisan video:frota-sync',
            ], 503);
        }

        $j = json_decode((string) file_get_contents($arquivo), true);

        return response()->json([
            'ok'            => true,
            'idade_s'       => max(0, time() - (int) @filemtime($arquivo)),
            'frota'         => $j,
        ]);
    }
}
