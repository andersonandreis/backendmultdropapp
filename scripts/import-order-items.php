<?php
/**
 * Import order items (pedidos_produtos) from legacy DB into order_items.
 *
 * Usage:
 *   php8.1 scripts/import-order-items.php [--dry-run]
 *
 * - Reads orders for client_id=10 that still have no items
 * - Fetches pedidos_produtos from legacy in chunks of 500 orders
 * - Skips orders that already have items
 * - client_product_id left NULL (catalog not yet populated for this client)
 */

declare(strict_types=1);

define('CHUNK_SIZE', 500);
define('CLIENT_ID',  10);

$dryRun = in_array('--dry-run', $argv ?? [], true);

// ── Connections ───────────────────────────────────────────────────────────────

$new = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=hubaiapp;charset=utf8mb4',
    'hubaiapp',
    'HubAI2026db',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$legacy = new PDO(
    'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
    'root',
    'ncpfxmbOTXqTflm0ieHI1174OJMZkl9A',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Load orders without items yet ─────────────────────────────────────────────

$allOrders = $new->query("
    SELECT o.id, o.legacy_id
    FROM orders o
    WHERE o.client_id = " . CLIENT_ID . "
      AND o.legacy_id IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
      )
    ORDER BY o.id
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($allOrders);
echo "[import-order-items] Orders to process: {$total}\n";

if ($total === 0) {
    echo "[import-order-items] Nothing to do.\n";
    exit(0);
}

// ── Prepare insert ────────────────────────────────────────────────────────────

$insertSql = "
    INSERT INTO order_items
        (order_id, sku, name, quantity, unit_price, total,
         product_image, legacy_sku_pai_id,
         created_at, updated_at)
    VALUES
        (:order_id, :sku, :name, :quantity, :unit_price, :total,
         :product_image, :legacy_sku_pai_id,
         NOW(), NOW())
";
$stmt = $new->prepare($insertSql);

// ── Process in chunks ─────────────────────────────────────────────────────────

$chunks          = array_chunk($allOrders, CHUNK_SIZE);
$totalInserted   = 0;
$ordersWithItems = 0;
$ordersNoItems   = 0;
$errors          = 0;

foreach ($chunks as $chunkIndex => $chunk) {
    $legacyIds  = array_column($chunk, 'legacy_id');
    // legacy_id => new order id
    $orderIdMap = array_column($chunk, 'id', 'legacy_id');

    $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));

    $legacyStmt = $legacy->prepare("
        SELECT id_pedido, sku, descricao, foto, qtd, valor_unitario, id_sku_pai
        FROM pedidos_produtos
        WHERE id_pedido IN ({$placeholders})
          AND sku IS NOT NULL
          AND sku != ''
    ");
    $legacyStmt->execute($legacyIds);
    $rows = $legacyStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $ordersNoItems += count($chunk);
        continue;
    }

    // Group rows by id_pedido
    $byPedido = [];
    foreach ($rows as $row) {
        $byPedido[(int)$row['id_pedido']][] = $row;
    }
    $ordersNoItems   += count($chunk) - count($byPedido);
    $ordersWithItems += count($byPedido);

    if (!$dryRun) {
        $new->beginTransaction();
    }

    try {
        foreach ($byPedido as $legacyOrderId => $items) {
            $newOrderId = $orderIdMap[$legacyOrderId] ?? null;
            if (!$newOrderId) {
                continue;
            }

            foreach ($items as $item) {
                $skuPaiId  = isset($item['id_sku_pai']) && $item['id_sku_pai'] !== ''
                    ? (int)$item['id_sku_pai']
                    : null;
                $qty       = max(1, (int)($item['qtd'] ?? 1));
                $unitPrice = (float)($item['valor_unitario'] ?? 0);
                $itemTotal = round($unitPrice * $qty, 2);
                $name      = trim($item['descricao'] ?? '') ?: $item['sku'];
                $foto      = trim($item['foto'] ?? '') ?: null;

                if (!$dryRun) {
                    $stmt->execute([
                        ':order_id'          => $newOrderId,
                        ':sku'               => $item['sku'],
                        ':name'              => substr($name, 0, 255),
                        ':quantity'          => $qty,
                        ':unit_price'        => $unitPrice,
                        ':total'             => $itemTotal,
                        ':product_image'     => $foto,
                        ':legacy_sku_pai_id' => $skuPaiId,
                    ]);
                }
                $totalInserted++;
            }
        }

        if (!$dryRun) {
            $new->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun) {
            $new->rollBack();
        }
        echo "[ERROR] Chunk " . ($chunkIndex + 1) . ": " . $e->getMessage() . "\n";
        $errors++;
    }

    $done = min(($chunkIndex + 1) * CHUNK_SIZE, $total);
    echo "[chunk " . ($chunkIndex + 1) . "/" . count($chunks) . "] "
        . "processed {$done}/{$total} orders — items so far: {$totalInserted}\n";
}

echo "\n[import-order-items] Done.\n";
echo "  Items inserted        : {$totalInserted}\n";
echo "  Orders with items     : {$ordersWithItems}\n";
echo "  Orders with no items  : {$ordersNoItems}\n";
echo "  Chunk errors          : {$errors}\n";
if ($dryRun) {
    echo "  DRY RUN — nothing written to DB.\n";
}
