<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Slug;

class SlugController extends Controller
{
    /**
     * Resolve incoming dynamic URL paths.
     */
    public function resolve($path)
    {
        $slug = Slug::with('sluggable')
            ->where('slug', $path)
            ->first();

        if (!$slug) {
            abort(404);
        }

        if (!$slug->is_canonical) {
            $canonicalUrl = url($slug->sluggable->slugs()->where('is_canonical', true)->value('slug'));
            return redirect()->to($canonicalUrl, 301);
        }

        $entity = $slug->sluggable;
        $class = get_class($entity);

        // TODO: Mapear as Views (ex: return view('store.product', compact('entity'));)
        return response()->json([
            'type' => class_basename($class),
            'id' => $entity->id,
            'data' => $entity
        ]);
    }
}
