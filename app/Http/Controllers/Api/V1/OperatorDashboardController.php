<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Supplier;
use App\Models\Order;
use App\Models\Plan;

class OperatorDashboardController extends Controller
{
    public function index()
    {
        $clientsTotal  = Client::count();
        $clientsActive = Client::whereHas('subscriptions', fn($q) =>
            $q->whereIn('status', ['active', 'trialing'])
        )->count();

        $suppliersActive = Supplier::where('is_active', true)->count();

        $ordersTotal = Order::count();
        $orders30d   = Order::where('created_at', '>=', now()->subDays(30))->count();

        $plansTotal = Plan::count();

        $recentClients = Client::with('user')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($c) => [
                'id'         => $c->id,
                'name'       => $c->company_name ?: ($c->user?->name ?? 'â€”'),
                'email'      => $c->user?->email ?? 'â€”',
                'created_at' => $c->created_at?->format('d/m/Y'),
            ]);

        return response()->json([
            'clients'        => ['total' => $clientsTotal,    'active' => $clientsActive],
            'suppliers'      => ['active' => $suppliersActive],
            'orders'         => ['total'  => $ordersTotal,     'last_30d' => $orders30d],
            'plans'          => ['total'  => $plansTotal],
            'recent_clients' => $recentClients,
        ]);
    }
}
