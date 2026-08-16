<?php

namespace App\Http\Controllers\Api\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\KitMirrorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MUL-236 F2 — WL recebe kit sincronizado do hub (evento federation.kit.sync).
 * Rota: POST /api/federation/kits/receive (verify.federation.hmac).
 */
class FederationKitReceiveController extends Controller
{
    public function receive(Request $request, KitMirrorService $mirror): JsonResponse
    {
        if (config('federation.tenant') === 'hubai') {
            return response()->json(['message' => 'Hub não recebe push de kit.'], 400);
        }

        $data = (array) $request->input('data', []);
        if (! $data) {
            return response()->json(['message' => 'Payload sem data.'], 422);
        }

        $kit = $mirror->applyFromHub($data);

        if (! $kit) {
            Log::warning('[FederationKitReceive] espelho não aplicado', [
                'event' => $request->input('event'),
                'sku'   => data_get($data, 'kit.sku'),
            ]);
            return response()->json(['success' => false, 'message' => 'Espelho não aplicado (ver logs).'], 200);
        }

        return response()->json(['success' => true, 'kit_id' => $kit->id, 'sku' => $kit->sku]);
    }
}
