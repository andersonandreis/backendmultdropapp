<?php

namespace App\Http\Controllers\Api\V1\Drop;

use App\Http\Controllers\Controller;
use App\Models\Drop\DropStore;
use App\Models\Drop\StorePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NativeStoreController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        if (!$client) {
            return response()->json(['error' => 'Cliente não encontrado'], 404);
        }

        $data = $request->validate([
            'store_slug'         => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9\-]+$/', Rule::unique('drop_stores', 'store_slug')],
            'store_display_name' => ['required', 'string', 'max:100'],
            'primary_color'      => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_url'           => ['nullable', 'url', 'max:500'],
            'banner_url'         => ['nullable', 'url', 'max:500'],
            'custom_domain'      => ['nullable', 'string', 'max:255'],
        ]);

        $existing = DropStore::where('client_id', $client->id)
            ->where('platform', 'native')
            ->first();

        if ($existing) {
            return response()->json(['error' => 'Voce ja tem uma loja nativa. Edite-a em vez de criar outra.'], 409);
        }

        $store = DropStore::create([
            'client_id'          => $client->id,
            'platform'           => 'native',
            'store_slug'         => $data['store_slug'],
            'store_display_name' => $data['store_display_name'],
            'primary_color'      => $data['primary_color'] ?? '#3B82F6',
            'logo_url'           => $data['logo_url'] ?? null,
            'banner_url'         => $data['banner_url'] ?? null,
            'custom_domain'      => $data['custom_domain'] ?? null,
            'is_published'       => false,
            'status'             => 'active',
            'shop_domain'        => 'native:' . $data['store_slug'],
            'currency'           => 'BRL',
        ]);

        return response()->json([
            'store'      => $store,
            'public_url' => $store->getPublicUrl(),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        $store  = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();

        $data = $request->validate([
            'store_display_name' => ['sometimes', 'string', 'max:100'],
            'primary_color'      => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_url'           => ['sometimes', 'nullable', 'url', 'max:500'],
            'banner_url'         => ['sometimes', 'nullable', 'url', 'max:500'],
            'custom_domain'      => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $store->update($data);

        return response()->json(['store' => $store, 'public_url' => $store->getPublicUrl()]);
    }

    public function publish(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        $store  = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();

        if (!$store->defaultGateway) {
            return response()->json(['error' => 'Adicione pelo menos um gateway de pagamento antes de publicar.'], 422);
        }

        $store->update(['is_published' => true, 'published_at' => now()]);

        return response()->json(['message' => 'Loja publicada com sucesso.', 'public_url' => $store->getPublicUrl()]);
    }

    public function unpublish(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        $store  = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();

        $store->update(['is_published' => false]);

        return response()->json(['message' => 'Loja despublicada.']);
    }

    public function addGateway(Request $request): JsonResponse
    {
        $client = $request->user()->client;
        $store  = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();

        $data = $request->validate([
            'gateway_type'        => ['required', Rule::in(['pagarme', 'stripe', 'mercadopago', 'pix_manual'])],
            'is_default'          => ['boolean'],
            'pix_key'             => ['nullable', 'string', 'max:255'],
            'pix_key_type'        => ['nullable', Rule::in(['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'])],
            'credentials'         => ['required_unless:gateway_type,pix_manual', 'array'],
            'credentials.api_key' => ['nullable', 'string'],
            'credentials.secret_key' => ['nullable', 'string'],
        ]);

        if ($data['is_default'] ?? false) {
            $store->paymentGateways()->update(['is_default' => false]);
        }

        $gateway = StorePaymentGateway::updateOrCreate(
            ['drop_store_id' => $store->id, 'gateway_type' => $data['gateway_type']],
            [
                'is_default'   => $data['is_default'] ?? false,
                'is_active'    => true,
                'pix_key'      => $data['pix_key'] ?? null,
                'pix_key_type' => $data['pix_key_type'] ?? null,
            ]
        );

        if (!empty($data['credentials'])) {
            $gateway->credentials = $data['credentials'];
            $gateway->save();
        }

        if (!$store->paymentGateways()->where('is_default', true)->exists()) {
            $gateway->update(['is_default' => true]);
        }

        return response()->json(['gateway' => $gateway->makeHidden('credentials_enc')], 201);
    }

    public function listGateways(Request $request): JsonResponse
    {
        $client   = $request->user()->client;
        $store    = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();
        $gateways = $store->paymentGateways()->where('is_active', true)->get()
            ->makeHidden('credentials_enc')
            ->map(fn($g) => array_merge($g->toArray(), ['has_credentials' => !empty($g->credentials)]));

        return response()->json(['gateways' => $gateways]);
    }

    public function removeGateway(Request $request, int $gatewayId): JsonResponse
    {
        $client  = $request->user()->client;
        $store   = DropStore::where('client_id', $client->id)->where('platform', 'native')->firstOrFail();
        $gateway = StorePaymentGateway::where('id', $gatewayId)
            ->where('drop_store_id', $store->id)
            ->firstOrFail();

        $gateway->delete();

        return response()->json(['message' => 'Gateway removido.']);
    }
}
