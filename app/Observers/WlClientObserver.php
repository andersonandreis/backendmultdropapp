<?php

namespace App\Observers;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Auth;

/**
 * NOV-214 / Antifraude WL MVP — Observer para auditoria de mudancas em clients.
 *
 * Intercepta:
 *   - updating: se is_active mudou de 1 para 0 (desativacao)
 *   - updating: se is_active mudou de 0 para 1 (reativacao)
 *   - deleted: exclusao fisica do registro
 *
 * Grava em wl_client_audit_log (banco hubaiapp, conexao default).
 * O empresa_id e resolvido via APP_EMPRESA_ID no .env de cada WL.
 * Registrado em AppServiceProvider de cada backend.
 *
 * IMPORTANTE: nao interfere no fluxo principal — falha silenciosa com Log::error.
 */
class WlClientObserver
{
    protected function empresaId(): int
    {
        return (int) config("app.empresa_id", 0);
    }

    protected function wlDatabase(): string
    {
        return config("database.connections." . config("database.default") . ".database", "unknown");
    }

    protected function getEmail(Client $client): ?string
    {
        try {
            return DB::table("users")
                ->where("id", $client->user_id)
                ->value("email");
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function updating(Client $client): void
    {
        try {
            $dirty = $client->getDirty();
            if (! array_key_exists("is_active", $dirty)) {
                return; // Nao mudou is_active, nao registra
            }

            $oldVal = $client->getOriginal("is_active");
            $newVal = $dirty["is_active"];

            // Ignora se nao houve mudanca real
            if ((bool)$oldVal === (bool)$newVal) {
                return;
            }

            $action = ((bool)$newVal === false) ? "deactivate" : "reactivate";
            $email  = $this->getEmail($client);

            DB::table("wl_client_audit_log")->insert([
                "empresa_id"        => $this->empresaId(),
                "wl_database"       => $this->wlDatabase(),
                "client_id"         => $client->id,
                "email"             => $email,
                "action"            => $action,
                "before"            => json_encode(["is_active" => (bool)$oldVal, "blocked_at" => $client->getOriginal("blocked_at")]),
                "after"             => json_encode(["is_active" => (bool)$newVal, "blocked_at" => $dirty["blocked_at"] ?? $client->blocked_at]),
                "changed_at"        => now(),
                "changed_by_user_id"=> optional(Auth::user())->id,
                "ip_address"        => Request::ip(),
                "created_at"        => now(),
                "updated_at"        => now(),
            ]);

            Log::info("[WlClientObserver] {$action} registrado", [
                "client_id"  => $client->id,
                "empresa_id" => $this->empresaId(),
                "email"      => $email,
            ]);

        } catch (\Throwable $e) {
            Log::error("[WlClientObserver] erro ao registrar update", [
                "client_id" => $client->id,
                "error"     => $e->getMessage(),
            ]);
        }
    }

    public function deleted(Client $client): void
    {
        try {
            $email = $this->getEmail($client);

            DB::table("wl_client_audit_log")->insert([
                "empresa_id"        => $this->empresaId(),
                "wl_database"       => $this->wlDatabase(),
                "client_id"         => $client->id,
                "email"             => $email,
                "action"            => "delete",
                "before"            => json_encode([
                    "is_active"  => (bool)$client->is_active,
                    "blocked_at" => $client->blocked_at,
                    "document"   => $client->document,
                    "phone"      => $client->phone,
                ]),
                "after"             => null,
                "changed_at"        => now(),
                "changed_by_user_id"=> optional(Auth::user())->id,
                "ip_address"        => Request::ip(),
                "created_at"        => now(),
                "updated_at"        => now(),
            ]);

            Log::info("[WlClientObserver] delete registrado", [
                "client_id"  => $client->id,
                "empresa_id" => $this->empresaId(),
                "email"      => $email,
            ]);

        } catch (\Throwable $e) {
            Log::error("[WlClientObserver] erro ao registrar delete", [
                "client_id" => $client->id,
                "error"     => $e->getMessage(),
            ]);
        }
    }
}
