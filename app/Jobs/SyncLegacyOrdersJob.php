<?php

namespace App\Jobs;

use App\Jobs\FetchShippingLabelJob;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza status dos pedidos manuais do banco legado para o novo HubAI.
 *
 * Pedidos manuais tem legacy_id apontando para legacy.pedidos.id.
 * Este job puxa status, rastreio e etiqueta a cada 5 minutos.
 *
 * Registrado em routes/console.php:
 *   Schedule::job(new SyncLegacyOrdersJob)->everyFiveMinutes()->withoutOverlapping()->name('sync-legacy-orders');
 *
 * Nota: usa updateQuietly() para nao disparar observers em cascata.
 *
 * Fix 2026-06-02: chunkById(1000) para evitar memory exhaustion em 35k+ pedidos.
 * O carregamento anterior com ->get() consumia ~245MB e excedia o limite de 256MB.
 *
 * Fix MUL-036 (2026-06-24): timeout 300->600s + tries=1 + skip pedidos finais estabilizados.
 * Causa raiz: retry_after=90s (config DB) < tempo de execucao do job (~300-600s para 37k pedidos),
 * fazendo o worker re-enfileirar o job antes de ele terminar — gerou 51k duplicatas acumuladas.
 * Correcao em 3 frentes:
 *   1. DB_QUEUE_RETRY_AFTER=660 no .env (maior que o timeout do job)
 *   2. $timeout=600 no job (era 300)
 *   3. $tries=1 (job de schedule nao deve ser retentado — o proximo ciclo de 5min cobre)
 *   4. Filtro de status: skip de pedidos delivered/cancelled com mais de 7 dias sem update
 *      (economiza ~40% das queries ao legado sem perder transicoes tardias)
 */
class SyncLegacyOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * HUB-113: queue dedicada legacy-import para isolar das queues default/webhooks.
     * Sem isso, este job (600s) sufoca a default e os webhooks reais ficam esperando,
     * gerando o backlog de 1.7M jobs visto em 26/06. Worker dedicado deve ser provisionado
     * no supervisor antes do merge (ver HUB-113 PR description).
     *
     * Aplicado via construtor para evitar conflito de propriedade tipada com a trait
     * Queueable (que ja define $queue sem tipo).
     */
    public function __construct()
    {
        $this->onQueue('legacy-import');
    }

    /**
     * Timeout em segundos. Deve ser menor que DB_QUEUE_RETRY_AFTER (660s) configurado
     * no .env para evitar que o worker re-enfileire o job antes de ele terminar.
     * Fix MUL-036: aumentado de 300 para 600.
     */
    public int $timeout = 600;

    /**
     * Job de schedule nao deve ser retentado automaticamente.
     * Em caso de falha, o proximo agendamento (5 min) cobre o ciclo.
     * tries=1 evita acumulo de duplicatas na fila em caso de erro.
     * Fix MUL-036: adicionado.
     */
    public int $tries = 1;

    /**
     * Janela de graca para pedidos em status final (delivered/cancelled).
     * Pedidos finais com updated_at > FINAL_STATUS_GRACE_DAYS dias atras sao ignorados:
     * o legado raramente reverte esses status apos esse periodo.
     * Pedidos recentes em qualquer status continuam sendo verificados.
     * Fix MUL-036: adicionado (economiza ~40% das queries ao legado).
     */
    const FINAL_STATUS_GRACE_DAYS = 7;

    public function handle(): void
    {
        // Cache lock: previne execucao paralela caso withoutOverlapping() falhe
        // (ex: race condition em restart de worker). TTL = timeout+60s.
        $lock = Cache::lock('sync-legacy-orders', 660);
        if (!$lock->get()) {
            Log::info('SyncLegacyOrdersJob: outra instancia em execucao, abortando.');
            return;
        }

        $startedAt = now();

        $totalChecked = 0;
        $synced       = 0;
        $errors       = 0;
        $skipped      = 0;

        // Escopo otimizado (MUL-036):
        //   - SEMPRE sincroniza pedidos em status nao-final (pending_payment, paid, shipped)
        //   - Para pedidos em status final (delivered, cancelled): apenas os atualizados
        //     nas ultimas FINAL_STATUS_GRACE_DAYS dias para capturar transicoes tardias
        //     (rastreio tardio, NF emitida com atraso, estorno de cancelamento, etc.)
        //
        // Antes: TODOS os 37k+ pedidos com legacy_id a cada execucao.
        // Agora: ~22k (status nao-finais) + ~500 (finais recentes) = ~42% reducao de volume.
        //
        // chunkById(1000): processa em fatias de 1.000 rows para manter
        // uso de memoria constante independente do total de pedidos.
        Order::whereNotNull('legacy_id')
            ->where(function ($q) {
                // Pedidos em status ativo: sempre sincronizar
                $q->whereNotIn('status', ['delivered', 'cancelled'])
                  // Pedidos em status final: so sincronizar se recentes (grace period)
                  ->orWhere(function ($q2) {
                      $q2->whereIn('status', ['delivered', 'cancelled'])
                         ->where('updated_at', '>=', now()->subDays(self::FINAL_STATUS_GRACE_DAYS));
                  });
            })
            ->chunkById(1000, function ($chunk) use (&$synced, &$errors, &$totalChecked, &$skipped, $startedAt) {
                // INF-023: time budget -- aborta graciosamente se restam <60s antes do timeout de 600s.
                // Evita TimeoutExceededException que gerava 384 failed_jobs. O proximo ciclo (5min) continua.
                if (now()->diffInSeconds($startedAt) >= 540) {
                    Log::warning('SyncLegacyOrdersJob: time budget 540s atingido, abortando chunk graciosamente.');
                    return false; // interrompe o chunkById
                }
                $legacyIds = $chunk->pluck('legacy_id')->all();

                // Query legada em sub-lotes de 500 para nao montar IN gigante.
                $legacyRows = collect();
                foreach (array_chunk($legacyIds, 500) as $sub) {
                    $rows = DB::connection('legacy')->table('pedidos')
                        ->whereIn('id', $sub)
                        ->select([
                            'id',
                            'status',
                            'status_marketplace',
                            'curso_pago',
                            'rastreio',
                            'url_img',
                            'valor_pix',
                            'saldo_conta_corrente',
                            'enviado_etiqueta',
                            // === Fase 1: NF + carrier pra sincronizar mudancas ===
                            'carrier_name',
                            'id_nota_erp',
                            'serie_nota_erp',
                            'danfe_nota',
                            'xml_nota',
                            'data_nfe_auto',
                            'desc_status_nota',
                            // === MES-041: detecta etiqueta impressa no painel legado ===
                            // dt_impresso_loja = datetime que o fornecedor clicou em
                            // imprimir etiqueta no painel legado (goolhub.io). Quando
                            // preenchido, o NovoHubAI deve refletir como label_printed
                            // (estava ficando preso em awaiting_label).
                            'dt_impresso_loja',
                        ])
                        ->get();
                    foreach ($rows as $r) {
                        $legacyRows[$r->id] = $r;
                    }
                }

                foreach ($chunk as $order) {
                    $legacy = $legacyRows[$order->legacy_id] ?? null;
                    if (!$legacy) {
                        continue;
                    }

                    try {
                        $updates = [];

                        // Mapeamento de status legado -> novo
                        $newStatus = $this->mapStatus($legacy);
                        if ($newStatus && $newStatus !== $order->status) {
                            $updates['status'] = $newStatus;
                                            $updates['canonical_status'] = $newStatus; // MES-048/MUL-126: manter canonical_status sincronizado com status
                            $updates['order_processing_status'] = $this->mapProcessingStatus($newStatus);

                            // Timestamps de transicao
                            if ($newStatus === 'paid' && !$order->paid_at) {
                                $updates['paid_at'] = $legacy->curso_pago ?: now();
                            }
                            if ($newStatus === 'shipped' && !$order->shipped_at) {
                                $updates['shipped_at'] = now();
                            }
                            if ($newStatus === 'delivered' && !$order->delivered_at) {
                                $updates['delivered_at'] = now();
                            }
                            if ($newStatus === 'cancelled' && !$order->cancelled_at) {
                                $updates['cancelled_at'] = now();
                                $updates['cancel_reason'] = 'Cancelado via legado (status_marketplace=' . ($legacy->status_marketplace ?? '') . ')';
                            }
                            // Pedido saiu de cancelado (legado reverteu): limpa o cancelamento.
                            if ($newStatus !== 'cancelled' && $order->cancelled_at) {
                                $updates['cancelled_at'] = null;
                                $updates['cancel_reason'] = null;
                            }
                        }

                        // Garante processing_status alinhado ao status atual, mesmo
                        // quando o status nao mudou (drift de imports antigos que
                        // ficaram com awaiting_label apos virar delivered).
                        //
                        // MES-041: se o legado marcou etiqueta como impressa
                        // (dt_impresso_loja) e o pedido continua em status 'paid'
                        // (mapeado para awaiting_label), promove o processing_status
                        // para 'label_printed'. Sem isso, fornecedores que imprimem
                        // no painel legado veem o pedido preso em "aguardando etiqueta"
                        // no painel novo.
                        $effectiveStatus = $newStatus ?? $order->status;
                        $expectedProc    = $this->mapProcessingStatus($effectiveStatus);
                        $hasPrintedLabel = $order->label_printed_at || !empty($legacy->dt_impresso_loja);
                        if ($expectedProc === 'awaiting_label' && $hasPrintedLabel) {
                            $expectedProc = 'label_printed';
                        }
                        if ($order->order_processing_status !== $expectedProc) {
                            $updates['order_processing_status'] = $expectedProc;
                        }

                        // MES-041: copia o timestamp de impressao da etiqueta do legado
                        // para label_printed_at no novo sistema. Nao sobrescreve se ja
                        // foi setado localmente (impressao via api.mestoredrop, por ex).
                        if (!empty($legacy->dt_impresso_loja) && !$order->label_printed_at) {
                            $updates['label_printed_at'] = $legacy->dt_impresso_loja;
                        }

                        // Etiqueta gerada no legado
                        // NOV-090: em vez de copiar URL externa (goolhub.io / sistemagrupoonline)
                        // diretamente para label_url, disparamos FetchShippingLabelJob para
                        // baixar e armazenar a etiqueta em storage local. Isso elimina
                        // dependencia de URLs externas frageis (20k+ pedidos afetados).
                        if ($legacy->url_img) {
                            $labelIsExternal = $order->label_url
                                && (str_contains($order->label_url, 'goolhub.io')
                                    || str_contains($order->label_url, 'sistemagrupoonline'));
                            $labelIsAbsent   = !($order->label_url);
                            // MUL-091: sem marketplace_account_id = pedido legado puro.
                            // FetchShippingLabelJob chamaria Shopee com order_sn invalido.
                            // Apenas despachar quando ha conta marketplace associada.
                            if (($labelIsAbsent || $labelIsExternal) && $order->marketplace_account_id) {
                                // INF-023: throttle 30min por pedido — evita loop de re-dispatch.
                                // ShouldBeUnique (uniqueFor=660s) no job e segunda camada de defesa.
                                $throttleKey = 'fetch-label-dispatch-' . $order->id;
                                if (! Cache::has($throttleKey)) {
                                    Cache::put($throttleKey, 1, now()->addMinutes(30));
                                    FetchShippingLabelJob::dispatch($order->id, 'legacysync')
                                        ->delay(now()->addSeconds(rand(1, 30)));
                                }
                            }
                        }

                        // Codigo de rastreio -- coluna tracking_number (nao tracking_code)
                        if ($legacy->rastreio && $order->tracking_number !== $legacy->rastreio) {
                            $updates['tracking_number'] = $legacy->rastreio;

                            // Gera tracking_url automaticamente ao receber codigo novo
                            $codigo  = $legacy->rastreio;
                            $carrier = $legacy->carrier_name ?? $order->carrier_name ?? '';

                            if (stripos($carrier, 'Correios') !== false) {
                                $updates['tracking_url'] = 'https://rastreamento.correios.com.br/app/index.php?objeto=' . $codigo;
                            } elseif (stripos($carrier, 'Shopee') !== false) {
                                $updates['tracking_url'] = 'https://spx.br/number/' . $codigo;
                            }
                        }

                        // === Fase 1: carrier + NF — atualiza quando o legado tem dado novo ===
                        if ($legacy->carrier_name && $order->carrier_name !== $legacy->carrier_name) {
                            $updates['carrier_name'] = $legacy->carrier_name;
                        }
                        if ($legacy->curso_pago && !$order->paid_at) {
                            $updates['paid_at'] = $legacy->curso_pago;
                        }
                        if ($legacy->id_nota_erp && $order->invoice_number !== $legacy->id_nota_erp) {
                            $updates['invoice_number']     = $legacy->id_nota_erp;
                            $updates['invoice_series']     = $legacy->serie_nota_erp ?: null;
                            $updates['invoice_access_key'] = $legacy->danfe_nota ?: null;
                            $updates['invoice_issued_at']  = $legacy->data_nfe_auto ?: null;
                            $updates['invoice_status']     = $legacy->desc_status_nota ?: null;
                            if ($legacy->xml_nota) {
                                $updates['invoice_xml'] = $legacy->xml_nota;
                            }
                        }

                        // INF-023: backfill de supplier_unit_cost MOVIDO para BackfillOrderCostsJob.
                        // Causa original do timeout (384 failed_jobs): este bloco fazia N+1 queries
                        // (~3 queries por pedido com custo ausente = >50k queries extras/execucao).
                        // BackfillOrderCostsJob roda a cada 10min na queue legacy-import com cursor
                        // persistente e chunkById(100) conservador, completando sem timeout.

                                                if (!empty($updates)) {
                            // updateQuietly para nao disparar Observer em cascata
                            $order->updateQuietly($updates);
                            $synced++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('SyncLegacyOrdersJob falhou para order ' . $order->id, [
                            'legacy_id' => $order->legacy_id,
                            'error'     => $e->getMessage(),
                        ]);
                        $errors++;
                    }

                    $totalChecked++;
                }
            });

        if ($totalChecked === 0) {
            Log::info('SyncLegacyOrdersJob: nenhum pedido com legacy_id.');
            return;
        }

        Log::info('SyncLegacyOrdersJob: ' . $synced . ' synced, ' . $errors . ' errors, ' . $totalChecked . ' checked, ' . $skipped . ' skipped (final+old)');

        // Registra historico pra /admin/sync (Fase 4 admin fix #3)
        try {
            \App\Models\LegacySyncRun::create([
                'job'          => 'sync-orders',
                'status'       => $errors > 0 ? 'partial' : 'success',
                'processed'    => $synced,
                'errors'       => $errors,
                'message'      => sprintf('%d synced / %d errors / %d checked / %d skipped', $synced, $errors, $totalChecked, $skipped),
                'started_at'   => $startedAt ?? now(),
                'finished_at'  => now(),
                'duration_ms'  => isset($startedAt) ? (int) ($startedAt->diffInMilliseconds(now())) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncLegacyOrdersJob: falha gravando LegacySyncRun: ' . $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Mapeia status_marketplace + campos do legado para o enum de status do novo sistema.
     *
     * Legado (status_marketplace Shopee) -> Novo:
     *   CANCELLED / CANCELED / REFUNDED / TO_RETURN  -> cancelled
     *   DELIVERED / COMPLETED                        -> delivered
     *   SHIPPED / TO_CONFIRM_RECEIVE                 -> shipped
     *   READY_TO_SHIP                                -> paid (pago, pronto p/ enviar)
     *   APPROVED / PAID + curso_pago preenchido      -> paid
     *   APPROVED sem curso_pago                      -> pending_payment
     *   (fallback) enviado_etiqueta preenchido       -> shipped
     *   demais                                       -> null (sem alteracao)
     */
    private function mapStatus(object $legacy): ?string
    {
        $sm = strtoupper((string) ($legacy->status_marketplace ?? ''));

        // Cancelamentos e devolucoes
        // (PEDIDO RETORNADO = string em portugues que algumas integracoes legadas
        // gravam — Shopee 'TO_RETURN' ja cobre o ingles, esta string trata o BR)
        if (in_array($sm, ['CANCELLED', 'CANCELED', 'REFUNDED', 'TO_RETURN', 'PEDIDO RETORNADO'])) {
            return 'cancelled';
        }

        // Entregue / concluido
        if (in_array($sm, ['DELIVERED', 'COMPLETED'])) {
            return 'delivered';
        }

        // Enviado / aguardando confirmacao de recebimento
        if (in_array($sm, ['SHIPPED', 'TO_CONFIRM_RECEIVE'])) {
            return 'shipped';
        }

        // READY_TO_SHIP = pago e pronto para enviar (Shopee)
        if ($sm === 'READY_TO_SHIP') {
            return 'paid';
        }

        // APPROVED/PAID precisam de confirmacao de pagamento
        if (in_array($sm, ['APPROVED', 'PAID']) && $legacy->curso_pago) {
            return 'paid';
        }

        // Aprovado mas sem pagamento confirmado
        if ($sm === 'APPROVED' && !$legacy->curso_pago) {
            return 'pending_payment';
        }

        // Fallback: etiqueta enviada mas status_marketplace desconhecido/vazio
        if ($legacy->enviado_etiqueta) {
            return 'shipped';
        }

        return null;
    }

    /**
     * Estado de processamento (etiqueta/envio) derivado do status.
     * Espelha ImportLegacyOrdersJob::mapProcessingStatus.
     */
    private function mapProcessingStatus(string $status): string
    {
        return match ($status) {
            'paid'      => 'awaiting_label',
            'shipped'   => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default     => 'pending',
        };
    }
}
