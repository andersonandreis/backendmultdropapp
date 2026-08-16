<?php

namespace App\Console\Commands;

use App\Models\ClientKit;
use App\Models\ClientKitItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MUL-147-A: Converte product_bundles (legacy_kit_id, supplier_id=1) em client_kits
 * por lojista (24 clientes), com componentes resolvidos via client_products DO PROPRIO lojista.
 *
 * Regras:
 *  - Kit pertence ao LOJISTA — cada bundle com legacy_kit_id pertence ao cliente identificado
 *    pelo prefixo do SKU (KIT{legacy_id_login}{seq})
 *  - Componentes sem client_product correspondente: registrar no relatorio (kit marcado inativo)
 *  - Cross-tenant seguro: componentes verificados APENAS no client_products do proprio cliente
 *  - Apos conversao bem-sucedida, remover do product_bundles
 *  - Idempotente: verifica legacy_kit_id em client_kits antes de criar
 */
class ConvertBundlesToClientKitsCommand extends Command
{
    protected $signature = 'kits:convert-bundles-to-client-kits
        {--supplier=1 : Supplier ID (padrao MultDrop = 1)}
        {--client=    : Converter apenas um client_id especifico}
        {--dry-run    : Simula sem persistir}';

    protected $description = 'MUL-147-A: Converte product_bundles de kits do legado em client_kits por lojista';

    private array $report         = [];
    private int $totalConverted   = 0;
    private int $totalPending     = 0;
    private int $totalPartial     = 0;
    private int $totalBundlesRemoved = 0;

    public function handle(): int
    {
        $supplierId = (int) $this->option('supplier');
        $dryRun     = (bool) $this->option('dry-run');
        $onlyClient = $this->option('client') ? (int) $this->option('client') : null;

        $this->info('=== MUL-147-A: CONVERSAO BUNDLES -> CLIENT_KITS ===');
        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteracao persistida.');
        }

        // Carregar todos os bundles com legacy_kit_id agrupados por kit
        $kitGroups = DB::table('product_bundles')
            ->whereNotNull('legacy_kit_id')
            ->where('supplier_id', $supplierId)
            ->select('legacy_kit_id', 'sku', 'name', 'price', 'description', 'cover_image_url', 'is_active')
            ->orderBy('legacy_kit_id')
            ->get()
            ->groupBy('legacy_kit_id');

        $this->info('Total de kits distintos em product_bundles: ' . $kitGroups->count());

        foreach ($kitGroups as $legacyKitId => $rows) {
            $firstRow = $rows->first();
            $kitSku   = $firstRow->sku ?? '';

            // Extrair legacy_id_login do SKU: KIT{legacy_id_login}{4 digitos de seq}
            $loginId = $this->extractLoginIdFromSku($kitSku);
            if (! $loginId) {
                $this->report[] = [
                    'legacy_kit_id' => $legacyKitId,
                    'sku'           => $kitSku,
                    'status'        => 'SKIP_SKU_PATTERN',
                    'detail'        => 'Nao foi possivel extrair legacy_id_login do SKU',
                ];
                continue;
            }

            $client = DB::table('clients')->where('legacy_id_login', $loginId)->first();
            if (! $client) {
                $this->report[] = [
                    'legacy_kit_id' => $legacyKitId,
                    'sku'           => $kitSku,
                    'status'        => 'NO_CLIENT',
                    'detail'        => 'legacy_id_login=' . $loginId . ' nao encontrado em clients',
                ];
                continue;
            }

            if ($onlyClient && $client->id !== $onlyClient) {
                continue;
            }

            $this->processKit((int) $legacyKitId, $rows, $client, $firstRow, $supplierId, $dryRun);
        }

        // Relatorio final
        $this->newLine();
        $this->info('=== RESULTADO ===');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Kits convertidos (100% componentes)',    $this->totalConverted],
                ['Kits parciais (alguns comp. faltando)',  $this->totalPartial],
                ['Kits pendentes (0 comp. encontrados)',   $this->totalPending],
                ['Bundles removidos do product_bundles',   $this->totalBundlesRemoved],
            ]
        );

        // Detalhamento por status
        $byStatus = collect($this->report)->groupBy('status');
        foreach ($byStatus as $status => $items) {
            $this->info('-- ' . $status . ': ' . $items->count());
            foreach ($items->take(5) as $r) {
                $this->line('   kit=' . $r['legacy_kit_id'] . ' sku=' . $r['sku'] . ' | ' . $r['detail']);
            }
            if ($items->count() > 5) {
                $this->line('   ... e mais ' . ($items->count() - 5) . ' kits');
            }
        }

        return Command::SUCCESS;
    }

    private function extractLoginIdFromSku(string $sku): ?string
    {
        // Formato: KIT{legacy_id_login}{4 digitos de seq}
        // Ex: KIT1737170002 -> login=173717, seq=0002
        if (! preg_match('/^KIT(\d+?)(\d{4})$/', $sku, $m)) {
            return null;
        }
        return $m[1];
    }

    private function processKit(
        int    $legacyKitId,
        object $rows,
        object $client,
        object $firstRow,
        int    $supplierId,
        bool   $dryRun
    ): void {
        // Idempotencia: kit ja convertido para este cliente?
        $existing = DB::table('client_kits')
            ->where('client_id', $client->id)
            ->where('legacy_kit_id', $legacyKitId)
            ->first();

        if ($existing) {
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $firstRow->sku,
                'status'        => 'ALREADY_EXISTS',
                'detail'        => 'client_kit_id=' . $existing->id . ' ja existe para client_id=' . $client->id,
            ];
            $this->totalConverted++;
            return;
        }

        // Buscar componentes dos bundles (com component_product_id resolvido)
        $bundleComponents = DB::table('product_bundles')
            ->where('legacy_kit_id', $legacyKitId)
            ->where('supplier_id', $supplierId)
            ->whereNotNull('component_product_id')
            ->select('component_product_id', 'qty')
            ->get();

        if ($bundleComponents->isEmpty()) {
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $firstRow->sku,
                'status'        => 'NO_COMPONENTS',
                'detail'        => 'Bundle sem componentes resolvidos (component_product_id NULL)',
            ];
            $this->totalPending++;
            return;
        }

        // Resolver client_product DO PROPRIO lojista para cada componente
        $resolvedItems  = [];
        $unresolvedSkus = [];

        foreach ($bundleComponents as $comp) {
            $cpId = DB::table('client_products')
                ->where('client_id', $client->id)
                ->where('product_id', $comp->component_product_id)
                ->value('id');

            if ($cpId) {
                $resolvedItems[] = [
                    'client_product_id' => $cpId,
                    'quantity'          => max(1, (int) $comp->qty),
                ];
            } else {
                $prodSku          = DB::table('products')->where('id', $comp->component_product_id)->value('sku')
                    ?? 'product_id=' . $comp->component_product_id;
                $unresolvedSkus[] = $prodSku;
            }
        }

        $totalComps      = count($bundleComponents);
        $resolvedCount   = count($resolvedItems);
        $kitName         = $firstRow->name ?? ('Kit ' . $firstRow->sku);
        $kitSku          = $firstRow->sku;
        $isFullyResolved = ($resolvedCount === $totalComps);
        $hasNoComp       = ($resolvedCount === 0);

        if ($hasNoComp) {
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $kitSku,
                'status'        => 'NO_MATCH',
                'detail'        => 'client_id=' . $client->id
                    . ' — 0/' . $totalComps
                    . ' comp. sem client_product. SKUs: '
                    . implode(', ', array_slice($unresolvedSkus, 0, 3)),
            ];
            $this->totalPending++;
            return;
        }

        if ($dryRun) {
            $status         = $isFullyResolved ? 'CONVERT_OK' : 'CONVERT_PARTIAL';
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $kitSku,
                'status'        => '[DRY]' . $status,
                'detail'        => 'client_id=' . $client->id . ' | resolved=' . $resolvedCount . '/' . $totalComps,
            ];
            if ($isFullyResolved) {
                $this->totalConverted++;
            } else {
                $this->totalPartial++;
            }
            return;
        }

        // Persistir: criar client_kit + client_kit_items e remover do product_bundles
        $removedCount = 0;
        DB::transaction(function () use (
            $client, $legacyKitId, $kitName, $kitSku, $firstRow,
            $resolvedItems, $isFullyResolved, $supplierId, &$removedCount
        ) {
            // Garantir unicidade de SKU para este cliente
            $finalSku      = $kitSku;
            $skuConflict   = DB::table('client_kits')
                ->where('client_id', $client->id)
                ->where('sku', $finalSku)
                ->whereNull('legacy_kit_id')
                ->exists();
            if ($skuConflict) {
                $finalSku = $kitSku . '-L' . $legacyKitId;
            }

            $kit = ClientKit::create([
                'client_id'     => $client->id,
                'name'          => mb_substr($kitName, 0, 200),
                'sku'           => $finalSku,
                'description'   => null,
                'price'         => $firstRow->price ?? null,
                'is_active'     => $isFullyResolved,
                'legacy_kit_id' => $legacyKitId,
            ]);

            foreach ($resolvedItems as $ri) {
                ClientKitItem::create([
                    'kit_id'            => $kit->id,
                    'client_product_id' => $ri['client_product_id'],
                    'quantity'          => $ri['quantity'],
                ]);
            }

            // Remover do product_bundles (kit agora e do lojista)
            $removedCount = DB::table('product_bundles')
                ->where('legacy_kit_id', $legacyKitId)
                ->where('supplier_id', $supplierId)
                ->delete();
        });

        $this->totalBundlesRemoved += $removedCount;

        if ($isFullyResolved) {
            $this->totalConverted++;
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $kitSku,
                'status'        => 'CONVERTED',
                'detail'        => 'client_id=' . $client->id . ' | ' . $resolvedCount . '/' . $totalComps . ' componentes',
            ];
        } else {
            $this->totalPartial++;
            $detail = 'client_id=' . $client->id . ' | ' . $resolvedCount . '/' . $totalComps
                . ' comp. | faltando: ' . implode(', ', array_slice($unresolvedSkus, 0, 3));
            if (count($unresolvedSkus) > 3) {
                $detail .= ' ...e mais ' . (count($unresolvedSkus) - 3);
            }
            $this->report[] = [
                'legacy_kit_id' => $legacyKitId,
                'sku'           => $kitSku,
                'status'        => 'PARTIAL_INACTIVE',
                'detail'        => $detail,
            ];
        }
    }
}
