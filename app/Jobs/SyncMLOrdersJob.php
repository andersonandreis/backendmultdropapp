<?php

namespace App\Jobs;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\Integrations\Marketplaces\MercadoLivreService;
use App\Services\Orders\DraftOrderPromoter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncMLOrdersJob
 *
 * Sync periodico de pedidos Mercado Livre para uma conta especifica.
 * Disparado automaticamente a cada hora pelo scheduler (console.php) para
 * TODAS as contas ML ativas — cobre pedidos nao recebidos via webhook.
 *
 * Complementa o MarketplaceAccountObserver (disparado no OAuth inicial).
 * Janela: ultimas 25h (1h de sobreposicao entre execucoes).
 * De-duplica por external_order_id — seguro re-executar a qualquer momento.
 */
class SyncMLOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    /** Janela de busca: 25h garante 1h de sobreposicao entre runs horarios */
    private const WINDOW_HOURS = 25;

    public function __construct(
        public readonly int $accountId
    ) {}

    public function handle(MercadoLivreService $ml): void
    {
        $account = MarketplaceAccount::find($this->accountId);

        if (! $account) {
            Log::channel('marketplace')->warning('[SyncMLOrdersJob] Conta nao encontrada', ['account_id' => $this->accountId]);
            return;
        }

        if (! in_array($account->platform, ['mercadolivre', 'mercado_livre'])) {
            return;
        }

        // MUL-212 F2: guard por instalacao (banco) — cobre cron, observer e dispatch manual
        $cfg = app(\App\Services\InstallationConfig::class);
        if (! $cfg->pullsOrders('mercadolivre') || $cfg->skipsCentralAccountPull((bool) $account->centrally_managed)) {
            Log::channel('marketplace')->info('[SyncMLOrdersJob] Pull desativado nesta instalacao (MUL-212 F2) — skip', [
                'account_id'        => $this->accountId,
                'centrally_managed' => (bool) $account->centrally_managed,
            ]);
            return;
        }

        if ($account->sync_blocked_at !== null || $account->status === 'needs_reauth') {
            Log::channel('marketplace')->info('[SyncMLOrdersJob] Conta bloqueada/needs_reauth — skip', ['account_id' => $this->accountId]);
            return;
        }

        if (! $account->access_token && ! $account->ml_access_token) {
            Log::channel('marketplace')->warning('[SyncMLOrdersJob] Sem token — abortando', ['account_id' => $this->accountId]);
            return;
        }

        Log::channel('marketplace')->info('[SyncMLOrdersJob] Iniciando sync de pedidos ML', [
            'account_id' => $this->accountId,
            'client_id'  => $account->client_id,
        ]);

        try {
            $since  = now()->subHours(self::WINDOW_HOURS)->toIso8601String();
            $orders = $ml->fetchOrders($account, $since);
        } catch (\Throwable $e) {
            Log::channel('marketplace')->error('[SyncMLOrdersJob] fetchOrders excecao', [
                'account_id' => $this->accountId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        if (empty($orders)) {
            Log::channel('marketplace')->info('[SyncMLOrdersJob] Nenhum pedido retornado', ['account_id' => $this->accountId]);
            // Bug fix 2026-08-10: atualiza last_sync_at mesmo sem pedidos
            $account->update(['last_sync_at' => now()]);
            return;
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $promoted = 0;

        // MUL-202: promove rascunho pra publicado quando dados minimos chegam
        $promoter = app(DraftOrderPromoter::class);

        foreach ($orders as $rawOrder) {
            $mlOrderId = (string) ($rawOrder['id'] ?? '');
            if (! $mlOrderId) {
                continue;
            }

            $mlStatus    = $rawOrder['status'] ?? '';
            $localStatus = $this->mapMLStatus($mlStatus);
            $buyer       = $rawOrder['buyer'] ?? [];
            $totalAmount = $rawOrder['total_amount'] ?? 0;

            // MUL-423: o payload da BUSCA ja traz shipping.id e date_created, e os dois
            // eram descartados aqui. Consequencia medida em 18/08/2026: 23 dos 40 pedidos
            // de ML sem external_shipping_id -- e o agendamento de etiqueta (MUL-205, nos
            // dois ramos abaixo) exige exatamente esse campo, entao ele nunca rodava para
            // pedido nascido do sync, so para os que chegavam pelo webhook.
            // A conversao de fuso repete a convencao do WebhookOrderService (FOR-119):
            // sem setTimezone a data fica 3h a frente e o painel mostra o pedido pago
            // antes de existir.
            $shippingIdMkt = ! empty(($rawOrder['shipping'] ?? [])['id'])
                ? (string) $rawOrder['shipping']['id']
                : null;
            $criadoNoMkt = ! empty($rawOrder['date_created'])
                ? \Carbon\Carbon::parse($rawOrder['date_created'])->setTimezone(config('app.timezone'))
                : null;


            // MUL-091: NOV-158 — importar apenas pedidos em aberto.
            // Skip pedidos ja enviados/finalizados/cancelados — nao precisam de acao.
            $shippingStatus = strtolower(($rawOrder["shipping"] ?? [])["status"] ?? "");
            // MUL-424: este guard existia para nao IMPORTAR pedido ja fechado (NOV-158),
            // mas rodava antes da busca no banco -- entao pedido que JA E NOSSO e que a ML
            // ja despachou tambem era descartado, e ficava parado na fila de separacao para
            // sempre (mesmo defeito medido na Shopee). Agora ele so barra a criacao; a
            // atualizacao de pedido nosso segue adiante.
            $pedidoFechadoNoML = in_array($mlStatus, ["cancelled", "invalid"], true)
                || in_array($shippingStatus, ["shipped", "delivered", "not_delivered"], true);

            // FOR-124: fora do escopo de tenant -- pedido com supplier_id NULL some do whereIn
            // e a deduplicacao acaba criando duplicata.
            $existing = Order::withoutTenantSupplierScope()
                ->where('external_order_id', $mlOrderId)
                ->where('source', 'mercadolivre')
                ->first();

            if (! $existing && $pedidoFechadoNoML) {
                $skipped++;
                continue;
            }

            if ($existing) {
                if (in_array($existing->status, ['shipped', 'completed', 'delivered'])) {
                    $skipped++;
                    continue;
                }

                $updates = [
                    'status'           => $localStatus,
                    'canonical_status' => $localStatus,
                    'updated_at'       => now(),
                ];

                // MUL-423: pedido que nasceu sem esses campos se conserta aqui, no proximo
                // sync horario -- sem script de backfill e sem rotina nova. So preenche o
                // que esta vazio: dado ja gravado (ex.: vindo do webhook, que e mais
                // completo) nunca e sobrescrito.
                if (! $existing->external_shipping_id && $shippingIdMkt) {
                    $updates['external_shipping_id'] = $shippingIdMkt;
                }
                if (! $existing->marketplace_created_at && $criadoNoMkt) {
                    $updates['marketplace_created_at'] = $criadoNoMkt;
                }

                // MUL-424: no ML o status do PEDIDO continua "paid" depois do despacho --
                // quem conta que saiu e o shipping.status. Sem traduzir isso, mapMLStatus
                // devolvia "paid" para sempre e o pedido nunca saia da fila de separacao.
                $statusDeEnvio = match ($shippingStatus) {
                    'shipped'       => 'shipped',
                    'delivered'     => 'delivered',
                    'not_delivered' => 'shipped',
                    default         => null,
                };
                if ($statusDeEnvio) {
                    $updates['status']           = $statusDeEnvio;
                    $updates['canonical_status'] = $statusDeEnvio;
                    if (! $existing->shipped_at) {
                        $updates['shipped_at'] = now();
                    }
                }
                // MUL-204: persistir shipping.status pra distinguir handling (adiada) de ready_to_ship
                $mappedShipping = $shippingStatus ? $this->mapMLShippingStatus($shippingStatus) : null;
                if ($mappedShipping !== null) {
                    $updates['order_processing_status'] = $mappedShipping;
                }
                $existing->update($updates);
                $updated++;

                // FOR-136: pedido que ja existia mas continua sem item -- o sync tem o
                // payload em maos e pode completar, em vez de esperar outro webhook.
                if (! empty($rawOrder['order_items']) && $existing->items()->count() === 0) {
                    try {
                        \App\Services\WebhookOrderService::upsertMLItemsFromPayload(
                            $existing, $rawOrder, $account
                        );
                        Log::channel('marketplace')->info('[FOR-136] itens completados no update', [
                            'order_id' => $existing->id, 'ml_id' => $mlOrderId,
                        ]);
                    } catch (\Throwable $e) {
                        Log::channel('marketplace')->warning('[FOR-136] falha ao completar itens', [
                            'order_id' => $existing->id, 'erro' => $e->getMessage(),
                        ]);
                    }
                }

                // MUL-202: se pedido existente ainda e rascunho, tenta promover
                if ($existing->is_draft) {
                    try {
                        [$wasPromoted,] = $promoter->promote($existing->fresh(), 'ml_sync_update');
                        if ($wasPromoted) {
                            $promoted++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[SyncMLOrdersJob] promote em update falhou', [
                            'order_id' => $existing->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

                // MUL-205: se handling + wallet_paid_at, agenda polling na data ML real
                $orderRef = $existing->fresh();
                if ($orderRef && $shippingStatus === 'handling' && $orderRef->wallet_paid_at && $orderRef->external_shipping_id && ! $orderRef->label_url) {
                    try {
                        $readyDate = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class)
                            ->getShipmentReadyDate($account, (string) $orderRef->external_shipping_id);
                        $nextCheck = $readyDate?->addHours(2) ?? now()->addHours(24);
                        \App\Models\OrderLabelQueue::updateOrCreate(
                            ['order_id' => $orderRef->id],
                            ['status' => 'pending', 'next_check_at' => $nextCheck]
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[SyncMLOrdersJob] MUL-205 schedule falhou', [
                            'order_id' => $orderRef->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

            } else {
                // MUL-202: nasce is_draft=1 - DraftOrderPromoter tenta promover na sequencia.
                // Se supplier_unit_cost nao calculavel (sem product vinculado), permanece rascunho.
                $order = Order::create([
                    'client_id'              => $account->client_id,
                    'supplier_id'            => $account->supplier_id,
                    'marketplace_account_id' => $account->id,
                    'source'                 => 'mercadolivre',
                    'external_order_id'      => $mlOrderId,
                    'marketplace_order_id'   => $mlOrderId,
                    'order_number'           => $mlOrderId,
                    'status'                 => $localStatus,
                    'canonical_status'       => $localStatus,
                    'subtotal'               => $totalAmount,
                    'total'                  => $totalAmount,
                    'currency'               => $rawOrder['currency_id'] ?? 'BRL',
                    // MUL-423: ver o bloco no inicio do loop
                    'external_shipping_id'   => $shippingIdMkt,
                    'marketplace_created_at' => $criadoNoMkt,
                    'buyer_id'               => (string) ($buyer['id'] ?? ''),
                    'buyer_username'         => $buyer['nickname'] ?? null,
                    'customer_name'          => trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')),
                    'raw_payload'            => json_encode($rawOrder),
                    'paid_at'                => $localStatus === 'paid' ? now() : null,
                    // MUL-204: shipping.status mapeado do ML
                    'order_processing_status' => ($shippingStatus ? $this->mapMLShippingStatus($shippingStatus) : null) ?? 'awaiting_label',
                    // MUL-202: nasce rascunho ate promover
                    'is_draft'               => 1,
                    'draft_reason'           => 'ml_sync_incomplete',
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
                $created++;

                // FOR-136: o cron RECEBE os itens no payload da API de busca e os jogava
                // fora -- media medida em 14/08: 301 de 406 payloads trazem order_items.
                // O pedido nascia so com cabecalho e dependia do webhook para ter item,
                // o que criava a janela de "pedido sem item" (ate 1h em 126 de 497 casos).
                // Grava com a MESMA chave dos outros caminhos (FOR-135), entao reescrever
                // depois pelo webhook e idempotente e nao duplica.
                if (! empty($rawOrder['order_items'])) {
                    try {
                        $itensGravados = \App\Services\WebhookOrderService::upsertMLItemsFromPayload(
                            $order, $rawOrder, $account
                        );
                        Log::channel('marketplace')->info('[FOR-136] itens gravados pelo sync', [
                            'order_id' => $order->id,
                            'ml_id'    => $mlOrderId,
                            'itens'    => $itensGravados,
                        ]);
                    } catch (\Throwable $e) {
                        // nao critico: o webhook grava depois. Nunca derrubar o sync por isso.
                        Log::channel('marketplace')->warning('[FOR-136] falha ao gravar itens no sync', [
                            'order_id' => $order->id,
                            'ml_id'    => $mlOrderId,
                            'erro'     => $e->getMessage(),
                        ]);
                    }
                }

                // MUL-202: tenta promover imediatamente. Se sem produto vinculado (custo=0),
                // fica rascunho ate webhook ML ou proximo sync com items completos.
                try {
                    [$wasPromoted, $missing] = $promoter->promote($order, 'ml_sync_create');
                    if ($wasPromoted) {
                        $promoted++;
                    }
                    Log::channel('marketplace')->info('[SyncMLOrdersJob] MUL-202 promote', [
                        'order_id' => $order->id,
                        'ml_id'    => $mlOrderId,
                        'promoted' => $wasPromoted,
                        'missing'  => $missing,
                    ]);

                    // MUL-423: o endpoint de BUSCA nao devolve buyer.first_name/last_name
                    // (traz so id e nickname), entao TODO pedido de ML nasce sem
                    // customer_name, o DraftOrderPromoter o mantem rascunho, e rascunho
                    // nao aparece no painel do seller. O GET /orders/{id} DEVOLVE o nome
                    // (conferido ao vivo em 18/08/2026), e quem chama esse endpoint e o
                    // EnrichMercadoLivreOrderJob. Em vez de esperar o cron de 15min mais o
                    // cooldown de 30min por pedido, despacha o MESMO job agora. Nao e
                    // processo novo: e o caminho que ja existe, sem a espera.
                    if (! $wasPromoted && in_array('customer_name', $missing, true)) {
                        \App\Jobs\EnrichMercadoLivreOrderJob::dispatch($order->id)->onQueue('default');
                    }
                } catch (\Throwable $e) {
                    Log::warning('[SyncMLOrdersJob] promote na criacao falhou', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // MUL-205: se handling + wallet_paid_at, agenda polling na data ML real
                $orderRef = $order;
                if ($orderRef && $shippingStatus === 'handling' && $orderRef->wallet_paid_at && $orderRef->external_shipping_id && ! $orderRef->label_url) {
                    try {
                        $readyDate = app(\App\Services\Integrations\Marketplaces\MercadoLivreService::class)
                            ->getShipmentReadyDate($account, (string) $orderRef->external_shipping_id);
                        $nextCheck = $readyDate?->addHours(2) ?? now()->addHours(24);
                        \App\Models\OrderLabelQueue::updateOrCreate(
                            ['order_id' => $orderRef->id],
                            ['status' => 'pending', 'next_check_at' => $nextCheck]
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('[SyncMLOrdersJob] MUL-205 schedule falhou', [
                            'order_id' => $orderRef->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }

            }
        }

        $account->update(['last_sync_at' => now()]);

        Log::channel('marketplace')->info('[SyncMLOrdersJob] Sync concluido', [
            'account_id' => $this->accountId,
            'client_id'  => $account->client_id,
            'total_api'  => count($orders),
            'created'    => $created,
            'updated'    => $updated,
            'skipped'    => $skipped,
            'promoted'   => $promoted,
        ]);
    }

    private function mapMLStatus(string $mlStatus): string
    {
        return match ($mlStatus) {
            'payment_required',
            'payment_in_process' => 'pending',
            'paid'               => 'paid',
            'cancelled',
            'invalid'            => 'cancelled',
            default              => 'pending',
        };
    }

    /**
     * MUL-204: mapeia shipping.status do ML pro order_processing_status local.
     * ML: handling | ready_to_ship | shipped | delivered | not_delivered | cancelled | to_be_agreed
     * Ficamos com a mesma semantica usada pra Shopee (awaiting_label/label_ready/shipped/delivered/cancelled).
     */
    private function mapMLShippingStatus(string $shippingStatus): ?string
    {
        return match ($shippingStatus) {
            'handling'      => 'awaiting_label',
            'ready_to_ship' => 'label_ready',
            'shipped'       => 'shipped',
            'delivered'     => 'delivered',
            'not_delivered' => 'label_failed',
            'cancelled'     => 'cancelled',
            'to_be_agreed'  => 'pending',
            default         => null,
        };
    }
}
