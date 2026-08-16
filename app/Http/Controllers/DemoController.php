<?php

namespace App\Http\Controllers;

use App\Models\SimulationConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DemoController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $config = SimulationConfig::where('slug', $slug)->first();

        if ($config) {
            $storeName      = $config->store_name    ?? 'Minha Loja';
            $revenue        = (float)  ($config->revenue        ?? 15000);
            $ordersToday    = (int)    ($config->orders_today   ?? 20);
            $monthlySales   = (int)    ($config->monthly_sales  ?? 0);
            $activeProducts = (int)    ($config->active_products ?? 0);
            $storeLink      = $config->store_link    ?? '';
            $marketplace    = $config->marketplace   ?? 'shopee';
            $rawProducts    = $config->product_links ?? [];
        } elseif ($request->hasAny(['s','r','o','m','p','mp'])) {
            $storeName      = $request->query('s', 'Minha Loja');
            $revenue        = (float)  $request->query('r', 15000);
            $ordersToday    = (int)    $request->query('o', 20);
            $monthlySales   = (int)    $request->query('m', 0);
            $activeProducts = (int)    $request->query('p', 0);
            $storeLink      = $request->query('l', '');
            $marketplace    = $request->query('mp', 'shopee');
            $rawProducts    = [];
        } else {
            return response()->view('demo.not_found', ['slug' => $slug], 200);
        }

        $products = [];
        if (empty($rawProducts)) {
            $rows = DB::table('products')
                ->leftJoin('product_media', function ($join) {
                    $join->on('product_media.product_id', '=', 'products.id')
                         ->where('product_media.is_cover', 1);
                })
                ->select('products.name', 'products.price', 'product_media.url as image_url')
                ->where('products.is_active', 1)
                ->orderBy('products.id')
                ->limit(5)
                ->get();
            foreach ($rows as $row) {
                $products[] = [
                    'name'      => $row->name,
                    'price'     => (float) $row->price,
                    'image_url' => $row->image_url ?? '',
                    'link'      => '',
                ];
            }
        } else {
            foreach ($rawProducts as $p) {
                $products[] = [
                    'name'      => $p['name']      ?? '',
                    'price'     => (float) ($p['price'] ?? 0),
                    'image_url' => $p['image_url'] ?? '',
                    'link'      => $p['link']      ?? '',
                ];
            }
        }

        return view('demo.show', compact(
            'storeName', 'revenue', 'ordersToday', 'monthlySales',
            'activeProducts', 'storeLink', 'marketplace', 'products'
        ));
    }
}
