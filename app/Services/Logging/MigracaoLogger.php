<?php

namespace App\Services\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Logger dedicado para clientes em migracao Legado -> NovoHubAI MultDrop.
 *
 * Le a lista de emails em /opt/migracao-multdrop/clientes-migrados.txt
 * (1 email por linha, lowercase). So loga se o cliente estiver na lista.
 * Tudo cai em storage/logs/migracao-YYYY-MM-DD.log.
 *
 * MUL-029 (2026-06-23)
 */
class MigracaoLogger
{
    protected const LISTA_PATH = "/opt/migracao-multdrop/clientes-migrados.txt";
    protected const CACHE_KEY = "migracao_multdrop_emails";
    protected const CACHE_TTL = 60;

    public static function log(string $evento, $clientOrEmail, array $context = []): void
    {
        $email = self::extractEmail($clientOrEmail);
        if (!$email || !self::isMigrado($email)) {
            return;
        }
        $payload = array_merge([
            "evento" => $evento,
            "email" => $email,
            "client_id" => self::extractClientId($clientOrEmail),
            "timestamp" => now()->toIso8601String(),
        ], $context);
        try {
            Log::channel("migracao")->info($evento, $payload);
        } catch (\Throwable $e) {
            Log::warning("MigracaoLogger fallback: " . $e->getMessage(), $payload);
        }
    }

    public static function error(string $evento, $clientOrEmail, array $context = []): void
    {
        $email = self::extractEmail($clientOrEmail);
        if (!$email || !self::isMigrado($email)) {
            return;
        }
        $payload = array_merge([
            "evento" => $evento,
            "email" => $email,
            "client_id" => self::extractClientId($clientOrEmail),
            "timestamp" => now()->toIso8601String(),
        ], $context);
        try {
            Log::channel("migracao")->error($evento, $payload);
        } catch (\Throwable $e) {
            Log::error("MigracaoLogger fallback: " . $e->getMessage(), $payload);
        }
    }

    public static function isMigrado(string $email): bool
    {
        $emails = self::getEmails();
        return in_array(strtolower(trim($email)), $emails, true);
    }

    public static function getEmails(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            if (!File::exists(self::LISTA_PATH)) {
                return [];
            }
            $raw = File::get(self::LISTA_PATH);
            $emails = array_filter(array_map(function ($line) {
                $line = strtolower(trim($line));
                return ($line && !str_starts_with($line, "#")) ? $line : null;
            }, explode("\n", $raw)));
            return array_values(array_unique($emails));
        });
    }

    public static function addEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $emails = self::getEmails();
        if (in_array($email, $emails, true)) {
            return false;
        }
        File::append(self::LISTA_PATH, $email . "\n");
        Cache::forget(self::CACHE_KEY);
        return true;
    }

    protected static function extractEmail($clientOrEmail): ?string
    {
        if (is_string($clientOrEmail)) {
            return strtolower(trim($clientOrEmail));
        }
        if (is_object($clientOrEmail)) {
            if (isset($clientOrEmail->email) && is_string($clientOrEmail->email)) {
                return strtolower(trim((string) $clientOrEmail->email));
            }
            // Client model: tem user relationship
            if (property_exists($clientOrEmail, "user") || method_exists($clientOrEmail, "getRelation")) {
                try {
                    $user = $clientOrEmail->user ?? null;
                    if ($user && isset($user->email)) {
                        return strtolower(trim((string) $user->email));
                    }
                } catch (\Throwable $e) {}
            }
        }
        return null;
    }

    protected static function extractClientId($clientOrEmail): ?int
    {
        if (is_object($clientOrEmail)) {
            if (isset($clientOrEmail->client_id) && is_numeric($clientOrEmail->client_id)) {
                return (int) $clientOrEmail->client_id;
            }
            if (isset($clientOrEmail->id) && is_numeric($clientOrEmail->id)) {
                return (int) $clientOrEmail->id;
            }
        }
        return null;
    }
}
