<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropModuleConfig;
use App\Models\Drop\DropStripeEvent;
use App\Services\Drop\ProfitReportService;
use App\Services\Drop\Stripe\StripeDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API endpoints autenticados para Stripe Connect Express no modulo Drop Internacional.
 */
class DropStripeApiController extends Controller
{
    public function __construct(
        private readonly StripeDropService   $stripeService,
        private readonly ProfitReportService $reportService
    ) {}

    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, "Usuario nao possui perfil de lojista.");
        }
        return $client;
    }

    /**
     * POST /api/v1/drop/stripe/connect
     * Inicia o onboarding Stripe Connect Express. Retorna {url}.
     */
    public function connect(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $url    = $this->stripeService->createConnectAccount($client->id);

        return response()->json(["data" => ["url" => $url]]);
    }

    /**
     * GET /api/v1/drop/stripe/status
     * Retorna status da conta Connect do cliente.
     */
    public function status(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $config = DropModuleConfig::where("client_id", $client->id)->first();

        if (!$config || empty($config->stripe_account_id)) {
            return response()->json([
                "data" => ["connected" => false, "stripe_account_id" => null, "status" => null],
            ]);
        }

        $status = $this->stripeService->getAccountStatus($config->stripe_account_id);

        $config->stripe_account_status = $status["charges_enabled"] ? "active" : "pending";
        $config->save();

        return response()->json([
            "data" => array_merge(
                ["connected" => true, "stripe_account_id" => $config->stripe_account_id, "db_status" => $config->stripe_account_status],
                $status
            ),
        ]);
    }

    /**
     * GET /api/v1/drop/stripe/events
     * Lista eventos Stripe do cliente paginado.
     */
    public function events(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $events = DropStripeEvent::forClient($client->id)->latest()->paginate(15);

        return response()->json(["data" => $events]);
    }

    /**
     * GET /api/v1/drop/financial/report?start=YYYY-MM-DD&end=YYYY-MM-DD
     * Gera relatorio financeiro do periodo.
     */
    public function financialReport(Request $request): JsonResponse
    {
        $client = $this->clientOrFail($request);

        $validated = $request->validate([
            "start" => "required|date_format:Y-m-d",
            "end"   => "required|date_format:Y-m-d|after_or_equal:start",
        ]);

        $report = $this->reportService->generateReport($client->id, $validated["start"], $validated["end"]);

        return response()->json([
            "data" => array_merge($report->toArray(), ["net_margin_pct" => $report->net_margin_pct]),
        ]);
    }
}
