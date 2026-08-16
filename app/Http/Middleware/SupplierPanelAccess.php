<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MUL-151 — Controle de acesso ao painel do FORNECEDOR.
 *
 * Role supplier pode acessar as 6 seções do painel fornecedor:
 *   Pedidos    → /supplier-admin/orders*
 *   Picking    → /supplier-admin/picking/* + /supplier-admin/packing* + /supplier-admin/verify-supervisor
 *   Produtos   → /supplier-admin/products* + /supplier-admin/ai-settings + /supplier-admin/categories*
 *               + /supplier-admin/integrations + /supplier-admin/returns*
 *   Pgto Shopee → /admin/shopee/* (ShopeePaymentController aceita supplier)
 *   Entrega Direta → incluído em orders (source=shopee) e /supplier-admin/orders/*
 *   Chamados   → /supplier/tickets + /tickets
 *
 * Role supplier NÃO pode acessar:
 *   Configurações → smtp-config, security, payment-gateway, warehouses, catalog-discounts,
 *                   banners, messages, pix/confirm-manual, plans, reports
 *   Clientes      → /supplier-admin/clients
 *   Financeiro    → /admin/clients, /admin/plans, /admin/marketplace-fees,
 *                   /admin/settings, /admin/suppliers, /admin/affiliates,
 *                   /admin/platform-settings, /admin/sync, /admin/dashboard,
 *                   /admin/orders, /admin/integrations, /admin/impersonate, /admin/monitor
 *
 * Roles super_admin e admin passam sem restrição.
 */
class SupplierPanelAccess
{
    /**
     * Prefixos de path (após /api/) bloqueados para role=supplier.
     * Verificação: str_starts_with($path, $prefix)
     * Onde $path = request()->path() sem o prefixo "api/"
     */
    private const DENIED_PREFIXES_FOR_SUPPLIER = [
        // ── Clientes do fornecedor ──────────────────────────────────────────
        'v1/supplier-admin/clients',

        // ── Configurações (smtp, 2FA, mensagens, gateway, etc.) ─────────────
        'v1/supplier-admin/payment-gateway',
        'v1/supplier-admin/smtp-config',
        'v1/supplier-admin/messages',
        'v1/supplier-admin/security',
        'v1/supplier-admin/banners',
        'v1/supplier-admin/warehouses',
        'v1/supplier-admin/catalog-discounts',
        'v1/supplier-admin/pix',
        'v1/supplier-admin/plans',
        'v1/supplier-admin/reports',

        // ── Painel admin global (/v1/admin/*) — exceto /v1/admin/shopee/* ──
        // Clientes, planos, taxas, settings, suppliers, affiliates, platform, sync, etc.
        'v1/admin/clients',
        'v1/admin/dashboard',
        'v1/admin/orders',
        'v1/admin/plans',
        'v1/admin/marketplace-fees',
        'v1/admin/settings',
        'v1/admin/suppliers',
        'v1/admin/sync',
        'v1/admin/affiliates',
        'v1/admin/platform-settings',
        'v1/admin/impersonate',
        'v1/admin/integrations',
        'v1/admin/monitor',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sem autenticação: deixa passar (auth:sanctum cuida antes)
        if (! $user) {
            return $next($request);
        }

        // super_admin e admin: acesso total sem restrição
        if (in_array($user->role, ['super_admin', 'admin'])) {
            return $next($request);
        }

        // Role supplier: verificar se a rota está na lista negada
        if ($user->role === 'supplier') {
            // path() retorna "api/v1/supplier-admin/clients/5" (sem barra inicial)
            $path = ltrim($request->path(), '/');
            // Remover prefixo "api/" para comparar com DENIED_PREFIXES_FOR_SUPPLIER
            $path = preg_replace('#^api/#', '', $path);

            foreach (self::DENIED_PREFIXES_FOR_SUPPLIER as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    abort(403, 'Acesso negado. Fornecedores não têm permissão para acessar esta seção.');
                }
            }
        }

        return $next($request);
    }
}
