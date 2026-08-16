<?php
// INF-054 R4

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Federation\HubProxyHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints pra etiqueta/NF.
 *
 * Decisao 02/06: bridge get_label.php retorna 404 para loja 565 (Multdrop)
 * porque loja.id_login=null nessa loja. Fix: consulta direta ao banco legado
 * via conexao "legacy", sem bridge. get_invoice.php nao afetado.
 */
class OrderLabelInvoiceController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;
        if (!$client) {
            abort(403, "Usuario nao possui perfil de lojista.");
        }
        return $client;
    }

    /**
     * GET /api/v1/orders/{id}/label-info
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $order  = Order::where("id", $id)->where("client_id", $client->id)->firstOrFail();

        if (!empty($order->label_url)) {
            return response()->json(["data" => [
                "url"        => $order->label_url,
                "status"     => "ready",
                "tentativas" => 0,
                "source"     => "cache",
            ]]);
        }

        if (empty($order->legacy_id)) {
            return response()->json(["data" => [
                "url"        => null,
                "status"     => "no_legacy_source",
                "tentativas" => 0,
            ]]);
        }

        return $this->fetchFromLegacy($order);
    }

    /**
     * GET /api/v1/orders/{id}/label-file  (MUL-359)
     *
     * Entrega o ARQUIVO da etiqueta autenticado e autorizado por dono: o
     * seller so abre etiqueta do proprio pedido. E o endpoint que os fronts
     * passam a usar no lugar do /storage/labels publico; o publico so fecha
     * depois de todos os fronts migrarem (fases C/D da MUL-359).
     */
    public function file(Request $request, int $id)
    {
        $user   = $request->user();
        $client = $user?->client;
        // MUL-359 Fase B: papel de admin manda ANTES do perfil de lojista —
        // o super_admin do WL tem um client fantasma vinculado (user 1 ->
        // client 57 no multdrop), e o ramo de dono derrubava o admin com 404.
        // Mesmo criterio de papel do requireSupplierAdmin (MUL-244).
        if ($user && in_array($user->role, ["super_admin", "admin", "supplier"], true)) {
            $order = Order::findOrFail($id);
        } elseif ($client) {
            $order = Order::where("id", $id)->where("client_id", $client->id)->firstOrFail();
        } else {
            abort(403, "Usuario nao possui perfil de lojista.");
        }

        $candidates = array_values(array_filter([
            $order->manual_label_path,
            $order->label_url,
        ]));
        if (!$candidates) {
            abort(404, "Pedido sem etiqueta.");
        }

        foreach ($candidates as $url) {
            // Etiqueta hospedada fora (Bling/legado): proxy server-side — o
            // navegador nunca ve a origem e CORS nao entra na conversa.
            if (str_starts_with($url, "http://") || str_starts_with($url, "https://")) {
                try {
                    $res = \Illuminate\Support\Facades\Http::timeout(25)->connectTimeout(8)->get($url);
                } catch (\Throwable) {
                    continue;
                }
                if ($res->successful()) {
                    return response($res->body(), 200, [
                        "Content-Type"  => $res->header("Content-Type") ?: "application/octet-stream",
                        "Cache-Control" => "private, max-age=300",
                    ]);
                }
                continue;
            }

            $rel = ltrim($url, "/");
            if (str_starts_with($rel, "storage/")) {
                $rel = substr($rel, strlen("storage/"));
            }
            if (str_contains($rel, "..")) {
                continue; // traversal
            }
            $mime = match (strtolower(pathinfo($rel, PATHINFO_EXTENSION))) {
                "pdf"          => "application/pdf",
                "png"          => "image/png",
                "jpg", "jpeg"  => "image/jpeg",
                "gif"          => "image/gif",
                "webp"         => "image/webp",
                default        => "application/octet-stream",
            };
            $abs = \Illuminate\Support\Facades\Storage::disk("public")->path($rel);
            if (is_file($abs)) {
                return response()->file($abs, [
                    "Content-Type"  => $mime,
                    "Cache-Control" => "private, max-age=300",
                ]);
            }

            // MUL-359: etiqueta ANTIGA movida para o privado (protecao dos
            // arquivos antigos). Este endpoint e autenticado — pode servir.
            $absPriv = \Illuminate\Support\Facades\Storage::disk("local")->path($rel);
            if (is_file($absPriv)) {
                return response()->file($absPriv, [
                    "Content-Type"  => $mime,
                    "Cache-Control" => "private, max-age=300",
                ]);
            }

            // WL sem o arquivo local: busca no hub (mesma logica da rota
            // fallback MUL-355) e cacheia pra proxima leitura.
            if (config("app.tenant") !== "hubai" && str_starts_with($rel, "labels/")) {
                $hubUrl = rtrim(config("services.hubai_federation.storage_url", "https://api.hubai.io"), "/");
                try {
                    // MUL-359: segredo de federacao — permite ao hub servir arquivo
                    // que ja foi movido pro privado dele
                    $res = \Illuminate\Support\Facades\Http::timeout(25)->connectTimeout(8)
                        ->withHeaders([
                            "X-Federation-Tenant" => (string) config("app.tenant"),
                            "X-Federation-Secret" => (string) (config("services.hubai_federation.secret") ?: env("FEDERATION_HMAC_SECRET", "")),
                        ])->get($hubUrl . "/storage/" . $rel);
                } catch (\Throwable) {
                    continue;
                }
                if ($res->successful()) {
                    try {
                        // FOR-101: cacheia no disk configurado -- no backend com o
                        // publico fechado o cache nao pode reabrir a exposicao.
                        \Illuminate\Support\Facades\Storage::disk((string) config("filesystems.labels_disk", "public"))
                            ->put($rel, $res->body());
                    } catch (\Throwable) {
                        // cache e otimizacao, nao requisito
                    }
                    return response($res->body(), 200, [
                        "Content-Type"  => $mime,
                        "Cache-Control" => "private, max-age=300",
                    ]);
                }
            }
        }

        abort(404, "Arquivo de etiqueta nao encontrado.");
    }

    /**
     * POST /api/v1/orders/{id}/label-fetch
     */
    public function requestLabel(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $u = $request->user();
            $c = $u ? $u->client : null;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/label-fetch", ['client_id' => $c ? ($c->hubai_id ?? $c->id) : null]);
        }
        $client = $this->clientOrFail($request);
        $order  = Order::where("id", $id)->where("client_id", $client->id)->firstOrFail();

        if (empty($order->legacy_id)) {
            return response()->json(["data" => null, "error" => "Pedido sem legacy_id"], 422);
        }

        return $this->fetchFromLegacy($order);
    }

    /**
     * Consulta url_img diretamente no banco legado.
     * Nao usa bridge get_label.php (quebrado para loja 565: loja.id_login=null).
     */
    private function fetchFromLegacy(Order $order): JsonResponse
    {
        try {
            $row = DB::connection("legacy")
                ->table("pedidos")
                ->where("id", $order->legacy_id)
                ->select(["id", "url_img", "tentativas_etiqueta", "erro_etiqueta", "id_canal", "enviado_etiqueta"])
                ->first();
        } catch (\Throwable $e) {
            Log::error("OrderLabelInvoice: falha ao consultar legado para pedido {$order->id}: {$e->getMessage()}");
            return response()->json(["data" => null, "error" => "Erro ao consultar banco legado"], 502);
        }

        if (!$row) {
            return response()->json(["data" => null, "error" => "Pedido nao encontrado no legado"], 404);
        }

        $urlImg     = $row->url_img    ?: null;
        $tentativas = (int) ($row->tentativas_etiqueta ?? 0);
        $erro       = $row->erro_etiqueta ?? null;
        $canal      = (int) ($row->id_canal ?? 0);

        if (!empty($urlImg)) {
            $status = "ready";
        } elseif ($tentativas >= 50) {
            $status = "failed_max_retries";
        } elseif (!empty($erro)) {
            $status = "error";
        } else {
            $status = "enqueued";
        }

        if (!empty($urlImg) && $urlImg !== $order->label_url) {
            $order->updateQuietly(["label_url" => $urlImg]);
        }

        return response()->json(["data" => [
            "url"        => $urlImg,
            "status"     => $status,
            "tentativas" => $tentativas,
            "canal"      => $canal,
            "erro"       => $erro,
            "source"     => "legacy_direct",
        ]]);
    }

    /**
     * GET /api/v1/orders/{id}/invoice-info
     *
     * Usa bridge get_invoice.php (funciona corretamente, nao afetado pelo bug).
     */
    public function invoice(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOrFail($request);
        $order  = Order::where("id", $id)->where("client_id", $client->id)->firstOrFail();

        if (!empty($order->invoice_number) && !empty($order->invoice_xml)) {
            return response()->json(["data" => [
                "numero"           => $order->invoice_number,
                "serie"            => $order->invoice_series,
                "chave"            => $order->invoice_access_key,
                "xml"              => $order->invoice_xml,
                "status"           => $order->invoice_status,
                "emitida_em"       => $order->invoice_issued_at,
                "status_resultado" => "issued",
                "source"           => "cache",
            ]]);
        }

        if (empty($order->legacy_id)) {
            return response()->json(["data" => [
                "status_resultado" => "not_issued",
                "source"           => "no_legacy_source",
            ]]);
        }

        $client      = $order->client;
        $legacyLogin = $client?->legacy_id_login ?? 0;
        if (!$legacyLogin) {
            return response()->json(["data" => [
                "status_resultado" => "not_issued",
                "source"           => "no_legacy_login",
            ]]);
        }

        $bridge = app(\App\Services\GoolhubBridgeService::class);
        $result = $bridge->getInvoice((int) $legacyLogin, (int) $order->legacy_id);

        if (!$result["success"]) {
            return response()->json(["data" => null, "error" => $result["error"] ?? "Bridge falhou"], 502);
        }

        $data = $result["data"] ?? [];

        if (($data["status_resultado"] ?? null) === "issued" && !empty($data["numero"])) {
            $order->updateQuietly([
                "invoice_number"     => $data["numero"]     ?? null,
                "invoice_series"     => $data["serie"]      ?? null,
                "invoice_access_key" => $data["chave"]      ?? null,
                "invoice_xml"        => $data["xml"]        ?? null,
                "invoice_status"     => $data["status"]     ?? null,
                "invoice_issued_at"  => $data["emitida_em"] ?? null,
            ]);
        }

        return response()->json(["data" => array_merge($data, ["source" => "bridge"])]);
    }

    /**
     * INF-054 R4: requestLabel via federation. Consulta legado.
     */
    public function requestLabelFromFederation(Request $request, int $id): JsonResponse
    {
        $request->validate(['client_id' => ['required', 'integer']]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = \App\Models\Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        $tid = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        if (!$tid || !\DB::table('tenant_supplier')->where('tenant_id', $tid)->where('supplier_id', $order->supplier_id)->exists()) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        if (empty($order->legacy_id)) return response()->json(['data' => null, 'error' => 'Pedido sem legacy_id'], 422);
        $resp = $this->fetchFromLegacy($order);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'label_fetch']);
        return $resp;
    }

}
