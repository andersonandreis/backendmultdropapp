<?php

namespace App\Http\Controllers\TenantApi\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GET /v1/events?since=<iso8601>
 * Fallback de polling para clientes que perderam webhooks.
 * Le order_audit_log do tenant.
 */
class EventController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $q = DB::table('order_audit_log as a')
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('o.tenant_id', $tenantId)
            ->orderBy('a.at', 'desc')
            ->orderBy('a.id', 'desc');

        if ($since = $request->query('since')) {
            try { $q->where('a.at', '>=', Carbon::parse($since)); } catch (\Throwable $e) {}
        }
        $limit = (int) min(max((int) $request->query('limit', 50), 1), 200);

        $rows = $q->limit($limit)->get([
            'a.id', 'a.order_id', 'a.action', 'a.from_state', 'a.to_state',
            'a.actor_type', 'a.at', 'o.order_number',
        ]);

        return response()->json([
            'data' => $rows->map(fn($r) => [
                'id'           => $r->id,
                'order_id'     => $r->order_id,
                'order_number' => $r->order_number,
                'action'       => $r->action,
                'from'         => $r->from_state,
                'to'           => $r->to_state,
                'actor'        => $r->actor_type,
                'at'           => $r->at,
            ])->values(),
        ]);
    }
}
