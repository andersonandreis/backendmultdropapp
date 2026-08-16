<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientSupplierBalance;
use App\Traits\FormatsMoneyBR;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * SettingsController - configuracoes do lojista (auto-pay wallet, etc.)
 */
class SettingsController extends Controller
{
    use FormatsMoneyBR;

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }
        return $client;
    }

    #[OA\Get(
        path: '/api/v1/settings/auto-pay',
        summary: 'Obter configuracoes de pagamento automatico via carteira',
        description: 'Retorna se o auto-pay esta habilitado, o saldo minimo configurado e o saldo atual consolidado de todas as carteiras do lojista.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Configuracoes retornadas com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean', example: true),
                        new OA\Property(property: 'min_balance', type: 'number', example: 50.00),
                        new OA\Property(property: 'min_balance_formatted', type: 'string', example: 'R$ 50,00'),
                        new OA\Property(property: 'current_balance', type: 'number', example: 250.00),
                        new OA\Property(property: 'current_balance_formatted', type: 'string', example: 'R$ 250,00'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Usuario nao possui perfil de lojista'),
        ]
    )]
    public function getAutoPay(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $currentBalance = (float) ClientSupplierBalance::where('client_id', $client->id)
            ->sum('balance');
        $minBalance = (float) $client->auto_pay_min_balance;

        return response()->json([
            'enabled'                  => (bool) $client->auto_pay_from_wallet,
            'min_balance'              => $minBalance,
            'min_balance_formatted'    => $this->formatBRL($minBalance),
            'current_balance'          => $currentBalance,
            'current_balance_formatted'=> $this->formatBRL($currentBalance),
        ]);
    }

    #[OA\Put(
        path: '/api/v1/settings/auto-pay',
        summary: 'Atualizar configuracoes de pagamento automatico via carteira',
        description: 'Habilita ou desabilita o auto-pay e define o saldo minimo que deve permanecer na carteira apos cada debito automatico.',
        tags: ['Configuracoes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['enabled'],
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
                    new OA\Property(property: 'min_balance', type: 'number', example: 50.00),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Configuracoes atualizadas'),
            new OA\Response(response: 403, description: 'Usuario nao possui perfil de lojista'),
            new OA\Response(response: 422, description: 'Dados invalidos'),
        ]
    )]
    public function updateAutoPay(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            'enabled'     => 'required|boolean',
            'min_balance' => 'sometimes|numeric|min:0|max:100000',
        ]);

        $client->update([
            'auto_pay_from_wallet' => $validated['enabled'],
            'auto_pay_min_balance' => $validated['min_balance'] ?? $client->auto_pay_min_balance,
        ]);

        $currentBalance = (float) ClientSupplierBalance::where('client_id', $client->id)
            ->sum('balance');
        $minBalance = (float) $client->auto_pay_min_balance;

        return response()->json([
            'enabled'                  => (bool) $client->auto_pay_from_wallet,
            'min_balance'              => $minBalance,
            'min_balance_formatted'    => $this->formatBRL($minBalance),
            'current_balance'          => $currentBalance,
            'current_balance_formatted'=> $this->formatBRL($currentBalance),
        ]);
    }
}
