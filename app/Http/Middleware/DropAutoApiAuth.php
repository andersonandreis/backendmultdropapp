<?php
namespace App\Http\Middleware;

use App\Models\TenantApiCredential;
use App\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Middleware de autenticação para a API Drop Autopeças.
 * Reutiliza o mesmo token TenantApiCredential do tenant API genérico,
 * mas expõe supplier_id diretamente no request para os controllers.
 */
class DropAutoApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(ht_live_[A-Za-z0-9]+)\.([A-Za-z0-9]+)$/', $auth, $m)) {
            return response()->json(['erro' => 'Token de acesso inválido ou ausente'], 401);
        }
        [$_, $keyId, $secret] = $m;

        $cred = TenantApiCredential::with('tenant')->where('key_id', $keyId)->first();
        if (!$cred || $cred->isRevoked() || !$cred->tenant) {
            return response()->json(['erro' => 'Não autorizado'], 401);
        }
        if (!password_verify($secret, $cred->key_hash)) {
            return response()->json(['erro' => 'Não autorizado'], 401);
        }
        if ($cred->tenant->status === 'suspended') {
            return response()->json(['erro' => 'Conta suspensa'], 403);
        }

        // Resolve supplier_id principal do tenant
        $supplierId = DB::table('tenant_supplier')
            ->where('tenant_id', $cred->tenant->id)
            ->value('supplier_id');

        if (!$supplierId) {
            return response()->json(['erro' => 'Nenhum fornecedor vinculado a este token'], 403);
        }

        $request->attributes->set('tenant_id',   $cred->tenant->id);
        $request->attributes->set('supplier_id',  (int) $supplierId);
        $request->attributes->set('tenant_slug',  $cred->tenant->slug);

        $cred->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
