<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SEL-227 — endpoint de telemetry que recebe device fingerprint do frontend.
 * POST /api/v1/telemetry/device
 * Body: { canvas, webgl, screen, platform, timezone, language,
 *         hardwareConcurrency, deviceMemory, isHeadless, event }
 *
 * event = 'register' | 'login' | 'heartbeat'
 */
class TelemetryDeviceController extends Controller
{
    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'canvas'               => 'nullable|string|max:512',
            'webgl'                => 'nullable|string|max:512',
            'screen'               => 'nullable|string|max:128',
            'platform'             => 'nullable|string|max:64',
            'timezone'             => 'nullable|string|max:64',
            'language'             => 'nullable|string|max:16',
            'hardwareConcurrency'  => 'nullable|integer|min:0|max:255',
            'deviceMemory'         => 'nullable|integer|min:0|max:255',
            'isHeadless'           => 'nullable|boolean',
            'event'                => 'nullable|in:register,login,heartbeat',
        ]);

        $event = $data['event'] ?? 'heartbeat';
        $userId = $request->user()?->id;

        $result = DeviceFingerprintService::record($request, $data, $event, $userId);

        return response()->json([
            'ok' => !$result['blocked'],
            'blocked' => $result['blocked'],
            'reason' => $result['reason'],
            'fingerprint' => substr($result['fingerprint_hash'], 0, 12), // pra debug/log
        ]);
    }
}
