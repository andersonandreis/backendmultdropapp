<?php

/**
 * import-orders-mestoredrop.php
 *
 * MES-010 - Agente 4 - Importa pedidos das lojas 423/515 (MEStoreDrop) do legado.
 *
 * Estrategia:
 *  1. Filtra legado: pedidos.id_loja IN (423, 515) AND status_marketplace != cancelled
 *  2. Mapeia cada pedido para o client correto via integracao.id_login -> clients.legacy_id_login
 *  3. Insere em orders com legacy_id como chave de idempotencia (firstOrCreate semantico)
 *  4. Importa itens da pedidos_produtos -> order_items
 *  5. NAO mexe em saldo. Apenas historico.
 *
 * Idempotente: rodar quantas vezes precisar, so insere o que falta.
 *
 * Uso:
 *   php8.3 scripts/import-orders-mestoredrop.php [--dry-run] [--limit=N] [--loja=423|515]
 */

declare(strict_types=1);

// -----------------------------------------------------------------------
// Config
// -----------------------------------------------------------------------
const SUPPLIER_ID       = 25;            // MEStoreDrop
const LEGACY_EMPRESA_ID = 20;
const LEGACY_DEPOSITO   = 447;
const BATCH_SIZE        = 500;
const TENANT_SLUG       = 'mestoredrop';

const CANAL_SOURCE_MAP = [
    3  => 'shopee',
    4  => 'magalu',
    6  => 'mercadolivre',
    7  => 'bling',
    8  => 'tiktok',
    13 => 'tiktok',
    20 => 'bling',
];

// -----------------------------------------------------------------------
// Args
// -----------------------------------------------------------------------
$dryRun = in_array('--dry-run', $argv, true);
$limit  = null;
$lojaFilter = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
    if (preg_match('/^--loja=(\d+)$/', $arg, $m)) {
        $lojaFilter = (int) $m[1];
    }
}

$lojas = $lojaFilter ? [$lojaFilter] : [423, 515];

// -----------------------------------------------------------------------
// Conexoes
// -----------------------------------------------------------------------
$novo = new PDO(
    'mysql:host=127.0.0.1;dbname=mestoredropapp_production;charset=utf8mb4',
    'mestoredropapp',
    '2c0b0ae12fb223a14b55cfd99992410c2d1c218b7dbed851b5c338cd1006db27',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

$legado = new PDO(
    'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
    'tudoonline_production',
    '1D5IaXlXxmtlT0e4GTF4mzU3rsf38BvCCZHUaSFD',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// -----------------------------------------------------------------------
// Pre-carrega mapeamento legacy_id_login -> client_id
// -----------------------------------------------------------------------
log_msg('Carregando mapa legacy_id_login -> client_id...');
$clientMap = [];
$stmt = $novo->query('SELECT id, legacy_id_login FROM clients WHERE legacy_id_login IS NOT NULL');
foreach ($stmt as $row) {
    $clientMap[(int) $row['legacy_id_login']] = (int) $row['id'];
}
log_msg('  ' . count($clientMap) . ' clients com legacy_id_login.');

// -----------------------------------------------------------------------
// Pre-carrega legacy_ids ja importados
// -----------------------------------------------------------------------
log_msg('Carregando legacy_ids ja importados...');
$alreadyImported = [];
$stmt = $novo->query('SELECT legacy_id FROM orders WHERE supplier_id = ' . SUPPLIER_ID . ' AND legacy_id IS NOT NULL');
foreach ($stmt as $row) {
    $alreadyImported[(int) $row['legacy_id']] = true;
}
log_msg('  ' . count($alreadyImported) . ' pedidos ja importados.');

// -----------------------------------------------------------------------
// Pre-carrega catalogo (legacy_sku_pai_id -> product_id) e imagens
// -----------------------------------------------------------------------
log_msg('Carregando catalogo products + imagens...');
$catalogProducts = [];
$stmt = $novo->query('SELECT id, legacy_sku_pai_id FROM products WHERE legacy_sku_pai_id IS NOT NULL');
foreach ($stmt as $row) {
    $catalogProducts[(int) $row['legacy_sku_pai_id']] = (int) $row['id'];
}

$catalogImages = [];
$stmt = $novo->query("
    SELECT p.legacy_sku_pai_id, pm.url
    FROM product_media pm
    INNER JOIN products p ON p.id = pm.product_id
    WHERE pm.is_cover = 1 AND p.legacy_sku_pai_id IS NOT NULL
");
foreach ($stmt as $row) {
    $catalogImages[(int) $row['legacy_sku_pai_id']] = $row['url'];
}
log_msg('  catalogo: ' . count($catalogProducts) . ' produtos, ' . count($catalogImages) . ' capas');

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function mapStatus(object $lp): string
{
    $sm = strtoupper((string) ($lp->status_marketplace ?? ''));

    if (in_array($sm, ['CANCELLED', 'CANCELED', 'REFUNDED', 'TO_RETURN', 'PEDIDO RETORNADO'], true)) {
        return 'cancelled';
    }
    if (in_array($sm, ['DELIVERED', 'COMPLETED'], true)) {
        return 'delivered';
    }
    if (in_array($sm, ['SHIPPED', 'TO_CONFIRM_RECEIVE'], true)) {
        return 'shipped';
    }
    if ($sm === 'READY_TO_SHIP') {
        return 'paid';
    }
    if (in_array($sm, ['APPROVED', 'PAID'], true) && $lp->curso_pago) {
        return 'paid';
    }
    if ($sm === 'APPROVED' && !$lp->curso_pago) {
        return 'pending_payment';
    }
    if ($lp->enviado_etiqueta) {
        return 'shipped';
    }
    if ($lp->curso_pago) {
        return 'paid';
    }
    return 'pending_payment';
}

function mapProcessingStatus(string $status): string
{
    return match ($status) {
        'paid'      => 'awaiting_label',
        'shipped'   => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        default     => 'pending',
    };
}

function resolveItemCost(object $li): float
{
    $costDia     = (float) ($li->custo_dia ?? 0);
    $costPagoDia = (float) ($li->custo_pago_dia ?? 0);
    if ($costDia > 0) return $costDia;
    if ($costPagoDia > 0) return $costPagoDia;
    return 0.0;
}

// -----------------------------------------------------------------------
// Loop por loja
// -----------------------------------------------------------------------
$prepOrder = $novo->prepare("
    INSERT INTO orders
        (legacy_id, client_id, supplier_id, source, external_order_id,
         order_number, customer_name, customer_document_number, customer_phone,
         customer_address, status, canonical_status, order_processing_status,
         subtotal, shipping_cost, total, supplier_total, currency,
         tracking_number, label_url, delivery_type, channel_name,
         paid_at, shipped_at, delivered_at, cancelled_at,
         shipping_mode, carrier_name,
         invoice_number, invoice_series, invoice_access_key, invoice_issued_at,
         invoice_status, invoice_xml,
         tenant_slug, notes,
         created_at, updated_at)
    VALUES
        (:legacy_id, :client_id, :supplier_id, :source, :external_order_id,
         :order_number, :customer_name, :cpf, :customer_phone,
         :customer_address, :status, :canonical_status, :order_processing_status,
         :subtotal, :shipping_cost, :total, :supplier_total, 'BRL',
         :tracking_number, :label_url, :delivery_type, :channel_name,
         :paid_at, :shipped_at, :delivered_at, :cancelled_at,
         :shipping_mode, :carrier_name,
         :invoice_number, :invoice_series, :invoice_access_key, :invoice_issued_at,
         :invoice_status, :invoice_xml,
         :tenant_slug, :notes,
         :created_at, :updated_at)
");

$prepItem = $novo->prepare("
    INSERT INTO order_items
        (order_id, sku, name, quantity, unit_price, total,
         supplier_unit_cost, supplier_total_cost,
         product_id, product_image, legacy_sku_pai_id,
         created_at, updated_at)
    VALUES
        (:order_id, :sku, :name, :qty, :unit_price, :total,
         :supplier_unit_cost, :supplier_total_cost,
         :product_id, :product_image, :legacy_sku_pai_id,
         NOW(), NOW())
");

$totalInserted = 0;
$totalItems    = 0;
$skippedNoClient = 0;
$skippedAlready = 0;
$cancelled = 0;
$errors    = 0;

foreach ($lojas as $idLoja) {
    log_msg("=== Loja {$idLoja} ===");

    // Filtra pedidos da loja, ordenados por id ASC.
    // Inclui colunas usadas pelo ImportLegacyOrdersJob.
    $sql = "
        SELECT p.id, p.id_integracao, p.id_canal, p.nome_canal,
               p.status, p.status_marketplace, p.valor_total, p.frete_valor,
               p.cliente_nome, p.cliente_cpf, p.data_pedido_canal, p.data_add,
               p.curso_pago, p.rastreio, p.url_img, p.enviado_etiqueta,
               p.shipping_id, p.nr_canal,
               p.endereco_endereco, p.endereco_numero, p.endereco_complemento,
               p.endereco_cidade, p.endereco_estado, p.endereco_bairro, p.endereco_cep,
               p.tipo_entrega,
               p.carrier_name, p.id_nota_erp, p.serie_nota_erp,
               p.danfe_nota, p.xml_nota, p.data_nfe_auto, p.desc_status_nota,
               p.dados,
               i.id_login
        FROM pedidos p
        INNER JOIN integracao i ON i.id = p.id_integracao
        WHERE p.id_loja = :loja
        ORDER BY p.id ASC
    ";
    $stmt = $legado->prepare($sql);
    $stmt->execute([':loja' => $idLoja]);

    $processed = 0;
    while ($lp = $stmt->fetchObject()) {
        $processed++;

        if ($limit && $totalInserted >= $limit) {
            break 2;
        }

        // Skip ja importado
        if (isset($alreadyImported[(int) $lp->id])) {
            $skippedAlready++;
            continue;
        }

        // Skip cancelado
        $smRaw = strtoupper((string) ($lp->status_marketplace ?? ''));
        if (in_array($smRaw, ['CANCELLED', 'CANCELED', 'IN_CANCEL', 'REFUNDED', 'TO_RETURN', 'PEDIDO RETORNADO'], true)) {
            $cancelled++;
            continue;
        }

        // Mapeia id_login -> client_id
        $idLogin = (int) $lp->id_login;
        $clientId = $clientMap[$idLogin] ?? null;
        if (!$clientId) {
            $skippedNoClient++;
            continue;
        }

        $status = mapStatus($lp);
        $source = CANAL_SOURCE_MAP[(int) $lp->id_canal] ?? 'manual';
        $processingStatus = mapProcessingStatus($status);

        $paidAt = $lp->curso_pago ?: null;
        $shippedAt = (in_array($status, ['shipped', 'delivered'], true) && $lp->enviado_etiqueta) ? $lp->enviado_etiqueta : null;
        $deliveredAt = null;
        $cancelledAt = null;
        if ($status === 'delivered') {
            $shippedAt = $shippedAt ?: date('Y-m-d H:i:s');
            $deliveredAt = date('Y-m-d H:i:s');
        }
        if ($status === 'cancelled') {
            $cancelledAt = date('Y-m-d H:i:s');
        }

        $customerAddress = null;
        if (!empty($lp->endereco_endereco)) {
            $customerAddress = json_encode([
                'street'       => $lp->endereco_endereco,
                'number'       => $lp->endereco_numero ?: '',
                'complement'   => $lp->endereco_complemento ?: '',
                'neighborhood' => $lp->endereco_bairro ?: '',
                'city'         => $lp->endereco_cidade ?: '',
                'state'        => $lp->endereco_estado ?: '',
                'zip_code'     => $lp->endereco_cep ?: '',
                'country'      => 'BR',
            ], JSON_UNESCAPED_UNICODE);
        }

        $total    = (float) ($lp->valor_total ?? 0);
        $shipping = (float) ($lp->frete_valor ?? 0);
        $subtotal = max(0.0, $total - $shipping);

        // Itens
        $itemsStmt = $legado->prepare("
            SELECT id, id_sku_pai, sku, descricao, qtd, valor_unitario, foto,
                   custo_dia, custo_pago_dia
            FROM pedidos_produtos
            WHERE id_pedido = :pid
        ");
        $itemsStmt->execute([':pid' => $lp->id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_OBJ);

        $supplierTotal = 0.0;
        foreach ($items as $li) {
            $supplierTotal += resolveItemCost($li) * max(1, (int) ($li->qtd ?? 1));
        }

        // created_at convertendo UTC->America/Sao_Paulo
        $rawDate = $lp->data_add ?: ($lp->data_pedido_canal ?: null);
        $createdAt = date('Y-m-d H:i:s');
        if ($rawDate) {
            $dt = new DateTime((string) $rawDate, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
            $createdAt = $dt->format('Y-m-d H:i:s');
        }

        if ($dryRun) {
            $totalInserted++;
            $totalItems += count($items);
            if ($totalInserted % 500 === 0) {
                log_msg("  [dry] loja={$idLoja} processados={$processed} inseridos={$totalInserted} items={$totalItems}");
            }
            continue;
        }

        try {
            $novo->beginTransaction();

            $prepOrder->execute([
                ':legacy_id'              => (int) $lp->id,
                ':client_id'              => $clientId,
                ':supplier_id'            => SUPPLIER_ID,
                ':source'                 => $source,
                ':external_order_id'      => $lp->nr_canal ?: ('LEG-' . $lp->id),
                ':order_number'           => (string) $lp->id,
                ':customer_name'          => mb_substr((string) ($lp->cliente_nome ?: 'Desconhecido'), 0, 255),
                ':cpf'                    => $lp->cliente_cpf ?: null,
                ':customer_phone'         => null,
                ':customer_address'       => $customerAddress,
                ':status'                 => $status,
                ':canonical_status'       => 'created',
                ':order_processing_status'=> $processingStatus,
                ':subtotal'               => $subtotal,
                ':shipping_cost'          => $shipping,
                ':total'                  => $total,
                ':supplier_total'         => $supplierTotal > 0 ? round($supplierTotal, 2) : null,
                ':tracking_number'        => $lp->rastreio ?: null,
                ':label_url'              => $lp->url_img ?: null,
                ':delivery_type'          => $lp->tipo_entrega ?: null,
                ':channel_name'           => $lp->nome_canal ?: null,
                ':paid_at'                => $paidAt,
                ':shipped_at'             => $shippedAt,
                ':delivered_at'           => $deliveredAt,
                ':cancelled_at'           => $cancelledAt,
                ':shipping_mode'          => $lp->tipo_entrega ?: null,
                ':carrier_name'           => $lp->carrier_name ?: null,
                ':invoice_number'         => $lp->id_nota_erp ?: null,
                ':invoice_series'         => $lp->serie_nota_erp ?: null,
                ':invoice_access_key'     => $lp->danfe_nota ?: null,
                ':invoice_issued_at'      => $lp->data_nfe_auto ?: null,
                ':invoice_status'         => $lp->desc_status_nota ?: null,
                ':invoice_xml'            => $lp->xml_nota ?: null,
                ':tenant_slug'            => TENANT_SLUG,
                ':notes'                  => 'Importado MES-010 em ' . date('Y-m-d H:i:s') . ' (loja=' . $idLoja . ')',
                ':created_at'             => $createdAt,
                ':updated_at'             => $createdAt,
            ]);
            $orderId = (int) $novo->lastInsertId();

            foreach ($items as $li) {
                $qty       = max(1, (int) ($li->qtd ?? 1));
                $unitPrice = (float) ($li->valor_unitario ?? 0);
                $unitCost  = resolveItemCost($li);
                $spId      = $li->id_sku_pai ? (int) $li->id_sku_pai : null;
                $catalogProdId = $spId ? ($catalogProducts[$spId] ?? null) : null;
                $productImage  = $spId ? ($catalogImages[$spId] ?? ($li->foto ?: null)) : ($li->foto ?: null);

                $prepItem->execute([
                    ':order_id'           => $orderId,
                    ':sku'                => $li->sku ?: '',
                    ':name'               => mb_substr((string) ($li->descricao ?: ($li->sku ?: 'Produto')), 0, 255),
                    ':qty'                => $qty,
                    ':unit_price'         => $unitPrice,
                    ':total'              => round($unitPrice * $qty, 2),
                    ':supplier_unit_cost' => $unitCost > 0 ? $unitCost : null,
                    ':supplier_total_cost'=> $unitCost > 0 ? round($unitCost * $qty, 2) : null,
                    ':product_id'         => $catalogProdId,
                    ':product_image'      => $productImage,
                    ':legacy_sku_pai_id'  => $spId,
                ]);
                $totalItems++;
            }

            $novo->commit();
            $alreadyImported[(int) $lp->id] = true;
            $totalInserted++;

            if ($totalInserted % 500 === 0) {
                log_msg("  loja={$idLoja} processados={$processed} inseridos={$totalInserted} items={$totalItems}");
            }
        } catch (Throwable $e) {
            $novo->rollBack();
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate entry') || str_contains($msg, '1062')) {
                // outra execucao concorrente importou - tudo bem
                $alreadyImported[(int) $lp->id] = true;
                $skippedAlready++;
                continue;
            }
            $errors++;
            if ($errors <= 5) {
                log_msg("  [ERR] pedido legacy={$lp->id}: {$msg}");
            }
        }
    }

    log_msg("  loja={$idLoja} - processados={$processed}");
}

log_msg('=== Resumo ===');
log_msg('Pedidos inseridos       : ' . $totalInserted);
log_msg('Items inseridos         : ' . $totalItems);
log_msg('Ja importados (skip)    : ' . $skippedAlready);
log_msg('Sem client mapeado      : ' . $skippedNoClient);
log_msg('Cancelados (skip)       : ' . $cancelled);
log_msg('Erros                   : ' . $errors);
if ($dryRun) {
    log_msg('DRY RUN - nada gravado.');
}
