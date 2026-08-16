<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogDiscountRule;
use App\Models\Client;
use App\Models\Order;
use App\Models\PixTransaction;
use App\Models\Plan;
use App\Models\Supplier;
use App\Models\SupplierMessage;
use App\Models\SupplierSmtpConfig;
use App\Models\SupplierWarehouse;
use App\Services\Mail\SupplierMailerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NOV-141..148 — Endpoints das features novas do painel admin do supplier.
 *
 * Agrupa SMTP, planos, top clients, descontos catalogo, depositos,
 * validacao manual PIX, 2FA por WL e log de mensagens.
 */
class SupplierFeaturesController extends Controller
{
    public function __construct(private SupplierMailerService $mailer)
    {
    }

    /**
     * Resolve supplier_id do request (auth ou X-Tenant-Slug).
     */
    private function resolveSupplierId(Request $request): ?int
    {
        $user = $request->user();
        if ($user && in_array($user->role, ['supplier', 'admin'])) {
            return $user->supplier?->id;
        }
        if ($user && $user->role === 'super_admin') {
            $tenantSlug = $request->header('X-Tenant-Slug');
            if ($tenantSlug) {
                $id = DB::table('tenant_supplier')
                    ->join('tenants', 'tenants.id', '=', 'tenant_supplier.tenant_id')
                    ->where('tenants.slug', $tenantSlug)
                    ->orderBy('tenant_supplier.supplier_id')
                    ->value('tenant_supplier.supplier_id');
                if ($id) {
                    return (int) $id;
                }
            }
            return (int) config('multdrop.supplier_id', 30);
        }
        return null;
    }

    private function requireSupplierAdmin(Request $request): int
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['super_admin', 'admin', 'supplier'])) {
            abort(403, 'Apenas admin do fornecedor.');
        }
        $id = $this->resolveSupplierId($request);
        if (!$id) {
            abort(403, 'supplier_nao_resolvido');
        }
        return $id;
    }

    // =========================================================================
    // NOV-141 — SMTP per-WL
    // =========================================================================

    /** GET /api/v1/supplier-admin/smtp-config */
    public function smtpShow(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $config = SupplierSmtpConfig::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->first();
        return response()->json(['data' => $config]);
    }

    /** PUT /api/v1/supplier-admin/smtp-config */
    public function smtpUpdate(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'smtp_host'       => 'required|string|max:255',
            'smtp_port'       => 'required|integer|min:1|max:65535',
            'smtp_user'       => 'required|string|max:255',
            'smtp_password'   => 'nullable|string|max:500',
            'smtp_from_name'  => 'nullable|string|max:255',
            'smtp_from_email' => 'nullable|email|max:255',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'active'          => 'boolean',
        ]);

        $config = SupplierSmtpConfig::withoutTenantSupplierScope()
            ->firstOrNew(['supplier_id' => $supplierId]);

        // Nao sobrescreve senha se vier vazia (mantem a existente)
        if (empty($validated['smtp_password'])) {
            unset($validated['smtp_password']);
        }
        $config->fill($validated);
        $config->supplier_id = $supplierId;
        $config->save();

        return response()->json(['data' => $config->fresh()]);
    }

    /** POST /api/v1/supplier-admin/smtp-config/test */
    public function smtpTest(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $config = SupplierSmtpConfig::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->first();
        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'config_not_found'], 404);
        }

        $ok = $this->mailer->testConnection($config);

        return response()->json([
            'ok'    => $ok,
            'data'  => $config->fresh(),
            'error' => $ok ? null : $config->fresh()->last_test_error,
        ]);
    }

    /** POST /api/v1/supplier-admin/smtp-config/send-test */
    public function smtpSendTest(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated  = $request->validate(['to' => 'required|email']);

        $config = SupplierSmtpConfig::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->first();
        if (!$config) {
            return response()->json(['ok' => false, 'error' => 'config_not_found'], 404);
        }

        $ok = $this->mailer->sendTestEmail($config, $validated['to']);
        return response()->json(['ok' => $ok]);
    }

    // =========================================================================
    // NOV-142 — Planos (listar com usuarios)
    // =========================================================================

    /** GET /api/v1/supplier-admin/plans */
    public function plansIndex(Request $request): JsonResponse
    {
        $this->requireSupplierAdmin($request);

        // subscriptions e a tabela canonica de cliente->plano no NovoHubAI;
        // users NAO tem plan_id (lib de auth do Filament).
        $plans = Plan::query()
            ->orderBy('price_monthly')
            ->get()
            ->map(function (Plan $p) {
                $usersCount = DB::table('subscriptions')
                    ->where('plan_id', $p->id)
                    ->where('status', 'active')
                    ->count();
                return [
                    'id'                          => $p->id,
                    'name'                        => $p->name,
                    'slug'                        => $p->slug,
                    'description'                 => $p->description,
                    'max_skus'                    => $p->max_skus,
                    'max_marketplace_connections' => $p->max_marketplace_connections,
                    'max_erp_connections'         => $p->max_erp_connections,
                    'max_integrations'            => $p->max_integrations ?? null,
                    'has_drop_internacional'      => (bool) $p->has_drop_internacional,
                    'price_monthly'               => (float) $p->price_monthly,
                    'price_yearly'                => (float) $p->price_yearly,
                    'trial_days'                  => $p->trial_days,
                    'affiliate_commission_percent'=> (float) $p->affiliate_commission_percent,
                    'is_active'                   => (bool) $p->is_active,
                    'users_count'                 => $usersCount,
                ];
            });

        return response()->json(['data' => $plans]);
    }

    // =========================================================================
    // NOV-143 — Top clients
    // =========================================================================

    /** GET /api/v1/supplier-admin/reports/top-clients?period=30&limit=50 */
    public function topClients(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $period = max(1, (int) $request->input('period', 30));
        $limit  = min(500, max(1, (int) $request->input('limit', 50)));

        // MUL-269 fase 2: nome do seller vem 100% do user (clients.company_name removido).
        // company_name no response mantido pra compat com o front (mesma fonte que name).
        $rows = DB::table('orders as o')
            ->join('clients as c', 'c.id', '=', 'o.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('o.supplier_id', $supplierId)
            ->where('o.created_at', '>=', now()->subDays($period))
            ->whereNotIn('o.status', ['cancelled', 'pending_payment'])
            ->select(
                'c.id',
                DB::raw("COALESCE(NULLIF(u.full_name,''), u.name) as name"),
                'u.email',
                DB::raw("COALESCE(NULLIF(u.full_name,''), u.name) as company_name"),
                'c.document',
                'c.phone',
                DB::raw('COUNT(o.id) as total_orders'),
                DB::raw('COALESCE(SUM(o.total), 0) as total_value'),
                DB::raw('MAX(o.created_at) as last_order_at')
            )
            ->groupBy('c.id', 'u.full_name', 'u.name', 'u.email', 'c.document', 'c.phone')
            ->orderByDesc('total_value')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'period_days' => $period,
                'limit'       => $limit,
                'count'       => $rows->count(),
            ],
        ]);
    }

    // =========================================================================
    // NOV-144 — Catalog discount rules
    // =========================================================================

    public function discountsIndex(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $rules = CatalogDiscountRule::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->get();
        return response()->json(['data' => $rules]);
    }

    public function discountsStore(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'catalog_id'   => 'nullable|integer',
            'name'         => 'nullable|string|max:255',
            'min_qty'      => 'required|integer|min:1',
            'max_qty'      => 'nullable|integer|min:1',
            'discount_pct' => 'required|numeric|min:0|max:100',
            'active'       => 'boolean',
            'starts_at'    => 'nullable|date',
            'ends_at'      => 'nullable|date|after_or_equal:starts_at',
        ]);
        $validated['supplier_id'] = $supplierId;
        $rule = CatalogDiscountRule::create($validated);
        return response()->json(['data' => $rule], 201);
    }

    public function discountsUpdate(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $rule = CatalogDiscountRule::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)->findOrFail($id);
        $validated = $request->validate([
            'catalog_id'   => 'nullable|integer',
            'name'         => 'nullable|string|max:255',
            'min_qty'      => 'sometimes|integer|min:1',
            'max_qty'      => 'nullable|integer|min:1',
            'discount_pct' => 'sometimes|numeric|min:0|max:100',
            'active'       => 'sometimes|boolean',
            'starts_at'    => 'nullable|date',
            'ends_at'      => 'nullable|date|after_or_equal:starts_at',
        ]);
        $rule->update($validated);
        return response()->json(['data' => $rule->fresh()]);
    }

    public function discountsDestroy(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $rule = CatalogDiscountRule::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)->findOrFail($id);
        $rule->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    // =========================================================================
    // NOV-145 — Supplier warehouses
    // =========================================================================

    public function warehousesIndex(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $rows = SupplierWarehouse::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function warehousesStore(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'legacy_deposito_id' => 'nullable|integer',
            'name'               => 'required|string|max:255',
            'address'            => 'nullable|string|max:255',
            'number'             => 'nullable|string|max:50',
            'complement'         => 'nullable|string|max:255',
            'district'           => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:255',
            'state'              => 'nullable|string|size:2',
            'zip_code'           => 'nullable|string|max:16',
            'contact_name'       => 'nullable|string|max:255',
            'contact_phone'      => 'nullable|string|max:50',
            'contact_email'      => 'nullable|email|max:255',
            'active'             => 'boolean',
            'is_default'         => 'boolean',
        ]);
        $validated['supplier_id'] = $supplierId;

        // Garante apenas um default
        if (!empty($validated['is_default'])) {
            SupplierWarehouse::withoutTenantSupplierScope()
                ->where('supplier_id', $supplierId)
                ->update(['is_default' => false]);
        }
        $wh = SupplierWarehouse::create($validated);
        return response()->json(['data' => $wh], 201);
    }

    public function warehousesUpdate(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $wh = SupplierWarehouse::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)->findOrFail($id);
        $validated = $request->validate([
            'legacy_deposito_id' => 'nullable|integer',
            'name'               => 'sometimes|string|max:255',
            'address'            => 'nullable|string|max:255',
            'number'             => 'nullable|string|max:50',
            'complement'         => 'nullable|string|max:255',
            'district'           => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:255',
            'state'              => 'nullable|string|size:2',
            'zip_code'           => 'nullable|string|max:16',
            'contact_name'       => 'nullable|string|max:255',
            'contact_phone'      => 'nullable|string|max:50',
            'contact_email'      => 'nullable|email|max:255',
            'active'             => 'sometimes|boolean',
            'is_default'         => 'sometimes|boolean',
        ]);
        if (!empty($validated['is_default'])) {
            SupplierWarehouse::withoutTenantSupplierScope()
                ->where('supplier_id', $supplierId)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }
        $wh->update($validated);
        return response()->json(['data' => $wh->fresh()]);
    }

    public function warehousesDestroy(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $wh = SupplierWarehouse::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)->findOrFail($id);
        $wh->delete();
        return response()->json(['data' => ['deleted' => true]]);
    }

    // =========================================================================
    // NOV-146 — PIX manual confirm
    // =========================================================================

    /** POST /api/v1/supplier-admin/pix/{id}/confirm-manual */
    public function pixConfirmManual(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated  = $request->validate(['note' => 'nullable|string|max:1000']);

        $pix = PixTransaction::where('supplier_id', $supplierId)->findOrFail($id);

        if ($pix->status === 'paid' || $pix->manually_confirmed_at) {
            return response()->json([
                'ok'    => false,
                'data'  => $pix,
                'error' => 'already_confirmed',
            ], 409);
        }

        DB::transaction(function () use ($pix, $request, $validated) {
            $pix->update([
                'status'                    => 'paid',
                'paid_at'                   => now(),
                'manually_confirmed_at'     => now(),
                'confirmed_by_user_id'      => $request->user()?->id,
                'manual_confirmation_note'  => $validated['note'] ?? null,
            ]);

            // Marca order como paid se for order_payment
            if ($pix->type === 'order_payment' && $pix->order_id) {
                Order::where('id', $pix->order_id)
                    ->whereIn('status', ['pending_payment', 'pending'])
                    ->update(['status' => 'paid', 'paid_at' => now()]);
            }
        });

        return response()->json(['ok' => true, 'data' => $pix->fresh()]);
    }

    // =========================================================================
    // NOV-147 — 2FA obrigatorio por WL
    // =========================================================================

    /** PUT /api/v1/supplier-admin/security/two-factor-required */
    public function twoFactorRequired(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated = $request->validate(['required' => 'required|boolean']);
        $supplier = Supplier::findOrFail($supplierId);
        $supplier->update(['two_factor_required' => $validated['required']]);
        return response()->json([
            'ok' => true,
            'data' => [
                'supplier_id'         => $supplier->id,
                'two_factor_required' => (bool) $supplier->two_factor_required,
            ],
        ]);
    }

    // =========================================================================
    // NOV-148 — Log de mensagens
    // =========================================================================

    /** GET /api/v1/supplier-admin/messages */
    public function messagesIndex(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);

        $query = SupplierMessage::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId);

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $rows = $query->orderByDesc('id')->paginate(min(100, (int) $request->input('per_page', 25)));
        return response()->json($rows);
    }

    /** POST /api/v1/supplier-admin/messages */
    public function messagesStore(Request $request): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $validated = $request->validate([
            'recipient_type' => 'required|in:all,client,segment',
            'recipient_id'   => 'nullable|integer',
            'channel'        => 'required|in:email,sms,push,whatsapp',
            'subject'        => 'nullable|string|max:255',
            'body'           => 'required|string',
        ]);

        $validated['supplier_id']        = $supplierId;
        $validated['status']             = 'pending';
        $validated['created_by_user_id'] = $request->user()?->id;

        // Pre-calculo da audiencia.
        // clients e relacionado a suppliers via tabela pivot client_supplier (N:N).
        if ($validated['recipient_type'] === 'all') {
            $validated['recipients_count'] = DB::table('client_supplier')
                ->where('supplier_id', $supplierId)
                ->distinct()
                ->count('client_id');
        } elseif ($validated['recipient_type'] === 'client' && !empty($validated['recipient_id'])) {
            $validated['recipients_count'] = 1;
        } else {
            $validated['recipients_count'] = 0;
        }

        $msg = SupplierMessage::create($validated);

        // O dispatch real para fila de envio sera implementado em sprint
        // dedicado (NOV-148 backend dispatch). Por ora persiste como pending.

        return response()->json(['data' => $msg], 201);
    }

    /** GET /api/v1/supplier-admin/messages/{id} */
    public function messagesShow(Request $request, int $id): JsonResponse
    {
        $supplierId = $this->requireSupplierAdmin($request);
        $msg = SupplierMessage::withoutTenantSupplierScope()
            ->where('supplier_id', $supplierId)->findOrFail($id);
        return response()->json(['data' => $msg]);
    }
}
