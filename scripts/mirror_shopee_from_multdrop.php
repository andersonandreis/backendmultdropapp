<?php
/**
 * SEL-357: Clona conta Shopee da Victoria (multdrop client_id=5, account_id=2)
 * para o sellerapp client_id=700 (Ruan admin) com mirror_mode=readonly.
 *
 * Executar UMA VEZ como: php scripts/mirror_shopee_from_multdrop.php
 *
 * Nao usar artisan tinker — script standalone com PDO puro pra evitar
 * carregar contexto multi-tenant que filtraria client_id=700 incorretamente.
 */

define('MULTDROP_DSN',  'mysql:host=127.0.0.1;port=3307;dbname=multdropapp_production;charset=utf8mb4');
define('MULTDROP_USER', 'multdropapp');
define('MULTDROP_PASS', 'mdAppPwd_2026_aF5bH8jK4eD3sQ7w');

define('SELLER_DSN',  'mysql:host=127.0.0.1;port=3307;dbname=sellerapp_production;charset=utf8mb4');
define('SELLER_USER', 'sellerapp');
define('SELLER_PASS', 'sgrAppPwd_2026_mB4vN9kJ2wQ6hT8x');

// APP_KEYs reais (base64-encoded, Laravel usa AES-256-CBC por padrao)
define('MULTDROP_APP_KEY', 'base64:1lMuDxW939hIcyAV9Qsax6+eV4f0s2nwy0zAFvSO3z4=');
define('SELLER_APP_KEY',   'base64:nas41PtGYFU9ErM8vOGr6Vil8sDPaIVbd/bgGXbKrfk=');

// =====================================================================
// Helpers de encrypt/decrypt compativel com Laravel Encrypter (AES-256-CBC)
// =====================================================================

function extractKey(string $appKey): string
{
    if (str_starts_with($appKey, 'base64:')) {
        return base64_decode(substr($appKey, 7));
    }
    return $appKey;
}

function laravelDecrypt(string $payload, string $appKey): string
{
    $key  = extractKey($appKey);
    $data = json_decode(base64_decode($payload), true);

    if (!$data || !isset($data['iv'], $data['value'], $data['mac'])) {
        throw new RuntimeException('Payload invalido — nao e um valor encriptado Laravel');
    }

    $iv    = base64_decode($data['iv']);
    $value = base64_decode($data['value']);

    // Verifica HMAC
    $mac = hash_hmac('sha256', $data['iv'] . $data['value'], $key);
    if (!hash_equals($data['mac'], $mac)) {
        throw new RuntimeException('HMAC invalido — APP_KEY incorreta ou payload corrompido');
    }

    $decrypted = openssl_decrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($decrypted === false) {
        throw new RuntimeException('Falha openssl_decrypt');
    }

    // Laravel serializa valores — desserializar
    return unserialize($decrypted);
}

function laravelEncrypt(string $value, string $appKey): string
{
    $key = extractKey($appKey);
    $iv  = random_bytes(16);

    $encrypted = openssl_encrypt(
        serialize($value),
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encrypted === false) {
        throw new RuntimeException('Falha openssl_encrypt');
    }

    $ivB64  = base64_encode($iv);
    $valB64 = base64_encode($encrypted);
    $mac    = hash_hmac('sha256', $ivB64 . $valB64, $key);

    return base64_encode(json_encode([
        'iv'    => $ivB64,
        'value' => $valB64,
        'mac'   => $mac,
    ]));
}

// =====================================================================
// Main
// =====================================================================

echo "[SEL-357] Iniciando clone Shopee Victoria (multdrop client=5) -> sellerapp client=700\n";

// 1. Buscar conta no multdrop
$mdPdo = new PDO(MULTDROP_DSN, MULTDROP_USER, MULTDROP_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $mdPdo->prepare('SELECT * FROM marketplace_accounts WHERE id = 2 AND client_id = 5 LIMIT 1');
$stmt->execute();
$src = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$src) {
    echo "[ERRO] marketplace_accounts id=2 client_id=5 nao encontrada no multdrop.\n";
    exit(1);
}

echo "[OK] Conta multdrop encontrada: shop_id={$src['shop_id']} status={$src['status']} account_name={$src['account_name']}\n";

// 2. Decriptar tokens com chave multdrop
echo "[...] Decriptando tokens com APP_KEY multdrop...\n";
$plainAccess  = laravelDecrypt($src['access_token'],  MULTDROP_APP_KEY);
$plainRefresh = laravelDecrypt($src['refresh_token'], MULTDROP_APP_KEY);

echo "[OK] access_token len=" . strlen($plainAccess)  . " prefixo=" . substr($plainAccess, 0, 20) . "...\n";
echo "[OK] refresh_token len=" . strlen($plainRefresh) . " prefixo=" . substr($plainRefresh, 0, 20) . "...\n";

// 3. Re-encriptar com chave sellerapp
echo "[...] Re-encriptando com APP_KEY sellerapp...\n";
$encAccess  = laravelEncrypt($plainAccess,  SELLER_APP_KEY);
$encRefresh = laravelEncrypt($plainRefresh, SELLER_APP_KEY);
echo "[OK] Tokens re-encriptados OK\n";

// 4. Verificar se ja existe espelho no sellerapp (idempotente)
$sgPdo = new PDO(SELLER_DSN, SELLER_USER, SELLER_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$check = $sgPdo->prepare(
    'SELECT id FROM marketplace_accounts WHERE client_id = 700 AND platform = ? AND shop_id = ? AND mirror_source_backend = ? LIMIT 1'
);
$check->execute(['shopee', $src['shop_id'], 'multdrop']);
$existing = $check->fetchColumn();

if ($existing) {
    echo "[SKIP] Espelho ja existe no sellerapp: id={$existing} — nada a inserir (idempotente).\n";
    exit(0);
}

// 5. Inserir no sellerapp
echo "[...] Inserindo no sellerapp marketplace_accounts...\n";

$insert = $sgPdo->prepare("
    INSERT INTO marketplace_accounts (
        client_id, platform, shop_id, account_name,
        access_token, refresh_token,
        token_expires_at, refresh_token_expires_at,
        status, mirror_mode, mirror_source_backend, mirror_source_client_id,
        import_mode, pricing_strategy, price_margin, tax_percentage,
        marketplace_commission, marketplace_fixed_fee, marketplace_shipping_fee,
        created_at, updated_at
    ) VALUES (
        700, 'shopee', :shop_id, :account_name,
        :access_token, :refresh_token,
        :token_expires_at, :refresh_token_expires_at,
        'active', 'readonly', 'multdrop', 5,
        'manual', 'fixed', 0.00, 0.00,
        0.00, 0.00, 0.00,
        NOW(), NOW()
    )
");

$insert->execute([
    ':shop_id'                    => $src['shop_id'],
    ':account_name'               => $src['account_name'] . ' [ESPELHO DEMO]',
    ':access_token'               => $encAccess,
    ':refresh_token'              => $encRefresh,
    ':token_expires_at'           => $src['token_expires_at'],
    ':refresh_token_expires_at'   => $src['refresh_token_expires_at'],
]);

$newId = $sgPdo->lastInsertId();
echo "[OK] Espelho inserido no sellerapp: marketplace_accounts.id={$newId} client_id=700 shop_id={$src['shop_id']} mirror_mode=readonly\n";

// 6. Confirmar
$confirm = $sgPdo->prepare('SELECT id, client_id, platform, shop_id, status, mirror_mode, mirror_source_backend, mirror_source_client_id FROM marketplace_accounts WHERE id = ?');
$confirm->execute([$newId]);
$row = $confirm->fetch(PDO::FETCH_ASSOC);
echo "[CONFIRMADO] ";
print_r($row);

echo "\n[SEL-357] Clone concluido. Proximos passos: rodar MirrorSyncCommand para copiar orders.\n";
