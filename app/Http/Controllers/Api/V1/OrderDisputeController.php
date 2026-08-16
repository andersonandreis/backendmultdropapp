<?php
// INF-054 R4 F3
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDispute;
use App\Models\OrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Federation\HubProxyHelper;
use Illuminate\Support\Facades\Auth;

class OrderDisputeController extends Controller
{
    /**
     * POST /api/v1/orders/{id}/dispute
     * Abre uma disputa para um pedido entregue.
     * Aceita upload de NF (xml e pdf) ou URLs externas.
     */
    public function dispute(Request $request, int $id): JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->only(['reason','description','invoice_xml_url','invoice_pdf_url']);
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/dispute", $body);
        }
        $user = Auth::user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'reason'          => ['required', 'string', 'max:200'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'invoice_xml'     => ['nullable', 'file', 'mimes:xml', 'max:10240'],
            'invoice_pdf'     => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
            'invoice_xml_url' => ['nullable', 'url', 'max:500'],
            'invoice_pdf_url' => ['nullable', 'url', 'max:500'],
        ]);

        $order = Order::findOrFail($id);

        $isAdmin = in_array($user->role ?? '', ['super_admin', 'admin', 'staff']);

        if (! $isAdmin) {
            $client = $user->client;
            if (! $client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
        }

        if ($order->status !== 'delivered') {
            return response()->json([
                'error'        => 'Only delivered orders can be disputed.',
                'order_status' => $order->status,
            ], 422);
        }

        $existing = OrderDispute::where('order_id', $id)
            ->whereIn('status', ['open', 'in_review'])
            ->first();

        if ($existing) {
            return response()->json([
                'error'      => 'There is already an open dispute for this order.',
                'dispute_id' => $existing->id,
                'status'     => $existing->status,
            ], 409);
        }

        $xmlPath = null;
        $pdfPath = null;
        $xmlUrl  = $validated['invoice_xml_url'] ?? null;
        $pdfUrl  = $validated['invoice_pdf_url'] ?? null;

        if ($request->hasFile('invoice_xml')) {
            $xmlPath = $request->file('invoice_xml')->storeAs(
                'disputes/' . $id,
                'invoice_' . time() . '.xml',
                'local'
            );
            $xmlUrl = null;
        }

        if ($request->hasFile('invoice_pdf')) {
            $pdfPath = $request->file('invoice_pdf')->storeAs(
                'disputes/' . $id,
                'invoice_' . time() . '.pdf',
                'local'
            );
            $pdfUrl = null;
        }

        $dispute = OrderDispute::create([
            'order_id'          => $id,
            'status'            => 'open',
            'reason'            => $validated['reason'],
            'description'       => $validated['description'] ?? null,
            'invoice_xml_path'  => $xmlPath,
            'invoice_pdf_path'  => $pdfPath,
            'invoice_xml_url'   => $xmlUrl,
            'invoice_pdf_url'   => $pdfUrl,
            'opened_by_user_id' => $user->id,
        ]);

        OrderEvent::create([
            'order_id'    => $id,
            'event_type'  => 'dispute_opened',
            'user_id'     => $user->id,
            'description' => 'Disputa aberta: ' . $validated['reason'],
            'metadata'    => json_encode([
                'dispute_id' => $dispute->id,
                'reason'     => $validated['reason'],
                'has_xml'    => ! is_null($xmlPath) || ! is_null($xmlUrl),
                'has_pdf'    => ! is_null($pdfPath) || ! is_null($pdfUrl),
            ]),
        ]);

        return response()->json([
            'success' => true,
            'dispute' => $dispute->fresh(),
        ], 201);
    }

    // MUL-214 item 11: NF de devolucao real nao carrega "ID Multdrop" nas observacoes;
    // o vinculo confiavel e o destinatario da NF (CPF/CNPJ ou nome) = cliente do pedido.
    private function noteMatchesOrder(array $nfe, \App\Models\Order $order): bool
    {
        $obs = strtolower((string) ($nfe['observacoes'] ?? $nfe['informacoesAdicionais'] ?? ''));
        if ($obs !== '' && (str_contains($obs, 'id multidrop ' . $order->id)
            || str_contains($obs, 'id multdrop ' . $order->id)
            || str_contains($obs, '#' . $order->id))) {
            return true;
        }
        $nfDoc    = preg_replace('/\D/', '', (string) ($nfe['contato']['numeroDocumento'] ?? ''));
        $orderDoc = preg_replace('/\D/', '', (string) ($order->customer_document_number ?? ''));
        if ($nfDoc !== '' && $orderDoc !== '' && $nfDoc === $orderDoc) {
            return true;
        }
        $norm      = fn($s) => trim(mb_strtolower(preg_replace('/\s+/', ' ', (string) $s)));
        $nfName    = $norm($nfe['contato']['nome'] ?? '');
        $orderName = $norm($order->customer_name ?? '');
        return $nfName !== '' && $orderName !== '' && $nfName === $orderName;
    }

    // MUL-161-BE1 #13a: GET /api/v1/orders/{id}/dispute/available-notes
    public function availableNotes(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user  = \Illuminate\Support\Facades\Auth::user();
        $order = \App\Models\Order::findOrFail($id);
        $isAdmin = in_array($user->role ?? '', ['super_admin', 'admin', 'staff']);
        if (! $isAdmin) {
            $client = $user->client;
            if (! $client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
        }
        // MUL-214 item 11: as NFs de devolucao ficam no Bling do LOJISTA (MarketplaceAccount
        // do cliente do pedido); fallback pro Bling ERP do fornecedor.
        $blingAccount = \App\Models\MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'bling')->where('status', 'active')->first()
            ?? \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
                ->where('platform', 'bling')->where('status', 'active')->first();
        if (! $blingAccount) {
            return response()->json(['data' => [], 'note' => 'Nenhuma conta Bling ativa para este pedido.']);
        }
        try {
            $blingClient = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);
            // Bling v3: devolucao = NF de ENTRADA (tipo 0); situacao 5 = autorizada.
            $response = $blingClient->get($blingAccount, '/nfe', ['tipo' => 0, 'limite' => 50]);
            $nfes = $response['data'] ?? [];
            $usedUrls = \App\Models\OrderDispute::where('order_id', $id)
                ->get()->flatMap(fn($d) => [$d->invoice_xml_url, $d->invoice_pdf_url])
                ->filter()->values()->toArray();
            $result = [];
            foreach ($nfes as $nfe) {
                if ((int) ($nfe['situacao'] ?? 0) !== 5) continue;
                if (! $this->noteMatchesOrder($nfe, $order)) continue;
                $accessKey = (string) ($nfe['chaveAcesso'] ?? '');
                $imported = $accessKey !== '' && collect($usedUrls)
                    ->contains(fn($u) => str_contains((string) $u, $accessKey));
                $result[] = [
                    'bling_note_id'    => $nfe['id'] ?? null,
                    'numero'           => $nfe['numero'] ?? null,
                    'serie'            => $nfe['serie'] ?? null,
                    'access_key'       => $nfe['chaveAcesso'] ?? null,
                    'issued_at'        => $nfe['dataEmissao'] ?? null,
                    'contact_name'     => $nfe['contato']['nome'] ?? null,
                    'already_imported' => $imported,
                ];
            }
            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'note' => 'Erro Bling: '.$e->getMessage()]);
        }
    }

    // MUL-161-BE1 #13b: POST /api/v1/orders/{id}/dispute/import-note
    public function importNote(\Illuminate\Http\Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        if (HubProxyHelper::isWl()) {
            $order = \App\Models\Order::find($id);
            $hubId = $order && $order->hubai_order_id ? $order->hubai_order_id : $id;
            $u = $request->user();
            $c = $u ? $u->client : null;
            $body = $request->only(['bling_note_id']);
            $body['client_id'] = $c ? ($c->hubai_id ?? $c->id) : null;
            return HubProxyHelper::forwardToHub('post', "/orders/$hubId/dispute/import-note", $body);
        }
        $user = \Illuminate\Support\Facades\Auth::user();
        $order = \App\Models\Order::findOrFail($id);
        $isAdmin = in_array($user->role ?? '', ['super_admin', 'admin', 'staff']);
        if (! $isAdmin) {
            $client = $user->client;
            if (! $client || $order->client_id !== $client->id) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
        }
        $validated = $request->validate(['bling_note_id' => 'required|integer']);
        $blingAccount = \App\Models\MarketplaceAccount::where('client_id', $order->client_id)
            ->where('platform', 'bling')->where('status', 'active')->first()
            ?? \App\Models\ErpAccount::where('supplier_id', $order->supplier_id)
                ->where('platform', 'bling')->where('status', 'active')->first();
        if (! $blingAccount) {
            return response()->json(['error' => 'Conta Bling nao encontrada para este pedido.'], 422);
        }
        try {
            $blingClient = app(\App\Services\Integrations\Erps\Bling\BlingApiClient::class);
            $nfeResp = $blingClient->getNfe($blingAccount, (int)$validated['bling_note_id']);
            $nfe = $nfeResp['data'] ?? null;
            if (! $nfe) {
                return response()->json(['error' => 'NF nao encontrada no Bling.'], 404);
            }
            if ((int) ($nfe['situacao'] ?? 0) !== 5) {
                return response()->json(['error' => 'Esta NF nao esta autorizada no Bling.'], 422);
            }
            // MUL-214 item 11a: revalida no import que a nota pertence a ESTE pedido
            // (mesma regra da listagem: observacoes, CPF/CNPJ ou nome do destinatario).
            if (! $this->noteMatchesOrder($nfe, $order)) {
                return response()->json([
                    'error' => 'Esta nota nao pertence a este pedido (cliente/documento nao confere com a NF).',
                ], 422);
            }

            $xmlUrl = $nfe['xml'] ?? $nfe['linkXmlDanfe'] ?? null;
            $pdfUrl = $nfe['linkPDF'] ?? $nfe['linkDanfe'] ?? null;

            // MUL-214 item 11b: impede reuso da mesma nota de devolucao em OUTRO pedido.
            if ($xmlUrl || $pdfUrl) {
                $reused = \App\Models\OrderDispute::where('order_id', '!=', $id)
                    ->where(function ($q) use ($xmlUrl, $pdfUrl) {
                        if ($xmlUrl) {
                            $q->orWhere('invoice_xml_url', $xmlUrl);
                        }
                        if ($pdfUrl) {
                            $q->orWhere('invoice_pdf_url', $pdfUrl);
                        }
                    })->first();
                if ($reused) {
                    return response()->json([
                        'error' => 'Esta nota de devolucao ja foi usada na disputa do pedido #' . $reused->order_id . '.',
                    ], 422);
                }
            }

            $dispute = \App\Models\OrderDispute::where('order_id', $id)
                ->whereIn('status', ['open', 'in_review'])->first();
            if ($dispute) {
                $dispute->update(['invoice_xml_url' => $xmlUrl, 'invoice_pdf_url' => $pdfUrl]);
            } else {
                $dispute = \App\Models\OrderDispute::create([
                    'order_id' => $id, 'status' => 'open',
                    'reason'   => 'Nota de devolucao importada do Bling',
                    'invoice_xml_url' => $xmlUrl, 'invoice_pdf_url' => $pdfUrl,
                    'opened_by_user_id' => $user->id,
                ]);
            }
            return response()->json([
                'success'    => true,
                'dispute_id' => $dispute->id,
                'xml_url'    => $xmlUrl,
                'pdf_url'    => $pdfUrl,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro: '.$e->getMessage()], 500);
        }
    }

    /**
     * INF-054 R4 F3: dispute via federation.
     */
    public function disputeFromFederation(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'client_id'       => ['required', 'integer'],
            'reason'          => ['required', 'string', 'max:200'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'invoice_xml_url' => ['nullable', 'url', 'max:500'],
            'invoice_pdf_url' => ['nullable', 'url', 'max:500'],
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = \App\Models\Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        if (!$this->tenantAuthorizedDispute($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        if ($order->status !== 'delivered') {
            return response()->json(['error' => 'Only delivered orders can be disputed.', 'order_status' => $order->status], 422);
        }
        $existing = \App\Models\OrderDispute::where('order_id', $id)
            ->whereIn('status', ['open', 'in_review'])->first();
        if ($existing) {
            return response()->json(['error' => 'Already has open dispute', 'dispute_id' => $existing->id], 422);
        }
        $dispute = \App\Models\OrderDispute::create([
            'order_id'        => $id,
            'client_id'       => $client->id,
            'reason'          => $request->input('reason'),
            'description'     => $request->input('description'),
            'invoice_xml_url' => $request->input('invoice_xml_url'),
            'invoice_pdf_url' => $request->input('invoice_pdf_url'),
            'status'          => 'open',
            'created_by'      => null,
        ]);
        \App\Jobs\FanoutOrderWebhookJob::dispatch($order->id, 'order.updated', ['source_wl' => $tenantSlug, 'action' => 'dispute_open', 'dispute_id' => $dispute->id]);
        return response()->json(['success' => true, 'dispute_id' => $dispute->id]);
    }

    /**
     * INF-054 R4 F3: importNote via federation. Chama Bling do HUB.
     */
    public function importNoteFromFederation(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'client_id'     => ['required', 'integer'],
            'bling_note_id' => ['required', 'integer'],
        ]);
        $tenantSlug = $request->attributes->get('federation_tenant');
        $client = \App\Models\Client::find($request->input('client_id'));
        if (!$client) return response()->json(['error' => 'client_not_found'], 404);
        $order = \App\Models\Order::where('id', $id)->where('client_id', $client->id)->first();
        if (!$order) return response()->json(['error' => 'order_not_found'], 404);
        if (!$this->tenantAuthorizedDispute($tenantSlug, $order)) {
            return response()->json(['error' => 'tenant_not_authorized'], 403);
        }
        // Reusa lógica original: fake auth pra reutilizar importNote()
        $request->setUserResolver(function () use ($client) {
            $u = new \stdClass();
            $u->id = null;
            $u->role = 'client';
            $u->client = $client;
            return $u;
        });
        return $this->importNote($request, $id);
    }

    private function tenantAuthorizedDispute(?string $tenantSlug, \App\Models\Order $order): bool
    {
        if (!$tenantSlug || !$order->supplier_id) return false;
        $tid = \DB::table('tenants')->where('slug', $tenantSlug)->value('id');
        if (!$tid) return false;
        return \DB::table('tenant_supplier')->where('tenant_id', $tid)->where('supplier_id', $order->supplier_id)->exists();
    }

}
