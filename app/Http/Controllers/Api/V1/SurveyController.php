<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-082 F7 — Questionário de onboarding do tier freemium tiktok_free.
 *
 * GET  /api/v1/me/survey   → estado atual (respondido, pulado, ou vazio)
 * POST /api/v1/me/survey   → grava respostas (nicho[], estado, perfil, ticket_medio, freq)
 * POST /api/v1/me/survey/skip → marca skipped_at (não pergunta de novo por 7 dias)
 *
 * Dados usados por F8 (cronograma) pra priorizar catálogo por nicho/estado.
 */
class SurveyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $client = $request->user()?->client;
        if (! $client) {
            return response()->json(['answered' => false, 'skipped' => false, 'survey' => null]);
        }
        $survey = $client->survey ? json_decode($client->survey, true) : null;
        return response()->json([
            'answered' => (bool) $survey,
            'skipped'  => (bool) $client->survey_skipped_at,
            'skipped_at' => $client->survey_skipped_at,
            'survey'   => $survey,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nicho'         => 'required|array|min:1|max:8',
            'nicho.*'       => 'string|max:40',
            'estado'        => 'required|string|size:2',
            'perfil'        => 'required|in:iniciante,intermediario,profissional',
            'ticket_medio'  => 'nullable|in:ate_50,50_150,150_500,500_mais',
            'freq_compra'   => 'nullable|in:imediato,7d,30d,curioso',
        ]);

        $client = $request->user()?->client;
        if (! $client) {
            return response()->json(['error' => 'client_missing'], 422);
        }

        $client->survey            = json_encode($data);
        $client->survey_skipped_at = null;
        if (! $client->account_started_at) {
            $client->account_started_at = now();
        }
        $client->save();

        return response()->json(['ok' => true, 'survey' => $data]);
    }

    public function skip(Request $request): JsonResponse
    {
        $client = $request->user()?->client;
        if (! $client) {
            return response()->json(['error' => 'client_missing'], 422);
        }
        $client->survey_skipped_at = now();
        if (! $client->account_started_at) {
            $client->account_started_at = now();
        }
        $client->save();
        return response()->json(['ok' => true]);
    }
}
