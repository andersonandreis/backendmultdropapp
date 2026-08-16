<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;

class WlPayCycleController extends Controller
{
    private array $empresaNomes = [
        17 => 'JTDrop',
        20 => 'MEStoreDrop',
        22 => 'Fornecefy',
        24 => 'MultDrop',
        15 => 'PlugLar',
        21 => 'DropKsr',
    ];

    private array $empresaEmails = [
        17 => 'admin@jtdrop.com.br',
        20 => 'admin@mestoredrop.com.br',
        22 => 'admin@fornecefy.io',
        24 => 'admin@multdrop.app',
        15 => 'admin@hubai.io',
        21 => 'admin@hubai.io',
    ];

    public function handle(Request $request): JsonResponse
    {
        $empresaId = (int)$request->input('empresa_id');
        $cycleId   = $request->input('cycle_id');

        if (!$empresaId || !$cycleId) {
            return response()->json(['message' => 'empresa_id e cycle_id sao obrigatorios'], 422);
        }

        // 1. Buscar ciclo no Supabase HubAI
        $supabaseUrl     = config('services.supabase_hubai.url', 'https://omvstizxjosygkcolzzl.supabase.co');
        $supabaseAnonKey = config('services.supabase_hubai.anon_key');

        $resp = Http::withHeaders([
            'apikey'        => $supabaseAnonKey,
            'Authorization' => 'Bearer ' . $supabaseAnonKey,
        ])->get($supabaseUrl . '/rest/v1/whitelabel_billing_cycles', [
            'id'          => 'eq.' . $cycleId,
            'empresa_id'  => 'eq.' . $empresaId,
            'limit'       => 1,
        ]);

        if (!$resp->successful()) {
            return response()->json(['message' => 'Erro ao consultar Supabase'], 502);
        }

        $rows = $resp->json();
        if (empty($rows)) {
            return response()->json(['message' => 'Ciclo nao encontrado'], 404);
        }

        $cycle = $rows[0];

        if (!empty($cycle['data_pagamento'])) {
            return response()->json(['message' => 'Fatura ja paga em ' . $cycle['data_pagamento']], 409);
        }

        $amountDue = (float)($cycle['amount_due'] ?? 0);
        if ($amountDue <= 0) {
            return response()->json(['message' => 'Valor da fatura invalido ou zerado'], 422);
        }

        $amountCents = (int)round($amountDue * 100);
        $nomeWl      = $this->empresaNomes[$empresaId]  ?? ('WL-' . $empresaId);
        $emailWl     = $this->empresaEmails[$empresaId] ?? 'admin@hubai.io';
        $cycleStart  = substr($cycle['cycle_start'] ?? '', 0, 10);
        $cycleEnd    = substr($cycle['cycle_end']   ?? '', 0, 10);
        $description = 'HubAI Fatura ' . $nomeWl . ' ciclo ' . $cycleStart . ' a ' . $cycleEnd;

        // 2. Gera ordem PIX no Pagar.me v5
        $pagarmeKey = config('services.pagarme.api_key');
        $pagarmePayload = [
            'items' => [[
                'amount'      => $amountCents,
                'description' => $description,
                'quantity'    => 1,
            ]],
            'payments' => [[
                'payment_method' => 'pix',
                'pix'            => [
                    'expires_in' => 259200,
                ],
            ]],
            'customer' => [
                'name'     => $nomeWl,
                'email'    => $emailWl,
                'type'     => 'company',
                'document' => '60885565000125', // MultDrop CNPJ — placeholder pagar.me payer
                'phones'   => [
                    'mobile_phone' => [
                        'country_code' => '55',
                        'area_code'    => '11',
                        'number'       => '999999999',
                    ],
                ],
            ],
        ];

        $pagarmeResp = Http::withBasicAuth($pagarmeKey, '')
            ->post('https://api.pagar.me/core/v5/orders', $pagarmePayload);

        if (!$pagarmeResp->successful()) {
            $errBody = $pagarmeResp->json();
            $errMsg  = $errBody['message'] ?? 'Erro ao criar ordem Pagar.me';
            return response()->json(['message' => $errMsg, 'pagarme_error' => $errBody], 502);
        }

        $order  = $pagarmeResp->json();
        $charge = $order['charges'][0] ?? null;
        $pix    = $charge['last_transaction'] ?? null;

        return response()->json([
            'success'      => true,
            'order_id'     => $order['id']     ?? null,
            'amount'       => $amountDue,
            'amount_cents' => $amountCents,
            'qr_code'      => $pix['qr_code']     ?? null,
            'qr_code_url'  => $pix['qr_code_url'] ?? null,
            'checkout_url' => $order['checkouts'][0]['payment_url'] ?? null,
            'description'  => $description,
            'expires_in'   => 259200,
            'empresa_id'   => $empresaId,
            'cycle_id'     => $cycleId,
        ]);
    }
}
