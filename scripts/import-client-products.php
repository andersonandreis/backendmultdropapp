<?php
/**
 * import-client-products.php
 *
 * Cria client_products para um cliente a partir dos sku_pai que ele ja vendeu
 * (registrados em order_items.legacy_sku_pai_id) e que ja existem em products.
 *
 * USO:
 *   php scripts/import-client-products.php <legacy_id_login> [--dry-run]
 *
 * REGRAS:
 *   - Banco legado : SOMENTE SELECT
 *   - Banco novo   : INSERT apenas (skip/update on duplicate)
 *   - Um client_product por (client_id, product_id, marketplace_account_id)
 *   - custom_sku = sku do pedido_produto no legado (fallback: product.sku)
 *   - external_listing_id = item_id do pedido_produto no legado (nullable)
 *   - sync_status = 'synced', listing_status = 'active'
 */

declare(strict_types=1);

$legacyIdLogin = $argv[1] ?? null;
$dryRun        = in_array('--dry-run', $argv ?? [], true);

if (!$legacyIdLogin || !is_numeric($legacyIdLogin)) {
    echo "Uso: php scripts/import-client-products.php <legacy_id_login> [--dry-run]\n";
    exit(1);
}
$legacyIdLogin = (int) $legacyIdLogin;

// ---------------------------------------------------------------------------
// Conexoes
// ---------------------------------------------------------------------------
try {
    $novo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=hubaiapp;charset=utf8mb4',
        'hubaiapp',
        'HubAI2026db',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "ERRO banco novo: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $legado = new PDO(
        'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
        'root',
        'ncpfxmbOTXqTflm0ieHI1174OJMZkl9A',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "ERRO banco legado: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Resolver client_id
// ---------------------------------------------------------------------------
$stmt = $novo->prepare("SELECT id FROM clients WHERE legacy_id_login = ?");
$stmt->execute([$legacyIdLogin]);
$clientId = $stmt->fetchColumn();

if (!$clientId) {
    echo "ERRO: Nenhum client com legacy_id_login={$legacyIdLogin} encontrado.\n";
    echo "Execute primeiro: php scripts/migrate-client.php {$legacyIdLogin}\n";
    exit(1);
}
$clientId = (int) $clientId;

echo "=== Import client_products — legacy_id_login={$legacyIdLogin} client_id={$clientId} ===\n";
if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita sera feita.\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 2. Carregar marketplace_accounts do cliente
//    Mapeia platform -> marketplace_account_id
// ---------------------------------------------------------------------------
$stmt = $novo->prepare(
    "SELECT id, platform FROM marketplace_accounts WHERE client_id = ? AND status != 'disabled'"
);
$stmt->execute([$clientId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// platform pode ter multiplas contas; pegar a primeira por plataforma
$accounts = []; // platform => id
foreach ($rows as $r) {
    if (!isset($accounts[$r['platform']])) {
        $accounts[$r['platform']] = (int) $r['id'];
    }
}

if (empty($accounts)) {
    echo "ERRO: Nenhuma marketplace_account encontrada para client_id={$clientId}.\n";
    exit(1);
}
echo "[marketplace_accounts] Encontradas: " . count($accounts) . "\n";
foreach ($accounts as $platform => $maId) {
    echo "  {$platform} => account_id={$maId}\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// 3. Buscar integracoes do cliente no legado
// ---------------------------------------------------------------------------
$stmt = $legado->prepare(
    "SELECT id, id_canal FROM integracao WHERE id_login = ? AND removida = 0"
);
$stmt->execute([$legacyIdLogin]);
$integRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$integIds = array_column($integRows, 'id');

if (empty($integIds)) {
    echo "AVISO: Nenhuma integracao ativa no legado para id_login={$legacyIdLogin}.\n";
    exit(0);
}
echo "[integracoes legado] Encontradas: " . count($integIds) . " (IDs: " . implode(', ', $integIds) . ")\n\n";

// ---------------------------------------------------------------------------
// 4. Mapa id_canal -> platform (mesmo do migrate-client.php)
// ---------------------------------------------------------------------------
$canalPlatformMap = [
    1  => 'magalu',
    3  => 'shopee',
    6  => 'mercadolivre',
    9  => 'shopify',
    13 => 'manual',
    20 => 'bling',
    21 => 'amazon',
    31 => 'tiktok',
];
for ($c = 14; $c <= 30; $c++) {
    if (!isset($canalPlatformMap[$c])) {
        $canalPlatformMap[$c] = 'bling';
    }
}

// ---------------------------------------------------------------------------
// 5. Buscar produtos distintos vendidos por este cliente via pedidos_produtos
//    Agrupa por (id_sku_pai, id_integracao) para mapear produto x canal
// ---------------------------------------------------------------------------
$placeholders = implode(',', array_fill(0, count($integIds), '?'));

$legadoStmt = $legado->prepare("
    SELECT
        pp.id_sku_pai,
        pp.sku                         AS legacy_sku,
        MAX(pp.item_id)                AS item_id,
        p.id_integracao,
        p.id_canal
    FROM pedidos_produtos pp
    JOIN pedidos p ON p.id = pp.id_pedido
    WHERE p.id_integracao IN ({$placeholders})
      AND pp.id_sku_pai IS NOT NULL
      AND pp.id_sku_pai > 0
      AND pp.sku IS NOT NULL
      AND pp.sku != ''
    GROUP BY pp.id_sku_pai, pp.sku, p.id_integracao, p.id_canal
    ORDER BY pp.id_sku_pai, p.id_integracao
");
$legadoStmt->execute($integIds);
$legadoRows = $legadoStmt->fetchAll(PDO::FETCH_ASSOC);

echo "[legado] Combinacoes sku_pai x integracao encontradas: " . count($legadoRows) . "\n\n";

if (empty($legadoRows)) {
    echo "Nenhum produto encontrado nos pedidos do cliente. Nada a importar.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 6. Inserir client_products
//    ON DUPLICATE KEY UPDATE: o unique constraint e (client_id, custom_sku)
//    Se custom_sku colidir com outro produto/canal, adiciona sufixo de plataforma
// ---------------------------------------------------------------------------
$insertSql = "
    INSERT INTO client_products
        (client_id, product_id, marketplace_account_id,
         custom_sku, custom_title, custom_images,
         external_listing_id,
         sync_status, listing_status, is_active,
         created_at, updated_at)
    VALUES
        (:client_id, :product_id, :marketplace_account_id,
         :custom_sku, :custom_title, :custom_images,
         :external_listing_id,
         'synced', 'active', 1,
         NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        product_id               = COALESCE(VALUES(product_id), product_id),
        marketplace_account_id   = COALESCE(VALUES(marketplace_account_id), marketplace_account_id),
        external_listing_id      = COALESCE(VALUES(external_listing_id), external_listing_id),
        sync_status              = 'synced',
        listing_status           = 'active',
        updated_at               = NOW()
";
$insertStmt = $novo->prepare($insertSql);

// Cache products para evitar queries repetidas
// legacy_sku_pai_id => ['id', 'sku', 'name', 'img']
$productCache = [];

$inserted  = 0;
$updated   = 0;
$skipped   = 0;
$noProduct = 0;
$noAccount = 0;

foreach ($legadoRows as $row) {
    $skuPaiId  = (int) $row['id_sku_pai'];
    $idCanal   = (int) $row['id_canal'];
    $legacySku = trim($row['legacy_sku'] ?? '');
    $itemId    = ($row['item_id'] !== null && $row['item_id'] !== '') ? trim((string)$row['item_id']) : null;
    $platform  = $canalPlatformMap[$idCanal] ?? null;

    // Resolver marketplace_account_id
    if (!$platform || !isset($accounts[$platform])) {
        $noAccount++;
        echo "  SKIP sku_pai={$skuPaiId}: sem account para canal={$idCanal}" . ($platform ? " (platform={$platform})" : '') . "\n";
        continue;
    }
    $maId = $accounts[$platform];

    // Resolver product via cache
    if (!array_key_exists($skuPaiId, $productCache)) {
        $pStmt = $novo->prepare(
            "SELECT p.id, p.sku, p.name,
                    (SELECT pm.url FROM product_media pm
                     WHERE pm.product_id = p.id AND pm.is_cover = 1
                     LIMIT 1) AS img
             FROM products p
             WHERE p.legacy_sku_pai_id = ?
             LIMIT 1"
        );
        $pStmt->execute([$skuPaiId]);
        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
        $productCache[$skuPaiId] = $prod ?: null;
    }

    $product = $productCache[$skuPaiId];
    if (!$product) {
        $noProduct++;
        echo "  SKIP sku_pai={$skuPaiId}: nao encontrado em products\n";
        continue;
    }

    $productId = (int) $product['id'];
    $customSku = $legacySku ?: $product['sku'];
    $customImg = ($product['img'] ?? null) ? json_encode([$product['img']]) : null;

    if ($dryRun) {
        printf(
            "  [DRY] client_id=%d product_id=%d ma_id=%d platform=%-15s sku=%-40s external=%s\n",
            $clientId, $productId, $maId, $platform,
            substr($customSku, 0, 40),
            $itemId ?? 'NULL'
        );
        $inserted++;
        continue;
    }

    // Tentativa 1: custom_sku original
    $trySkus = [$customSku, $customSku . '-' . $platform, $customSku . '-' . $skuPaiId];
    $done    = false;

    foreach ($trySkus as $trySku) {
        try {
            $insertStmt->execute([
                ':client_id'              => $clientId,
                ':product_id'             => $productId,
                ':marketplace_account_id' => $maId,
                ':custom_sku'             => substr($trySku, 0, 255),
                ':custom_title'           => substr($product['name'], 0, 255),
                ':custom_images'          => $customImg,
                ':external_listing_id'    => $itemId ? substr($itemId, 0, 255) : null,
            ]);

            $affected = (int) $insertStmt->rowCount();
            if ($affected === 1) {
                $inserted++;
            } elseif ($affected === 2) {
                $updated++;
            } else {
                $skipped++;
            }
            $done = true;
            break;
        } catch (PDOException $e) {
            // 23000 = unique constraint; tentar proximo sufixo
            if ($e->getCode() !== '23000') {
                echo "  ERRO sku_pai={$skuPaiId} sku={$trySku}: " . $e->getMessage() . "\n";
                $skipped++;
                $done = true;
                break;
            }
            // continua pro proximo trySku
        }
    }

    if (!$done) {
        echo "  SKIP sku_pai={$skuPaiId}: nao foi possivel inserir (todos os skus colidiam)\n";
        $skipped++;
    }
}

echo "\n=== Resultado ===\n";
echo "Inseridos  : {$inserted}\n";
echo "Atualizados: {$updated}\n";
echo "Ja existiam: {$skipped}\n";
echo "Sem product: {$noProduct}\n";
echo "Sem account: {$noAccount}\n";
if ($dryRun) {
    echo "(DRY-RUN — nenhuma escrita realizada)\n";
}
