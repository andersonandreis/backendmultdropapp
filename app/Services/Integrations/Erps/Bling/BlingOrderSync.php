<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ClientProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BlingOrderSync
{
    public function __construct(
        protected BlingApiClient $client
    ) {}

    // ---------------------------------------------------------------
    // Export: App -> Bling (create sales order for NF-e emission)
    // ---------------------------------------------------------------

    /**
     * Export an Order from HubAI to Bling as a "pedido de venda".
     *
     * Creates the order in Bling so the seller can emit NF-e from there.
     * Returns the Bling response array on success, or false on failure.
     *
     * NOTE: The Order model should have a `bling_order_id` column (string, nullable)
     *       to store the Bling ID. Add it via migration if not present:
     *       $table->string('bling_order_id')->nullable()->after('external_order_id');
     */
    public function exportOrder(ErpAccount|MarketplaceAccount $account, Order $order): array|false
    {
        try {
            $order->loadMissing(['items', 'items.product']);

            $payload = $this->buildExportPayload($order);

            Log::info('[BlingOrderSync] Exporting order to Bling', [
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
            ]);

            $response = $this->client->createOrder($account, $payload);

            $blingOrderId = $response['data']['id'] ?? null;

            if ($blingOrderId) {
                // Persist the Bling order ID back on the Order model.
                // Requires `bling_order_id` column — see NOTE above.
                if (in_array('bling_order_id', $order->getFillable())) {
                    $order->update(['bling_order_id' => (string) $blingOrderId]);
                } else {
                    // Fallback: save via raw query to avoid fillable guard
                    \Illuminate\Support\Facades\DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['bling_order_id' => (string) $blingOrderId]);
                }

                Log::info('[BlingOrderSync] Order exported successfully', [
                    'order_id'       => $order->id,
                    'bling_order_id' => $blingOrderId,
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('[BlingOrderSync] Export failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build the Bling v3 payload for creating a sales order (pedido de venda).
     */
    protected function buildExportPayload(Order $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            $product = $item->product;

            $items[] = [
                'codigo'     => $item->sku ?? $product?->sku ?? '',
                'descricao'  => $item->name ?? $product?->name ?? 'Item',
                'unidade'    => 'UN',
                'quantidade' => (float) $item->quantity,
                'valor'      => (float) $item->unit_price,
            ];

            // NOV-203: produto com codigo de servico gera linha extra (mesma qtd)
            if ($product?->service_sku) {
                $items[] = [
                    'codigo'     => $product->service_sku,
                    'descricao'  => 'Servico embalagem - ' . ($item->name ?? $product->name ?? 'Item'),
                    'unidade'    => 'UN',
                    'quantidade' => (float) $item->quantity,
                    'valor'      => 0.0,
                ];
            }
        }

        // Determine customer type (F = pessoa fisica, J = pessoa juridica)
        $docNumber = $order->customer_document_number ?? '';
        $docClean = preg_replace('/\D/', '', $docNumber);
        $tipoPessoa = strlen($docClean) > 11 ? 'J' : 'F';

        // Build address from the order's customer_address JSON field
        $address = is_array($order->customer_address) ? $order->customer_address : [];

        $contato = [
            'nome'             => $order->customer_name ?? 'Consumidor Final',
            'tipoPessoa'       => $tipoPessoa,
            'numeroDocumento'  => $docClean ?: null,
            'email'            => $order->customer_email ?? null,
            'telefone'         => $order->customer_phone ?? null,
        ];

        // Remove null values so Bling doesn't reject empty fields
        $contato = array_filter($contato, fn ($v) => $v !== null && $v !== '');

        $payload = [
            'numero'            => $order->order_number,
            'data'              => $order->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'contato'           => $contato,
            'itens'             => $items,
            'totalProdutos'     => (float) $order->subtotal ?: (float) $order->total,
            'observacoes'       => "Pedido HubAI #{$order->id}",
            'observacoesInternas' => "source={$order->source} external_id={$order->external_order_id}",
        ];

        // Transport / shipping info
        if ($order->shipping_cost && (float) $order->shipping_cost > 0) {
            $payload['transporte'] = [
                'frete' => (float) $order->shipping_cost,
            ];
        }

        return $payload;
    }

    // ---------------------------------------------------------------
    // MUL-264: Export supplier-mode (fornecedor -> seller, contato=seller)
    // ---------------------------------------------------------------

    public function exportSupplierOrder(\App\Models\ErpAccount $erp, \App\Models\Order $order): array|false
    {
        try {
            $order->loadMissing(['items','items.product','client','client.user','marketplaceAccount.client.user']);
            // MUL-268: o cliente faturado no Bling e o SELLER dono da CONTA que originou o
            // pedido — nunca o client_id gravado no pedido (pode vir errado de importacao).
            $sellerClient = $order->marketplaceAccount?->client ?: $order->client;
            if ($order->marketplace_account_id && !$order->marketplaceAccount?->client) {
                \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Conta do pedido sem client associado — fallback pro client do pedido', ['order_id'=>$order->id, 'marketplace_account_id'=>$order->marketplace_account_id]);
            }
            $contactId = $this->resolveSellerContact($erp, $sellerClient);
            if (!$contactId) {
                \Illuminate\Support\Facades\Log::error('[BlingOrderSync] Nao foi possivel resolver contato do seller', ['order_id'=>$order->id, 'client_id'=>$order->client?->id, 'seller_client_id'=>$sellerClient?->id]);
                return false;
            }
            $payload = $this->buildSupplierPayload($order, $erp, $contactId, $sellerClient);
            $existing = $order->bling_pedido_id ? (int) $order->bling_pedido_id : null;
            \Illuminate\Support\Facades\Log::info('[BlingOrderSync] exportSupplierOrder', ['order_id'=>$order->id, 'seller_id'=>$sellerClient?->id, 'bling_contact_id'=>$contactId, 'mode'=>$existing ? 'update' : 'create', 'bling_pedido_id_existente'=>$existing]);
            if ($existing) {
                // UPDATE do pedido existente no Bling (PUT /pedidos/vendas/{id})
                $response = $this->client->put($erp, "/pedidos/vendas/{$existing}", $payload);
                $response['data'] = $response['data'] ?? ['id' => $existing];
                \Illuminate\Support\Facades\Log::info('[BlingOrderSync] Supplier order updated', ['order_id'=>$order->id, 'bling_order_id'=>$existing]);
            } else {
                // CREATE
                $response = $this->client->createOrder($erp, $payload);
                $blingOrderId = $response['data']['id'] ?? null;
                if ($blingOrderId) {
                    \Illuminate\Support\Facades\Log::info('[BlingOrderSync] Supplier order created', ['order_id'=>$order->id, 'bling_order_id'=>$blingOrderId]);
                }
            }
            return $response;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $blingFields = [];
            $blingDescription = null;
            if (preg_match('/\[(400|4\d\d|500|5\d\d)\].+?(\{.*\})/s', $msg, $m)) {
                $json = json_decode($m[2], true);
                $blingDescription = $json['error']['description'] ?? $json['error']['message'] ?? null;
                $blingFields = $json['error']['fields'] ?? [];
            }
            // MUL-264: code=3 'Informacoes identicas' = pedido ja esta OK no Bling, considerar sucesso silencioso
            foreach ($blingFields as $f) {
                if (($f['code'] ?? null) === 3 && str_contains(($f['msg'] ?? ''), 'id')) {
                    \Illuminate\Support\Facades\Log::info('[BlingOrderSync] Payload identico — pedido ja OK no Bling', ['order_id'=>$order->id, 'bling_pedido_id'=>$order->bling_pedido_id]);
                    return ['data' => ['id' => $order->bling_pedido_id]];
                }
            }
            \Illuminate\Support\Facades\Log::error('[BlingOrderSync] exportSupplierOrder failed', ['order_id'=>$order->id, 'error'=>$msg]);
            return ['_error' => true, 'message' => $msg, 'bling_description' => $blingDescription, 'bling_fields' => $blingFields];
        }
    }

    protected function resolveSellerContact(\App\Models\ErpAccount $erp, ?\App\Models\Client $client): ?int
    {
        if (!$client) return null;
        $doc = preg_replace('/\D/', '', (string) ($client->document ?? ''));
        if (!$doc) {
            throw new \RuntimeException('Seller ' . ($client->company_name ?? "#".$client->id) . ' esta sem CPF/CNPJ cadastrado (obrigatorio para Bling)');
        }

        // MUL-264 (24/07 revisado): NUNCA faz PUT no Bling. Bling é fonte de verdade pras razões sociais PJ.
        // MUL-271: vínculo gravado só vale se o documento do contato no Bling ainda bate com o
        // do seller — vínculos pré-MUL-266B apontavam pro contato PF antigo e faturavam a pessoa errada.
        if ($client->bling_supplier_contact_id) {
            $bid = (int) $client->bling_supplier_contact_id;
            try {
                $ct = $this->client->get($erp, "/contatos/{$bid}");
                $ctData = $ct['data'] ?? [];
                $ctDoc = preg_replace('/\D/', '', (string) ($ctData['numeroDocumento'] ?? ''));
                $ctSit = strtoupper((string) ($ctData['situacao'] ?? 'A'));
                // MUL-336: contato Excluido/Inativo no Bling nao serve de vinculo. A busca por
                // documento tambem nao devolve contato excluido, entao mante-lo aqui faria o
                // sync criar um contato PF novo a cada pedido. Zera e re-resolve.
                if ($ctDoc === $doc && in_array($ctSit, ['E', 'I'], true)) {
                    \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Contato do vinculo esta ' . ($ctSit === 'E' ? 'excluido' : 'inativo') . ' no Bling — re-resolvendo', ['client_id'=>$client->id, 'bling_contact_id'=>$bid, 'situacao'=>$ctSit]);
                    $client->update(['bling_supplier_contact_id' => null]);
                } elseif ($ctDoc === $doc) {
                    // MUL-336: o GET acima ja trouxe o contato inteiro — completa o cadastro
                    // local aqui, sem gastar uma segunda chamada. Antes desta linha o vinculo
                    // valido retornava direto e o cadastro local ficava eternamente vazio.
                    $this->syncContactIntoClient($client, $ctData, $bid);
                    return $bid;
                } else {
                    \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Vinculo Bling divergente do documento do seller — re-resolvendo', ['client_id'=>$client->id, 'bling_contact_id'=>$bid, 'contact_doc'=>$ctDoc, 'client_doc'=>$doc]);
                    $client->update(['bling_supplier_contact_id' => null]);
                }
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '[404]')) {
                    \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Contato do vinculo nao existe mais no Bling — re-resolvendo', ['client_id'=>$client->id, 'bling_contact_id'=>$bid]);
                    $client->update(['bling_supplier_contact_id' => null]);
                } else {
                    // Erro transitorio (rate limit/rede): mantem o vinculo em vez de arriscar criar contato duplicado
                    return $bid;
                }
            }
        }

        // Busca por numeroDocumento no Bling
        try {
            $search = $this->client->get($erp, '/contatos', ['numeroDocumento' => $doc]);
            $hit = $search['data'][0] ?? null;
            if ($hit) {
                $bid = (int) $hit['id'];
                // BAIXA dados do Bling pro sistema (razão social/IE/endereço/telefone que foram cadastrados manual lá)
                $this->pullContactFromBling($erp, $client, $bid);
                return $bid;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Busca contato falhou (segue pra criar)', ['doc'=>$doc, 'error'=>$e->getMessage()]);
        }

        // Não achou → cria com o melhor palpite disponível
        // MUL-269 fase 2: clients.full_name e company_name (coluna) removidos —
        // nome do responsavel/seller vem do USER conectado.
        $tipoPessoa = strlen($doc) > 11 ? 'J' : 'F';
        $userFullName = $client->user?->full_name ?: null;
        $userName     = $client->user?->name ?? null;
        $nome = $tipoPessoa === 'J'
            ? ($client->legal_name ?: $userFullName ?: $userName ?: 'Seller ' . $client->id)
            : ($userFullName ?: $userName ?: 'Seller ' . $client->id);

        $phoneClean = $this->formatBrazilPhone($client->phone);

        // MUL-336: o contato nascia so com nome+documento e alguem tinha que completar IE,
        // endereco e e-mail de NF na mao dentro do Bling. Agora leva o cadastro inteiro.
        $body = array_filter([
            'nome'               => $nome,
            'tipo'               => $tipoPessoa,
            'numeroDocumento'    => $doc,
            'email'              => $client->user?->email,
            'telefone'           => $phoneClean,
            'situacao'           => 'A',
            'fantasia'           => $client->trade_name,
            'ie'                 => $tipoPessoa === 'J' ? $client->state_registration : null,
            'indicadorIe'        => $tipoPessoa === 'J' ? $client->ie_indicator : null,
            'inscricaoMunicipal' => $client->municipal_registration,
            'emailNotaFiscal'    => $client->nfe_email,
        ], fn($v) => $v !== null && $v !== '');

        if ($client->address_cep) {
            $endereco = array_filter([
                'endereco'    => $client->address_street,
                'cep'         => $client->address_cep,
                'bairro'      => $client->address_neighborhood,
                'municipio'   => $client->address_city,
                'uf'          => $client->address_state,
                'numero'      => $client->address_number,
                'complemento' => $client->address_complement,
            ], fn($v) => $v !== null && $v !== '');
            if ($endereco) {
                $body['endereco'] = ['geral' => $endereco];
            }
        }

        $created = $this->client->post($erp, '/contatos', $body);
        $newId = $created['data']['id'] ?? null;
        if ($newId) {
            $client->update(['bling_supplier_contact_id' => (int) $newId]);
            return (int) $newId;
        }
        return null;
    }

    /** MUL-264: baixa dados do Bling (razão social/IE/endereço) e grava no banco local (Bling é fonte de verdade). */
    protected function pullContactFromBling(\App\Models\ErpAccount $erp, \App\Models\Client $client, int $contactId): void
    {
        try {
            $full = $this->client->get($erp, "/contatos/{$contactId}");
            $this->syncContactIntoClient($client, $full['data'] ?? [], $contactId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Falha ao baixar dados do Bling', ['error'=>$e->getMessage()]);
            $client->update(['bling_supplier_contact_id' => $contactId]);
        }
    }

    /**
     * MUL-336: grava no banco local um contato do Bling ja carregado em memoria.
     * Separado do pullContactFromBling pra poder ser chamado quando o vinculo ja e valido —
     * ali o GET de validacao ja trouxe o payload e uma segunda chamada seria desperdicio.
     * Preenche so campo vazio: nunca sobrescreve o que o seller ou o admin digitou.
     */
    protected function syncContactIntoClient(\App\Models\Client $client, array $d, int $contactId): void
    {
        try {
            if (! $d) { return; }
            $end = $d['endereco']['geral'] ?? [];
            $isPJ = ($d['tipo'] ?? '') === 'J';

            $updates = ['bling_supplier_contact_id' => $contactId];

            // Nome oficial do Bling (razão social pra PJ, nome pra PF)
            if (!empty($d['nome'])) {
                if ($isPJ && empty($client->legal_name)) {
                    $updates['legal_name'] = $d['nome'];
                }
            }
            // IE
            if ($isPJ && !empty($d['ie']) && empty($client->state_registration)) {
                $updates['state_registration'] = $d['ie'];
            }
            // Endereço — só preenche se banco tá vazio (não sobrescreve dado local)
            if (!empty($end['cep']) && empty($client->address_cep)) {
                $updates['address_cep'] = preg_replace('/\D/', '', $end['cep']);
                if (!empty($end['endereco'])) $updates['address_street'] = $end['endereco'];
                if (!empty($end['numero'])) $updates['address_number'] = $end['numero'];
                if (!empty($end['complemento'])) $updates['address_complement'] = $end['complemento'];
                if (!empty($end['bairro'])) $updates['address_neighborhood'] = $end['bairro'];
                if (!empty($end['municipio'])) $updates['address_city'] = $end['municipio'];
                if (!empty($end['uf'])) $updates['address_state'] = $end['uf'];
            }
            if (empty($client->person_type)) {
                $updates['person_type'] = $isPJ ? 'PJ' : 'PF';
            }

            // MUL-336: campos que existiam no contato do Bling mas nunca eram lidos
            if (!empty($d['fantasia']) && empty($client->trade_name)) {
                $updates['trade_name'] = $d['fantasia'];
            }
            if (!empty($d['inscricaoMunicipal']) && empty($client->municipal_registration)) {
                $updates['municipal_registration'] = $d['inscricaoMunicipal'];
            }
            if (!empty($d['emailNotaFiscal']) && empty($client->nfe_email)) {
                $updates['nfe_email'] = $d['emailNotaFiscal'];
            }
            if ($isPJ && !empty($d['indicadorIe']) && empty($client->ie_indicator)) {
                $updates['ie_indicator'] = (int) $d['indicadorIe'];
            }

            $client->update($updates);
            \Illuminate\Support\Facades\Log::info('[BlingOrderSync] Dados do Bling baixados', ['client_id'=>$client->id, 'bling_contact_id'=>$contactId, 'fields'=>array_keys($updates)]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Falha ao gravar dados do Bling', ['error'=>$e->getMessage()]);
            // Grava só o id ainda
            $client->update(['bling_supplier_contact_id' => $contactId]);
        }
    }

    /** MUL-264: formata telefone BR pra padrão que o Bling aceita (DD) 9NNNN-NNNN */
    protected function formatBrazilPhone(?string $phone): ?string
    {
        $p = preg_replace('/\D/', '', (string) $phone);
        if (strlen($p) === 13 && str_starts_with($p, '55')) $p = substr($p, 2);
        if (strlen($p) === 12 && str_starts_with($p, '55')) $p = substr($p, 2);
        if (strlen($p) < 10 || strlen($p) > 11) return null;
        $ddd = substr($p, 0, 2);
        $rest = substr($p, 2);
        return strlen($rest) === 9
            ? "({$ddd}) " . substr($rest, 0, 5) . '-' . substr($rest, 5)
            : "({$ddd}) " . substr($rest, 0, 4) . '-' . substr($rest, 4);
    }

    protected function buildSupplierPayload(\App\Models\Order $order, \App\Models\ErpAccount $erp, int $contactId, ?\App\Models\Client $sellerClient = null): array
    {
        $seller = $sellerClient ?: $order->client;
        $prefixes = array_filter(array_map('trim', explode(',', (string)($erp->sku_prefixes_to_strip ?? ''))));
        $stripPrefix = function(string $sku) use ($prefixes): string {
            foreach ($prefixes as $p) {
                if ($p && str_starts_with($sku, $p.'-')) return substr($sku, strlen($p)+1);
            }
            return $sku;
        };
        $items = [];
        $missing = [];
        foreach ($order->items as $item) {
            $rawSku = $item->sku ?? $item->product?->sku ?? '';
            $stripped = $stripPrefix($rawSku);
            // Bling v3: lookup do produto por codigo pra pegar id (produto.codigo puro nao referencia; grava id=0)
            $blingProductId = null;
            $lookupErr = null;
            try {
                $search = $this->client->get($erp, '/produtos', ['codigo' => $stripped, 'limite' => 1]);
                $blingProductId = $search['data'][0]['id'] ?? null;
            } catch (\Throwable $e) {
                $lookupErr = $e->getMessage();
                // MUL-264: se foi erro 5xx Bling, distinguir de 'nao encontrado' — throw pra retentar depois
                if (preg_match('/\[(5\d\d)\]/', $lookupErr)) {
                    throw new \RuntimeException('Bling API instavel — tentar sync novamente em 1 minuto (SKU ' . $stripped . '): ' . $lookupErr);
                }
                \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Lookup produto falhou (nao 5xx)', ['sku'=>$stripped, 'error'=>$lookupErr]);
            }
            if (!$blingProductId) { $missing[] = $stripped; continue; }
            $cost = $item->supplier_unit_cost ?? $item->unit_price ?? 0;
            $items[] = [
                'produto' => ['id' => (int) $blingProductId],
                'unidade' => 'UN',
                'quantidade' => (float) $item->quantity,
                'valor' => (float) $cost,
            ];
        }
        if (!empty($missing)) {
            throw new \RuntimeException('SKU(s) nao encontrado(s) no catalogo do Bling do fornecedor: ' . implode(', ', $missing));
        }
        // Total = soma dos custos (o que seller paga ao fornecedor)
        $totalCusto = 0.0;
        foreach ($items as $it) { $totalCusto += ((float) $it['valor']) * ((float) $it['quantidade']); }

        // Busca dados completos do contato do seller no Bling pra montar transporte.etiqueta
        $etiqueta = ['nome' => $seller?->company_name ?? '', 'endereco' => '', 'numero' => '', 'complemento' => '', 'municipio' => '', 'uf' => '', 'cep' => '', 'bairro' => '', 'nomePais' => 'Brasil'];
        try {
            $contactData = $this->client->get($erp, "/contatos/{$contactId}");
            $d = $contactData['data'] ?? [];
            $end = $d['endereco']['geral'] ?? [];
            $etiqueta = [
                'nome' => $d['nome'] ?? $etiqueta['nome'],
                'endereco' => $end['endereco'] ?? '',
                'numero' => $end['numero'] ?? '',
                'complemento' => $end['complemento'] ?? '',
                'municipio' => $end['municipio'] ?? '',
                'uf' => $end['uf'] ?? '',
                'cep' => $end['cep'] ?? '',
                'bairro' => $end['bairro'] ?? '',
                'nomePais' => 'Brasil',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BlingOrderSync] Nao foi possivel buscar contato completo para etiqueta', ['contact_id'=>$contactId, 'error'=>$e->getMessage()]);
        }

        $payload = [
            'numero' => (string) $order->id,  // MUL-264: id numerico interno (nao order_number alfa)
            'data' => $order->created_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            'contato' => ['id' => $contactId],
            'itens' => $items,
            'totalProdutos' => (float) $totalCusto,
            'observacoes' => "Pedido HubAI/MultDrop #{$order->id} - seller: " . ($seller?->company_name ?? 'N/A'),
            'observacoesInternas' => "multdrop_order_id={$order->id} source={$order->source}",
            'transporte' => [
                'fretePorConta' => 0,
                'frete' => 0,
                'quantidadeVolumes' => 1,
                'etiqueta' => $etiqueta,
            ],
        ];

        // MUL-368 (decisao Ruan 11/08): data de saida do pedido = data do PAGAMENTO ao
        // fornecedor — o Bling usa esse campo pra preencher o dhSaiEnt (data/hora de
        // saida) da NF-e gerada do pedido. Sem pagamento ainda, vai em branco (valido).
        $pagoEm = $order->wallet_paid_at ?? $order->paid_at;
        if ($pagoEm) {
            $payload['dataSaida'] = $pagoEm->format('Y-m-d');
        }

        // MUL-375 (decisao Ruan 12/08): "numero loja virtual" no Bling do fornecedor =
        // id do pedido NO MARKETPLACE (ordersn Shopee / id ML) — antes nao era enviado.
        $numeroLoja = $order->marketplace_order_id ?? $order->external_order_id;
        if ($numeroLoja) {
            $payload['numeroLoja'] = (string) $numeroLoja;
        }

        return $payload;
    }

    // ---------------------------------------------------------------
    // Import: Bling -> App (bulk sync)
    // ---------------------------------------------------------------

    /**
     * Sincroniza pedidos do Bling -> HubAI.
     * Match por SKU dos itens do pedido.
     */
    public function syncAll(MarketplaceAccount $account): array
    {
        $stats = ["created" => 0, "updated" => 0, "skipped" => 0, "errors" => 0];

        // MUL-311 — decisao do Ruan em 31/07/2026: a varredura historica de pedidos do
        // Bling esta DESLIGADA no sistema inteiro. Este e o funil de TODOS os caminhos:
        // conexao OAuth, botao "sincronizar" do seller, sync horario e importacao manual.
        // Nao adianta desbloquear a conta: enquanto esta chave estiver false, nada e puxado.
        //
        // O que CONTINUA funcionando: pedido novo entrando por webhook, envio de pedido
        // ao Bling e emissao de NF-e. So a varredura para tras esta parada.
        //
        // Religar: IMPORT_AUTO_ORDERS_ON_CONNECT=true no .env + php artisan config:clear
        if (! config('imports.auto_orders_on_connect', false)) {
            Log::channel('marketplace')->info('[MUL-311] varredura de pedidos do Bling DESLIGADA — nada importado', [
                'account_id' => $account->id,
                'client_id'  => $account->client_id,
            ]);
            $stats['skipped'] = 'MUL-311: importacao de pedidos desligada no sistema';
            return $stats;
        }

        // MUL-082: usar data_inicial_import da conta (fallback: created_at, fallback final: 25h atras)
        // MUL-303: SOMENTE data, sem hora. O Bling IGNORA dataInicial quando vem com hora e
        // devolve o historico inteiro — o loop entao pagina ate o fim e reimporta tudo.
        // Provado na API (conta 819, mesma pagina 60): com '2026-07-01 00:00:00' vinha
        // dezembro/2025; com '2026-07-01' vem vazio.
        $cutoffDate = $account->data_inicial_import
            ? \Carbon\Carbon::parse($account->data_inicial_import)->format('Y-m-d')
            : ($account->created_at ? $account->created_at->format('Y-m-d') : now()->subHours(25)->format('Y-m-d'));

        // MUL-082 / MUL-303: canais permitidos (lojas que o seller marcou na tela).
        //   null  = nunca configurou  -> varre todos os canais (comportamento historico)
        //   []    = configurou e DESMARCOU tudo -> nao importa nada
        //   [ids] = importa so esses canais
        // Antes, null e [] eram tratados igual e "nenhuma loja marcada" virava "todas as
        // lojas" — o oposto do que a tela diz.
        $allowedIntegrations = $account->allowed_integrations;
        // MUL-382 (decisao Ruan 13/08): sem lojas CONFIGURADAS nao se varre NADA.
        // Antes, "nunca configurou" (null) varria todos os canais — o oposto da regra.
        if (!is_array($allowedIntegrations) || $allowedIntegrations === []) {
            Log::channel('marketplace')->info('[BlingOrderSync][syncAll] lojas nao configuradas/desmarcadas — varredura ignorada', [
                'account_id' => $account->id,
                'config'     => $allowedIntegrations === null ? 'nunca_configurou' : 'desmarcou_tudo',
            ]);
            $stats['skipped'] = 'lojas nao configuradas';
            return $stats;
        }

        Log::info('[BlingOrderSync][syncAll] cutoff+filtros', [
            'account_id' => $account->id,
            'cutoff' => $cutoffDate,
            'integrations' => $allowedIntegrations,
        ]);

        // MUL-138: a API v3 filtra por UMA loja por chamada (idLoja singular — o
        // idsIntegracoes[] que mandávamos antes não existe e era ignorado). Com
        // canais selecionados, faz uma varredura paginada POR canal; sem seleção,
        // uma varredura sem filtro (todos os canais).
        $lojaIds = $allowedIntegrations !== [] ? $allowedIntegrations : [null]; // [null] so cai aqui se nunca configurou

        foreach ($lojaIds as $lojaId) {
            $page = 1;
            do {
                try {
                    $response = $this->client->listOrders($account, $page, $cutoffDate, $lojaId ? (int) $lojaId : null);
                    $orders = $response["data"] ?? [];

                    if (empty($orders)) {
                        break;
                    }

                    foreach ($orders as $blingOrder) {
                        try {
                            $result = $this->syncOrder($account, $blingOrder);
                            $stats[$result]++;
                        } catch (\Throwable $e) {
                            $stats["errors"]++;
                            Log::warning("Bling order sync error", [
                                "bling_order_id" => $blingOrder["id"] ?? "?",
                                "error" => $e->getMessage(),
                            ]);
                        }
                    }

                    $page++;
                    usleep(350000);
                } catch (\Throwable $e) {
                    Log::error("Bling order sync page error", ["page" => $page, "loja_id" => $lojaId, "error" => $e->getMessage()]);
                    break;
                }
            } while (count($orders ?? []) >= 100);
        }

        return $stats;
    }

    /**
     * Resolve o tenant_slug para um order Bling a partir do supplier vinculado a conta.
     *
     * HUB-113: o ImportMarketplaceAccountDataJob estava criando 12k+ orders sem tenant_slug,
     * quebrando o painel Filament multi-tenant. Padrao espelhado em ImportLegacyOrdersJob
     * (linhas 300-315): usar tenant_supplier + tenants para resolver, preferindo o tenant
     * mais especifico (qualquer um != 'fornecefy') quando houver multiplos.
     *
     * Retorna null quando supplier_id e null OU quando nao ha tenant ativo vinculado.
     * Salvar null e melhor que inferir tenant errado (pior pra multi-tenant scoping).
     */
    protected function resolveTenantSlug(?int $supplierId): ?string
    {
        if (!$supplierId) {
            return null;
        }

        $tenantSlug = DB::table('tenant_supplier as ts')
            ->join('tenants as t', 't.id', '=', 'ts.tenant_id')
            ->where('ts.supplier_id', $supplierId)
            ->where('t.status', 'active')
            ->orderByRaw("CASE WHEN t.slug = 'fornecefy' THEN 1 ELSE 0 END ASC")
            ->value('t.slug');

        return $tenantSlug ?: null;
    }

    /**
     * MUL-138: aplica as configs de importação da conta (tela "Configurar
     * importação" do seller) ANTES de criar um pedido novo. Fonte única de
     * verdade pra sync periódico, webhook e importação inicial.
     */
    protected function passesImportFilters(MarketplaceAccount $account, array $blingOrder): bool
    {
        $allowed = $account->allowed_integrations;
        // MUL-303: [] explicito = seller desmarcou todas as lojas -> nao entra nada.
        // MUL-382 (reativacao Bling, decisao Ruan 13/08): NULL (nunca configurou) tambem
        // NAO importa — antes null virava "todas as lojas", o oposto da regra "sempre
        // respeitando as lojas marcadas". Conta so importa depois de configurar.
        if (! is_array($allowed) || $allowed === []) {
            Log::channel('marketplace')->info('[BlingOrderSync] Lojas nao configuradas ou desmarcadas — pedido ignorado', [
                'account_id' => $account->id,
                'bling_id'   => $blingOrder["id"] ?? null,
                'config'     => $allowed === null ? 'nunca_configurou' : 'desmarcou_tudo',
            ]);
            return false;
        }
        if (is_array($allowed) && $allowed !== []) {
            $lojaId = (int) ($blingOrder["loja"]["id"] ?? 0);
            if (! in_array($lojaId, array_map('intval', $allowed), true)) {
                Log::channel('marketplace')->info('[BlingOrderSync] Canal fora dos permitidos — pedido ignorado', [
                    'account_id' => $account->id,
                    'bling_id'   => $blingOrder["id"] ?? null,
                    'loja_id'    => $lojaId,
                    'allowed'    => $allowed,
                ]);
                return false;
            }
        }

        if ($account->data_inicial_import && ! empty($blingOrder["data"])) {
            $cutoff = \Carbon\Carbon::parse($account->data_inicial_import)->startOfDay();
            if (\Carbon\Carbon::parse($blingOrder["data"])->lt($cutoff)) {
                return false;
            }
        }

        return true;
    }

    /**
     * MUL-133: entrada pública pro webhook — busca o detalhe completo e força o
     * refresh (o webhook já indica que algo mudou no Bling), mantendo status,
     * rastreio, canal, endereço e capture_payload sincronizados.
     */
    public function syncSingle(MarketplaceAccount $account, int $blingId, bool $overwrite = false): string
    {
        $detail = $this->client->getOrder($account, $blingId);
        $orderData = $detail["data"] ?? null;
        if (! $orderData) {
            return "skipped";
        }

        return $this->syncOrder($account, $orderData, true, $overwrite);
    }

    /**
     * MUL-311: TRAVA CONTRA DUPLICATA.
     *
     * A deduplicacao fazia SELECT e depois INSERT, sem exclusao mutua. Com varios
     * workers na mesma conta, dois processavam o MESMO pedido do Bling ao mesmo tempo:
     * os dois nao achavam nada no SELECT e os dois criavam. Em 31/07/2026 isso gerou
     * 673 pedidos duplicados em 30 minutos, com 0 a 4 segundos entre as copias.
     *
     * A trava e por (cliente, id do pedido no Bling) e vale entre processos (Redis).
     * Se nao conseguir a trava em 10s, desiste — outro worker esta cuidando do mesmo
     * pedido, entao nao ha nada a fazer.
     */
    protected function syncOrder(MarketplaceAccount $account, array $blingOrder, bool $force = false, bool $overwrite = false): string
    {
        $blingId = $blingOrder["id"] ?? null;
        if (!$blingId) {
            return "skipped";
        }

        $chave = "bling-order-sync:{$account->client_id}:{$blingId}";

        try {
            return Cache::lock($chave, 60)->block(10, function () use ($account, $blingOrder, $force, $overwrite) {
                return $this->syncOrderSemTrava($account, $blingOrder, $force, $overwrite);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::channel('marketplace')->info('[MUL-311] pedido ja sendo processado por outro worker — ignorado', [
                'account_id' => $account->id,
                'bling_id'   => $blingId,
            ]);
            return "skipped";
        }
    }

    /**
     * Sincroniza um pedido individual. NAO chamar direto — use syncOrder(), que trava.
     */
    protected function syncOrderSemTrava(MarketplaceAccount $account, array $blingOrder, bool $force = false, bool $overwrite = false): string
    {
        $blingId = $blingOrder["id"] ?? null;
        $orderNumber = $blingOrder["numero"] ?? $blingId;

        if (!$blingId) {
            return "skipped";
        }

        // MUL-092: Dedup em 2 niveis
        // Nivel 1: pedido Bling ja importado (external_order_id = bling id)
        $existing = Order::where("client_id", $account->client_id)
            ->where("external_order_id", (string) $blingId)
            ->where("source", "bling")
            ->first();

        // MUL-311 (pedido do Ruan): conferir o ID DO PEDIDO NO MARKETPLACE antes de importar.
        // O nivel 3 ja comparava por numeroLoja, mas com `source != bling` — dois pedidos
        // do PROPRIO Bling com o mesmo pedido de marketplace nunca eram comparados entre si.
        // Acontece quando o mesmo pedido aparece em dois canais da conta, ou quando o Bling
        // devolve o pedido com dois ids diferentes.
        if (!$existing) {
            $numeroLojaDedup = $this->extractMarketplaceOrderId($blingOrder);
            if ($numeroLojaDedup) {
                $mesmoMarketplace = Order::where("client_id", $account->client_id)
                    ->where("source", "bling")
                    ->where("external_order_id", "!=", (string) $blingId)
                    ->where(function ($q) use ($numeroLojaDedup) {
                        $q->where("marketplace_order_id", $numeroLojaDedup)
                          ->orWhere("external_order_id", $numeroLojaDedup);
                    })
                    ->first();

                if ($mesmoMarketplace) {
                    Log::channel('marketplace')->info('[MUL-311] mesmo pedido de marketplace ja importado do Bling — duplicata evitada', [
                        'account_id'           => $account->id,
                        'bling_id'             => $blingId,
                        'marketplace_order_id' => $numeroLojaDedup,
                        'pedido_existente'     => $mesmoMarketplace->id,
                        'bling_id_existente'   => $mesmoMarketplace->external_order_id,
                    ]);
                    return "skipped";
                }
            }
        }

        // Nivel 2 (MUL-092): pedido ja existente no legado com mesmo numero (nr_canal).
        // Legado grava external_order_id = numero_bling (nr_canal), enquanto o sync
        // direto do Bling grava external_order_id = bling_id. Sem esse cruzamento,
        // seller com Bling + legado ganha o mesmo pedido 2x (3.242 duplicatas do
        // storetotao e 184 do snapmixbrasil vistos em 01/07/2026).
        if (!$existing && $orderNumber) {
            $legacyDup = Order::where("client_id", $account->client_id)
                ->where("source", "bling")
                ->whereNotNull("legacy_id")
                ->where(function ($q) use ($orderNumber, $blingId) {
                    // MUL-264: order_number agora é interno MUL-XXX — dedup usa bling_order_number + external_order_id
                    $q->where("bling_order_number", (string) $orderNumber)
                      ->orWhere("order_number", (string) $orderNumber)       // legacy pedidos antigos
                      ->orWhere("external_order_id", (string) $orderNumber)
                      ->orWhere("external_order_id", (string) $blingId);
                })
                ->first();

            if ($legacyDup) {
                Log::channel('marketplace')->info('[BlingOrderSync] Pedido ja existe via legado, ignorando duplicata Bling', [
                    'account_id'      => $account->id,
                    'bling_id'        => $blingId,
                    'order_number'    => $orderNumber,
                    'legacy_order_id' => $legacyDup->id,
                    'legacy_id'       => $legacyDup->legacy_id,
                ]);
                return "skipped";
            }
        }

        if ($existing) {
            $newStatus = $this->mapStatus($this->extractSituacaoId($blingOrder));
            $statusChanged = $existing->status !== $newStatus;

            // MUL-133: rastreio de pedidos DBA só existe no Bling DEPOIS do envio
            // (transporte.volumes[].codigoRastreamento). Enriquece o pedido na mudança
            // de status e reconsulta enquanto pedido enviado seguir sem rastreio.
            // Fulfillment (FBA) fica de fora: a Amazon envia e o Bling nunca tem rastreio.
            $missingTracking = ! $existing->tracking_number
                && ! in_array($existing->status, ["pending", "cancelled"], true)
                && $existing->carrier_name !== "Amazon Fulfillment";

            if (! $force && ! $statusChanged && ! $missingTracking) {
                return "skipped";
            }

            // Quando $blingOrder já é o detalhe completo (webhook), não re-busca.
            $orderData = isset($blingOrder["itens"])
                ? $blingOrder
                : ($this->client->getOrder($account, $blingId)["data"] ?? $blingOrder);

            // MUL-389: canonical_status precisa acompanhar status. O importador Bling
            // NUNCA gravava essa coluna -- medido em 15/08: 12.489 pedidos no multdrop e
            // 10.993 no hub travados no default "created" mesmo com status=paid. O painel
            // le canonical_status, entao o pedido aparecia eternamente como "criado".
            $set = ["status" => $newStatus, "canonical_status" => $newStatus];
            if (($overwrite || ! $existing->tracking_number) && ($tracking = $this->extractTracking($orderData))) {
                $set["tracking_number"] = $tracking;
            }
            if (($overwrite || ! $existing->shipping_mode) && ($mode = $this->extractShippingMode($orderData))) {
                $set["shipping_mode"] = $mode;
            }
            if (($overwrite || ! $existing->carrier_name) && ($carrier = $this->extractCarrier($account, $orderData))) {
                $set["carrier_name"] = $carrier;
            }
            // MUL-135 (decisão Ruan): numeroLoja vai COMPLETO, inclusive o composto
            // "P7Nb6Z0dG|701-9607244-2362616" dos Fulfillment — sempre espelha o Bling.
            $mktId = $this->extractMarketplaceOrderId($orderData);
            if ($mktId && $mktId !== $existing->marketplace_order_id) {
                $set["marketplace_order_id"] = $mktId;
            }
            if (($overwrite || ! $existing->channel_name) && ($mktName = $this->marketplaceName($account, $orderData))) {
                $set["channel_name"] = $mktName;
            }
            if (($overwrite || ! $existing->customer_address) && ($address = $this->extractAddress($orderData))) {
                $set["customer_address"] = $address;
            }
            // MUL-133: payload bruto do Bling (nomes originais) — qualquer campo que
            // faltar no futuro sai daqui, sem novo backfill via API.
            $set["capture_payload"] = json_encode($orderData, JSON_UNESCAPED_UNICODE);

            // MUL-237: preencher marketplace_created_at se NULL
            if (($overwrite || ! $existing->marketplace_created_at) && ! empty($orderData["data"])) {
                $set["marketplace_created_at"] = self::dataDoPedidoBling($orderData["data"]) /* MUL-460 */;
            }
            $existing->update($set);
            return "updated";
        }

        // MUL-139: dedup nível 3 — integração NATIVA tem prioridade. Se o mesmo
        // pedido já existe via Shopee/ML direto (external_order_id = numeroLoja),
        // NÃO cria duplicata: anexa o JSON do Bling no pedido original
        // (bling_payload + bling_order_id) pra ter os dados fiscais/NF-e do ERP.
        // Roda ANTES do filtro de canais de propósito: anexar não é importar.
        $numeroLoja = $this->extractMarketplaceOrderId($blingOrder);
        // MUL-382 camada 2 (decisao Ruan 13/08): RASTREIO tambem identifica o mesmo
        // pedido fisico — se um pedido de outra origem ja carrega o mesmo codigo de
        // rastreio, e a mesma venda: anexa em vez de duplicar.
        $trackingDedup = $this->extractTracking($blingOrder);
        if ($numeroLoja || $trackingDedup) {
            $native = Order::where("client_id", $account->client_id)
                ->where("source", "!=", "bling")
                // MUL-186: cascas do sync Shopee entram com external_order_id NULL
                // (listagem sem detalhe) e ficavam invisiveis aqui — casar tambem por
                // marketplace_order_id mata a duplicata quando a casca chega primeiro.
                ->where(function ($q) use ($numeroLoja, $trackingDedup) {
                    if ($numeroLoja) {
                        $q->where("external_order_id", $numeroLoja)
                            ->orWhere("marketplace_order_id", $numeroLoja);
                    }
                    if ($trackingDedup) {
                        $numeroLoja
                            ? $q->orWhere("tracking_number", $trackingDedup)
                            : $q->where("tracking_number", $trackingDedup);
                    }
                })
                ->first();

            if ($native) {
                $orderData = isset($blingOrder["itens"])
                    ? $blingOrder
                    : ($this->client->getOrder($account, $blingId)["data"] ?? $blingOrder);

                $set = [
                    "bling_order_id" => (int) $blingId,
                    "bling_payload"  => json_encode($orderData, JSON_UNESCAPED_UNICODE),
                ];

                // MUL-186: nativo-casca (total zerado, sem detalhe da Shopee) herda os
                // valores reais do Bling; nunca rebaixa dado real ja preenchido.
                if ((float) $native->total <= 0) {
                    $set["total"] = (float) ($orderData["totais"]["totalVenda"]
                        ?? $orderData["total"]
                        ?? $orderData["totalProdutos"]
                        ?? 0);
                    $set["subtotal"] = (float) ($orderData["totalProdutos"]
                        ?? array_sum(array_map(fn ($i) => (float) ($i["valor"] ?? 0) * (float) ($i["quantidade"] ?? 1), $orderData["itens"] ?? [])));
                    if (in_array($native->status, ["pending", "pending_payment", "processing"], true)) {
                        $set["status"] = $this->mapStatus($this->extractSituacaoId($orderData));
                    }
                }
                if (! $native->external_order_id) {
                    $set["external_order_id"] = $numeroLoja;
                }
                if (! $native->tracking_number && ($blingTracking = $this->extractTracking($orderData))) {
                    $set["tracking_number"] = $blingTracking;
                }

                // MUL-237: preencher marketplace_created_at se NULL (dedup nivel 3)
                if (! $native->marketplace_created_at && ! empty($orderData["data"])) {
                    $set["marketplace_created_at"] = self::dataDoPedidoBling($orderData["data"]) /* MUL-460 */;
                }
                $native->update($set);

                // MUL-186: casca sem itens ganha os itens do Bling (mesmo criterio do
                // create, match por SKU) — pedido novo precisa entrar completo.
                if (! empty($orderData["itens"]) && $native->items()->count() === 0) {
                    foreach ($orderData["itens"] as $item) {
                        $sku = $item["codigo"] ?? null;
                        // MUL-382: SKU exato em QUALQUER fornecedor — o filtro por supplier da
                        // conta perdia produtos da Filial (D773 = supplier 31) e deixou 1.023
                        // pedidos sem custo na importacao de 13/08. Prioridade: supplier da
                        // conta, depois qualquer um com price valido.
                        $product = $sku
                            ? (Product::where("supplier_id", $account->supplier_id)->where("sku", $sku)->first()
                                ?? Product::where("sku", $sku)->where("price", ">", 0)->orderBy("id")->first())
                            : null;
                        $clientProductId = $product
                            ? ClientProduct::where("client_id", $account->client_id)->where("product_id", $product->id)->value("id")
                            : null;
                        $qty       = (int) ($item["quantidade"] ?? 1);
                        $unitPrice = (float) ($item["valor"] ?? 0);
                        // MUL-339: price, nao cost. cost e o custo do fornecedor; price e o que o
                        // seller paga, que e o que supplier_unit_cost representa. O caminho do ML
                        // (ProcessMLOrderJob) ja usa price — este estava divergente.
                        $cost      = (float) ($product?->price ?? 0);
                        // MUL-382: KIT* nao e produto — custo = soma da composicao (client_kits,
                        // price por componente). Kits migrados do legado ja tem composicao.
                        if ($cost <= 0 && $sku && str_starts_with($sku, 'KIT')) {
                            $cost = (float) (\Illuminate\Support\Facades\DB::table('client_kits as ck')
                                ->join('client_kit_items as ki', 'ki.kit_id', '=', 'ck.id')
                                ->leftJoin('client_products as cp', 'cp.id', '=', 'ki.client_product_id')
                                ->leftJoin('products as p', 'p.id', '=', 'cp.product_id')
                                ->where('ck.client_id', $account->client_id)->where('ck.sku', $sku)
                                ->selectRaw('SUM(COALESCE(p.price,0) * ki.quantity) s')->value('s') ?? 0);
                        }
                        OrderItem::create([
                            "order_id"            => $native->id,
                            "product_id"          => $product?->id,
                            "client_product_id"   => $clientProductId,
                            "sku"                 => $sku,
                            "name"                => $product?->name ?? $item["descricao"] ?? "Item",
                            "quantity"            => $qty,
                            "unit_price"          => $unitPrice,
                            "total"               => $qty * $unitPrice,
                            "supplier_unit_cost"  => $cost > 0 ? $cost : null,
                            "supplier_total_cost" => $cost > 0 ? ($cost * $qty) : null,
                "product_image"       => $this->capaDoProduto($productId),
                        ]);
                    }
                    $supplierTotal = $native->items()->sum("supplier_total_cost");
                    if ($supplierTotal > 0) {
                        $native->update(["supplier_total" => $supplierTotal]);
                    }
                    // MUL-382: kit explode em componentes — mesmo tratamento dos fluxos
                    // Shopee/espelho (MUL-147-B). Sem isso o fornecedor ve "KIT..." no
                    // picking em vez dos produtos a separar, e o custo nao resolve.
                    try { app(\App\Services\KitExplosionService::class)->explodeOrder($native->fresh()); } catch (\Throwable $eKit) {
                        Log::channel('marketplace')->warning('[BlingOrderSync] explosao de kit falhou (nao critico)', ['order_id' => $native->id, 'error' => $eKit->getMessage()]);
                    }
                }

                Log::channel('marketplace')->info('[BlingOrderSync] Pedido nativo já existe — JSON Bling anexado (sem duplicata)', [
                    'account_id'      => $account->id,
                    'bling_id'        => $blingId,
                    'numero_loja'     => $numeroLoja,
                    'native_order_id' => $native->id,
                    'native_source'   => $native->source,
                ]);

                return "updated";
            }
        }

        // MUL-138: enforcement LOCAL das configs de importação — a API v3 do Bling
        // não aceitava o filtro que mandávamos (idsIntegracoes[] não existe) e o
        // create path nunca checava canal/data, então pedidos de canais desmarcados
        // (ex. Shopee do storetotao) entravam pelo sync horário e pelo webhook.
        // Pedido NOVO só entra se o canal estiver permitido e a data respeitar
        // data_inicial_import; updates de pedidos já importados seguem normais.
        if (! $this->passesImportFilters($account, $blingOrder)) {
            return "skipped";
        }

        // HUB-113: alinha com o guard ja existente em SyncShopeeOrdersJob — sem supplier
        // nao da pra resolver tenant nem custos. Abortar sem criar pedido evita poluir
        // o painel multi-tenant com orders orfas (12.793 orders criadas indevidamente
        // nas 24h antes do fix, todas com tenant_slug=null por conta de supplier=null
        // ou supplier sem tenant vinculado).
        $tenantSlug = $this->resolveTenantSlug($account->supplier_id, $account);
        if (!$account->supplier_id || !$tenantSlug) {
            Log::channel('marketplace')->warning('[BlingOrderSync] supplier_id ou tenant_slug ausente — abortando sem criar pedido', [
                'account_id'   => $account->id,
                'client_id'    => $account->client_id,
                'supplier_id'  => $account->supplier_id,
                'bling_id'     => $blingId,
            ]);
            return "skipped";
        }

        // Busca detalhes completos do pedido (a lista so retorna resumo);
        // quando $blingOrder já é o detalhe (webhook), não re-busca.
        $orderData = isset($blingOrder["itens"])
            ? $blingOrder
            : ($this->client->getOrder($account, $blingId)["data"] ?? $blingOrder);

        // Cria o pedido
        $order = Order::create([
            "client_id" => $account->client_id,
            "supplier_id" => $account->supplier_id,
            "tenant_slug" => $tenantSlug,
            "source" => "bling",
            // INF-037: estampa qual conta Bling (marketplace_accounts) trouxe o
            // pedido — o enricher consulta a conta certa em vez de adivinhar.
            "marketplace_account_id" => $account->id,
            "external_order_id" => (string) $blingId,
            // MUL-264: order_number deixado vazio — Order::boot gera automatico MUL-YYMMDD-XXXX (padronizado com Shopee/ML/etc)
            "status" => $this->mapStatus($this->extractSituacaoId($orderData)),
            // MUL-389: mesmo valor do status -- ver comentario no update acima.
            "canonical_status" => $this->mapStatus($this->extractSituacaoId($orderData)),
            // MUL-161-BE1 #24: total = totais.totalVenda (pedido de venda) se disponivel,
            // senao total da raiz, senao soma dos itens (totalProdutos).
            "total" => (float) ($orderData["totais"]["totalVenda"]
                ?? $orderData["total"]
                ?? $orderData["totalProdutos"]
                ?? 0),
            // Subtotal = soma dos valores dos itens (sem frete)
            "subtotal" => (float) ($orderData["totalProdutos"]
                ?? array_sum(array_map(fn($i) => (float)($i["valor"] ?? 0) * (float)($i["quantidade"] ?? 1), $orderData["itens"] ?? []))),
            "shipping_cost" => (float) ($orderData["transporte"]["fretePorConta"] ?? 0),
            "customer_name" => $orderData["contato"]["nome"] ?? "Comprador Bling",
            "customer_document_number" => $orderData["contato"]["numeroDocumento"] ?? null,
            // MUL-133: observacoes ia parar em cancel_reason (campo errado — era só
            // onde coube na primeira versão); agora vai pra notes.
            "notes" => $orderData["observacoes"] ?? null,
            // MUL-133: numeroLoja é o ID real do marketplace (ex. 701-9607244-2362616
            // na Amazon) — antes ficava perdido dentro de observacoes.
            "marketplace_order_id" => $this->extractMarketplaceOrderId($orderData),
            "tracking_number" => $this->extractTracking($orderData),
            "shipping_mode"   => $this->extractShippingMode($orderData),
            "carrier_name"    => $this->extractCarrier($account, $orderData),
            // MUL-135: nome do MARKETPLACE (Amazon/Shopee/...) — o Bling é ERP
            // integrador, não o marketplace; loja.nome nunca vem no detalhe.
            "channel_name"    => $this->marketplaceName($account, $orderData)
                ?? ($orderData["loja"]["nome"] ?? null),
            "customer_address" => $this->extractAddress($orderData),
            // MUL-133: payload bruto do Bling (nomes originais) — qualquer campo que
            // faltar no futuro sai daqui, sem novo backfill via API.
            "capture_payload" => json_encode($orderData, JSON_UNESCAPED_UNICODE),
            // MUL-161-BE1 #7: numero e ID do pedido de venda Bling para consulta futura
            "bling_order_number" => (string) $orderNumber,
            "bling_order_id"     => (int) $blingId,
            // MUL-237: campo data do Bling (Y-m-d ou Y-m-d H:i:s) -> marketplace_created_at UTC
            "marketplace_created_at" => ! empty($orderData["data"])
                ? self::dataDoPedidoBling($orderData["data"]) /* MUL-460 */
                : null,
        ]);

        // Cria os items do pedido -- match por SKU
        $items = $orderData["itens"] ?? [];
        foreach ($items as $item) {
            $sku = $item["codigo"] ?? null;
            $productId = null;
            $clientProductId = null;

            if ($sku) {
                // Match: SKU do Bling -> Product.sku -> ClientProduct
                // MUL-382: SKU exato em QUALQUER fornecedor (Filial D773 = supplier 31
                // ficava invisivel com o filtro da conta) — mesma regra do outro bloco.
                $product = Product::where("supplier_id", $account->supplier_id)
                    ->where("sku", $sku)
                    ->first()
                    ?? Product::where("sku", $sku)->where("price", ">", 0)->orderBy("id")->first();

                if ($product) {
                    $productId = $product->id;

                    $clientProduct = ClientProduct::where("client_id", $account->client_id)
                        ->where("product_id", $product->id)
                        ->first();

                    $clientProductId = $clientProduct?->id;
                }
            }

            $qty       = (int) ($item["quantidade"] ?? 1);
            $unitPrice = (float) ($item["valor"] ?? 0);
            // MUL-339: price, nao cost — ver a nota no outro ponto de criacao de item.
            $cost      = isset($product) && $product ? ((float) ($product->price ?? 0)) : 0.0;
            // MUL-382: KIT* pela composicao client_kits (mesma regra do outro bloco)
            if ($cost <= 0 && $sku && str_starts_with($sku, 'KIT')) {
                $cost = (float) (\Illuminate\Support\Facades\DB::table('client_kits as ck')
                    ->join('client_kit_items as ki', 'ki.kit_id', '=', 'ck.id')
                    ->leftJoin('client_products as cp', 'cp.id', '=', 'ki.client_product_id')
                    ->leftJoin('products as p', 'p.id', '=', 'cp.product_id')
                    ->where('ck.client_id', $account->client_id)->where('ck.sku', $sku)
                    ->selectRaw('SUM(COALESCE(p.price,0) * ki.quantity) s')->value('s') ?? 0);
            }

            OrderItem::create([
                "order_id"            => $order->id,
                "product_id"          => $productId,
                "client_product_id"   => $clientProductId,
                "sku"                 => $sku,
                "name"                => isset($product) ? ($product->name ?? $item["descricao"] ?? "Item") : ($item["descricao"] ?? "Item"),
                "quantity"            => $qty,
                "unit_price"          => $unitPrice,
                "total"               => $qty * $unitPrice,
                "supplier_unit_cost"  => $cost > 0 ? $cost : null,
                "supplier_total_cost" => $cost > 0 ? ($cost * $qty) : null,
                "product_image"       => $this->capaDoProduto($productId),
            ]);
        }

        // Recalcula supplier_total a partir dos itens criados
        $supplierTotal = $order->items()->sum("supplier_total_cost");
        if ($supplierTotal > 0) {
            $order->update(["supplier_total" => $supplierTotal]);
        }

        // MUL-382: kit explode em componentes — mesmo tratamento dos fluxos Shopee/espelho
        // (MUL-147-B). O importador Bling era o unico fluxo sem explosao: item ficava com
        // SKU KIT* sem vinculo de produto, sem custo e invisivel pro picking.
        try { app(\App\Services\KitExplosionService::class)->explodeOrder($order->fresh()); } catch (\Throwable $eKit) {
            Log::channel('marketplace')->warning('[BlingOrderSync] explosao de kit falhou (nao critico)', ['order_id' => $order->id, 'error' => $eKit->getMessage()]);
        }

        return "created";
    }

    /**
     * Mapeia status do Bling -> status HubAI.
     */
    /**
     * MUL-133: o ID da situação vem em situacao.id (6/9/12/15). O campo situacao.valor
     * é um flag 0/1 — mapear por ele fazia TODO pedido cair no default "pending"
     * (1.821 pedidos do client 12 estavam pending, inclusive os Atendidos).
     */
    protected function extractSituacaoId(array $data): ?int
    {
        $situacao = $data["situacao"] ?? [];

        return $situacao["id"] ?? null;
    }

    /**
     * MUL-133: primeiro codigoRastreamento não-vazio em transporte.volumes[].
     * DBA preenche depois do envio (ex. AMZB988095328tx); fulfillment nunca tem.
     */
    protected function extractTracking(array $orderData): ?string
    {
        foreach (($orderData["transporte"]["volumes"] ?? []) as $volume) {
            if (! empty($volume["codigoRastreamento"])) {
                return $volume["codigoRastreamento"];
            }
        }

        return null;
    }

    protected function extractShippingMode(array $orderData): ?string
    {
        return $orderData["transporte"]["volumes"][0]["servico"] ?? null;
    }

    /**
     * MUL-133: transportadora quase nunca vem em pedidos de canal (Amazon/Shopee);
     * cai pro tipo de integração do canal de venda (loja.id → /canais-venda).
     * MUL-374 (decisão Ruan 12/08): o canal de envio verdadeiro é o SERVIÇO do objeto
     * de postagem (transporte.volumes[].servico — ex. "Shopee Xpress"), não o tipo da
     * integração ("Shopee"). EXCEÇÃO: canais Amazon mantêm o rótulo do canal
     * ("Amazon Fulfillment"/"Amazon DBA") — a pagabilidade FBA (MUL-280) e o painel
     * dependem desses rótulos canônicos.
     */
    protected function extractCarrier(MarketplaceAccount $account, array $orderData): ?string
    {
        $canal = $this->channelLabel($account, $orderData["loja"]["id"] ?? null);
        if ($canal !== null && str_starts_with($canal, 'Amazon')) {
            return $orderData["transporte"]["transportadora"]["nome"]
                ?? $orderData["transporte"]["nome"]
                ?? $canal;
        }

        return $orderData["transporte"]["volumes"][0]["servico"]
            ?? $orderData["transporte"]["transportadora"]["nome"]
            ?? $orderData["transporte"]["nome"]
            ?? $canal;
    }

    /** @var array<int, array<int, array{label: string|null, marketplace: string|null}>> mapa loja.id → info, por conta (cache por request) */
    protected array $channelCache = [];

    protected function channelInfo(MarketplaceAccount $account, ?int $lojaId): ?array
    {
        if (! $lojaId) {
            return null;
        }

        if (! isset($this->channelCache[$account->id])) {
            $map = [];
            try {
                $resp = $this->client->listSalesChannels($account);
                foreach (($resp["data"] ?? []) as $channel) {
                    $tipo = $channel["tipo"] ?? "";
                    $desc = $channel["descricao"] ?? "";
                    $map[$channel["id"]] = [
                        "label" => match (true) {
                            $tipo === "AmazonFulfillment" => "Amazon Fulfillment",
                            $tipo === "Amazon" && stripos($desc, "dba") !== false => "Amazon DBA",
                            default => $tipo ?: null,
                        },
                        // MUL-135: nome do marketplace de verdade (o Bling é só o ERP)
                        "marketplace" => match (true) {
                            str_starts_with($tipo, "Amazon") => "Amazon",
                            $tipo === "MercadoLivre" => "Mercado Livre",
                            default => $tipo ?: null,
                        },
                    ];
                }
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[BlingOrderSync] falha ao listar canais de venda', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
            $this->channelCache[$account->id] = $map;
        }

        return $this->channelCache[$account->id][$lojaId] ?? null;
    }

    protected function channelLabel(MarketplaceAccount $account, ?int $lojaId): ?string
    {
        return $this->channelInfo($account, $lojaId)["label"] ?? null;
    }

    protected function marketplaceName(MarketplaceAccount $account, array $orderData): ?string
    {
        return $this->channelInfo($account, $orderData["loja"]["id"] ?? null)["marketplace"] ?? null;
    }

    /**
     * MUL-135 (decisão Ruan 03/07): o numeroLoja vai COMPLETO — nos Fulfillment ele é
     * composto ("P7Nb6Z0dG|701-9607244-2362616") e o Ruan quer ver o valor inteiro.
     */
    protected function extractMarketplaceOrderId(array $orderData): ?string
    {
        return trim((string) ($orderData["numeroLoja"] ?? "")) ?: null;
    }

    /**
     * MUL-133: endereço de entrega vem em transporte.etiqueta → JSON no formato
     * já usado pelos pedidos Shopee/ML (street/number/.../zip_code).
     */
    protected function extractAddress(array $orderData): ?string
    {
        $etiqueta = $orderData["transporte"]["etiqueta"] ?? null;
        if (! $etiqueta || empty($etiqueta["endereco"])) {
            return null;
        }

        return json_encode([
            "street"       => $etiqueta["endereco"] ?? "",
            "number"       => $etiqueta["numero"] ?? "",
            "complement"   => $etiqueta["complemento"] ?? "",
            "neighborhood" => $etiqueta["bairro"] ?? "",
            "city"         => $etiqueta["municipio"] ?? "",
            "state"        => $etiqueta["uf"] ?? "",
            "zip_code"     => preg_replace("/\D/", "", $etiqueta["cep"] ?? ""),
            "country"      => "BR",
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function mapStatus(?int $situacao): string
    {
        return match ($situacao) {
            6 => "pending",         // Em aberto
            9 => "paid",            // Atendido
            12 => "cancelled",      // Cancelado
            15 => "shipped",        // Em andamento (enviado)
            24 => "shipped",        // Verificado (fim do checkout Bling: NF emitida + saida) — MUL-238
            default => "pending",
        };
    }

    /**
     * MUL-385: capa do produto para o item do pedido. Mesmo criterio do
     * ProcessMLOrderJob (url com fallback para original_url, ordenando por is_cover).
     *
     * O importador Bling NUNCA preenchia product_image -- zero ocorrencias no arquivo --
     * entao o item aparecia sem foto no painel mesmo com a capa cadastrada no catalogo.
     * Medido em 15/08: 1.802 itens sem imagem tinham capa disponivel no produto.
     */
    protected function capaDoProduto(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        $cover = \App\Models\ProductMedia::where('product_id', $productId)
            ->orderByDesc('is_cover')->orderBy('position')->first();

        return $cover?->url ?: $cover?->original_url;
    }

    /**
     * MUL-460: o campo `data` do Bling e o DIA EM UTC, date-only. Pedido de 21h-0h BR
     * chega datado de "amanha" (flip medido as 21h BR = 00:00 UTC). Sem hora no campo,
     * data futura em relacao a captura = usa o momento da captura (sync de 5 min =>
     * captura ~= criacao). Datas passadas ficam como o Bling mandou.
     */
    private static function dataDoPedidoBling(?string $data): ?\Carbon\Carbon
    {
        if (empty($data)) {
            return null;
        }
        $d = \Carbon\Carbon::parse($data, config('app.timezone'));

        return $d->isAfter(now()) ? now() : $d;
    }
}
