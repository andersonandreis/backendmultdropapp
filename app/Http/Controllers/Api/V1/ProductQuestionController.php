<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductQuestion;
use App\Models\Product;
use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductQuestionController extends Controller
{
    /** GET /api/v1/products/{product_id}/questions — public Q&A listing */
    public function index(int $productId): JsonResponse
    {
        $questions = ProductQuestion::where('product_id', $productId)
            ->public()
            ->with([
                'client:id,user_id',
                'client.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($q) => [
                'id'           => $q->id,
                'question'     => $q->question,
                'answer'       => $q->answer,
                'answered_at'  => $q->answered_at?->toISOString(),
                'asker_name'   => $q->client?->user?->name ?? 'Seller',
                'created_at'   => $q->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $questions]);
    }

    /** POST /api/v1/products/{product_id}/questions — seller asks question */
    public function store(Request $request, int $productId): JsonResponse
    {
        $user = Auth::user();
        $client = Client::where('user_id', $user->id)->first();
        if (!$client) return response()->json(['error' => 'Perfil de seller nao encontrado'], 403);

        $product = Product::findOrFail($productId);
        $data = $request->validate(['question' => 'required|string|min:5|max:1000']);

        $q = ProductQuestion::create([
            'product_id'  => $productId,
            'client_id'   => $client->id,
            'supplier_id' => $product->supplier_id,
            'question'    => trim($data['question']),
            'is_public'   => true,
        ]);

        return response()->json(['data' => ['id' => $q->id, 'question' => $q->question]], 201);
    }

    // ─── Supplier Admin ───────────────────────────────────────────────────────

    /** GET /api/v1/supplier-admin/questions — pending/all for this supplier */
    public function supplierIndex(Request $request): JsonResponse
    {
        $user = Auth::user();
        $supplier = Supplier::where('user_id', $user->id)->first();
        if (!$supplier) return response()->json(['error' => 'Perfil de fornecedor nao encontrado'], 403);

        $status = $request->query('status', 'pending'); // pending | answered | all
        $q = ProductQuestion::where('supplier_id', $supplier->id)
            ->with([
                'product:id,name,sku',
                'client:id,user_id',
                'client.user:id,name',
            ])
            ->orderByDesc('created_at');

        if ($status === 'pending')  $q->pending();
        if ($status === 'answered') $q->answered();

        $items = $q->paginate(20)->through(fn($item) => [
            'id'           => $item->id,
            'question'     => $item->question,
            'answer'       => $item->answer,
            'answered_at'  => $item->answered_at?->toISOString(),
            'asker_name'   => $item->client?->user?->name ?? 'Seller',
            'product_id'   => $item->product_id,
            'product_name' => $item->product?->name ?? '—',
            'product_sku'  => $item->product?->sku ?? '—',
            'created_at'   => $item->created_at?->toISOString(),
        ]);

        return response()->json(['data' => $items->items(), 'total' => $items->total()]);
    }

    /** PATCH /api/v1/supplier-admin/questions/{id} — answer */
    public function answer(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $supplier = Supplier::where('user_id', $user->id)->first();
        if (!$supplier) return response()->json(['error' => 'Perfil de fornecedor nao encontrado'], 403);

        $q = ProductQuestion::where('id', $id)->where('supplier_id', $supplier->id)->firstOrFail();
        $data = $request->validate(['answer' => 'required|string|min:2|max:2000']);

        $q->update([
            'answer'              => trim($data['answer']),
            'answered_at'         => now(),
            'answered_by_user_id' => $user->id,
        ]);

        return response()->json(['data' => ['id' => $q->id, 'answer' => $q->answer]]);
    }

    /** GET /api/v1/supplier-admin/questions/count — badge count (pending) */
    public function pendingCount(Request $request): JsonResponse
    {
        $user = Auth::user();
        $supplier = Supplier::where('user_id', $user->id)->first();
        if (!$supplier) return response()->json(['data' => ['count' => 0]]);

        $count = ProductQuestion::where('supplier_id', $supplier->id)->pending()->count();
        return response()->json(['data' => ['count' => $count]]);
    }

    /** PATCH /api/v1/supplier-admin/questions/{id}/visibility --- MUL-142-E #7 */
    public function setVisibility(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $supplier = Supplier::where('user_id', $user->id)->first();
        if (!$supplier && $user->role !== 'super_admin') {
            return response()->json(['error' => 'Acesso restrito.'], 403);
        }

        $data = $request->validate(['is_public' => 'required|boolean']);

        $q = ProductQuestion::when(! $user->is('super_admin'), function ($qq) use ($supplier) {
            $qq->where('supplier_id', $supplier->id);
        })->findOrFail($id);

        $q->update(['is_public' => (bool) $data['is_public']]);

        return response()->json(['data' => ['id' => $q->id, 'is_public' => $q->is_public]]);
    }

}
