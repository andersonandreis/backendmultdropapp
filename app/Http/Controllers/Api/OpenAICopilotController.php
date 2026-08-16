<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OpenAICopilotController extends Controller
{
    /**
     * Webhook receptor de perguntas do Mercado Livre.
     * DESATIVADO: endpoint mockado, sem integracao real com OpenAI.
     * Mantido para nao quebrar a rota configurada no ML.
     */
    public function handleMercadoLivreQuestion(Request $request)
    {
        Log::warning('OpenAICopilot: ML enviou pergunta mas handler esta desativado', [
            'topic'    => $request->input('topic'),
            'resource' => $request->input('resource'),
            'ip'       => $request->ip(),
        ]);

        return response()->json([
            'status'  => 'disabled',
            'message' => 'ML question handler temporarily disabled',
        ]);
    }
}
