<?php

/**
 * Script: populate-financial.php
 * Popula saldo e transacoes financeiras dos clientes migrados do legado.
 *
 * Logica:
 *  1. Insere credito inicial consolidado = saldo legado informado
 *  2. Insere debitos por cada pedido paid/shipped (ordem cronologica ASC)
 *  3. Calcula running_balance progressivo
 *  4. Upsert em client_supplier_balances com saldo final
 *
 * Uso: php scripts/populate-financial.php [--dry-run]
 *
 * ATENCAO: banco legado somente leitura.
 */

define('DRY_RUN', in_array('--dry-run', $argv ?? []));

// -----------------------------------------------------------------------
// Conexao com banco novo (localhost)
// -----------------------------------------------------------------------
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=hubaiapp;charset=utf8mb4',
    'hubaiapp',
    'HubAI2026db',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// -----------------------------------------------------------------------
// Clientes migrados
// -----------------------------------------------------------------------
$clients = [
    10 => ['legacy_id' => 160088, 'legacy_balance' => 13161.42, 'name' => 'Victoria'],
    11 => ['legacy_id' => 173717, 'legacy_balance' => 124.00,   'name' => 'Vaneide'],
    12 => ['legacy_id' => 180942, 'legacy_balance' => 549.00,   'name' => 'Maria'],
];

// Supplier Multdrop — todos os pedidos migrados pertencem a ele
$SUPPLIER_ID = 30;

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function abort_script(string $msg): void
{
    echo '[ERRO] ' . $msg . PHP_EOL;
    exit(1);
}

// -----------------------------------------------------------------------
// Processar cada cliente
// -----------------------------------------------------------------------
foreach ($clients as $clientId => $info) {
    $name          = $info['name'];
    $legacyBalance = (float) $info['legacy_balance'];

    log_msg("=== Processando {$name} (client_id={$clientId}) ===");

    // Verificar se ja tem transacoes para nao duplicar
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM client_supplier_transactions WHERE client_id = ? AND supplier_id = ?'
    );
    $stmt->execute([$clientId, $SUPPLIER_ID]);
    $existingCount = (int) $stmt->fetchColumn();

    if ($existingCount > 0) {
        log_msg("  AVISO: ja possui {$existingCount} transacoes. Pulando.");
        continue;
    }

    // Buscar pedidos paid/shipped ordenados cronologicamente
    $stmt = $pdo->prepare(
        'SELECT id, order_number, total, status, created_at
         FROM orders
         WHERE client_id = ?
           AND supplier_id = ?
           AND status IN ("paid","shipped")
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$clientId, $SUPPLIER_ID]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDebits = (float) array_sum(array_column($orders, 'total'));
    $finalBalance = $legacyBalance - $totalDebits;

    log_msg("  Saldo legado:           R$ " . number_format($legacyBalance, 2, ',', '.'));
    log_msg("  Pedidos a importar:     " . count($orders));
    log_msg("  Total debitos:          R$ " . number_format($totalDebits, 2, ',', '.'));
    log_msg("  Saldo final esperado:   R$ " . number_format($finalBalance, 2, ',', '.'));

    if (DRY_RUN) {
        log_msg("  [DRY-RUN] Nenhuma escrita realizada.");
        continue;
    }

    $pdo->beginTransaction();

    try {
        $now = date('Y-m-d H:i:s');

        // Data do credito inicial = data do primeiro pedido (ou now se sem pedidos)
        $creditDate = !empty($orders) ? $orders[0]['created_at'] : $now;

        // -------------------------------------------------------------------
        // 1. Credito inicial — saldo legado consolidado
        // -------------------------------------------------------------------
        $pdo->prepare(
            'INSERT INTO client_supplier_transactions
             (client_id, supplier_id, type, amount, description, reference,
              running_balance, transaction_type, order_id,
              created_at, updated_at)
             VALUES (?, ?, "credit", ?, "Saldo inicial migrado do sistema legado",
                     "legacy_migration", ?, "deposit", NULL, ?, ?)'
        )->execute([
            $clientId,
            $SUPPLIER_ID,
            $legacyBalance,
            $legacyBalance,
            $creditDate,
            $creditDate,
        ]);

        log_msg("  Credito inicial inserido: R$ " . number_format($legacyBalance, 2, ',', '.'));

        // -------------------------------------------------------------------
        // 2. Debitos por pedido
        // -------------------------------------------------------------------
        $stmtDebit = $pdo->prepare(
            'INSERT INTO client_supplier_transactions
             (client_id, supplier_id, type, amount, description, reference,
              running_balance, transaction_type, order_id,
              created_at, updated_at)
             VALUES (?, ?, "debit", ?, ?, "order", ?, "order", ?, ?, ?)'
        );

        $runningBalance = $legacyBalance;
        $batchCount = 0;

        foreach ($orders as $order) {
            $amount          = (float) $order['total'];
            $runningBalance -= $amount;
            $description     = 'Pedido #' . $order['order_number'];

            $stmtDebit->execute([
                $clientId,
                $SUPPLIER_ID,
                $amount,
                $description,
                $runningBalance,
                $order['id'],
                $order['created_at'],
                $order['created_at'],
            ]);

            $batchCount++;
            if ($batchCount % 500 === 0) {
                log_msg("    {$batchCount} debitos inseridos...");
            }
        }

        log_msg("  Debitos inseridos: {$batchCount}");

        // -------------------------------------------------------------------
        // 3. Upsert saldo final
        // -------------------------------------------------------------------
        $pdo->prepare(
            'INSERT INTO client_supplier_balances
             (client_id, supplier_id, balance, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE balance = VALUES(balance), updated_at = VALUES(updated_at)'
        )->execute([
            $clientId,
            $SUPPLIER_ID,
            $finalBalance,
            $now,
            $now,
        ]);

        log_msg("  Saldo registrado: R$ " . number_format($finalBalance, 2, ',', '.'));

        $pdo->commit();
        log_msg("  OK — commit realizado com sucesso.");

    } catch (Exception $e) {
        $pdo->rollBack();
        abort_script("Erro ao processar client_id={$clientId}: " . $e->getMessage());
    }
}

log_msg("=== Script concluido ===");
