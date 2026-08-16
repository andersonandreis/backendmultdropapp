<?php
/**
 * migrate-client.php
 * Migra um cliente do banco legado para o novo HubAI.
 *
 * USO: php scripts/migrate-client.php <legacy_id_login>
 *
 * REGRAS:
 *   - Banco legado: SOMENTE SELECT -- nunca INSERT/UPDATE/DELETE
 *   - Banco novo: escrita normal
 *   - marketplace_accounts ficam status=imported (sem OAuth)
 */

$legacyIdLogin = $argv[1] ?? null;
if (!$legacyIdLogin || !is_numeric($legacyIdLogin)) {
    echo "Uso: php scripts/migrate-client.php <legacy_id_login>\n";
    exit(1);
}
$legacyIdLogin = (int) $legacyIdLogin;

// ---------------------------------------------------------------------------
// Conexoes
// ---------------------------------------------------------------------------
try {
    $novo = new PDO(
        'mysql:host=localhost;dbname=hubaiapp;charset=utf8mb4',
        'hubaiapp',
        'HubAI2026db',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "ERRO: Falha ao conectar banco novo: " . $e->getMessage() . "\n";
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
    echo "ERRO: Falha ao conectar banco legado: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Migrando cliente legacy_id_login={$legacyIdLogin} ===\n\n";

// ---------------------------------------------------------------------------
// 1. Buscar cliente no legado
// ---------------------------------------------------------------------------
$stmt = $legado->prepare(
    "SELECT id, nome_completo, empresa, login, email, plano, conta_corrente,
            cpf_cnpj, celular
     FROM login
     WHERE id = ?"
);
$stmt->execute([$legacyIdLogin]);
$login = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$login) {
    echo "ERRO: Cliente {$legacyIdLogin} nao encontrado no legado.\n";
    exit(1);
}

$email       = trim($login['email']        ?? $login['login'] ?? '');
$nome        = trim($login['nome_completo'] ?: ($login['empresa'] ?: $login['login']));
$cpfCnpj     = preg_replace('/\D/', '', $login['cpf_cnpj'] ?? '');
$phone       = preg_replace('/\D/', '', $login['celular']  ?? '');

echo "Cliente : {$nome}\n";
echo "E-mail  : {$email}\n";
echo "Plano   : {$login['plano']} | Saldo: R\${$login['conta_corrente']}\n\n";

if (!$email) {
    echo "ERRO: Cliente sem e-mail no legado.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 2. Criar/atualizar User no novo
// ---------------------------------------------------------------------------
$stmt = $novo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if (!$userId) {
    $hash = password_hash('123456', PASSWORD_BCRYPT);
    $stmt = $novo->prepare(
        "INSERT INTO users (name, email, password, role, is_active, email_verified_at, created_at, updated_at)
         VALUES (?, ?, ?, 'client', 1, NOW(), NOW(), NOW())"
    );
    $stmt->execute([$nome, $email, $hash]);
    $userId = (int) $novo->lastInsertId();
    echo "[users] Criado: ID={$userId}\n";
} else {
    $userId = (int) $userId;
    echo "[users] Ja existe: ID={$userId}\n";
}

// ---------------------------------------------------------------------------
// 3. Criar/atualizar Client no novo
//    clients.document e NOT NULL -- usar CPF/CNPJ do legado ou placeholder
// ---------------------------------------------------------------------------
$stmt = $novo->prepare("SELECT id FROM clients WHERE user_id = ?");
$stmt->execute([$userId]);
$clientId = $stmt->fetchColumn();

$documentValue = $cpfCnpj ?: '00000000000';

if (!$clientId) {
    $stmt = $novo->prepare(
        "INSERT INTO clients (user_id, company_name, document, phone, legacy_id_login, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())"
    );
    $stmt->execute([$userId, $nome, $documentValue, $phone, $legacyIdLogin]);
    $clientId = (int) $novo->lastInsertId();
    echo "[clients] Criado: ID={$clientId} (legacy_id_login={$legacyIdLogin})\n";
} else {
    $clientId = (int) $clientId;
    $novo->prepare(
        "UPDATE clients
         SET legacy_id_login = ?, updated_at = NOW()
         WHERE id = ? AND (legacy_id_login IS NULL OR legacy_id_login = 0)"
    )->execute([$legacyIdLogin, $clientId]);
    echo "[clients] Ja existe: ID={$clientId}\n";
}

// ---------------------------------------------------------------------------
// 4. Importar integracoes como marketplace_accounts (status=imported)
// ---------------------------------------------------------------------------
$platformMap = [
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
    if (!isset($platformMap[$c])) {
        $platformMap[$c] = 'bling';
    }
}

$stmt = $legado->prepare(
    "SELECT id, id_canal, shop_name, nome_loja_shopee, usuario, sync_status
     FROM integracao
     WHERE id_login = ?
       AND (sync_status IS NULL OR sync_status != 'disabled')"
);
$stmt->execute([$legacyIdLogin]);
$integracoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n[marketplace_accounts] Integracoes encontradas: " . count($integracoes) . "\n";

$supplierId    = 30; // Multdrop
$createdStores = 0;
$skippedStores = 0;

foreach ($integracoes as $integ) {
    $idCanal     = (int) ($integ['id_canal'] ?? 0);
    $platform    = $platformMap[$idCanal] ?? 'unknown_' . $idCanal;
    $accountName = trim($integ['shop_name']        ?? '') ?:
                   trim($integ['nome_loja_shopee'] ?? '') ?:
                   trim($integ['usuario']          ?? '') ?:
                   $platform;

    // seller_id: campo usuario armazena shop_id (Shopee) ou meli_user_id (ML)
    $sellerId = null;
    if (in_array($platform, ['shopee', 'mercadolivre'])) {
        $sellerId = $integ['usuario'] ?? null;
    }

    $chk = $novo->prepare(
        "SELECT id FROM marketplace_accounts
         WHERE client_id = ? AND platform = ? AND account_name = ?"
    );
    $chk->execute([$clientId, $platform, $accountName]);
    $existingId = $chk->fetchColumn();

    if (!$existingId) {
        $ins = $novo->prepare(
            "INSERT INTO marketplace_accounts
                 (client_id, supplier_id, platform, account_name, status, import_mode, seller_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'imported', 'manual', ?, NOW(), NOW())"
        );
        $ins->execute([$clientId, $supplierId, $platform, $accountName, $sellerId]);
        echo "  + Criada: {$platform} ({$accountName})\n";
        $createdStores++;
    } else {
        echo "  ~ Ja existe: {$platform} ({$accountName}) ID={$existingId}\n";
        $skippedStores++;
    }
}
echo "  Criadas: {$createdStores} | Ja existiam: {$skippedStores}\n";

// ---------------------------------------------------------------------------
// 5. Importar pedidos dos ultimos 90 dias via join em id_integracao
// ---------------------------------------------------------------------------
$stmt = $legado->prepare("SELECT id FROM integracao WHERE id_login = ?");
$stmt->execute([$legacyIdLogin]);
$integIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "\n[orders] Importando pedidos (ultimos 90 dias)...\n";
$importedOrders = 0;
$skippedOrders  = 0;
$errorOrders    = 0;

if (!empty($integIds)) {
    $placeholders = implode(',', array_fill(0, count($integIds), '?'));
    $params = array_merge($integIds, [90]);

    $stmt = $legado->prepare(
        "SELECT p.id            AS legacy_id,
                p.nr_canal      AS external_order_id,
                p.status_marketplace,
                p.valor_total,
                p.data_add,
                p.id_canal,
                p.cliente_nome,
                p.cliente_cpf
         FROM pedidos p
         WHERE p.id_integracao IN ({$placeholders})
           AND p.data_add > DATE_SUB(NOW(), INTERVAL ? DAY)
         ORDER BY p.data_add DESC"
    );
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  Encontrados: " . count($pedidos) . "\n";

    $statusMap = [
        'ready_to_ship' => 'paid',
        'shipped'       => 'shipped',
        'delivered'     => 'delivered',
        'cancelled'     => 'cancelled',
        'unpaid'        => 'pending_payment',
        'to_return'     => 'cancelled',
        'in_cancel'     => 'cancelled',
    ];

    foreach ($pedidos as $ped) {
        $chk = $novo->prepare(
            "SELECT id FROM orders WHERE legacy_id = ? AND client_id = ?"
        );
        $chk->execute([$ped['legacy_id'], $clientId]);
        if ($chk->fetchColumn()) {
            $skippedOrders++;
            continue;
        }

        $platform    = $platformMap[$ped['id_canal']] ?? 'unknown';
        $rawStatus   = strtolower($ped['status_marketplace'] ?? 'pending');
        $status      = $statusMap[$rawStatus] ?? 'pending_payment';
        $orderNumber = 'LEG-' . $ped['legacy_id'];

        try {
            $ins = $novo->prepare(
                "INSERT INTO orders
                     (client_id, supplier_id, legacy_id, order_number, external_order_id,
                      source, status, total, customer_name, customer_document_number,
                      created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $ins->execute([
                $clientId,
                $supplierId,
                $ped['legacy_id'],
                $orderNumber,
                $ped['external_order_id'],
                $platform,
                $status,
                $ped['valor_total'] ?? 0,
                $ped['cliente_nome'] ?? null,
                $ped['cliente_cpf']  ?? null,
                $ped['data_add'],
            ]);
            $importedOrders++;
        } catch (PDOException $e) {
            echo "  ! Erro pedido #{$ped['legacy_id']}: " . $e->getMessage() . "\n";
            $errorOrders++;
        }
    }

    echo "  Importados: {$importedOrders} | Ja existiam: {$skippedOrders} | Erros: {$errorOrders}\n";
} else {
    echo "  Nenhuma integracao encontrada -- pedidos nao importados.\n";
}

// ---------------------------------------------------------------------------
// 6. Mapear produtos via login_deposito -> id_deposito -> sku_pai
//    So vincula legacy_sku_pai_id em products existentes (nao cria novos)
// ---------------------------------------------------------------------------
echo "\n[products] Mapeando produtos...\n";

$stmt = $legado->prepare(
    "SELECT id_deposito FROM login_deposito
     WHERE id_login = ? AND ativo = 1
     LIMIT 1"
);
$stmt->execute([$legacyIdLogin]);
$depositoId = $stmt->fetchColumn();

if ($depositoId) {
    $stmt = $legado->prepare(
        "SELECT id, sku, descricao
         FROM sku_pai
         WHERE id_deposito = ?
           AND (status IS NULL OR status != 'excluido')
         LIMIT 500"
    );
    $stmt->execute([$depositoId]);
    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "  Deposito={$depositoId} | SKUs encontrados: " . count($skus) . "\n";

    $mapped = 0;
    foreach ($skus as $sku) {
        $chk = $novo->prepare(
            "SELECT id FROM products WHERE legacy_sku_pai_id = ? AND supplier_id = ?"
        );
        $chk->execute([$sku['id'], $supplierId]);
        $productId = $chk->fetchColumn();

        if (!$productId) {
            $chk2 = $novo->prepare(
                "SELECT id FROM products WHERE sku = ? AND supplier_id = ?"
            );
            $chk2->execute([$sku['sku'], $supplierId]);
            $productId = $chk2->fetchColumn();
        }

        if ($productId) {
            $novo->prepare(
                "UPDATE products
                 SET legacy_sku_pai_id = ?
                 WHERE id = ? AND (legacy_sku_pai_id IS NULL OR legacy_sku_pai_id = 0)"
            )->execute([$sku['id'], $productId]);
            $mapped++;
        }
    }
    echo "  Produtos mapeados: {$mapped} / " . count($skus) . "\n";
} else {
    echo "  Sem deposito ativo vinculado -- produtos nao mapeados.\n";
}

// ---------------------------------------------------------------------------
// Resumo final
// ---------------------------------------------------------------------------
echo "\n=== Migracao concluida ===\n";
echo "User ID      : {$userId}\n";
echo "Client ID    : {$clientId}\n";
echo "Legacy ID    : {$legacyIdLogin}\n";
echo "Login        : {$email}\n";
echo "Senha inicial: 123456\n";
