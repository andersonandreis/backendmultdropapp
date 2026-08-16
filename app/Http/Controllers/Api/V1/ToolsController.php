<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ToolsController extends Controller
{
    public function trends(Request $request)
    {
        $categoria = $request->query('categoria', '');

        $cacheKey = 'multdrop_trends_' . md5($categoria);

        $trends = Cache::remember($cacheKey, 3600, function () use ($categoria) {
            $query = DB::table('products')
                ->where('is_active', true)
                ->whereNotNull('name');

            if ($categoria) {
                $query->where('category_id', function ($sub) use ($categoria) {
                    $sub->select('id')->from('categories')->where('name', 'like', '%' . $categoria . '%')->limit(1);
                });
            }

            $words = [];
            $names = $query->pluck('name')->take(500);

            $stopwords = ['de', 'da', 'do', 'para', 'com', 'em', 'e', 'a', 'o', 'as', 'os', 'um', 'uma', 'por', 'que', 'no', 'na', 'ao', 'ou'];

            foreach ($names as $name) {
                $tokens = preg_split('/[\s\-\/]+/', mb_strtolower($name));
                foreach ($tokens as $token) {
                    $token = trim($token, '.,!?()[]"\'');
                    if (mb_strlen($token) >= 4 && !in_array($token, $stopwords)) {
                        $words[$token] = ($words[$token] ?? 0) + 1;
                    }
                }
            }

            arsort($words);
            $top = array_slice($words, 0, 20, true);

            $pos = 1;
            return array_map(function ($keyword, $count) use (&$pos) {
                return [
                    'keyword' => ucfirst($keyword),
                    'url' => 'https://lista.mercadolivre.com.br/' . urlencode($keyword),
                    'position' => $pos++,
                    'searches' => $count,
                ];
            }, array_keys($top), array_values($top));
        });

        return response()->json($trends);
    }
}
