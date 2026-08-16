<?php

namespace App\Services;

use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HUB-032 — Logger central de integrações.
 *
 * Use estes métodos sempre que receber webhook ou disparar chamada externa.
 *
 *   IntegrationLogger::inbound([...])
 *   IntegrationLogger::outbound([...])
 *
 * Sanitiza tokens/segredos antes de persistir e trunca payload em 8KB para
 * não inflar a tabela.
 */
class IntegrationLogger
{
    /** Tamanho máximo dos payloads serializados (8 KB). */
    public const MAX_PAYLOAD_BYTES = 8192;

    /** Regex usadas para mascarar chaves sensíveis. */
    private const SENSITIVE_KEYS = [
        'access_token', 'refresh_token', 'token', 'authorization', 'auth',
        'bearer', 'password', 'secret', 'api_key', 'apikey', 'client_secret',
        'webhook_secret', 'x-api-key', 'x-secret', 'cookie', 'set-cookie',
    ];

    public static function inbound(array $data): ?IntegrationLog
    {
        return self::write(array_merge($data, [
            'direction' => IntegrationLog::DIRECTION_INBOUND,
        ]));
    }

    public static function outbound(array $data): ?IntegrationLog
    {
        return self::write(array_merge($data, [
            'direction' => IntegrationLog::DIRECTION_OUTBOUND,
        ]));
    }

    /**
     * Persistência tolerante a falhas — qualquer exceção é apenas registrada
     * no log default para não derrubar o fluxo da integração.
     */
    public static function write(array $data): ?IntegrationLog
    {
        try {
            $data['request_payload'] = self::truncate(
                self::sanitize($data['request_payload'] ?? null)
            );
            $data['response_body']   = self::truncate(
                self::sanitize($data['response_body'] ?? null)
            );

            if (! isset($data['occurred_at'])) {
                $data['occurred_at'] = now();
            }

            return IntegrationLog::create($data);
        } catch (Throwable $e) {
            Log::warning('IntegrationLogger.write_failed', [
                'message' => $e->getMessage(),
                'data'    => array_intersect_key($data, array_flip([
                    'integration_name', 'direction', 'url', 'status_code',
                ])),
            ]);

            return null;
        }
    }

    /**
     * Mascara recursivamente chaves consideradas sensíveis.
     */
    public static function sanitize($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::sanitize($decoded);
            }

            return self::scrubString($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $v) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $value[$key] = '***';
                    continue;
                }
                $value[$key] = self::sanitize($v);
            }
            return $value;
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mascara tokens "Bearer xxx" e segredos óbvios em strings soltas.
     */
    private static function scrubString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9\._\-]+/i', 'Bearer ***', $value);
        $value = preg_replace('/(access_token|refresh_token|api_key|secret|password)["\']?\s*[:=]\s*["\']?[^"\'&\s,}]+/i',
            '$1=***', $value);
        return $value;
    }

    /**
     * Trunca em 8 KB para não inflar a tabela. Mantém formato JSON nativo
     * quando possível para o cast `array` do model funcionar.
     */
    public static function truncate($value)
    {
        if ($value === null) {
            return null;
        }

        $json = is_array($value) ? json_encode($value) : (is_string($value) ? $value : (string) $value);
        if ($json === false || $json === null) {
            return null;
        }

        if (strlen($json) <= self::MAX_PAYLOAD_BYTES) {
            return is_array($value) ? $value : $json;
        }

        return [
            '_truncated' => true,
            '_original_size' => strlen($json),
            '_preview' => mb_substr($json, 0, self::MAX_PAYLOAD_BYTES - 80, 'UTF-8'),
        ];
    }
}
