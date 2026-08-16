<?php

namespace App\Http\Middleware;

use App\Models\MarketplaceAccount;
use App\Services\Logging\MigracaoLogger;
use Closure;
use Illuminate\Http\Request;

/**
 * Captura eventos das rotas (webhooks, login, integracoes) e loga via MigracaoLogger
 * APENAS se o cliente envolvido estiver na lista de migrados.
 *
 * Aplicado nas rotas:
 *  - /api/webhooks/* (bling, ml, shopee, asaas, supplier_payment, hubai_order)
 *  - /login, /auth/login
 *
 * MUL-029 (2026-06-23)
 */
class LogMigracaoEvents
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            $email = $this->resolveEmail($request);
            if (!$email) {
                return $response;
            }

            $duration = round((microtime(true) - $start) * 1000, 2);

            MigracaoLogger::log("http." . $this->classifyRoute($request), $email, [
                "method" => $request->method(),
                "path" => $request->path(),
                "query" => $request->query(),
                "status" => $response->getStatusCode(),
                "ip" => $request->ip(),
                "user_agent" => substr((string) $request->userAgent(), 0, 200),
                "duration_ms" => $duration,
                "body_size" => strlen((string) $request->getContent()),
            ]);
        } catch (\Throwable $e) {
            // silencioso, nunca quebra
        }

        return $response;
    }

    /**
     * Tenta resolver o email do cliente a partir do request.
     * Olha: user autenticado, payload da webhook (shop_id, ml_user_id, callback_url, account.id),
     * email no body/query.
     */
    protected function resolveEmail(Request $request): ?string
    {
        // 1) Auth
        $user = $request->user();
        if ($user && isset($user->email)) {
            return strtolower(trim((string) $user->email));
        }

        // 2) email/login no body/query
        foreach (["email", "login", "user_email"] as $k) {
            $v = $request->input($k) ?? $request->query($k);
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($v));
            }
        }

        // 3) Webhooks: identifica conta via shop_id / ml_user_id / account_id no payload
        $path = $request->path();
        if (str_contains($path, "webhooks/")) {
            $payload = $request->all();
            // Shopee: shop_id no topo
            if (isset($payload["shop_id"])) {
                $email = $this->emailByMarketplaceField("shopee", "shop_id", (string) $payload["shop_id"]);
                if ($email) return $email;
            }
            // ML: user_id no topo
            if (isset($payload["user_id"])) {
                $email = $this->emailByMarketplaceField("mercadolivre", "ml_user_id", (string) $payload["user_id"]);
                if ($email) return $email;
            }
            // Bling: pode vir empresa.id ou seller info; salvamos como fallback no payload bruto
            if (isset($payload["data"]["empresa"]["id"])) {
                $email = $this->emailByMarketplaceField("bling", "external_account_id", (string) $payload["data"]["empresa"]["id"]);
                if ($email) return $email;
            }
        }

        return null;
    }

    protected function emailByMarketplaceField(string $platform, string $field, string $value): ?string
    {
        try {
            $account = MarketplaceAccount::where("platform", $platform)
                ->where($field, $value)
                ->orderByDesc("id")
                ->first();
            if (!$account) return null;
            $client = \App\Models\Client::find($account->client_id);
            return strtolower(trim((string) ($client?->user?->email ?? "")));
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function classifyRoute(Request $request): string
    {
        $path = $request->path();
        if (str_contains($path, "webhooks/bling")) return "webhook.bling";
        if (str_contains($path, "webhooks/mercadolivre")) return "webhook.mercadolivre";
        if (str_contains($path, "webhooks/shopee")) return "webhook.shopee";
        if (str_contains($path, "webhooks/asaas")) return "webhook.asaas";
        if (str_contains($path, "webhooks/")) return "webhook.other";
        if (str_contains($path, "login")) return "auth.login";
        if (str_contains($path, "oauth")) return "oauth";
        return "request";
    }
}
