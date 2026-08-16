<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, "Nao autenticado.");
        }

        if (! in_array($user->role, $roles)) {
            abort(403, "Acesso negado.");
        }

        // SEL (08/08 Ruan): trava de DISPOSITIVO no admin. So aparelhos "selados"
        // (token confiavel) acessam o painel admin, mesmo com a senha certa.
        // Gated por flag admin_device_lock (comeca DESLIGADO -> zero efeito ate o
        // Ruan provar que entra). Cobre TODAS as rotas admin porque todas usam
        // role:admin,super_admin.
        $ehAdminRoute = in_array("admin", $roles, true) || in_array("super_admin", $roles, true);
        if ($ehAdminRoute) {
            try {
                $lock = DB::table("settings")->where("key", "admin_device_lock")->value("value");
                if ((string) $lock === "1") {
                    $token = $request->header("X-SG-Device") ?: $request->cookie("sg_admin_dev");
                    $ok = false;
                    if ($token) {
                        $hash = hash("sha256", (string) $token);
                        $raw = DB::table("settings")->where("key", "admin_trusted_devices")->value("value");
                        $list = $raw ? (json_decode($raw, true) ?: []) : [];
                        $ok = is_array($list) && in_array($hash, $list, true);
                    }
                    if (! $ok) {
                        abort(403, "Dispositivo nao autorizado. Acesse o painel apenas de um aparelho selado.");
                    }
                }
            } catch (\Throwable $e) {
                // fail-open no erro de infra (nao trancar o Ruan por bug de DB)
            }
        }

        return $next($request);
    }
}
