<?php

/**
 * import-finance-mestoredrop.php
 *
 * MES-010 - Agente 4 - Importa transacoes financeiras (conta_corrente) do legado
 * para client_supplier_transactions.
 *
 * Fonte: conta_corrente WHERE id_empresa = 20 AND status = 1 (lancamentos confirmados)
 *
 * Mapping:
 *   id_login (legado)         -> client.legacy_id_login -> client.id
 *   tipo C/D                  -> type credit/debit
 *   valor                     -> amount
 *   data                      -> created_at  (UTC -> America/Sao_Paulo)
 *   id (legado)               -> legacy_cc_id (de-dup)
 *   descricao                 -> description (sanitizada)
 *
 * NUNCA escreve em client_supplier_balances/supplier_balances - apenas historico.
 *
 * Idempotente: usa legacy_cc_id pra ignorar lancamentos ja importados.
 *
 * Uso: php8.3 scripts/import-finance-mestoredrop.php [--dry-run] [--limit=N]
 */

declare(strict_types=1);

const SUPPLIER_ID       = 25;            // MEStoreDrop
const LEGACY_EMPRESA_ID = 20;
const BATCH_SIZE        = 500;

$dryRun = in_array('--dry-run', $argv, true);
$limit  = null;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

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
// Mapas em memoria
// -----------------------------------------------------------------------
log_msg('Carregando mapa legacy_id_login -> client_id...');
$clientMap = [];
$stmt = $novo->query('SELECT id, legacy_id_login FROM clients WHERE legacy_id_login IS NOT NULL');
foreach ($stmt as $row) {
    $clientMap[(int) $row['legacy_id_login']] = (int) $row['id'];
}
log_msg('  ' . count($clientMap) . ' clients com legacy_id_login.');

log_msg('Carregando legacy_cc_ids ja importados...');
$alreadyImported = [];
$stmt = $novo->query(
    'SELECT legacy_cc_id FROM client_supplier_transactions
     WHERE supplier_id = ' . SUPPLIER_ID . ' AND legacy_cc_id IS NOT NULL'
);
foreach ($stmt as $row) {
    $alreadyImported[(int) $row['legacy_cc_id']] = true;
}
log_msg('  ' . count($alreadyImported) . ' lancamentos ja importados.');

// -----------------------------------------------------------------------
// Le tudo do legado de uma vez (8.5k linhas - manejavel)
// -----------------------------------------------------------------------
log_msg('Lendo lancamentos do legado (empresa=' . LEGACY_EMPRESA_ID . ', status=1)...');
$stmt = $legado->prepare(
    'SELECT id, id_login, tipo, valor, data, descricao
     FROM conta_corrente
     WHERE id_empresa = :emp AND status = 1
     ORDER BY data ASC, id ASC'
);
$stmt->execute([':emp' => LEGACY_EMPRESA_ID]);
$legacyRows = $stmt->fetchAll(PDO::FETCH_OBJ);
log_msg('  ' . count($legacyRows) . ' lancamentos no legado.');

// -----------------------------------------------------------------------
// Insert
// -----------------------------------------------------------------------
$prep = $novo->prepare("
    INSERT INTO client_supplier_transactions
        (client_id, supplier_id, type, amount, description, reference,
         transaction_type, legacy_cc_id, created_at, updated_at)
    VALUES
        (:client_id, :supplier_id, :type, :amount, :description, 'legacy_cc',
         'legacy_sync', :legacy_cc_id, :created_at, :updated_at)
");

$totalInserted = 0;
$skippedNoClient = 0;
$skippedAlready  = 0;
$errors          = 0;
$now = date('Y-m-d H:i:s');

$batch = [];
$novo->beginTransaction();

foreach ($legacyRows as $r) {
    if ($limit && $totalInserted >= $limit) {
        break;
    }
    $ccId = (int) $r->id;
    if (isset($alreadyImported[$ccId])) {
        $skippedAlready++;
        continue;
    }
    $clientId = $clientMap[(int) $r->id_login] ?? null;
    if (!$clientId) {
        $skippedNoClient++;
        continue;
    }
    $desc = trim(strip_tags((string) ($r->descricao ?? '')));
    if ($desc === '') $desc = 'Lancamento legado';

    $tipo = strtoupper((string) $r->tipo);
    if (!in_array($tipo, ['C', 'D'], true)) {
        continue;
    }

    // Timestamp UTC -> Sao Paulo
    $rawDate = (string) $r->data;
    try {
        $dt = new DateTime($rawDate, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $createdAt = $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $createdAt = $now;
    }

    if ($dryRun) {
        $totalInserted++;
        continue;
    }

    try {
        $prep->execute([
            ':client_id'    => $clientId,
            ':supplier_id'  => SUPPLIER_ID,
            ':type'         => $tipo === 'C' ? 'credit' : 'debit',
            ':amount'       => (float) $r->valor,
            ':description'  => mb_substr($desc, 0, 255),
            ':legacy_cc_id' => $ccId,
            ':created_at'   => $createdAt,
            ':updated_at'   => $now,
        ]);
        $totalInserted++;
        $alreadyImported[$ccId] = true;

        if ($totalInserted % 500 === 0) {
            $novo->commit();
            $novo->beginTransaction();
            log_msg('  inseridos=' . $totalInserted);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate entry') || str_contains($msg, '1062')) {
            $alreadyImported[$ccId] = true;
            $skippedAlready++;
            continue;
        }
        $errors++;
        if ($errors <= 5) {
            log_msg('  [ERR] cc_id=' . $ccId . ': ' . $msg);
        }
    }
}

if (!$dryRun) {
    $novo->commit();
}

log_msg('=== Resumo ===');
log_msg('Lancamentos inseridos    : ' . $totalInserted);
log_msg('Ja importados (skip)     : ' . $skippedAlready);
log_msg('Sem client mapeado (skip): ' . $skippedNoClient);
log_msg('Erros                    : ' . $errors);
if ($dryRun) {
    log_msg('DRY RUN - nada gravado.');
}

// -----------------------------------------------------------------------
// Verificacao final
// -----------------------------------------------------------------------
$row = $novo->query(
    'SELECT COUNT(*) total,
            SUM(CASE WHEN type = "credit" THEN amount ELSE 0 END) cred,
            SUM(CASE WHEN type = "debit"  THEN amount ELSE 0 END) deb
     FROM client_supplier_transactions
     WHERE supplier_id = ' . SUPPLIER_ID
)->fetch(PDO::FETCH_ASSOC);
log_msg('client_supplier_transactions supplier=' . SUPPLIER_ID . ':');
log_msg('  total      = ' . $row['total']);
log_msg('  creditos   = R$ ' . number_format((float) $row['cred'], 2, ',', '.'));
log_msg('  debitos    = R$ ' . number_format((float) $row['deb'], 2, ',', '.'));
log_msg('  saldo info = R$ ' . number_format((float) $row['cred'] - (float) $row['deb'], 2, ',', '.'));
