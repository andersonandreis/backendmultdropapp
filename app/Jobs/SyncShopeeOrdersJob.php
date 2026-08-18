<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Financial\AutoPayService;
use App\Services\WebhookOrderService;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncShopeeOrdersJob
 *
 * Disparado automaticamente via MarketplaceAccountObserver quando:
 * - Uma conta Shopee e criada (created)
 * - Status muda para "active" (updated + isDirty status)
 *
 * Busca pedidos READY_TO_SHIP desde account.data_inicial_import
 * (fallback: created_at da conta; ultimo fallback: 7 dias).
 * De-duplica por marketplace_order_id OU external_order_id (pedidos vindos do legado).
 * Atualiza tracking/status se pedido ja existe e status mudou.
 * MUL-092: filtro ready_to_ship + dedup cruzado com legado.
 */
class SyncShopeeOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $accountId
    ) {}

    public function handle(ShopeeService $shopee): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (! $account) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] Conta nao encontrada', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if ($account->platform !== 'shopee') {
            return;
        }

        // SEL-357: conta espelho readonly — nao sincronizar da API Shopee real
        if (($account->mirror_mode ?? 'active') === 'readonly') {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Conta espelho readonly (SEL-357) — skip sync real', [
                'account_id'            => $this->accountId,
                'mirror_source_backend' => $account->mirror_source_backend,
            ]);
            return;
        }

        // MUL-212 F2: guard por instalacao (banco) — cobre cron, observer e dispatch manual
        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->pullsOrders('shopee') || $cfg->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Pull desativado nesta instalacao (MUL-212 F2) — skip', [
                'account_id'        => $this->accountId,
                'centrally_managed' => (bool) $account->centrally_managed,
            ]);
            return;
        }

        if (! $account->shop_id || ! $account->access_token) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] shop_id ou access_token ausente — abortando', [
                'account_id' => $this->accountId,
                'status'     => $account->status,
            ]);
            return;
        }

        if ($account->sync_blocked_at !== null) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] Conta bloqueada — abortando', [
                'account_id' => $this->accountId,
            ]);
            return;
        }

        if (! $account->supplier_id) {
            Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] supplier_id ausente — abortando sem criar pedidos', [
                'account_id' => $this->accountId,
                'client_id'  => $account->client_id,
            ]);
            return;
        }

        Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Iniciando sync de pedidos', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'client_id'  => $account->client_id,
        ]);

        // MUL-092: respeitar data_inicial_import da conta (fallback: created_at, ultimo fallback: 7 dias).
        // Sem esse filtro, o cron horario acaba puxando historico inteiro e duplica pedidos
        // que ja existem no legado. data_inicial_import e configuravel pelo seller em /integracoes.
        $sinceDate = $account->data_inicial_import
            ? \Carbon\Carbon::parse($account->data_inicial_import)->toDateTimeString()
            : ($account->created_at ? $account->created_at->toDateTimeString() : now()->subDays(7)->toDateTimeString());

        try {
            $orders = $shopee->fetchOrders($account, $sinceDate);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[SyncShopeeOrdersJob] fetchOrders excecao', [
                'account_id' => $this->accountId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (empty($orders)) {
            Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Nenhum pedido retornado pela API', [
                'account_id' => $this->accountId,
            ]);
            // Bug fix 2026-08-10: atualiza last_sync_at mesmo sem pedidos
            $account->update(['last_sync_at' => now()]);
            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($orders as $rawOrder) {
            $orderSn = $rawOrder['order_sn'] ?? null;
            if (! $orderSn) {
                continue;
            }

            $shopeeStatus = strtolower($rawOrder['order_status'] ?? '');

            // MUL-092: importar SOMENTE pedidos com status 'A Enviar' (READY_TO_SHIP).
            // Motivo: evitar duplicar com pedidos ja no legado. Depois que o cliente
            // conecta, so importar o que ainda precisa ser processado (a enviar).
            // Alinha com ImportMarketplaceAccountDataJob::importShopeeOrders.
            // MUL-424: o filtro por READY_TO_SHIP era aplicado AQUI, antes de procurar o
            // pedido no banco. Quando a Shopee virava o pedido para SHIPPED, o payload era
            // descartado e o pedido ficava congelado como "a enviar" para sempre: em
            // 18/08/2026, 59 dos 80 pedidos da fila de separacao ja estavam SHIPPED na
            // Shopee. Nenhum outro caminho consertava isso -- shipped_at so era escrito
            // pelo legado e pelo despacho manual do painel.
            // O filtro continua valendo para a CRIACAO (razao original da MUL-092: nao
            // duplicar pedido que ja existe no legado), e por isso desceu para o ramo do
            // else. Ele nao pode mais impedir a ATUALIZACAO de um pedido que ja e nosso.

            // MUL-092: dedup robusto — cruzar com pedidos do legado (external_order_id)
            // e com pedidos criados via OAuth (marketplace_order_id). Sem cruzar com
            // external_order_id, seller com Shopee ja no legado ganha o pedido 2x.
            // Ver auditoria MUL-092: storetotao (client 12) — 64 pedidos duplicados
            // do legado (external_order_id = order_sn) apos conectar Shopee OAuth.
                        // MUL-187: sem filtro de source — pedido pode ja existir via Bling/legado
            // com o mesmo marketplace_order_id; filtrar por source criava duplicata zerada.
$existing = Order::where('client_id', $account->client_id)
                ->where(function ($q) use ($orderSn) {
                    $q->where('marketplace_order_id', $orderSn)
                      ->orWhere('external_order_id', $orderSn);
                })
                ->first();

            if ($existing) {
                // Pedido ja existe — atualizar status e tracking se mudou
                $novoStatus = $this->mapShopeeStatus($shopeeStatus);

                // MUL-424: antes, QUALQUER pedido local em paid/shipped/completed/delivered
                // era pulado -- e era exatamente isso que congelava o pedido pago em
                // "a enviar" mesmo depois de despachado na Shopee. A regra correta e outra:
                // o status so AVANCA, nunca volta. Assim a Shopee consegue nos contar que
                // enviou (ou cancelou), e um payload atrasado nao rebaixa um pedido que ja
                // seguiu adiante.
                $avancou = $this->rankStatus($novoStatus) > $this->rankStatus($existing->canonical_status ?: $existing->status);

                // MUL-424b: rastreio e transportadora NAO dependem do status avancar -- a
                // Shopee costuma emitir o codigo com o pedido ainda em "a enviar". Barrar
                // isso junto com o status faria a correcao do congelamento custar o dado
                // de logistica, que e o que o separador usa na etiqueta.
                $mudancas = [];

                $novoRastreio = $rawOrder['tracking_no'] ?? null;
                if ($novoRastreio && $novoRastreio !== $existing->tracking_number) {
                    $mudancas['tracking_number'] = $novoRastreio;
                }

                $novaTransportadora = $rawOrder['package_list'][0]['shipping_carrier'] ?? $rawOrder['shipping_carrier'] ?? null;
                if ($novaTransportadora && $novaTransportadora !== $existing->carrier_name) {
                    $mudancas['carrier_name'] = $novaTransportadora;
                }

                if ($avancou) {
                    $mudancas['status']           = $novoStatus;
                    $mudancas['canonical_status'] = $novoStatus;

                    // MUL-424: nenhum caminho de marketplace escrevia shipped_at (so o
                    // legado e o despacho manual), entao jaFoiEnviado() e os relatorios de
                    // expedicao nao enxergavam o envio feito na propria Shopee. E o
                    // instante em que ficamos sabendo, nao o do despacho -- a Shopee nao
                    // manda essa hora na listagem, e a hora real de saida esta no rastreio.
                    if (in_array($novoStatus, ['shipped', 'delivered', 'completed'], true) && ! $existing->shipped_at) {
                        $mudancas['shipped_at'] = now();
                    }
                }

                // Nada mudou: nao gasta escrita nem acorda o observer.
                if (! $mudancas) {
                    $skipped++;
                    continue;
                }

                $mudancas['updated_at'] = now();
                $existing->update($mudancas);
                $updated++;
            } else {
                // MUL-424 / MUL-092: aqui sim o filtro vale. Pedido que ainda nao e nosso e
                // nao esta "a enviar" nao entra -- e o que evita duplicar com o legado.
                if ($shopeeStatus !== 'ready_to_ship') {
                    $skipped++;
                    continue;
                }

                // MUL-197: pedido nasce RASCUNHO (is_draft=1) e so vira pedido normal na
                // PROMOCAO (DraftOrderPromoter), quando completo: customer_name, total>0,
                // itens, paid_at. Rascunho nao dispara fanout/efeitos (OrderObserver
                // suprime) nem AutoPay — ambos rodam na promocao.
                // MUL-329: data real da venda, em horario LOCAL (era ->utc). Mesmo padrao do pay_time abaixo.
                $marketplaceCreatedAt = ! empty($rawOrder["create_time"])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder["create_time"])->setTimezone(config('app.timezone'))
                    : null;

                $payTime = ! empty($rawOrder['pay_time'])
                    ? \Carbon\Carbon::createFromTimestamp((int) $rawOrder['pay_time'])->setTimezone(config('app.timezone'))
                    : null;

                $newOrder = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'shopee',
                    'marketplace_order_id'   => $orderSn,
                    'order_number'           => $orderSn,
                    'status'                 => $this->mapShopeeStatus($shopeeStatus),
                    'canonical_status'       => $this->mapShopeeStatus($shopeeStatus),
                    'total'                  => $rawOrder['total_amount'] ?? 0,
                    'customer_name'          => $this->extractCustomerName($rawOrder),
                    'paid_at'                => $payTime,
                    "marketplace_created_at" => $marketplaceCreatedAt,
                    'buyer_username'         => $rawOrder['buyer_username'] ?? null,
                    'tracking_number'        => $rawOrder['tracking_no'] ?? null,
                    'carrier_name'           => $rawOrder['package_list'][0]['shipping_carrier'] ?? $rawOrder['shipping_carrier'] ?? null,
                    'raw_payload'            => json_encode($rawOrder),
                    'is_draft'               => true,
                    'draft_reason'           => 'awaiting_validation',
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
                $created++;

                // MES-043: sincronizar itens do pedido a partir do raw_payload
                // item_list ja vem no fetchOrders (response_optional_fields inclui item_list)
                try {
                    WebhookOrderService::upsertShopeeItemsFromPayload($newOrder, $rawOrder, $account);
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] upsertItems falhou (nao critico)', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // MUL-197: promocao imediata quando o payload ja veio completo.
                // Fanout order.created + AutoPay (idempotente) saem do promoter.
                // Incompleto: fica rascunho e o EnrichShopeeOrderJob busca o resto
                // via get_order_detail (retry/refresh/backoff).
                try {
                    [$promoted] = app(\App\Services\Orders\DraftOrderPromoter::class)->promote($newOrder, 'sync_shopee_job');
                    if (! $promoted) {
                        \App\Jobs\EnrichShopeeOrderJob::dispatch($newOrder->id)->delay(now()->addSeconds(60));
                    }
                } catch (\Throwable $e) {
                    Log::channel('marketplace')->warning('[SyncShopeeOrdersJob] promocao/enrich dispatch falhou', [
                        'order_sn' => $orderSn,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        $account->update(['last_sync_at' => now()]);

        Log::channel('marketplace')->info('[SyncShopeeOrdersJob] Sync concluido', [
            'account_id' => $this->accountId,
            'shop_id'    => $account->shop_id,
            'total_api'  => count($orders),
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
        ]);
    }

    /**
     * MUL-197: nome do comprador do recipient_address do payload, ignorando
     * mascaras da Shopee (pedidos antigos voltam "*****" — sem alfanumerico).
     */
    private function extractCustomerName(array $rawOrder): ?string
    {
        $name = trim((string) ($rawOrder['recipient_address']['name'] ?? ''));
        if ($name === '' || ! preg_match('/[\p{L}0-9]/u', $name)) {
            return null;
        }
        return $name;
    }

    /**
     * MUL-424: ordem de avanco do ciclo de vida do pedido. Existe para o sync nunca
     * REBAIXAR um pedido: payload antigo que chega atrasado nao pode desfazer um envio
     * ja registrado. cancelled/returned ficam no topo por serem desfechos -- valem
     * sobre qualquer etapa anterior.
     */
    private function rankStatus(?string $status): int
    {
        return match ($status) {
            'pending'                => 0,
            'processing', 'paid'     => 1,
            'shipped'                => 2,
            'delivered', 'completed' => 3,
            'returned', 'cancelled'  => 4,
            default                  => 0,
        };
    }

    private function mapShopeeStatus(string $shopeeStatus): string
    {
        return match ($shopeeStatus) {
            'unpaid'        => 'pending',
            'ready_to_ship' => 'processing',
            'processed'     => 'processing',
            'shipped'       => 'shipped',
            'in_cancel'     => 'cancelled',
            'cancelled'     => 'cancelled',
            'to_return'     => 'returned',
            'completed'     => 'completed',
            default         => 'pending',
        };
    }
}
