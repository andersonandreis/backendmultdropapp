<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SimulationConfig;
use App\Models\Affiliate;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    public function show(Request $request)
    {
        $user   = $request->user();
        $config = SimulationConfig::where('user_id', $user->id)->first();

        if (! $config) {
            $slug   = $this->defaultSlug($user);
            $config = SimulationConfig::create([
                'user_id'           => $user->id,
                'slug'              => $slug,
                'revenue_per_month' => 50000,
                'orders_per_day'    => 30,
                'store_name'        => $user->name ?? 'Minha Loja',
                'store_link'        => null,
                'label_enabled'     => true,
                'product_links'     => null,
            ]);
        }

        return response()->json([
            'success'  => true,
            'data'     => $config,
            'demo_url' => url('/demo/' . $config->slug),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'revenue_per_month'    => 'sometimes|numeric|min:0',
            'orders_per_day'       => 'sometimes|integer|min:0',
            'store_name'           => 'sometimes|string|max:255',
            'store_link'           => 'sometimes|nullable|url|max:500',
            'label_enabled'        => 'sometimes|boolean',
            'product_links'        => 'sometimes|nullable|array',
            'product_links.*.name' => 'required_with:product_links|string|max:255',
            'product_links.*.url'  => 'required_with:product_links|url|max:500',
        ]);

        $config = SimulationConfig::where('user_id', $user->id)->first();

        if ($config) {
            $config->update($validated);
        } else {
            $slug   = $this->defaultSlug($user);
            $config = SimulationConfig::create(array_merge([
                'user_id'           => $user->id,
                'slug'              => $slug,
                'revenue_per_month' => 50000,
                'orders_per_day'    => 30,
                'store_name'        => $user->name ?? 'Minha Loja',
                'store_link'        => null,
                'label_enabled'     => true,
                'product_links'     => null,
            ], $validated));
        }

        return response()->json([
            'success'  => true,
            'data'     => $config->fresh(),
            'demo_url' => url('/demo/' . $config->slug),
        ]);
    }

    private function defaultSlug($user): string
    {
        $affiliate = Affiliate::where('user_id', $user->id)->first();
        $base = $affiliate ? strtolower($affiliate->referral_code) : 'user-' . $user->id;

        $slug = $base;
        $i    = 1;
        while (SimulationConfig::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
