<?php
/**
 * MES-007: import-client-products-from-legacy.php
 *
 * Importa client_products a partir do CATALOGO PERSONALIZADO do legado
 * (tabela `produtos` filtrada por empresa=20, ativo, nao removido).
 *
 * Difere de import-client-products.php (que usa pedidos_produtos / vendas).
 * Aqui o catalogo vem direto da tabela `produtos` que e a vitrine do cliente
 * por integracao (mkt account).
 *
 * USO:
 *   php scripts/import-client-products-from-legacy.php [--client=<legacy_id_login>] [--dry-run] [--chunk=200] [--limit=N]
 *
 * Exemplos:
 *   php scripts/import-client-products-from-legacy.php --client=262267 --dry-run
 *   php scripts/import-client-products-from-legacy.php --dry-run --limit=10
 *   php scripts/import-client-products-from-legacy.php
 *
 * REGRAS:
 *   - Banco legado : SOMENTE SELECT
 *   - Banco novo   : INSERT com ON DUPLICATE KEY UPDATE
 *   - Idempotencia via coluna legacy_id (= produtos.id do legado)
 *   - Unique constraint adicional: (client_id, custom_sku) — usa sufixo se colidir
 *   - product_id pode ser NULL quando id_sku_pai nao existir em products
 *   - Empresa filtrada: 20 (MEStoreDrop)
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Parametros CLI
// ---------------------------------------------------------------------------
$opts = getopt('', ['client::', 'dry-run', 'chunk::', 'limit::']);

$onlyClient = isset($opts['client']) ? (int) $opts['client'] : null;
$dryRun     = array_key_exists('dry-run', $opts);
$chunkSize  = isset($opts['chunk']) ? max(50, (int) $opts['chunk']) : 200;
$limitProd  = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;

// ---------------------------------------------------------------------------
// Conexoes (le do .env)
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/../.env';
if (!is_readable($envFile)) {
    fwrite(STDERR, "ERRO: .env nao encontrado em {$envFile}\n");
    exit(1);
}
// Parser .env manual (parse_ini_file quebra com APP_KEY base64)
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (!preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) continue;
    $val = $m[2];
    // remover aspas envoltorias (se houver)
    if (strlen($val) >= 2) {
        $first = $val[0];
        $last  = $val[strlen($val) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
        }
    }
    $env[$m[1]] = $val;
}
foreach (['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD',
          'LEGACY_DB_HOST','LEGACY_DB_PORT','LEGACY_DB_DATABASE','LEGACY_DB_USERNAME','LEGACY_DB_PASSWORD'] as $k) {
    if (!array_key_exists($k, $env)) {
        fwrite(STDERR, "ERRO: variavel {$k} ausente no .env\n");
        exit(1);
    }
}

try {
    $novo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'], $env['DB_PORT'] ?? '3306', $env['DB_DATABASE']),
        $env['DB_USERNAME'],
        $env['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_TIMEOUT => 30,
         PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ERRO banco novo: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $legado = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['LEGACY_DB_HOST'], $env['LEGACY_DB_PORT'], $env['LEGACY_DB_DATABASE']),
        $env['LEGACY_DB_USERNAME'],
        $env['LEGACY_DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_TIMEOUT => 30,
         PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ERRO banco legado: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Mapa id_canal -> platform (alinhado com migrate-client.php)
// ---------------------------------------------------------------------------
$canalPlatformMap = [
    1  => 'magalu',
    3  => 'shopee',
    5  => 'shopee',        // Shopee Modo Ferias
    6  => 'mercadolivre',
    8  => 'magalu',        // Magalu Bloqueado
    9  => 'shopify',
    11 => 'shopify',
    12 => 'mercadolivre',  // Meli Dessincronizado
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
for ($c = 1001; $c <= 1100; $c++) {
    $canalPlatformMap[$c] = 'bling';
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$logLine = function (string $s) {
    echo '[' . date('H:i:s') . '] ' . $s . "\n";
    @flush();
};

$logLine("=== MES-007: import client_products from legacy ===");
$logLine(sprintf("dry-run=%s, chunk=%d, only_client=%s, limit=%s",
    $dryRun ? 'YES' : 'NO',
    $chunkSize,
    $onlyClient ?: 'ALL',
    $limitProd ?: 'ALL'));

// ---------------------------------------------------------------------------
// 1. Lista de clientes do MEStoreDrop a processar
// ---------------------------------------------------------------------------
if ($onlyClient) {
    $stmt = $novo->prepare("SELECT id, legacy_id_login FROM clients WHERE legacy_id_login = ?");
    $stmt->execute([$onlyClient]);
} else {
    $stmt = $novo->prepare("SELECT id, legacy_id_login FROM clients WHERE legacy_id_login IS NOT NULL ORDER BY id");
    $stmt->execute();
}
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$logLine("[clientes] Total a processar: " . count($clientes));

if (empty($clientes)) {
    $logLine("Nada a fazer.");
    exit(0);
}

// ---------------------------------------------------------------------------
// 2. Pre-carregar mapas globais (caches)
// ---------------------------------------------------------------------------
$logLine("[cache] Carregando marketplace_accounts (legacy_id -> id)...");
$maMap = []; // legacy_id => ['id'=>..., 'platform'=>..., 'client_id'=>...]
$stmt  = $novo->query("SELECT id, legacy_id, client_id, platform FROM marketplace_accounts WHERE legacy_id IS NOT NULL");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $maMap[(int) $r['legacy_id']] = [
        'id'        => (int) $r['id'],
        'client_id' => (int) $r['client_id'],
        'platform'  => $r['platform'],
    ];
}
$stmt->closeCursor();
$logLine("  -> " . count($maMap) . " marketplace_accounts mapeadas");

$logLine("[cache] Carregando products (legacy_sku_pai_id -> id)...");
$productMap = []; // legacy_sku_pai_id => id
$stmt = $novo->query("SELECT id, legacy_sku_pai_id FROM products WHERE legacy_sku_pai_id IS NOT NULL");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $productMap[(int) $r['legacy_sku_pai_id']] = (int) $r['id'];
}
$stmt->closeCursor();
$logLine("  -> " . count($productMap) . " products mapeados");

// ---------------------------------------------------------------------------
// 3. Statements pre-preparados
// ---------------------------------------------------------------------------
$insertSql = "
    INSERT INTO client_products
        (legacy_id, client_id, product_id, marketplace_account_id,
         custom_sku, custom_title, custom_price,
         external_listing_id, ml_external_item_id, shopee_external_item_id,
         sync_status, listing_status, is_active,
         created_at, updated_at)
    VALUES
        (:legacy_id, :client_id, :product_id, :marketplace_account_id,
         :custom_sku, :custom_title, :custom_price,
         :external_listing_id, :ml_external_item_id, :shopee_external_item_id,
         'synced', 'active', :is_active,
         NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        product_id               = COALESCE(VALUES(product_id), product_id),
        marketplace_account_id   = COALESCE(VALUES(marketplace_account_id), marketplace_account_id),
        custom_title             = COALESCE(VALUES(custom_title), custom_title),
        custom_price             = COALESCE(VALUES(custom_price), custom_price),
        external_listing_id      = COALESCE(VALUES(external_listing_id), external_listing_id),
        ml_external_item_id      = COALESCE(VALUES(ml_external_item_id), ml_external_item_id),
        shopee_external_item_id  = COALESCE(VALUES(shopee_external_item_id), shopee_external_item_id),
        is_active                = VALUES(is_active),
        sync_status              = 'synced',
        listing_status           = 'active',
        updated_at               = NOW()
";
$insertStmt = $novo->prepare($insertSql);

// Statement para buscar produtos do legado por intervalo de IDs de integracao
$selectProdutos = $legado->prepare("
    SELECT
        p.id            AS legacy_id,
        p.id_integracao,
        p.id_sku_pai,
        p.sku           AS legacy_sku,
        p.produto       AS titulo,
        p.preco,
        p.estoque,
        p.mlbid,
        p.item_id,
        p.situacao,
        i.id_canal
    FROM produtos p
    JOIN integracao i ON i.id = p.id_integracao
    WHERE p.id_integracao = ?
      AND COALESCE(p.excluido, 0) = 0
    ORDER BY p.id
");

// ---------------------------------------------------------------------------
// 4. Loop por cliente
// ---------------------------------------------------------------------------
$totalInserted  = 0;
$totalUpdated   = 0;
$totalSkipped   = 0;
$totalNoAccount = 0;
$totalNoMatch   = 0;
$totalErrors    = 0;
$totalClientes  = 0;
$totalProdScan  = 0;

$clientsWithoutLegacyAccount = []; // clients sem nenhuma marketplace_account legacy
$totalReachedLimit = false;

foreach ($clientes as $cli) {
    if ($totalReachedLimit) break;
    $clientId      = (int) $cli['id'];
    $legacyIdLogin = (int) $cli['legacy_id_login'];
    $totalClientes++;

    // Buscar integracoes deste cliente no banco novo (via marketplace_accounts.legacy_id)
    $stmt = $novo->prepare("SELECT legacy_id FROM marketplace_accounts WHERE client_id = ? AND legacy_id IS NOT NULL");
    $stmt->execute([$clientId]);
    $integracaoIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt->closeCursor();

    if (empty($integracaoIds)) {
        $clientsWithoutLegacyAccount[] = $clientId;
        if ($onlyClient) {
            $logLine("  AVISO: cliente {$clientId} (legacy_id_login={$legacyIdLogin}) sem marketplace_account com legacy_id");
        }
        continue;
    }

    $cliInserted = 0;
    $cliUpdated  = 0;
    $cliSkipped  = 0;
    $cliNoMatch  = 0;
    $cliErrors   = 0;

    foreach ($integracaoIds as $integId) {
        if (!isset($maMap[$integId])) {
            // Defensive: nao deveria, ja filtramos
            continue;
        }
        $mkt = $maMap[$integId];
        if ($mkt['client_id'] !== $clientId) {
            // outra integracao com mesmo legacy_id em outro cliente — raro mas possivel
            continue;
        }
        $maId     = $mkt['id'];
        $platform = $mkt['platform'];

        // Buscar produtos do legado para esta integracao
        $selectProdutos->execute([$integId]);

        $batchSeen = 0;
        while ($prod = $selectProdutos->fetch(PDO::FETCH_ASSOC)) {
            $totalProdScan++;
            $batchSeen++;
            if ($limitProd !== null && $totalProdScan > $limitProd) {
                $totalReachedLimit = true;
                break;
            }

            $legacyProdId = (int) $prod['legacy_id'];
            $skuPai       = $prod['id_sku_pai'] !== null ? (int) $prod['id_sku_pai'] : 0;
            $productId    = ($skuPai > 0 && isset($productMap[$skuPai])) ? $productMap[$skuPai] : null;

            $legacySku = trim((string) ($prod['legacy_sku'] ?? ''));
            if ($legacySku === '') {
                // gerar SKU placeholder pra atender unique (client_id, custom_sku)
                $legacySku = 'LEG-' . $legacyProdId;
            }
            $titulo  = trim((string) ($prod['titulo'] ?? ''));
            $preco   = ($prod['preco'] !== null && (float) $prod['preco'] > 0) ? (float) $prod['preco'] : null;
            $estoque = (int) ($prod['estoque'] ?? 0);
            $mlbid   = trim((string) ($prod['mlbid'] ?? ''));
            $itemId  = ($prod['item_id'] !== null && (string) $prod['item_id'] !== '')
                       ? (string) $prod['item_id'] : null;

            // External listing por plataforma
            $extListing = $mlbid !== '' ? $mlbid : $itemId;
            $mlExt      = null;
            $shopeeExt  = null;
            if ($platform === 'mercadolivre') {
                $mlExt = $mlbid !== '' ? $mlbid : $itemId;
            } elseif ($platform === 'shopee') {
                $shopeeExt = $itemId;
            }

            // is_active: ativo se estoque > 0 OU preco > 0 (lateral) — produtos com tudo 0 viram inativos
            $isActive = ($estoque > 0 || $preco !== null) ? 1 : 0;

            if ($dryRun) {
                if ($totalProdScan <= 20 || $totalProdScan % 1000 === 0) {
                    printf("  [DRY] legacy_id=%d cli=%d ma=%d plat=%-13s sku=%-30s mlbid=%s product_id=%s\n",
                        $legacyProdId, $clientId, $maId, $platform,
                        substr($legacySku, 0, 30),
                        $mlbid ?: '-',
                        $productId ?? 'NULL');
                }
                $cliInserted++;
                if ($productId === null) {
                    $cliNoMatch++;
                }
                continue;
            }

            // Tentativas com sufixo em caso de colisao no unique (client_id, custom_sku)
            $trySkus = [
                $legacySku,
                $legacySku . '-' . $platform,
                $legacySku . '-' . $legacyProdId,
            ];

            $done = false;
            foreach ($trySkus as $trySku) {
                try {
                    $insertStmt->execute([
                        ':legacy_id'              => $legacyProdId,
                        ':client_id'              => $clientId,
                        ':product_id'             => $productId,
                        ':marketplace_account_id' => $maId,
                        ':custom_sku'             => substr($trySku, 0, 255),
                        ':custom_title'           => $titulo !== '' ? substr($titulo, 0, 255) : null,
                        ':custom_price'           => $preco,
                        ':external_listing_id'    => $extListing ? substr((string) $extListing, 0, 255) : null,
                        ':ml_external_item_id'    => $mlExt ? substr((string) $mlExt, 0, 50) : null,
                        ':shopee_external_item_id' => $shopeeExt ? substr((string) $shopeeExt, 0, 50) : null,
                        ':is_active'              => $isActive,
                    ]);
                    $aff = (int) $insertStmt->rowCount();
                    if ($aff === 1) {
                        $cliInserted++;
                    } elseif ($aff === 2) {
                        $cliUpdated++;
                    } else {
                        $cliSkipped++;
                    }
                    if ($productId === null) {
                        $cliNoMatch++;
                    }
                    $done = true;
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        // unique colidiu — tentar proximo sufixo
                        continue;
                    }
                    fwrite(STDERR, "  ERRO legacy_id={$legacyProdId} cli={$clientId}: " . $e->getMessage() . "\n");
                    $cliErrors++;
                    $done = true;
                    break;
                }
            }
            if (!$done) {
                $cliSkipped++;
            }
        }
        $selectProdutos->closeCursor();
        if ($totalReachedLimit) break;
    }

    $totalInserted += $cliInserted;
    $totalUpdated  += $cliUpdated;
    $totalSkipped  += $cliSkipped;
    $totalNoMatch  += $cliNoMatch;
    $totalErrors   += $cliErrors;

    // Log progresso a cada 25 clientes (ou sempre se onlyClient)
    if ($onlyClient || $totalClientes % 25 === 0) {
        $logLine(sprintf(
            "[progress] cli=%d/%d (id=%d legacy=%d) ins=+%d upd=+%d skip=+%d nomatch=+%d err=+%d | TOTAIS ins=%d upd=%d skip=%d nomatch=%d err=%d scanned=%d",
            $totalClientes, count($clientes), $clientId, $legacyIdLogin,
            $cliInserted, $cliUpdated, $cliSkipped, $cliNoMatch, $cliErrors,
            $totalInserted, $totalUpdated, $totalSkipped, $totalNoMatch, $totalErrors, $totalProdScan
        ));
    }
}

$logLine("");
$logLine("=== RESULTADO FINAL ===");
$logLine(sprintf("Clientes processados : %d", $totalClientes));
$logLine(sprintf("Sem mkt_account legacy: %d", count($clientsWithoutLegacyAccount)));
$logLine(sprintf("Produtos escaneados   : %d", $totalProdScan));
$logLine(sprintf("Inseridos            : %d", $totalInserted));
$logLine(sprintf("Atualizados (re-run) : %d", $totalUpdated));
$logLine(sprintf("Skipped / ja existia : %d", $totalSkipped));
$logLine(sprintf("Sem match em products: %d (importados com product_id=NULL)", $totalNoMatch));
$logLine(sprintf("Erros                : %d", $totalErrors));
if ($dryRun) {
    $logLine("(DRY-RUN — nenhuma escrita realizada)");
}
