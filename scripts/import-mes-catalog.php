<?php
/**
 * import-mes-catalog.php
 *
 * Importa o catalogo MEStoreDrop (deposito 355 e 447) do legado para o
 * banco mestoredropapp_production.
 *
 * USO:
 *   php scripts/import-mes-catalog.php [--dry-run] [--chunk=500]
 *
 * Comportamento:
 *   - Cria/atualiza suppliers para legacy_id 355 (M&E Store drop) e 447 (M&E store atacado e drop)
 *     Mapeia ambos como variantes do supplier 25 logico do tenant 019ef78f-00b7-71bf-8246-6ac919988506
 *   - Importa TODOS os sku_pai dos depositos 355 e 447 (ignora flag liberada)
 *   - Cria/atualiza products (idempotente por legacy_sku_pai_id)
 *   - Cria inventory rows (idempotente por product_id+warehouse_id)
 *   - Salva URL da imagem em product_media (NAO baixa — backfill separado faz isso)
 *   - Cria vinculo tenant_supplier para o tenant MEStoreDrop
 *
 * Regras:
 *   - Legado: SOMENTE SELECT
 *   - Falha em 1 produto NUNCA derruba o resto (try/catch por SKU)
 *   - Idempotente: rerodar nao duplica nada
 */

declare(strict_types=1);

$dryRun = in_array('--dry-run', $argv, true);
$chunkSize = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--chunk=(\d+)$/', $arg, $m)) {
        $chunkSize = (int) $m[1];
    }
}

$TENANT_ID = '019ef78f-00b7-71bf-8246-6ac919988506';
$LEGACY_DEPOSITOS = [355, 447];

// ---------------------------------------------------------------------------
// Conexoes
// ---------------------------------------------------------------------------
try {
    $novo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=mestoredropapp_production;charset=utf8mb4',
        'mestoredropapp',
        '2c0b0ae12fb223a14b55cfd99992410c2d1c218b7dbed851b5c338cd1006db27',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]
    );
} catch (PDOException $e) {
    echo "ERRO banco novo (mestoredropapp): " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $legado = new PDO(
        'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
        'tudoonline_production',
        '1D5IaXlXxmtlT0e4GTF4mzU3rsf38BvCCZHUaSFD',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]
    );
} catch (PDOException $e) {
    echo "ERRO banco legado: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Import MEStoreDrop Catalog ===\n";
echo "Depositos: " . implode(', ', $LEGACY_DEPOSITOS) . "\n";
echo "Tenant   : {$TENANT_ID}\n";
echo "Chunk    : {$chunkSize}\n";
if ($dryRun) {
    echo "[DRY-RUN] Nenhuma escrita sera feita.\n";
}
echo "\n";

// ---------------------------------------------------------------------------
// FASE 1: garantir suppliers para legacy_id 355 e 447
// ---------------------------------------------------------------------------
echo "--- FASE 1: Suppliers ---\n";

// Pega owner user_id (admin)
$ownerId = (int) $novo->query("SELECT id FROM users WHERE email = 'admin@mestoredrop.com.br' LIMIT 1")->fetchColumn();
if (!$ownerId) {
    $ownerId = (int) $novo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
}
if (!$ownerId) {
    echo "ERRO: nenhum user no banco novo para usar como owner.\n";
    exit(1);
}
echo "owner user_id = {$ownerId}\n";

$supplierIdsByLegacy = [];

foreach ($LEGACY_DEPOSITOS as $legacyId) {
    $dep = $legado->prepare("SELECT id, menu_titulo, cep FROM deposito WHERE id = ?");
    $dep->execute([$legacyId]);
    $depRow = $dep->fetch(PDO::FETCH_ASSOC);
    if (!$depRow) {
        echo "  AVISO: deposito {$legacyId} nao encontrado no legado.\n";
        continue;
    }

    $name = mb_convert_encoding($depRow['menu_titulo'] ?: "Deposito {$legacyId}", 'UTF-8', 'UTF-8');
    $zip  = (string) ($depRow['cep'] ?: '');

    // Existe?
    $find = $novo->prepare("SELECT id FROM suppliers WHERE legacy_id = ? LIMIT 1");
    $find->execute([$legacyId]);
    $supplierId = $find->fetchColumn();

    if ($supplierId) {
        if (!$dryRun) {
            $upd = $novo->prepare(
                "UPDATE suppliers SET company_name = ?, zipcode = ?, is_active = 1, updated_at = NOW() WHERE id = ?"
            );
            $upd->execute([$name, $zip, $supplierId]);
        }
        echo "  [UPD] supplier id={$supplierId} legacy={$legacyId} name={$name}\n";
    } else {
        if (!$dryRun) {
            $ins = $novo->prepare(
                "INSERT INTO suppliers (legacy_id, user_id, type, company_name, document, zipcode, is_active, allows_direct_deposit, created_at, updated_at)
                 VALUES (?, ?, 'warehouse', ?, '', ?, 1, 1, NOW(), NOW())"
            );
            $ins->execute([$legacyId, $ownerId, $name, $zip]);
            $supplierId = (int) $novo->lastInsertId();
        } else {
            $supplierId = -1;
        }
        echo "  [INS] supplier id={$supplierId} legacy={$legacyId} name={$name}\n";
    }

    // tenant_supplier link
    if (!$dryRun && $supplierId > 0) {
        $linkSql = "INSERT IGNORE INTO tenant_supplier (tenant_id, supplier_id, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())";
        $link = $novo->prepare($linkSql);
        $link->execute([$TENANT_ID, $supplierId]);
    }

    $supplierIdsByLegacy[$legacyId] = (int) $supplierId;
}

if (count($supplierIdsByLegacy) !== count($LEGACY_DEPOSITOS)) {
    echo "ERRO: nem todos os suppliers foram criados. Abortando.\n";
    exit(1);
}

echo "\n";

// ---------------------------------------------------------------------------
// FASE 2: Importar sku_pai
// ---------------------------------------------------------------------------
echo "--- FASE 2: Produtos + Estoque + URL imagens ---\n";

// Conta total
$totalStmt = $legado->prepare(
    "SELECT COUNT(*) FROM sku_pai WHERE id_deposito IN (" . implode(',', $LEGACY_DEPOSITOS) . ")"
);
$totalStmt->execute();
$total = (int) $totalStmt->fetchColumn();
echo "Total a processar: {$total}\n\n";

// SELECT antes pra decidir INSERT vs UPDATE (legacy_sku_pai_id nao eh unique)
$insertProductSql = "INSERT INTO products
    (legacy_sku_pai_id, supplier_id, sku, name, description, price, cost, ean, brand,
     weight_kg, height_cm, width_cm, length_cm, ncm, origem, video_url,
     ml_category_id, ml_attributes, shopee_category_id, shopee_attributes,
     attributes, `condition`, is_active, created_at, updated_at)
    VALUES
    (:legacy_sku_pai_id, :supplier_id, :sku, :name, :description, :price, :cost, :ean, :brand,
     :weight_kg, :height_cm, :width_cm, :length_cm, :ncm, :origem, :video_url,
     :ml_category_id, :ml_attributes, :shopee_category_id, :shopee_attributes,
     :attributes, 'new', 1, NOW(), NOW())";
$insertProductStmt = $novo->prepare($insertProductSql);

$updateProductSql = "UPDATE products SET
    supplier_id        = :supplier_id,
    sku                = :sku,
    name               = :name,
    description        = :description,
    price              = :price,
    cost               = :cost,
    ean                = COALESCE(:ean, ean),
    brand              = COALESCE(:brand, brand),
    weight_kg          = :weight_kg,
    height_cm          = :height_cm,
    width_cm           = :width_cm,
    length_cm          = :length_cm,
    ncm                = COALESCE(:ncm, ncm),
    origem             = COALESCE(:origem, origem),
    video_url          = COALESCE(:video_url, video_url),
    ml_category_id     = COALESCE(:ml_category_id, ml_category_id),
    ml_attributes      = COALESCE(:ml_attributes, ml_attributes),
    shopee_category_id = COALESCE(:shopee_category_id, shopee_category_id),
    shopee_attributes  = COALESCE(:shopee_attributes, shopee_attributes),
    attributes         = COALESCE(:attributes, attributes),
    is_active          = 1,
    updated_at         = NOW()
    WHERE id = :id";
$updateProductStmt = $novo->prepare($updateProductSql);

$insertInventorySql = "INSERT INTO inventory
    (warehouse_id, product_id, producer_id, quantity, reserved, created_at, updated_at)
    VALUES
    (:warehouse_id, :product_id, :producer_id, :quantity, 0, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        quantity   = VALUES(quantity),
        updated_at = NOW()";
// IMPORTANTE: inventory NAO tem unique key composta nativa, mas vamos verificar antes
// pra evitar dup; checa-se via SELECT primeiro
$findInvSql = "SELECT id FROM inventory WHERE product_id = ? AND warehouse_id = ? LIMIT 1";
$findInvStmt = $novo->prepare($findInvSql);

$insertMediaSql = "INSERT INTO product_media
    (product_id, type, url, original_url, position, is_cover, created_at, updated_at)
    VALUES
    (:product_id, 'image', :url, :original_url, :position, :is_cover, NOW(), NOW())";
$insertMediaStmt = $novo->prepare($insertMediaSql);

$findMediaSql = "SELECT id FROM product_media WHERE product_id = ? AND url = ? LIMIT 1";
$findMediaStmt = $novo->prepare($findMediaSql);

$findProductBySkuPaiSql = "SELECT id FROM products WHERE legacy_sku_pai_id = ? LIMIT 1";
$findProductBySkuPaiStmt = $novo->prepare($findProductBySkuPaiSql);

$findProductBySkuSql = "SELECT id, legacy_sku_pai_id FROM products WHERE sku = ? LIMIT 1";
$findProductBySkuStmt = $novo->prepare($findProductBySkuSql);

// Imagens extras
$findExtraImagesSql = "SELECT img FROM sku_pai_imagens WHERE id_sku_pai = ? AND img IS NOT NULL AND img != '' ORDER BY id LIMIT 10";
$findExtraImagesStmt = $legado->prepare($findExtraImagesSql);

// Iterar com chunk via OFFSET
$created = 0;
$updated = 0;
$skipped = 0;
$imagesIns = 0;
$errors = 0;

$offset = 0;
while (true) {
    $stmt = $legado->prepare(
        "SELECT id, sku, descricao, desc_produto, custo, custo_curso, estoque_real,
                peso, largura, altura, comprimento, marca, ean, ncm, origem, video_url, img,
                meli_categoria, meli_atributos, shopee_cat, shopee_atributos,
                id_deposito, cor, tamanho, garantia, data_add, data_update,
                reg_anatel, reg_anvisa, reg_inmetro
         FROM sku_pai
         WHERE id_deposito IN (" . implode(',', $LEGACY_DEPOSITOS) . ")
         ORDER BY id
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $chunkSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) break;

    foreach ($rows as $row) {
        $skuPaiId = (int) $row['id'];
        $idDeposito = (int) $row['id_deposito'];
        $supplierId = $supplierIdsByLegacy[$idDeposito] ?? null;

        if (!$supplierId) {
            $skipped++;
            continue;
        }

        try {
            // Build attributes JSON
            $attributes = [];
            $meliAttr = null;
            if (!empty($row['meli_atributos'])) {
                $tmp = json_decode($row['meli_atributos'], true);
                if (is_array($tmp)) {
                    $attributes['mercadolivre'] = $tmp;
                    $meliAttr = json_encode($tmp, JSON_UNESCAPED_UNICODE);
                }
            }
            $shopeeAttr = null;
            if (!empty($row['shopee_atributos'])) {
                $tmp = json_decode($row['shopee_atributos'], true);
                if (is_array($tmp)) {
                    $attributes['shopee'] = $tmp;
                    $shopeeAttr = json_encode($tmp, JSON_UNESCAPED_UNICODE);
                }
            }
            if (!empty($row['cor'])) $attributes['cor'] = $row['cor'];
            if (!empty($row['tamanho'])) $attributes['tamanho'] = $row['tamanho'];
            if (!empty($row['reg_anatel'])) $attributes['reg_anatel'] = $row['reg_anatel'];
            if (!empty($row['reg_anvisa'])) $attributes['reg_anvisa'] = $row['reg_anvisa'];
            if (!empty($row['reg_inmetro'])) $attributes['reg_inmetro'] = $row['reg_inmetro'];

            $rawSku = $row['sku'] ?: "SKU-{$skuPaiId}";
            // Nao duplicar prefixo D{deposito}- se ja vier no SKU original
            if (preg_match('/^D\d+-/', $rawSku)) {
                $sku = $rawSku;
            } else {
                $sku = "D{$idDeposito}-{$rawSku}";
            }
            $name = mb_convert_encoding($row['descricao'] ?: 'Sem nome', 'UTF-8', 'UTF-8');
            $description = mb_convert_encoding((string)($row['desc_produto'] ?? ''), 'UTF-8', 'UTF-8');

            $shopeeCategoryId = null;
            if (!empty($row['shopee_cat']) && ctype_digit((string)$row['shopee_cat'])) {
                $shopeeCategoryId = (int) $row['shopee_cat'];
            }

            $params = [
                ':legacy_sku_pai_id'   => $skuPaiId,
                ':supplier_id'         => $supplierId,
                ':sku'                 => substr($sku, 0, 255),
                ':name'                => substr($name, 0, 255),
                ':description'         => $description,
                ':price'               => (float) ($row['custo_curso'] ?: $row['custo'] ?: 0),
                ':cost'                => (float) ($row['custo'] ?: 0),
                ':ean'                 => $row['ean'] ? substr((string)$row['ean'], 0, 20) : null,
                ':brand'               => $row['marca'] ? mb_convert_encoding($row['marca'], 'UTF-8', 'UTF-8') : null,
                ':weight_kg'           => (float) ($row['peso'] ?: 0),
                ':height_cm'           => (float) ($row['altura'] ?: 0),
                ':width_cm'            => (float) ($row['largura'] ?: 0),
                ':length_cm'           => (float) ($row['comprimento'] ?: 0),
                ':ncm'                 => $row['ncm'] ? substr((string)$row['ncm'], 0, 20) : null,
                ':origem'              => $row['origem'] ? substr((string)$row['origem'], 0, 10) : null,
                ':video_url'           => $row['video_url'] ? substr((string)$row['video_url'], 0, 500) : null,
                ':ml_category_id'      => $row['meli_categoria'] ? substr((string)$row['meli_categoria'], 0, 50) : null,
                ':ml_attributes'       => $meliAttr,
                ':shopee_category_id'  => $shopeeCategoryId,
                ':shopee_attributes'   => $shopeeAttr,
                ':attributes'          => !empty($attributes) ? json_encode($attributes, JSON_UNESCAPED_UNICODE) : null,
            ];

            // Decide se eh insert ou update consultando primeiro
            $findProductBySkuPaiStmt->execute([$skuPaiId]);
            $existsId = $findProductBySkuPaiStmt->fetchColumn();
            $productId = null;

            if ($dryRun) {
                if ($existsId) $updated++; else $created++;
                continue;
            }

            if ($existsId) {
                // UPDATE explicito
                $updateParams = $params;
                unset($updateParams[':legacy_sku_pai_id']);
                $updateParams[':id'] = (int) $existsId;
                try {
                    $updateProductStmt->execute($updateParams);
                    $updated++;
                    $productId = (int) $existsId;
                } catch (PDOException $e) {
                    // colisao SKU em update — tentar com sufixo skuPaiId
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'products_sku_unique')) {
                        $updateParams[':sku'] = substr($sku . '-' . $skuPaiId, 0, 255);
                        try {
                            $updateProductStmt->execute($updateParams);
                            $updated++;
                            $productId = (int) $existsId;
                        } catch (PDOException $e2) {
                            $errors++;
                            echo "  ERR UPD sku_pai={$skuPaiId}: " . $e2->getMessage() . "\n";
                            continue;
                        }
                    } else {
                        $errors++;
                        echo "  ERR UPD sku_pai={$skuPaiId}: " . $e->getMessage() . "\n";
                        continue;
                    }
                }
            } else {
                // INSERT
                try {
                    $insertProductStmt->execute($params);
                    $created++;
                    $productId = (int) $novo->lastInsertId();
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'products_sku_unique')) {
                        $params[':sku'] = substr($sku . '-' . $skuPaiId, 0, 255);
                        try {
                            $insertProductStmt->execute($params);
                            $created++;
                            $productId = (int) $novo->lastInsertId();
                        } catch (PDOException $e2) {
                            $errors++;
                            echo "  ERR INS sku_pai={$skuPaiId} sku={$params[':sku']}: " . $e2->getMessage() . "\n";
                            continue;
                        }
                    } else {
                        $errors++;
                        echo "  ERR INS sku_pai={$skuPaiId}: " . $e->getMessage() . "\n";
                        continue;
                    }
                }
            }

            if (!$productId) continue;

            // Inventory
            $findInvStmt->execute([$productId, $supplierId]);
            $invId = $findInvStmt->fetchColumn();
            $qty = max(0, (int) ($row['estoque_real'] ?: 0));
            if ($invId) {
                $novo->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$qty, $invId]);
            } else {
                $novo->prepare(
                    "INSERT INTO inventory (warehouse_id, product_id, producer_id, quantity, reserved, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 0, NOW(), NOW())"
                )->execute([$supplierId, $productId, $supplierId, $qty]);
            }

            // Media principal (img field)
            if (!empty($row['img'])) {
                $imgUrl = trim((string) $row['img']);
                $imgUrl = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $imgUrl);
                $imgUrl = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $imgUrl);
                if (!preg_match('#^https?://#i', $imgUrl)) {
                    $imgUrl = 'https://goolhub.io/imagens/produtos/' . ltrim($imgUrl, '/');
                }

                $findMediaStmt->execute([$productId, $imgUrl]);
                if (!$findMediaStmt->fetchColumn()) {
                    $insertMediaStmt->execute([
                        ':product_id'   => $productId,
                        ':url'          => substr($imgUrl, 0, 255),
                        ':original_url' => substr($imgUrl, 0, 500),
                        ':position'     => 0,
                        ':is_cover'     => 1,
                    ]);
                    $imagesIns++;
                }
            }

            // Imagens extras (sku_pai_imagens)
            $findExtraImagesStmt->execute([$skuPaiId]);
            $extras = $findExtraImagesStmt->fetchAll(PDO::FETCH_COLUMN);
            $pos = 1;
            foreach ($extras as $extraImg) {
                $extraImg = trim((string)$extraImg);
                if (!$extraImg) continue;
                $extraImg = str_replace('www.sistemagrupoonline.com.br', 'goolhub.io', $extraImg);
                $extraImg = str_replace('://sistemagrupoonline.com.br', '://goolhub.io', $extraImg);
                if (!preg_match('#^https?://#i', $extraImg)) {
                    $extraImg = 'https://goolhub.io/imagens/produtos/' . ltrim($extraImg, '/');
                }

                $findMediaStmt->execute([$productId, $extraImg]);
                if (!$findMediaStmt->fetchColumn()) {
                    $insertMediaStmt->execute([
                        ':product_id'   => $productId,
                        ':url'          => substr($extraImg, 0, 255),
                        ':original_url' => substr($extraImg, 0, 500),
                        ':position'     => $pos,
                        ':is_cover'     => 0,
                    ]);
                    $imagesIns++;
                    $pos++;
                }
            }

        } catch (Throwable $e) {
            $errors++;
            echo "  ERR sku_pai={$skuPaiId}: " . $e->getMessage() . "\n";
            continue;
        }
    }

    $offset += $chunkSize;
    echo "  progresso: {$offset}/{$total} (criados={$created} atualizados={$updated} pulados={$skipped} erros={$errors} imagens={$imagesIns})\n";
}

echo "\n=== Resultado ===\n";
echo "Criados      : {$created}\n";
echo "Atualizados  : {$updated}\n";
echo "Pulados      : {$skipped}\n";
echo "Erros        : {$errors}\n";
echo "Imagens add  : {$imagesIns}\n";

// Contagens finais
$totalProducts = (int) $novo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$sup355 = $supplierIdsByLegacy[355] ?? 0;
$sup447 = $supplierIdsByLegacy[447] ?? 0;
$prod355 = (int) $novo->query("SELECT COUNT(*) FROM products WHERE supplier_id = {$sup355}")->fetchColumn();
$prod447 = (int) $novo->query("SELECT COUNT(*) FROM products WHERE supplier_id = {$sup447}")->fetchColumn();
$totalMedia = (int) $novo->query("SELECT COUNT(*) FROM product_media")->fetchColumn();
$totalInv = (int) $novo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();

echo "\nTotais no banco destino:\n";
echo "  products total       : {$totalProducts}\n";
echo "  products supplier 355: {$prod355} (supplier id={$sup355})\n";
echo "  products supplier 447: {$prod447} (supplier id={$sup447})\n";
echo "  product_media        : {$totalMedia}\n";
echo "  inventory            : {$totalInv}\n";

if ($dryRun) {
    echo "\n(DRY-RUN — nenhuma escrita realizada)\n";
}
