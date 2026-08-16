<?php
/**
 * NOV-210: fast-ack de webhooks ML — responde 200 em ms sem bootar o Laravel.
 *
 * O ML exige resposta <500ms; sob carga o boot do framework por request satura
 * os 25 slots LSPHP e o ML reenvia (retry storm). Este script recebe o POST,
 * empurra o payload cru pra lista redis `fastack:webhooks` e responde na hora.
 * O comando `webhooks:fastack-drain` drena a lista e despacha WebhookIngestJob
 * (pipeline INF-034 intacto: dedup, signature, guard órfão).
 *
 * Gate: WEBHOOK_FASTACK=true no .env do site. Desligado ou em erro → fallback
 * `require index.php` (comportamento idêntico ao atual — seguro nos 7 backends).
 */

$root = dirname(__DIR__);

$envVals = ['WEBHOOK_FASTACK' => null, 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => null, 'REDIS_DB' => '0'];
$envFile = @file_get_contents($root . '/.env');
if ($envFile !== false) {
    foreach ($envVals as $key => $default) {
        if (preg_match('/^' . $key . '=(["\']?)(.*?)\1\s*$/m', $envFile, $m)) {
            $envVals[$key] = $m[2] === '' ? $default : $m[2];
        }
    }
}

if ($envVals['WEBHOOK_FASTACK'] !== 'true' || !class_exists('Redis')) {
    require __DIR__ . '/index.php';
    exit;
}

$uri = strtok($_SERVER['REQUEST_URI'] ?? '/webhooks', '?');
if (!preg_match('~/webhooks/([a-z0-9_-]+)$~i', $uri, $m)) {
    require __DIR__ . '/index.php';
    exit;
}
$platform = strtolower($m[1]);

$headers = [];
foreach ($_SERVER as $key => $val) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $val;
    }
}
if (isset($_SERVER['CONTENT_TYPE']))   { $headers['content-type'] = $_SERVER['CONTENT_TYPE']; }
if (isset($_SERVER['CONTENT_LENGTH'])) { $headers['content-length'] = $_SERVER['CONTENT_LENGTH']; }

$item = json_encode([
    'platform' => $platform,
    'body'     => file_get_contents('php://input'),
    'headers'  => $headers,
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    'method'   => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    'uri'      => $uri,
    'ts'       => microtime(true),
]);

try {
    $redis = new Redis();
    $redis->connect($envVals['REDIS_HOST'], (int) $envVals['REDIS_PORT'], 1.0);
    if ($envVals['REDIS_PASSWORD'] !== null && $envVals['REDIS_PASSWORD'] !== '') {
        $redis->auth($envVals['REDIS_PASSWORD']);
    }
    $redis->select((int) $envVals['REDIS_DB']);
    $redis->rPush('fastack:webhooks', $item);
} catch (Throwable $e) {
    require __DIR__ . '/index.php';
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"status":"ok","mode":"fastack"}';
