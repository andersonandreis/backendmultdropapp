<?php

namespace App\Http\Controllers\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropStore;
use App\Models\Drop\DropOrder;
use App\Models\Drop\ImportedProduct;
use App\Models\Drop\StorePaymentGateway;
use App\Services\Drop\Payment\NativeCheckoutPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NativeStorefrontController extends Controller
{
    private function resolveStore(string $slug): DropStore
    {
        return DropStore::where('store_slug', $slug)
            ->where('platform', 'native')
            ->where('is_published', true)
            ->where('status', 'active')
            ->firstOrFail();
    }

    public function catalog(string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $products = ImportedProduct::where('drop_store_id', $store->id)
            ->where('status', 'published')
            ->select(['id', 'title', 'images', 'sell_price', 'cost_usd', 'shipping_usd', 'supplier_slug', 'external_supplier_id'])
            ->latest()
            ->paginate(24);

        $products->getCollection()->transform(function ($p) {
            $images = json_decode($p->images, true) ?? [];
            return [
                'id'        => $p->id,
                'title'     => $p->title,
                'image'     => $images[0] ?? null,
                'price_brl' => number_format($p->sell_price, 2, ',', '.'),
                'price_raw' => (float) $p->sell_price,
            ];
        });

        return response()->json([
            'store' => [
                'name'          => $store->store_display_name,
                'logo_url'      => $store->logo_url,
                'banner_url'    => $store->banner_url,
                'primary_color' => $store->primary_color,
                'slug'          => $store->store_slug,
            ],
            'products' => $products,
        ]);
    }

    public function product(string $slug, int $productId): JsonResponse
    {
        $store   = $this->resolveStore($slug);
        $product = ImportedProduct::where('id', $productId)
            ->where('drop_store_id', $store->id)
            ->where('status', 'published')
            ->firstOrFail();

        $images   = json_decode($product->images, true) ?? [];
        $variants = json_decode($product->variants_data, true) ?? [];

        return response()->json([
            'store' => [
                'name'          => $store->store_display_name,
                'primary_color' => $store->primary_color,
                'slug'          => $store->store_slug,
            ],
            'product' => [
                'id'          => $product->id,
                'title'       => $product->title_ai ?? $product->title,
                'description' => $product->description_ai ?? $product->description,
                'images'      => $images,
                'price_raw'   => (float) $product->sell_price,
                'price_brl'   => number_format($product->sell_price, 2, ',', '.'),
                'variants'    => $variants,
            ],
            'payment_methods' => $this->getPublicGateways($store),
        ]);
    }

    public function checkout(Request $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $data = $request->validate([
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.variant_sku'=> ['nullable', 'string'],
            'customer.name'      => ['required', 'string', 'max:150'],
            'customer.email'     => ['required', 'email'],
            'customer.phone'     => ['required', 'string'],
            'customer.cpf'       => ['required', 'string'],
            'shipping.zip'       => ['required', 'string'],
            'shipping.address'   => ['required', 'string'],
            'shipping.city'      => ['required', 'string'],
            'shipping.state'     => ['required', 'string', 'size:2'],
            'payment.method'     => ['required', 'string'],
            'payment.gateway'    => ['nullable', 'string'],
            'payment.card_token' => ['nullable', 'string'],
        ]);

        $total      = 0;
        $orderItems = [];

        foreach ($data['items'] as $item) {
            $product = ImportedProduct::where('id', $item['product_id'])
                ->where('drop_store_id', $store->id)
                ->where('status', 'published')
                ->firstOrFail();

            $qty   = $item['quantity'];
            $price = (float) $product->sell_price;
            $total += $price * $qty;

            $orderItems[] = [
                'product_id'    => $product->id,
                'title'         => $product->title_ai ?? $product->title,
                'quantity'      => $qty,
                'unit_price'    => $price,
                'subtotal'      => $price * $qty,
                'supplier_slug' => $product->supplier_slug,
                'external_id'   => $product->external_supplier_id,
                'variant_sku'   => $item['variant_sku'] ?? null,
            ];
        }

        $order = DropOrder::create([
            'client_id'       => $store->client_id,
            'drop_store_id'   => $store->id,
            'order_key'       => Str::uuid()->toString(),
            'customer_name'   => $data['customer']['name'],
            'customer_email'  => $data['customer']['email'],
            'customer_phone'  => preg_replace('/\D/', '', $data['customer']['phone']),
            'customer_cpf'    => preg_replace('/\D/', '', $data['customer']['cpf']),
            'shipping_zip'    => preg_replace('/\D/', '', $data['shipping']['zip']),
            'shipping_address'=> $data['shipping']['address'],
            'shipping_city'   => $data['shipping']['city'],
            'shipping_state'  => $data['shipping']['state'],
            'items_json'      => json_encode($orderItems),
            'total_amount'    => $total,
            'payment_method'  => $data['payment']['method'],
            'status'          => 'pending_payment',
            'source'          => 'native_store',
            'currency'        => 'BRL',
        ]);

        // Processar pagamento imediatamente
        $paymentService = app(NativeCheckoutPaymentService::class);
        $paymentResult  = $paymentService->processPayment($order, [
            'gateway_type' => $data['payment']['gateway'] ?? null,
            'method'       => $data['payment']['method'],
            'card_token'   => $data['payment']['card_token'] ?? null,
        ]);

        return response()->json([
            'order_key'    => $order->order_key,
            'total_brl'    => number_format($total, 2, ',', '.'),
            'total_raw'    => $total,
            'order_status' => $order->fresh()->status,
            'payment'      => $paymentResult,
            'tracking_url' => url("/loja/{$slug}/pedido/{$order->order_key}"),
        ], 201);
    }

    public function orderStatus(string $slug, string $orderKey): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $order = DropOrder::where('order_key', $orderKey)
            ->where('drop_store_id', $store->id)
            ->firstOrFail();

        $statusLabel = match ($order->status) {
            'pending_payment'  => 'Aguardando pagamento',
            'payment_received' => 'Pago — processando pedido',
            'awaiting_supplier'=> 'Enviando ao fornecedor',
            'ordered_supplier' => 'Pedido no fornecedor',
            'shipped'          => 'Em transporte',
            'delivered'        => 'Entregue',
            'cancelled'        => 'Cancelado',
            default            => $order->status,
        };

        return response()->json([
            'order_key'     => $order->order_key,
            'status'        => $order->status,
            'status_label'  => $statusLabel,
            'total_brl'     => number_format($order->total_amount, 2, ',', '.'),
            'tracking_code' => $order->tracking_code ?? null,
            'created_at'    => $order->created_at?->format('d/m/Y H:i'),
        ]);
    }

    private function getPublicGateways(DropStore $store): array
    {
        return $store->paymentGateways()
            ->where('is_active', true)
            ->get()
            ->map(fn ($g) => [
                'type'       => $g->gateway_type,
                'label'      => match ($g->gateway_type) {
                    'pagarme'     => 'PIX, Boleto, Cartao',
                    'stripe'      => 'Cartao Internacional',
                    'mercadopago' => 'Mercado Pago',
                    'pix_manual'  => 'PIX Manual',
                    default       => $g->gateway_type,
                },
                'is_default' => $g->is_default,
            ])
            ->toArray();
    }
}
