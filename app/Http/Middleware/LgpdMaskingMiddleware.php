<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEL-357: Mascara dados LGPD em runtime para respostas JSON de contas espelho.
 *
 * Aplica quando qualquer marketplace_account do cliente autenticado tiver
 * mirror_mode=readonly. Substitui nos campos de response JSON:
 *   customer_name       -> "Cliente #XXX"
 *   customer_email      -> "clienteXXX@demo.local"
 *   customer_phone      -> "(00) 90000-0000"
 *   document / cpf/cnpj -> "000.000.000-00"
 *   customer_address    -> objeto fake
 *   buyer_nickname      -> "comprador_demo"
 *
 * Para evitar custo de query extra a cada request, cacheamos a flag
 * de masking no request scope usando o atributo 'lgpd_mask_active'.
 *
 * Middleware apenas mascara na saida — nao altera banco de dados.
 */
class LgpdMaskingMiddleware
{
    private const MASKED_PHONE   = '(00) 90000-0000';
    private const MASKED_DOC     = '000.000.000-00';
    private const MASKED_NICK    = 'comprador_demo';

    /**
     * Campos de primeiro nivel a mascarar em qualquer array/objeto JSON.
     */
    private const FIELDS_MAP = [
        'customer_name'            => null, // gerado dinamicamente com ID
        'customer_email'           => null, // gerado dinamicamente com ID
        'customer_phone'           => self::MASKED_PHONE,
        'customer_document_number' => self::MASKED_DOC,
        'customer_document_type'   => 'CPF',
        'buyer_nickname'           => self::MASKED_NICK,
        'buyer_username'           => self::MASKED_NICK,
        'buyer_id'                 => '000000000',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // So mascarar se cliente autenticado tiver conta mirror_mode=readonly
        if (! $this->clientHasMirrorAccount($request)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $data = $this->maskRecursive($data);
            $response->setData($data);
        }

        return $response;
    }

    private function clientHasMirrorAccount(Request $request): bool
    {
        // Cache no request para nao repetir query a cada call
        if ($request->attributes->has('lgpd_mask_active')) {
            return (bool) $request->attributes->get('lgpd_mask_active');
        }

        try {
            $user = $request->user();
            if (! $user) {
                $request->attributes->set('lgpd_mask_active', false);
                return false;
            }

            $clientId = $user->client_id ?? ($user->client->id ?? null);
            if (! $clientId) {
                $request->attributes->set('lgpd_mask_active', false);
                return false;
            }

            $hasMirror = \App\Models\MarketplaceAccount::where('client_id', $clientId)
                ->where('mirror_mode', 'readonly')
                ->exists();

            $request->attributes->set('lgpd_mask_active', $hasMirror);
            return $hasMirror;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function maskRecursive(mixed $data): mixed
    {
        if (is_array($data)) {
            // Se parece um pedido (tem customer_name), mascarar
            if (isset($data['customer_name']) || isset($data['customer_email'])) {
                $data = $this->maskOrder($data);
            }

            // Se e uma lista paginada (data.data) ou array de items, descer recursivo
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $this->maskRecursive($value);
                }
            }
        }

        return $data;
    }

    private function maskOrder(array $item): array
    {
        // Extrair ID numerico do item pra gerar mascaras consistentes
        $id = $item['id'] ?? rand(100, 999);
        $idSuffix = substr((string) $id, -3);

        foreach (self::FIELDS_MAP as $field => $maskedValue) {
            if (!array_key_exists($field, $item)) {
                continue;
            }

            if ($field === 'customer_name') {
                $item[$field] = 'Cliente #' . $idSuffix;
            } elseif ($field === 'customer_email') {
                $item[$field] = 'cliente' . $id . '@demo.local';
            } else {
                $item[$field] = $maskedValue;
            }
        }

        // Mascarar customer_address (pode ser JSON string ou array)
        if (isset($item['customer_address'])) {
            $item['customer_address'] = $this->fakeAddress($id);
        }

        return $item;
    }

    private function fakeAddress(mixed $seed): array
    {
        $num = (is_numeric($seed) ? (int) $seed % 900 : 42) + 100;

        return [
            'street'     => 'Rua Demonstracao',
            'number'     => (string) $num,
            'complement' => '',
            'district'   => 'Bairro X',
            'city'       => 'Cidade X',
            'state'      => 'UF',
            'zip_code'   => '00000-000',
            'country'    => 'BR',
        ];
    }
}
