<?php
/**
 * fix-clients-mestoredrop.php
 *
 * MES-010 — Agente 1 — Fix Clients + client_supplier no MEStoreDrop
 *
 * O que faz:
 *  1. Garante que existe Supplier id=25 (MEStoreDrop) — cria se nao existir.
 *  2. Para cada user role=client em mestoredropapp_production que NAO tem
 *     registro em clients, cria o registro em clients fazendo match por email
 *     com a tabela login do legado (tudoonline_production.login) para preencher
 *     legacy_id_login, document, phone, etc.
 *  3. Cria registro em client_supplier (client_id, supplier_id=25, is_extra=0).
 *
 * Idempotente: usa INSERT ... WHERE NOT EXISTS / SELECT antes de inserir.
 * Banco legado: SOMENTE SELECT.
 */

const SUPPLIER_ID = 25;
const LEGACY_EMPRESA_ID = 20;          // empresa MEStoreDrop no legado
const SUPPLIER_OWNER_USER_ID = 2;      // admin@mestoredrop.com.br
const SUPPLIER_SLUG = 'mestoredrop';
const SUPPLIER_PREFIX = 'MED';

// ---------------------------------------------------------------------------
// Conexoes
// ---------------------------------------------------------------------------
try {
    $novo = new PDO(
        'mysql:host=127.0.0.1;dbname=mestoredropapp_production;charset=utf8mb4',
        'mestoredropapp',
        '2c0b0ae12fb223a14b55cfd99992410c2d1c218b7dbed851b5c338cd1006db27',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "[OK] Conectado em mestoredropapp_production\n";
} catch (PDOException $e) {
    echo "ERRO: Falha ao conectar banco novo: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $legado = new PDO(
        'mysql:host=217.216.81.157;port=32000;dbname=tudoonline_production;charset=utf8mb4',
        'tudoonline_production',
        '1D5IaXlXxmtlT0e4GTF4mzU3rsf38BvCCZHUaSFD',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "[OK] Conectado em tudoonline_production (legado)\n";
} catch (PDOException $e) {
    echo "ERRO: Falha ao conectar banco legado: " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Garantir Supplier id=25 (MEStoreDrop)
// ---------------------------------------------------------------------------
echo "\n[1] Garantindo Supplier id=" . SUPPLIER_ID . " (MEStoreDrop)...\n";

$check = $novo->prepare("SELECT id, company_name FROM suppliers WHERE id = ?");
$check->execute([SUPPLIER_ID]);
$existing = $check->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo "  [OK] Supplier ja existe: id={$existing['id']} ({$existing['company_name']})\n";
} else {
    // Buscar dados do supplier no legado: empresa 20 -> deposito 447
    $stmt = $legado->prepare(
        "SELECT e.id AS empresa_id, e.empresa AS empresa_nome, e.host,
                d.id AS deposito_id, d.descricao AS deposito_desc,
                d.estado, d.cidade, d.endereco_completo, d.cep, d.telefone
         FROM empresas e
         LEFT JOIN deposito d ON d.id = e.id_deposito
         WHERE e.id = ?"
    );
    $stmt->execute([LEGACY_EMPRESA_ID]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        echo "ERRO: Empresa legacy_empresa_id=" . LEGACY_EMPRESA_ID . " nao encontrada no legado.\n";
        exit(1);
    }

    $companyName = $emp['empresa_nome'] ?: 'MEStoreDrop';
    $document    = '00000000000000';  // placeholder ate Ruan informar CNPJ real
    $address     = $emp['endereco_completo'] ?? null;
    $city        = $emp['cidade'] ?? null;
    $state       = $emp['estado'] ?? null;
    $zipcode     = $emp['cep'] ?? null;
    $phone       = $emp['telefone'] ?? null;

    try {
        $novo->beginTransaction();

        $ins = $novo->prepare(
            "INSERT INTO suppliers
                 (id, legacy_id, user_id, legacy_empresa_id, type, prefix, slug,
                  company_name, display_name, document, phone, address, city, state, zipcode,
                  is_active, allows_direct_payment, pix_fee, is_factory,
                  supports_meli_flex, flex_fee, allows_direct_deposit,
                  is_private,
                  created_at, updated_at)
             VALUES
                 (?, ?, ?, ?, 'warehouse', ?, ?,
                  ?, ?, ?, ?, ?, ?, ?, ?,
                  1, 0, 0.00, 0,
                  0, 0.00, 1,
                  0,
                  NOW(), NOW())"
        );
        $ins->execute([
            SUPPLIER_ID,
            $emp['empresa_id'],            // legacy_id = id da empresa legada
            SUPPLIER_OWNER_USER_ID,
            LEGACY_EMPRESA_ID,
            SUPPLIER_PREFIX,
            SUPPLIER_SLUG,
            $companyName,
            $companyName,
            $document,
            $phone,
            $address,
            $city,
            $state,
            $zipcode,
        ]);

        $novo->commit();
        echo "  [OK] Supplier criado: id=" . SUPPLIER_ID . " ({$companyName})\n";
    } catch (PDOException $e) {
        $novo->rollBack();
        echo "ERRO ao criar supplier: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2. Pre-carregar map de emails legado: email -> { id_login, nome, cpf, celular }
//    Limita aos emails dos users no MEStoreDrop pra ser eficiente.
// ---------------------------------------------------------------------------
echo "\n[2] Carregando users e cruzando com legado...\n";

$stmt = $novo->query(
    "SELECT u.id AS user_id, u.name, u.email
     FROM users u
     LEFT JOIN clients c ON c.user_id = u.id
     WHERE u.role = 'client' AND c.id IS NULL
     ORDER BY u.id"
);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);
echo "  Users role=client SEM clients record: {$totalUsers}\n";

if ($totalUsers === 0) {
    echo "  Nada a fazer.\n";
    exit(0);
}

// Montar lista de emails para query no legado em batch
$emails = array_map(function ($u) {
    return strtolower(trim($u['email']));
}, $users);
$emails = array_filter($emails);
$emails = array_unique($emails);

// Query no legado em lotes de 500 pra nao estourar
$legacyMap = [];   // email -> primeiro registro (newest wins via ORDER BY DESC)
$chunks = array_chunk($emails, 500);
echo "  Consultando legado em " . count($chunks) . " lote(s)...\n";

foreach ($chunks as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $stmt = $legado->prepare(
        "SELECT id, login, email, nome_completo, empresa, cpf_cnpj, celular
         FROM login
         WHERE LOWER(TRIM(email)) IN ({$placeholders})
         ORDER BY id DESC"
    );
    $stmt->execute($chunk);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = strtolower(trim($row['email']));
        if (!isset($legacyMap[$key])) {     // primeiro = id mais alto = mais recente
            $legacyMap[$key] = $row;
        }
    }
}
echo "  Matched: " . count($legacyMap) . " / " . count($emails) . " emails\n";

// ---------------------------------------------------------------------------
// 3. Criar clients + client_supplier
// ---------------------------------------------------------------------------
echo "\n[3] Criando clients + client_supplier...\n";

$createdClients   = 0;
$createdClientSup = 0;
$matchedLegacy    = 0;
$noLegacyMatch    = 0;
$errors           = 0;
$errorSamples     = [];

$insClient = $novo->prepare(
    "INSERT INTO clients
         (user_id, company_name, document, phone, legacy_id_login,
          is_active, listing_mode, discount_sales_count, current_discount_percent,
          auto_pay_from_wallet, auto_pay_min_balance,
          created_at, updated_at)
     VALUES
         (?, ?, ?, ?, ?,
          1, 'manual', 0, 50,
          0, 0.00,
          NOW(), NOW())"
);

$checkClientSup = $novo->prepare(
    "SELECT id FROM client_supplier WHERE client_id = ? AND supplier_id = ?"
);
$insClientSup = $novo->prepare(
    "INSERT INTO client_supplier (client_id, supplier_id, is_extra, created_at, updated_at)
     VALUES (?, ?, 0, NOW(), NOW())"
);

foreach ($users as $u) {
    $emailKey  = strtolower(trim($u['email']));
    $legacy    = $legacyMap[$emailKey] ?? null;

    if ($legacy) {
        $matchedLegacy++;
        $companyName    = $legacy['nome_completo'] ?: ($legacy['empresa'] ?: ($u['name'] ?: $u['email']));
        $document       = preg_replace('/\D/', '', $legacy['cpf_cnpj'] ?? '') ?: null;
        $phone          = preg_replace('/\D/', '', $legacy['celular']  ?? '') ?: null;
        $legacyIdLogin  = (int) $legacy['id'];
    } else {
        $noLegacyMatch++;
        $companyName    = $u['name'] ?: $u['email'];
        $document       = null;
        $phone          = null;
        $legacyIdLogin  = null;
    }

    try {
        $insClient->execute([
            $u['user_id'],
            $companyName,
            $document,
            $phone,
            $legacyIdLogin,
        ]);
        $clientId = (int) $novo->lastInsertId();
        $createdClients++;

        // client_supplier: idempotente
        $checkClientSup->execute([$clientId, SUPPLIER_ID]);
        if (!$checkClientSup->fetchColumn()) {
            $insClientSup->execute([$clientId, SUPPLIER_ID]);
            $createdClientSup++;
        }
    } catch (PDOException $e) {
        $errors++;
        if (count($errorSamples) < 5) {
            $errorSamples[] = "user_id={$u['user_id']} email={$u['email']}: " . $e->getMessage();
        }
    }

    if (($createdClients + $errors) % 100 === 0) {
        echo "  Progresso: criados={$createdClients} erros={$errors}\n";
    }
}

echo "\n=== Resumo ===\n";
echo "Users processados        : {$totalUsers}\n";
echo "Match com legado (login) : {$matchedLegacy}\n";
echo "Sem match no legado      : {$noLegacyMatch}\n";
echo "Clients criados          : {$createdClients}\n";
echo "Client_supplier criados  : {$createdClientSup}\n";
echo "Erros                    : {$errors}\n";

if ($errors > 0) {
    echo "\nAmostras de erros:\n";
    foreach ($errorSamples as $e) {
        echo "  - {$e}\n";
    }
}

// ---------------------------------------------------------------------------
// 4. Contagens finais (verificacao)
// ---------------------------------------------------------------------------
echo "\n=== Contagens finais ===\n";
$row = $novo->query("SELECT COUNT(*) FROM clients")->fetch(PDO::FETCH_NUM);
echo "clients (total)                       : {$row[0]}\n";
$row = $novo->query("SELECT COUNT(*) FROM clients WHERE legacy_id_login IS NOT NULL")->fetch(PDO::FETCH_NUM);
echo "clients com legacy_id_login           : {$row[0]}\n";
$row = $novo->query("SELECT COUNT(*) FROM client_supplier WHERE supplier_id = " . SUPPLIER_ID)->fetch(PDO::FETCH_NUM);
echo "client_supplier (supplier_id=" . SUPPLIER_ID . ")       : {$row[0]}\n";
$row = $novo->query("SELECT COUNT(*) FROM suppliers")->fetch(PDO::FETCH_NUM);
echo "suppliers (total)                     : {$row[0]}\n";

echo "\n[DONE]\n";
