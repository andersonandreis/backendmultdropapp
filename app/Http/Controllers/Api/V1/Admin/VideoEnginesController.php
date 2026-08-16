<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoEngine;
use App\Services\Ai\DicloakVeoAdapter;
use App\Services\Ai\DicloakNotConfiguredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SEL-425 — CRUD + health do pool de motores de vídeo.
 *
 * Rotas (prefixo /api/v1/admin/video-engines):
 *   GET    /           lista engines com saúde atual
 *   POST   /           cria engine (ou sincroniza do DICloak)
 *   PATCH  /{id}       atualiza name, priority, is_active, config_json
 *   DELETE /{id}       remove engine
 *   POST   /{id}/reset-cooldown    limpa cooldown imediatamente
 *   GET    /dicloak/profiles       lista perfis VEO3 no DICloak (requer DICLOAK_TUNNEL_URL)
 *
 * CAMUFLAGEM: nenhum endpoint expõe custo/crédito ao cliente.
 * Este controller só é acessível por admin (middleware aplicado na rota).
 */
class VideoEnginesController extends Controller
{
    /**
     * Lista todos os engines com status calculado.
     */
    public function index(): JsonResponse
    {
        $engines = VideoEngine::orderBy('priority')->get()->map(fn($e) => $this->format($e));
        return response()->json(['data' => $engines]);
    }

    /**
     * Cria novo engine.
     */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'        => 'required|string|max:80',
            'provider'    => 'required|in:dicloak-flow,mac-flow,seedance',
            'priority'    => 'integer|min:1|max:9999',
            'is_active'   => 'boolean',
            'config_json' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $engine = VideoEngine::create([
            'name'        => $request->input('name'),
            'provider'    => $request->input('provider'),
            'priority'    => $request->input('priority', 100),
            'is_active'   => $request->boolean('is_active', true),
            'config_json' => $request->input('config_json', []),
        ]);

        Log::info('[SEL-425][Admin] engine criado', ['id' => $engine->id, 'name' => $engine->name]);

        return response()->json(['data' => $this->format($engine)], 201);
    }

    /**
     * Atualiza engine (reordenar, ativar/desativar, editar config).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $engine = VideoEngine::findOrFail($id);

        $v = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:80',
            'priority'    => 'sometimes|integer|min:1|max:9999',
            'is_active'   => 'sometimes|boolean',
            'config_json' => 'sometimes|array',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $engine->fill($request->only(['name', 'priority', 'is_active', 'config_json']))->save();

        Log::info('[SEL-425][Admin] engine atualizado', ['id' => $engine->id, 'changes' => $request->only(['name', 'priority', 'is_active'])]);

        return response()->json(['data' => $this->format($engine)]);
    }

    /**
     * Remove engine (não permite remover o Mac-Ruan-Flow — último fallback).
     */
    public function destroy(int $id): JsonResponse
    {
        $engine = VideoEngine::findOrFail($id);

        if ($engine->provider === 'mac-flow') {
            return response()->json([
                'error' => 'O engine Mac-Flow não pode ser removido — é o fallback de último recurso. Desative com is_active=false se precisar.',
            ], 422);
        }

        Log::info('[SEL-425][Admin] engine removido', ['id' => $engine->id, 'name' => $engine->name]);
        $engine->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Limpa o cooldown de um engine imediatamente (útil após resolver incidente).
     */
    public function resetCooldown(int $id): JsonResponse
    {
        $engine = VideoEngine::findOrFail($id);
        $engine->update(['cooldown_until' => null, 'last_failure_at' => null, 'last_error' => null]);

        Log::info('[SEL-425][Admin] cooldown resetado', ['id' => $engine->id, 'name' => $engine->name]);

        return response()->json(['data' => $this->format($engine)]);
    }

    /**
     * Lista perfis VEO3 disponíveis no DICloak.
     * Requer DICLOAK_TUNNEL_URL configurado (INF-072).
     */
    public function dicloakProfiles(): JsonResponse
    {
        try {
            $adapter  = new DicloakVeoAdapter();
            $profiles = $adapter->listVeo3Profiles();
            return response()->json(['profiles' => $profiles]);

        } catch (DicloakNotConfiguredException $e) {
            return response()->json([
                'error'   => 'DICloak não configurado',
                'detail'  => 'Configure DICLOAK_TUNNEL_URL no .env após INF-072 concluir.',
                'status'  => 'pending_inf072',
            ], 503);

        } catch (\Throwable $e) {
            Log::warning('[SEL-425][Admin] dicloakProfiles erro', ['err' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao consultar DICloak: ' . mb_substr($e->getMessage(), 0, 200)], 502);
        }
    }

    private function format(VideoEngine $e): array
    {
        $isOnCooldown = $e->isOnCooldown();
        $secondsLeft  = $isOnCooldown
            ? max(0, (int) now()->diffInSeconds($e->cooldown_until, false) * -1)
            : 0;

        return [
            'id'               => $e->id,
            'name'             => $e->name,
            'provider'         => $e->provider,
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
}
