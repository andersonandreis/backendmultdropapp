<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HUB-QZ 2026-07-17 — Impressão automática de etiquetas via QZ Tray.
 *
 * QZ Tray (qz.io) roda como app local no PC do fornecedor e expõe WebSocket.
 * O cliente JS (qz-tray.js) conecta, autentica com nosso certificado público
 * e assina cada request via nosso /qz/sign. Sem assinatura, o QZ mostra prompt
 * de segurança a cada operação — assinatura remove isso (após "Trust always"
 * do usuário na 1a vez com a demo cert).
 *
 * Chaves em storage/app/qz-tray/ (private-key.pem gitignored, cert público).
 */
class QzTrayController extends Controller
{
    private const CERT_PATH = 'qz-tray/digital-certificate.pem';
    private const KEY_PATH  = 'qz-tray/private-key.pem';

    /**
     * GET /admin/qz/certificate — retorna cert público (PEM).
     */
    public function certificate(Request $request)
    {
        $path = storage_path('app/' . self::CERT_PATH);
        if (! file_exists($path)) {
            Log::warning('[QzTray] certificate ausente em ' . $path);
            return response('QZ certificate not configured', 503, ['Content-Type' => 'text/plain']);
        }

        return response(file_get_contents($path), 200, [
            'Content-Type'  => 'text/plain',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * GET /admin/qz/sign?request=<payload> — assina o payload com openssl_sign SHA512.
     */
    public function sign(Request $request)
    {
        $payload = (string) $request->query('request', '');
        if ($payload === '') {
            return response('empty request', 400, ['Content-Type' => 'text/plain']);
        }

        $keyPath = storage_path('app/' . self::KEY_PATH);
        if (! file_exists($keyPath)) {
            Log::warning('[QzTray] private-key ausente em ' . $keyPath);
            return response('QZ key not configured', 503, ['Content-Type' => 'text/plain']);
        }

        $privateKey = openssl_pkey_get_private('file://' . $keyPath);
        if (! $privateKey) {
            return response('QZ key invalid', 500, ['Content-Type' => 'text/plain']);
        }

        $signature = null;
        $ok = openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA512);
        if (! $ok || ! $signature) {
            return response('QZ sign failed', 500, ['Content-Type' => 'text/plain']);
        }

        return response(base64_encode($signature), 200, [
            'Content-Type'  => 'text/plain',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * POST /admin/qz/mark-printed — chamado pelo JS após qz.print resolver com sucesso.
     * Marca orders.label_printed_at + registra em audit log (order_beeps já cobre a bipagem).
     */
    public function markPrinted(Request $request)
    {
        $data = $request->validate([
            'order_id'      => 'nullable|integer',
            'order_number'  => 'nullable|string|max:64',
            'printer'       => 'nullable|string|max:191',
        ]);

        $order = null;
        if (! empty($data['order_id'])) {
            $order = Order::find($data['order_id']);
        }
        if (! $order && ! empty($data['order_number'])) {
            $order = Order::where('order_number', $data['order_number'])->first();
        }

        if (! $order) {
            return response()->json(['ok' => false, 'reason' => 'order_not_found'], 404);
        }

        // Só marca se ainda não estiver marcado (evita sobrescrever timestamp real)
        $already = (bool) $order->label_printed_at;
        if (! $already) {
            $order->update(['label_printed_at' => now()]);
            \App\Jobs\EmitBlingNfeJob::dispatchIfTrigger($order->id, 'label_printed'); // MUL-276
        }

        Log::info('[QzTray] etiqueta impressa via QZ', [
            'order_id'      => $order->id,
            'order_number'  => $order->order_number,
            'printer'       => $data['printer'] ?? null,
            'user_id'       => auth()->id(),
            'already'       => $already,
        ]);

        return response()->json([
            'ok'      => true,
            'already' => $already,
            'printed_at' => $order->fresh()->label_printed_at?->toIso8601String(),
        ]);
    }
}
