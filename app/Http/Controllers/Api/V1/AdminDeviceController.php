<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEL (08/08 Ruan): trava de dispositivo do painel admin. Selar aparelho = abrir
 * o link secreto uma vez naquele aparelho -> cookie de confianca. So aparelhos
 * selados entram no admin (quando a flag admin_device_lock estiver ligada).
 */
class AdminDeviceController extends Controller
{
    public function seal(Request $request)
    {
        $code = (string) $request->query("code", "");
        $secret = (string) DB::table("settings")->where("key", "admin_trust_secret")->value("value");

        if ($secret === "" || ! hash_equals($secret, $code)) {
            return response("Codigo invalido.", 403);
        }

        $token = Str::random(48);
        $hash = hash("sha256", $token);

        $raw = DB::table("settings")->where("key", "admin_trusted_devices")->value("value");
        $list = $raw ? (json_decode($raw, true) ?: []) : [];
        if (! in_array($hash, $list, true)) { $list[] = $hash; }
        DB::table("settings")->updateOrInsert(
            ["key" => "admin_trusted_devices"],
            ["value" => json_encode(array_values($list)), "updated_at" => now()]
        );

        $html = "<html><body style=\"font-family:sans-serif;background:#0a0a0a;color:#d7ff60;text-align:center;padding:60px\">"
              . "<h1>Aparelho selado com sucesso</h1>"
              . "<p style=\"color:#c3c9ba\">Este aparelho agora pode acessar o painel admin. Pode fechar esta aba.</p>"
              . "</body></html>";

        // cookie 1 ano, Secure + SameSite=None (pra ir nas chamadas cross-site do
        // frontend seller.global -> api.seller.global), httpOnly (JS nao le).
        return response($html)
            ->cookie("sg_admin_dev", $token, 60 * 24 * 365, "/", null, true, true, false, "None");
    }
}
