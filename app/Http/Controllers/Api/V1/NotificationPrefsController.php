<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MUL-227 item 28 — Sininho seller personalizado.
 * Preferências salvas em clients.notification_prefs (JSON).
 * Categorias fixas: pedidos, produtos, sistema, financeiro.
 * Canais: email, push. Quiet hours + digest opcionais.
 */
class NotificationPrefsController extends Controller
{
    public const DEFAULTS = [
        'categories' => ['pedidos' => true, 'produtos' => true, 'sistema' => true, 'financeiro' => true],
        'channels'   => ['email' => true, 'push' => true],
        'quiet_hours' => null,
        'digest'     => 'instant', // instant | hourly | daily
    ];

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (! $client) abort(403, 'Usuario nao possui perfil de lojista.');
        return $client;
    }

    public function show(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $prefs = $client->notification_prefs;
        if (is_string($prefs)) $prefs = json_decode($prefs, true);

        return response()->json([
            'data' => array_replace_recursive(self::DEFAULTS, is_array($prefs) ? $prefs : []),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $data = $request->validate([
            'categories'         => ['nullable', 'array'],
            'categories.pedidos' => ['nullable', 'boolean'],
            'categories.produtos'=> ['nullable', 'boolean'],
            'categories.sistema' => ['nullable', 'boolean'],
            'categories.financeiro' => ['nullable', 'boolean'],
            'channels'           => ['nullable', 'array'],
            'channels.email'     => ['nullable', 'boolean'],
            'channels.push'      => ['nullable', 'boolean'],
            'quiet_hours'        => ['nullable', 'array'],
            'quiet_hours.start'  => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'quiet_hours.end'    => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'digest'             => ['nullable', 'string', 'in:instant,hourly,daily'],
        ]);

        $current = $client->notification_prefs;
        if (is_string($current)) $current = json_decode($current, true);
        if (!is_array($current)) $current = [];

        $merged = array_replace_recursive(self::DEFAULTS, $current, $data);
        $client->notification_prefs = $merged;
        $client->save();

        return response()->json(['data' => $merged]);
    }
}
