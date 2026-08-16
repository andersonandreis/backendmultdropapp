<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropTrackingPixel;
use App\Services\Drop\AttributionService;
use App\Services\Drop\DropTrackingEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Gerencia pixels de rastreamento e dados de atribuicao do modulo Drop.
 *
 * Endpoints:
 *   GET    /api/v1/drop/pixels
 *   POST   /api/v1/drop/pixels
 *   PUT    /api/v1/drop/pixels/{id}
 *   DELETE /api/v1/drop/pixels/{id}
 *   POST   /api/v1/drop/sessions
 *   GET    /api/v1/drop/attribution/performance
 *   GET    /api/v1/drop/attribution/campaigns
 */
class DropTrackingController extends Controller
{
    public function __construct(
        private readonly DropTrackingEventService $trackingService,
        private readonly AttributionService $attributionService,
    ) {}

    // =========================================================================
    // Pixels — CRUD
    // =========================================================================

    /**
     * Lista pixels configurados do cliente autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $pixels = DropTrackingPixel::where('client_id', $clientId)
            ->orderBy('platform')
            ->get()
            ->map(fn($p) => $this->formatPixel($p));

        return response()->json(['data' => $pixels]);
    }

    /**
     * Cria um novo pixel de rastreamento.
     */
    public function store(Request $request): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $validated = $request->validate([
            'platform'        => ['required', Rule::in(['meta', 'google', 'tiktok'])],
            'pixel_id'        => ['required', 'string', 'max:64'],
            'access_token'    => ['nullable', 'string'],
            'test_event_code' => ['nullable', 'string', 'max:32'],
            'is_active'       => ['nullable', 'boolean'],
        ]);

        // Unicidade [client_id, platform, pixel_id]
        $exists = DropTrackingPixel::where('client_id', $clientId)
            ->where('platform', $validated['platform'])
            ->where('pixel_id', $validated['pixel_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Pixel ja cadastrado para essa plataforma.',
            ], 422);
        }

        $pixel = DropTrackingPixel::create(array_merge(
            $validated,
            ['client_id' => $clientId]
        ));

        return response()->json(['data' => $this->formatPixel($pixel)], 201);
    }

    /**
     * Atualiza pixel existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $pixel = DropTrackingPixel::where('id', $id)
            ->where('client_id', $clientId)
            ->firstOrFail();

        $validated = $request->validate([
            'access_token'    => ['nullable', 'string'],
            'test_event_code' => ['nullable', 'string', 'max:32'],
            'is_active'       => ['nullable', 'boolean'],
        ]);

        $pixel->update($validated);

        return response()->json(['data' => $this->formatPixel($pixel->fresh())]);
    }

    /**
     * Remove pixel.
     */
    public function destroy(int $id): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $pixel = DropTrackingPixel::where('id', $id)
            ->where('client_id', $clientId)
            ->firstOrFail();

        $pixel->delete();

        return response()->json(['message' => 'Pixel removido com sucesso.']);
    }

    // =========================================================================
    // Sessoes de atribuicao
    // =========================================================================

    /**
     * Registra uma sessao de atribuicao vinda do script da loja Shopify.
     * Endpoint chamado pelo snippet JS no storefront.
     */
    public function storeSession(Request $request): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $validated = $request->validate([
            'session_id'   => ['required', 'string', 'max:128'],
            'fbp'          => ['nullable', 'string', 'max:128'],
            'fbc'          => ['nullable', 'string', 'max:128'],
            'gclid'        => ['nullable', 'string', 'max:128'],
            'ttclid'       => ['nullable', 'string', 'max:128'],
            'utm_source'   => ['nullable', 'string', 'max:64'],
            'utm_campaign' => ['nullable', 'string', 'max:128'],
            'utm_medium'   => ['nullable', 'string', 'max:64'],
            'utm_content'  => ['nullable', 'string', 'max:128'],
            'utm_term'     => ['nullable', 'string', 'max:128'],
            'landing_url'  => ['nullable', 'string', 'max:2048'],
            'user_agent'   => ['nullable', 'string', 'max:512'],
        ]);

        // IP anonimizado pelo servico
        $validated['ip'] = $request->ip();

        $session = $this->trackingService->trackSession($clientId, $validated);

        return response()->json([
            'data' => ['session_id' => $session->session_id],
        ], 201);
    }

    // =========================================================================
    // Attribution — relatorios
    // =========================================================================

    /**
     * Performance por canal (utm_source) num periodo.
     *
     * Query params: start_date (Y-m-d), end_date (Y-m-d)
     */
    public function channelPerformance(Request $request): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $data = $this->attributionService->getChannelPerformance(
            $clientId,
            $request->input('start_date'),
            $request->input('end_date'),
        );

        return response()->json(['data' => $data]);
    }

    /**
     * ROAS estimado por campanha (utm_campaign) num periodo.
     *
     * Query params: start_date (Y-m-d), end_date (Y-m-d), campaign (string)
     */
    public function campaignRoas(Request $request): JsonResponse
    {
        $clientId = Auth::user()->client_id;

        $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date'   => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'campaign'   => ['nullable', 'string', 'max:128'],
        ]);

        $startDate = $request->input('start_date');
        $endDate   = $request->input('end_date');
        $campaign  = $request->input('campaign');

        // Se campanha especificada, retorna dados daquela campanha
        if ($campaign) {
            $data = $this->attributionService->calculateCampaignRoas(
                $clientId,
                $campaign,
                $startDate,
                $endDate,
            );
            return response()->json(['data' => $data]);
        }

        // Sem campanha, retorna todas as campanhas do periodo
        $campaigns = \DB::table('drop_orders')
            ->where('client_id', $clientId)
            ->whereNotNull('utm_campaign')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->distinct()
            ->pluck('utm_campaign');

        $results = $campaigns->map(fn($c) => $this->attributionService->calculateCampaignRoas(
            $clientId,
            $c,
            $startDate,
            $endDate,
        ))->values()->toArray();

        return response()->json(['data' => $results]);
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    /**
     * Formata pixel para resposta — nunca expoe access_token em texto claro.
     */
    private function formatPixel(DropTrackingPixel $pixel): array
    {
        return [
            'id'              => $pixel->id,
            'platform'        => $pixel->platform,
            'pixel_id'        => $pixel->pixel_id,
            'has_access_token'=> !empty($pixel->access_token),
            'test_event_code' => $pixel->test_event_code,
            'is_active'       => $pixel->is_active,
            'created_at'      => $pixel->created_at?->toISOString(),
            'updated_at'      => $pixel->updated_at?->toISOString(),
        ];
    }
}
