<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ClientDiscountController extends Controller
{
    #[OA\Get(
        path: '/api/v1/client/discount-info',
        summary: 'Informacoes do desconto gradual do lojista',
        description: 'Retorna o desconto atual do lojista, quantas vendas ja realizou, se o ramp de reducao ja iniciou e estimativa de dias ate zerar.',
        tags: ['Descontos'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados de desconto do lojista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'current_discount_percent', type: 'integer', example: 50, description: 'Percentual de desconto atual (0-50)'),
                        new OA\Property(property: 'sales_count', type: 'integer', example: 2, description: 'Quantas vendas ja realizou com desconto ativo'),
                        new OA\Property(property: 'ramp_started', type: 'boolean', example: false, description: 'Se o ramp de reducao ja iniciou'),
                        new OA\Property(property: 'ramp_start_date', type: 'string', format: 'date-time', nullable: true, example: null),
                        new OA\Property(property: 'days_until_zero', type: 'integer', nullable: true, example: null, description: 'Quantos dias faltam para o desconto zerar (null se ramp nao iniciou)'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Nao autenticado'),
            new OA\Response(response: 403, description: 'Usuario sem perfil de lojista'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $client = $request->user()->client;

        if (! $client) {
            return response()->json(['message' => 'Usuario nao possui perfil de lojista.'], 403);
        }

        $rampStarted   = ! is_null($client->discount_ramp_start);
        $daysUntilZero = null;

        if ($rampStarted && $client->current_discount_percent > 0) {
            // dias decorridos desde o inicio do ramp
            $diasDecorridos = (int) now()->diffInDays($client->discount_ramp_start);
            // falta: (desconto atual) dias para chegar a zero
            $daysUntilZero  = max(0, (int) $client->current_discount_percent);
        }

        return response()->json([
            'current_discount_percent' => (int) ($client->current_discount_percent ?? 50),
            'sales_count'              => (int) ($client->discount_sales_count ?? 0),
            'ramp_started'             => $rampStarted,
            'ramp_start_date'          => $client->discount_ramp_start?->toIso8601String(),
            'days_until_zero'          => $daysUntilZero,
        ]);
    }
}
