<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BlingWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Bling Webhook Recebido', $request->all());

        // Processa payload para status de NFe, Atualizacao de Estoque Bling->SaaS
        return response()->json(['status' => 'received']);
    }
}
