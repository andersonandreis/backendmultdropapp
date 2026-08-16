<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ShopeeStatsController extends Controller
{
    private string $baseUrl = "https://shopeevendas.com";

    public function recentProducts(): JsonResponse
    {
        $token = Cache::remember("shopee_stats_token", 3600, fn () => $this->authenticate());

        if (!$token) {
            return response()->json(["error" => "Falha ao autenticar no ShopeeStats"], 503);
        }

        $response = Http::withHeaders([
            "Cookie"       => "auth-token={$token}",
            "Content-Type" => "application/json",
        ])->post("{$this->baseUrl}/api/vendas", [
            "modo"          => "hoje",
            "marketplace"   => config("services.shopee_stats.marketplace", "3"),
            "dias_anterior" => 1,
        ]);

        if ($response->status() === 401 || $response->status() === 403) {
            Cache::forget("shopee_stats_token");
            $token = $this->authenticate();
            if (!$token) {
                return response()->json(["error" => "Token expirado e re-autenticação falhou"], 503);
            }
            Cache::put("shopee_stats_token", $token, 3600);
            $response = Http::withHeaders([
                "Cookie"       => "auth-token={$token}",
                "Content-Type" => "application/json",
            ])->post("{$this->baseUrl}/api/vendas", [
                "modo"          => "hoje",
                "marketplace"   => config("services.shopee_stats.marketplace", "3"),
                "dias_anterior" => 1,
            ]);
        }

        if (!$response->successful()) {
            return response()->json(["error" => "ShopeeStats indisponível"], 503);
        }

        $data = $response->json();
        $vendas = $data["hoje"]["ultimas_vendas"] ?? [];

        $products = collect($vendas)->map(fn ($v) => [
            "sku"        => $v["sku"],
            "name"       => $v["descricao"],
            "image"      => $v["foto"],
            "price"      => (float) $v["valor_total"],
            "sku_pai"    => $v["id_sku_pai"],
            "source"     => "shopee",
        ])->values();

        return response()->json(["data" => $products]);
    }

    private function authenticate(): ?string
    {
        $res = Http::post("{$this->baseUrl}/api/auth/login", [
            "email"    => config("services.shopee_stats.email"),
            "password" => config("services.shopee_stats.password"),
        ]);

        if (!$res->successful()) {
            return null;
        }

        // Extrai o token do Set-Cookie
        $setCookie = $res->header("Set-Cookie");
        if (preg_match("/auth-token=([^;]+)/", $setCookie, $m)) {
            return $m[1];
        }

        return null;
    }
}
