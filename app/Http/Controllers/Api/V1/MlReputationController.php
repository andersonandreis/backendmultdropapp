<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/v1/ml/seller-reputation/{seller_id}?client_id={id}
 *
 * Retorna dados de reputacao do vendedor do Mercado Livre.
 * client_id (opcional): se fornecido, usa o token OAuth do cliente para
 * contornar bloqueio de IP de datacenter na API publica do ML.
 * Resultado cacheado por 30 minutos.
 */
class MlReputationController extends Controller
{
    /** Mapeamento de level_id para label legivel e cor hex. */
    private const LEVEL_MAP = [
        '5_green'       => ['label' => 'Excelente', 'color' => '#00a650'],
        '4_light_green' => ['label' => 'Otimo',     'color' => '#39b54a'],
        '3_yellow'      => ['label' => 'Bom',       'color' => '#f5b40a'],
        '2_orange'      => ['label' => 'Regular',   'color' => '#f27b27'],
        '1_red'         => ['label' => 'Iniciante', 'color' => '#e02020'],
    ];

    public function __construct(private readonly MercadoLivreService $mlService)
    {
    }

    public function show(Request $request, string $seller_id): JsonResponse
    {
        // Validacao simples: seller_id deve ser numerico
        if (! ctype_digit($seller_id)) {
            return response()->json([
                'success' => false,
                'error'   => 'seller_id deve ser numerico.',
            ], 422);
        }

        $clientId = $request->query('client_id');
        $cacheKey = "ml_seller_{$seller_id}";

        $result = Cache::remember($cacheKey, 1800, function () use ($seller_id, $clientId) {
            $token = $this->resolveToken($clientId);

            $httpClient = Http::timeout(10)->withHeaders([
                'User-Agent' => 'HubAI/1.0 (+https://api.hubai.io)',
                'Accept'     => 'application/json',
            ]);

            if ($token) {
                $httpClient = $httpClient->withToken($token);
            }

            $response = $httpClient->get("https://api.mercadolibre.com/users/{$seller_id}");

            if ($response->failed()) {
                Log::warning('[MlReputationController] API ML retornou erro', [
                    'seller_id'   => $seller_id,
                    'http_status' => $response->status(),
                    'has_token'   => ! empty($token),
                ]);
                return null;
            }

            return $response->json();
        });

        if (is_null($result)) {
            return response()->json([
                'success' => false,
                'error'   => 'Vendedor nao encontrado ou erro ao consultar o Mercado Livre. Tente informar client_id na query.',
            ], 404);
        }

        return response()->json($this->transform($result));
    }

    /**
     * Resolve token ML a partir do client_id (se fornecido).
     * Retorna null se client_id nao informado ou conta nao encontrada.
     */
    private function resolveToken(?string $clientId): ?string
    {
        if (empty($clientId) || ! is_numeric($clientId)) {
            return null;
        }

        $account = MarketplaceAccount::where('client_id', (int) $clientId)
            ->where('platform', 'mercadolivre')
            ->whereIn('status', ['active', 'connected'])
            ->first();

        if (! $account) {
            return null;
        }

        return $this->mlService->getAccessToken($account);
    }

    /** Transforma a resposta bruta da API ML no formato do frontend. */
    private function transform(array $data): array
    {
        $rep       = $data['seller_reputation'] ?? [];
        $levelId   = $rep['level_id'] ?? null;
        $levelInfo = self::LEVEL_MAP[$levelId] ?? ['label' => 'Novo', 'color' => '#999999'];

        $transactions = $rep['transactions'] ?? [];
        $total        = (int) ($transactions['total'] ?? 0);
        $ratings      = $transactions['ratings'] ?? [];
        $positive     = (int) ($ratings['positive'] ?? 0);
        $negative     = (int) ($ratings['negative'] ?? 0);

        $positiveRate = $total > 0 ? round(($positive / $total) * 100, 2) : 100.0;
        $negativeRate = $total > 0 ? round(($negative / $total) * 100, 2) : 0.0;

        $metrics     = $rep['metrics'] ?? [];
        $claimsRate  = (float) ($metrics['claims']['rate'] ?? 0);
        $cancelRate  = (float) ($metrics['cancellations']['rate'] ?? 0);
        $delayedRate = (float) ($metrics['delayed_handling_time']['rate'] ?? 0);
        $sales60d    = (int) ($metrics['sales']['completed'] ?? 0);

        $siteStatus  = $data['status']['site_status'] ?? 'unknown';
        $statusLabel = $siteStatus === 'active' ? 'Conta ativa' : 'Conta inativa';

        return [
            'success'            => true,
            'seller_id'          => (int) ($data['id'] ?? 0),
            'nickname'           => $data['nickname'] ?? '',
            'reputation_level'   => $levelId,
            'reputation_label'   => $levelInfo['label'],
            'reputation_color'   => $levelInfo['color'],
            'status'             => $siteStatus,
            'status_label'       => $statusLabel,
            'transactions_total' => $total,
            'positive_rate'      => $positiveRate,
            'negative_rate'      => $negativeRate,
            'claims_rate'        => $claimsRate,
            'cancellations_rate' => $cancelRate,
            'delayed_rate'       => $delayedRate,
            'sales_60d'          => $sales60d,
        ];
    }
}
