<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropModuleConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drop Internacional - Configuracao do modulo por cliente.
 */
class DropModuleController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    /**
     * Retorna a configuracao atual do modulo Drop para o cliente autenticado.
     */
    public function getConfig(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $config = DropModuleConfig::firstOrNew(['client_id' => $client->id]);
        return response()->json(['data' => $config]);
    }

    /**
     * Cria ou atualiza a configuracao do modulo Drop para o cliente autenticado.
     */
    public function saveConfig(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'default_supplier'     => 'nullable|string|in:aliexpress,cj,zendrop',
            'auto_pricing_enabled' => 'nullable|boolean',
            'default_markup_pct'   => 'nullable|numeric|min:1|max:500',
            'notification_email'   => 'nullable|email',
        ]);

        $config = DropModuleConfig::updateOrCreate(
            ['client_id' => $client->id],
            $validated
        );

        return response()->json(['data' => $config], 201);
    }
}
