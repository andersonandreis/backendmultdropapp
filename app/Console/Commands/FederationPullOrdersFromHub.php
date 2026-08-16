<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FederationPullOrdersFromHub extends Command
{
    protected $signature = 'federation:pull-orders-from-hub {--since=} {--dry : Só lista o que faria, sem pedir redispatch}';

    protected $description = 'Puxa do hub os pedidos do supplier local ausentes/divergentes e pede redispatch — rede de segurança do webhook em tempo real (INF-039)';

    private const CURSOR_CACHE_KEY = 'federation:pull_orders_since';

    public function handle(): int
    {
        $hubUrl = rtrim((string) config('federation.hub_url'), '/');
        $hubToken = (string) config('federation.hub_token');

        if ($hubUrl === '' || $hubToken === '') {
            // Backend sem federação configurada (ex.: o próprio hub) — no-op.
            return self::SUCCESS;
        }

        $since = $this->option('since')
            ?: Cache::get(self::CURSOR_CACHE_KEY)
            ?: now()->subDay()->toIso8601String();

        $this->info("Consultando delta-supplier desde {$since}...");

        $page = 1;
        $lastPage = 1;
        $maxUpdatedAt = null;
        $toRedispatch = [];
        $checked = 0;
        $faltando = 0;
        $divergente = 0;

        do {
            $response = Http::withToken($hubToken)
                ->timeout(30)
                ->get($hubUrl.'/api/federation/orders/delta-supplier', [
                    'since' => $since,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                $this->error("Hub retornou HTTP {$response->status()} na página {$page}.");
                Log::warning('[FederationPullOrdersFromHub] falha ao consultar hub', [
                    'status' => $response->status(),
                    'page' => $page,
                ]);

                return self::FAILURE;
            }

            $body = $response->json();
            $rows = $body['data'] ?? [];
            $lastPage = (int) ($body['last_page'] ?? 1);

            if ($rows === []) {
                break;
            }

            $hubIds = array_column($rows, 'hub_order_id');

            // MUL-339: carrega o pedido inteiro, nao so o status.
            $local = Order::withoutGlobalScopes()
                ->whereIn('hubai_order_id', $hubIds)
                ->get(['id', 'hubai_order_id', 'status', 'canonical_status', 'total', 'supplier_total'])
                ->keyBy('hubai_order_id');

            // impressao digital dos itens locais, uma consulta para a pagina inteira
            $itensLocais = \Illuminate\Support\Facades\DB::table('order_items')
                ->whereIn('order_id', $local->pluck('id'))
                ->orderBy('order_id')->orderBy('sku')
                ->get(['order_id', 'sku', 'quantity', 'unit_price', 'supplier_unit_cost'])
                ->groupBy('order_id');

            foreach ($rows as $row) {
                $checked++;
                $hubId = $row['hub_order_id'];
                $pedido = $local[$hubId] ?? null;

                if ($pedido === null) {
                    $toRedispatch[] = $hubId;   // nem existe aqui
                    $faltando++;
                } elseif ($this->divergiu($pedido, $row, $itensLocais->get($pedido->id, collect()))) {
                    $toRedispatch[] = $hubId;
                    $divergente++;
                }

                if (! empty($row['updated_at']) && ($maxUpdatedAt === null || $row['updated_at'] > $maxUpdatedAt)) {
                    $maxUpdatedAt = $row['updated_at'];
                }
            }

            $page++;
        } while ($page <= $lastPage && $page <= 50);

        $toRedispatch = array_values(array_unique($toRedispatch));
        $this->info('Verificados '.$checked.' pedidos do hub; ausentes: '.$faltando.', divergentes: '.$divergente.'.');

        if ($this->option('dry')) {
            $this->line('Dry-run — ids: '.implode(',', array_slice($toRedispatch, 0, 50)).(count($toRedispatch) > 50 ? '...' : ''));

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach (array_chunk($toRedispatch, 200) as $chunk) {
            $response = Http::withToken($hubToken)
                ->timeout(30)
                ->post($hubUrl.'/api/federation/orders/redispatch', ['ids' => $chunk]);

            if ($response->successful()) {
                $dispatched += (int) $response->json('dispatched', 0);
            } else {
                Log::warning('[FederationPullOrdersFromHub] falha no redispatch', [
                    'status' => $response->status(),
                    'chunk_size' => count($chunk),
                ]);
            }
        }

        if ($maxUpdatedAt !== null) {
            Cache::forever(self::CURSOR_CACHE_KEY, $maxUpdatedAt);
        }

        Log::info('[FederationPullOrdersFromHub] ciclo concluído', [
            'since' => $since,
            'checked' => $checked,
            'redispatch_pedidos' => count($toRedispatch),
            'dispatched' => $dispatched,
        ]);
        $this->info("Redispatch solicitado pro hub: {$dispatched} pedidos.");

        return self::SUCCESS;
    }
    /**
     * MUL-339: o pedido local bate com o do hub?
     *
     * Compara canonical_status e nao status. O status e' o valor CRU do marketplace
     * (to_confirm_receive, to_return) e a WL guarda o traduzido (shipped, processed) — comparar
     * os dois faz 53 de 87 pedidos acusarem divergencia para sempre, sem nunca convergir.
     *
     * Campo que o hub nao mandou (chave ausente) e' ignorado: mantem compatibilidade enquanto o
     * hub e as WLs nao estiverem todos atualizados.
     */
    private function divergiu(object $local, array $hub, $itensLocais): bool
    {
        // status canonico
        if (array_key_exists('canonical_status', $hub)
            && (string) $local->canonical_status !== (string) $hub['canonical_status']) {
            return true;
        }

        // valores do cabecalho, com tolerancia de centavo
        foreach (['total', 'supplier_total'] as $campo) {
            if (! array_key_exists($campo, $hub) || $hub[$campo] === null) {
                continue;
            }
            if (abs((float) $local->$campo - (float) $hub[$campo]) >= 0.005) {
                return true;
            }
        }

        // conteudo dos itens
        if (array_key_exists('items_count', $hub)
            && (int) $itensLocais->count() !== (int) $hub['items_count']) {
            return true;
        }
        if (array_key_exists('items_hash', $hub) && $hub['items_hash'] !== null
            && $this->hashDosItens($itensLocais) !== $hub['items_hash']) {
            return true;
        }

        return false;
    }

    /**
     * MUL-339: mesma conta do FederationOrderController::hashDosItens no hub.
     * Mudar aqui sem mudar la faz TODO pedido acusar divergencia.
     */
    private function hashDosItens($itens): string
    {
        $linhas = collect($itens)
            ->map(fn ($i) => implode('|', [
                (string) ($i->sku ?? ''),
                (int) ($i->quantity ?? 0),
                number_format((float) ($i->unit_price ?? 0), 2, '.', ''),
                number_format((float) ($i->supplier_unit_cost ?? 0), 2, '.', ''),
            ]))
            ->sort()
            ->values()
            ->implode(';');

        return substr(sha1($linhas), 0, 16);
    }
}
