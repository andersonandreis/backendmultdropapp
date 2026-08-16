<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Services\AIAnnouncementService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AIAnnouncementController extends Controller
{
    public function generate(Request $request, AIAnnouncementService $service): StreamedResponse
    {
        $validated = $request->validate([
            'client_product_id' => 'required|integer|exists:client_products,id',
            'field'             => 'required|in:title,description',
        ]);

        $clientProduct = ClientProduct::with(['product', 'marketplaceAccount'])
            ->findOrFail($validated['client_product_id']);

        // Authorization: record must belong to the authenticated client
        $user      = auth()->user();
        $clientId  = optional($user->client)->id;
        if (!$clientId) {
            abort(403, 'Cliente não autenticado.');
        }
        // Verifica direto pelo client_id (mais seguro e funciona mesmo quando ainda não tem marketplace_account)
        if ((int) $clientProduct->client_id !== (int) $clientId) {
            abort(403, 'Este produto não pertence à sua conta.');
        }

        $field = $validated['field'];

        return response()->stream(function () use ($clientProduct, $field, $service): void {
            if (ob_get_level() > 0) ob_end_clean();

            $method = $field === 'title' ? 'streamTitle' : 'streamDescription';

            $service->{$method}($clientProduct, function (string $chunk): void {
                $safe = str_replace(["\r\n", "\r", "\n"], ' ', $chunk);
                echo "data: {$safe}\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            });

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
