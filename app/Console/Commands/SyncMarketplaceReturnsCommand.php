<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-340 — traz as devolucoes do marketplace.
 *
 * Ate 06/08/2026 o sistema nao acompanhava devolucao nenhuma: order_returns com 0 linhas,
 * orders.return_status sempre vazio, e a API da Shopee nunca consultada — 63 devolucoes numa
 * conta so, R$ 2.318,16 estornados, e o custo do fornecedor seguia lancado.
 *
 * A varredura e por CONTA, nao por pedido. Das 63 devolucoes medidas, so 14 tinham pedido
 * correspondente no hub; buscar a partir dos nossos pedidos perderia 78%.
 *
 * Detalhe da API que custou uma tentativa: page_no comeca em 0, e a chamada sem janela de tempo
 * funciona — passar create_time_from/to devolve erro.
 */
class SyncMarketplaceReturnsCommand extends Command
{
    protected $signature = 'returns:sync
                            {--account= : so uma conta}
                            {--paginas=10 : maximo de paginas por conta}
                            {--dry-run : so conta, nao grava}';

    protected $description = 'MUL-340: sincroniza devolucoes do marketplace';

    public function handle(ShopeeService $shopee): int
    {
        $dry = (bool) $this->option('dry-run');

        $contas = MarketplaceAccount::where('platform', 'shopee')
            ->where('status', 'active')
            ->whereNotNull('shop_id')
            ->whereNull('sync_blocked_at')
            ->when($this->option('account'), fn ($q) => $q->where('id', $this->option('account')))
            ->get();

        $this->info(sprintf('Contas Shopee ativas: %d%s', $contas->count(), $dry ? ' [dry-run]' : ''));

        $novas = 0; $atualizadas = 0; $erros = 0;

        foreach ($contas as $conta) {
            try {
                $devolucoes = $this->buscarDevolucoes($shopee, $conta);
            } catch (\Throwable $e) {
                $erros++;
                Log::warning('[MUL-340] falha ao listar devolucoes', [
                    'account_id' => $conta->id, 'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (! $devolucoes) {
                continue;
            }

            $this->line(sprintf('  conta %-6s %-24s %d devolucao(oes)',
                $conta->id, mb_substr((string) $conta->account_name, 0, 24), count($devolucoes)));

            if ($dry) {
                continue;
            }

            foreach ($devolucoes as $d) {
                $existia = DB::table('marketplace_returns')
                    ->where('marketplace_account_id', $conta->id)
                    ->where('return_sn', $d['return_sn'] ?? '')
                    ->exists();

                $this->gravar($conta, $d);
                $existia ? $atualizadas++ : $novas++;
            }
        }

        $this->info(sprintf('novas=%d atualizadas=%d erros=%d', $novas, $atualizadas, $erros));

        if ($novas || $atualizadas) {
            Log::warning('[MUL-340] returns:sync', [
                'novas' => $novas, 'atualizadas' => $atualizadas, 'erros' => $erros,
            ]);
        }

        return self::SUCCESS;
    }

    /** @return array<int,array<string,mixed>> */
    private function buscarDevolucoes(ShopeeService $shopee, MarketplaceAccount $conta): array
    {
        $token = $shopee->getValidAccessToken($conta);
        if (! $token) {
            return [];
        }

        $chamar = \Closure::bind(
            fn ($ep, $p, $m) => $this->callApi($ep, $p, $m),
            $shopee,
            ShopeeService::class
        );

        $todas = [];
        $maxPaginas = (int) $this->option('paginas');

        // page_no comeca em 0 — com 1 a Shopee devolve vazio
        for ($pagina = 0; $pagina < $maxPaginas; $pagina++) {
            $r = $chamar('/api/v2/returns/get_return_list', [
                'shop_id'      => (int) $conta->shop_id,
                'access_token' => $token,
                'page_no'      => $pagina,
                'page_size'    => 50,
            ], 'GET');

            if (! empty($r['error'])) {
                throw new \RuntimeException((string) ($r['error'] . ': ' . ($r['message'] ?? '')));
            }

            $lista = $r['response']['return'] ?? [];
            if (! $lista) {
                break;
            }

            $todas = array_merge($todas, $lista);

            if (count($lista) < 50) {
                break;
            }

            usleep(350000);   // rate limit
        }

        return $todas;
    }

    private function gravar(MarketplaceAccount $conta, array $d): void
    {
        $orderSn = $d['order_sn'] ?? null;

        // o pedido pode nao existir aqui — 49 das 63 medidas nao existiam. Fica nulo e o
        // order_sn preserva a ligacao para quando o pedido chegar.
        $pedido = $orderSn
            ? DB::table('orders')->where('external_order_id', $orderSn)
                ->orWhere('marketplace_order_id', $orderSn)
                ->first(['id', 'supplier_id'])
            : null;

        $data = fn ($ts) => ! empty($ts) ? date('Y-m-d H:i:s', (int) $ts) : null;

        DB::table('marketplace_returns')->updateOrInsert(
            ['marketplace_account_id' => $conta->id, 'return_sn' => (string) ($d['return_sn'] ?? '')],
            [
                'order_sn'                   => $orderSn,
                'order_id'                   => $pedido->id ?? null,
                'supplier_id'                => $pedido->supplier_id ?? $conta->supplier_id,
                'status'                     => $d['status'] ?? null,
                'reason'                     => $d['reason'] ?? null,
                'text_reason'                => $d['text_reason'] ?? null,
                'return_solution'            => isset($d['return_solution']) ? (string) $d['return_solution'] : null,
                'return_refund_type'         => $d['return_refund_type'] ?? null,
                'refund_amount'              => $d['refund_amount'] ?? null,
                'amount_before_discount'     => $d['amount_before_discount'] ?? null,
                'currency'                   => $d['currency'] ?? null,
                'follow_up_action_list'      => ! empty($d['follow_up_action_list'])
                                                    ? json_encode($d['follow_up_action_list']) : null,
                'seller_proof_status'        => $d['seller_proof_status'] ?? null,
                'seller_compensation_status' => $d['seller_compensation_status'] ?? null,
                'negotiation_status'         => $d['negotiation_status'] ?: null,
                'seller_evidence_deadline'   => $data($d['seller_evidence_deadline'] ?? null),
                'return_seller_due_date'     => $data($d['return_seller_due_date'] ?? null),
                'return_ship_due_date'       => $data($d['return_ship_due_date'] ?? null),
                'due_date'                   => $data($d['due_date'] ?? null),
                'needs_logistics'            => ! empty($d['needs_logistics']),
                'is_arrived_at_warehouse'    => ! empty($d['is_arrived_at_warehouse']),
                'tracking_number'            => $d['tracking_number'] ?: null,
                'raw_payload'                => json_encode($d),
                'marketplace_created_at'     => $data($d['create_time'] ?? null),
                'marketplace_updated_at'     => $data($d['update_time'] ?? null),
                'updated_at'                 => now(),
                'created_at'                 => now(),
            ]
        );

        // MUL-340: carimba o pedido com a devolucao MAIS RECENTE, nao a ultima gravada pelo laco.
        // Um pedido pode ter mais de uma — o 134373 teve uma CANCELLED as 18:17 e outra ACCEPTED
        // as 22:48, porque o comprador reabriu depois que a primeira caiu. Carimbar pela ordem de
        // gravacao daria um valor ou outro conforme a API devolvesse.
        if ($pedido) {
            $maisRecente = DB::table('marketplace_returns')
                ->where('order_id', $pedido->id)
                ->orderByDesc('marketplace_created_at')
                ->value('status');

            DB::table('orders')->where('id', $pedido->id)
                ->update(['return_status' => $maisRecente ?: ($d['status'] ?? null)]);
        }
    }
}