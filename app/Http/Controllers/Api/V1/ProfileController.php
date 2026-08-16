<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Helpers\DocumentValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ProfileController extends Controller
{
    private function clientOrFail(Request $request)
    {
        $client = $request->user()->client;

        if (! $client) {
            abort(403, 'Usuario nao possui perfil de lojista.');
        }

        return $client;
    }

    #[OA\Get(
        path: '/api/v1/profile',
        summary: 'Perfil completo do lojista autenticado',
        description: 'Retorna dados do usuario, do perfil de lojista (client), assinatura ativa e contas de marketplace conectadas. Use este endpoint para montar a tela de perfil/conta no frontend.',
        tags: ['Perfil'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Perfil completo do lojista',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'user',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 42),
                                        new OA\Property(property: 'name', type: 'string', example: 'Joao Silva'),
                                        new OA\Property(property: 'email', type: 'string', example: 'joao@loja.com'),
                                        new OA\Property(property: 'role', type: 'string', example: 'client'),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'client',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 7),
                                        new OA\Property(property: 'company_name', type: 'string', nullable: true, example: 'Minha Loja LTDA'),
                                        new OA\Property(property: 'document', type: 'string', nullable: true, example: '12.345.678/0001-99'),
                                        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '11999998888'),
                                        new OA\Property(property: 'is_active', type: 'boolean', example: true),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'subscription',
                                    type: 'object',
                                    nullable: true,
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 3),
                                        new OA\Property(property: 'status', type: 'string', example: 'active'),
                                        new OA\Property(property: 'payment_method', type: 'string', nullable: true, example: 'credit_card'),
                                        new OA\Property(property: 'current_period_start', type: 'string', format: 'date-time', nullable: true, example: '2026-05-01T00:00:00Z'),
                                        new OA\Property(property: 'current_period_end', type: 'string', format: 'date-time', nullable: true, example: '2026-06-01T00:00:00Z'),
                                        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true, example: null),
                                        new OA\Property(
                                            property: 'plan',
                                            type: 'object',
                                            nullable: true,
                                            properties: [
                                                new OA\Property(property: 'id', type: 'integer', example: 2),
                                                new OA\Property(property: 'name', type: 'string', example: 'Pro'),
                                                new OA\Property(property: 'slug', type: 'string', example: 'pro'),
                                                new OA\Property(property: 'price_monthly', type: 'number', example: 99.90),
                                            ]
                                        ),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'marketplace_accounts',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer', example: 1),
                                            new OA\Property(property: 'platform', type: 'string', example: 'mercadolivre'),
                                            new OA\Property(property: 'account_name', type: 'string', nullable: true, example: 'Loja Oficial'),
                                            new OA\Property(property: 'seller_nickname', type: 'string', nullable: true, example: 'MINHA_LOJA'),
                                            new OA\Property(property: 'status', type: 'string', example: 'active'),
                                        ]
                                    )
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Usuario nao possui perfil de lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Usuario nao possui perfil de lojista.')]
                )
            ),
        ]
    )]
    public function show(Request $request)
    {
        $user   = $request->user();
        $client = $this->clientOrFail($request);

        $subscription = $client->subscriptions()
            ->with('plan:id,name,slug,price_monthly')
            ->latest()
            ->first();

        $marketplaceAccounts = \App\Models\MarketplaceAccount::where('client_id', $client->id)
            ->get(['id', 'platform', 'account_name', 'seller_nickname', 'status']);

        return response()->json([
            'data' => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ],
                'client' => [
                    'id'           => $client->id,
                    'company_name' => $client->company_name,
                    'document'     => $client->document,
                    'phone'        => $client->phone,
                    'is_active'    => $client->is_active,
                    // MUL-336: o painel do seller mostrava so nome/documento/telefone enquanto o
                    // admin via o cadastro fiscal inteiro. Mesma tabela, mesma resposta agora.
                    'person_type'            => $client->person_type,
                    'legal_name'             => $client->legal_name,
                    'trade_name'             => $client->trade_name,
                    'state_registration'     => $client->state_registration,
                    'ie_indicator'           => $client->ie_indicator,
                    'municipal_registration' => $client->municipal_registration,
                    'nfe_email'              => $client->nfe_email,
                    'address' => [
                        'cep'          => $client->address_cep,
                        'street'       => $client->address_street,
                        'number'       => $client->address_number,
                        'complement'   => $client->address_complement,
                        'neighborhood' => $client->address_neighborhood,
                        'city'         => $client->address_city,
                        'state'        => $client->address_state,
                    ],
                    'bling_contact_id' => $client->bling_supplier_contact_id,
                ],
                'subscription'          => $subscription,
                'marketplace_accounts'  => $marketplaceAccounts,
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/profile',
        summary: 'Atualizar perfil do lojista',
        description: 'Atualiza os dados do perfil do lojista: razao social, documento (CPF/CNPJ) e telefone. Todos os campos sao opcionais — envie apenas o que deseja alterar.',
        tags: ['Perfil'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', nullable: true, description: 'Razao social ou nome da loja', example: 'Minha Loja LTDA'),
                    new OA\Property(property: 'document', type: 'string', nullable: true, description: 'CPF ou CNPJ (somente numeros ou formatado)', example: '12345678000199'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, description: 'Telefone com DDD', example: '11999998888'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Perfil atualizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Perfil atualizado com sucesso.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 7),
                                new OA\Property(property: 'company_name', type: 'string', example: 'Minha Loja LTDA'),
                                new OA\Property(property: 'document', type: 'string', example: '12.345.678/0001-99'),
                                new OA\Property(property: 'phone', type: 'string', example: '11999998888'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Usuario nao possui perfil de lojista',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Usuario nao possui perfil de lojista.')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Erro de validacao',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'The document field must not be greater than 18 characters.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['document' => ['The document field must not be greater than 18 characters.']]),
                    ]
                )
            ),
        ]
    )]
    public function update(Request $request)
    {
        $client = $this->clientOrFail($request);

        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        // Se o front mandar company_name, atualiza o USER conectado (full_name);
        // client so recebe document/phone.
        // MUL-336: o seller passa a manter o proprio cadastro fiscal — os mesmos campos que o
        // admin edita. Todos opcionais; entra so o que vier na requisicao.
        $validated = $request->validate([
            'company_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'document'     => ['sometimes', 'nullable', 'string', 'max:18'],
            'phone'        => ['sometimes', 'nullable', 'string', 'max:20'],

            'person_type'            => ['sometimes', 'nullable', 'in:PF,PJ'],
            'legal_name'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_name'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'state_registration'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'ie_indicator'           => ['sometimes', 'nullable', 'integer', 'in:1,2,9'],
            'municipal_registration' => ['sometimes', 'nullable', 'string', 'max:30'],
            'nfe_email'              => ['sometimes', 'nullable', 'email', 'max:255'],
            'address_cep'            => ['sometimes', 'nullable', 'string', 'max:10'],
            'address_street'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_number'         => ['sometimes', 'nullable', 'string', 'max:20'],
            'address_complement'     => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_neighborhood'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_city'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'address_state'          => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);

        if (array_key_exists('address_cep', $validated) && $validated['address_cep'] !== null) {
            $validated['address_cep'] = preg_replace('/\D/', '', $validated['address_cep']) ?: null;
        }
        if (array_key_exists('address_state', $validated) && $validated['address_state'] !== null) {
            $validated['address_state'] = strtoupper($validated['address_state']);
        }

        // Limpar mascara e validar CPF/CNPJ antes de salvar
        if (isset($validated['document']) && $validated['document'] !== null) {
            $cleanDoc = preg_replace('/\D/', '', $validated['document']);
            if ($cleanDoc === '') {
                $validated['document'] = null;
            } elseif (!DocumentValidator::isValid($cleanDoc)) {
                throw ValidationException::withMessages([
                    'document' => ['CPF ou CNPJ invalido. Verifique os digitos informados.'],
                ]);
            } else {
                $validated['document'] = $cleanDoc;
            }
        }

        // MUL-269 fase 2: company_name vai pro USER.full_name (nao pro client).
        if (array_key_exists('company_name', $validated)) {
            if ($client->user_id && $client->user) {
                $client->user->update(['full_name' => $validated['company_name']]);
            }
            unset($validated['company_name']);
        }

        if (!empty($validated)) {
            $client->update($validated);
        }

        $client->refresh();

        return response()->json([
            'message' => 'Perfil atualizado com sucesso.',
            'data'    => [
                'id'           => $client->id,
                'company_name' => $client->company_name,
                'document'     => $client->document,
                'phone'        => $client->phone,
                // MUL-336: devolve o cadastro fiscal pro front nao precisar refazer o GET
                'person_type'            => $client->person_type,
                'legal_name'             => $client->legal_name,
                'trade_name'             => $client->trade_name,
                'state_registration'     => $client->state_registration,
                'ie_indicator'           => $client->ie_indicator,
                'municipal_registration' => $client->municipal_registration,
                'nfe_email'              => $client->nfe_email,
                'address' => [
                    'cep'          => $client->address_cep,
                    'street'       => $client->address_street,
                    'number'       => $client->address_number,
                    'complement'   => $client->address_complement,
                    'neighborhood' => $client->address_neighborhood,
                    'city'         => $client->address_city,
                    'state'        => $client->address_state,
                ],
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/password',
        summary: 'Trocar senha do usuario autenticado',
        description: 'Altera a senha do usuario. Exige confirmacao da senha atual por seguranca. A nova senha deve ter no minimo 8 caracteres e ser confirmada.',
        tags: ['Perfil'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'new_password', 'new_password_confirmation'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', format: 'password', description: 'Senha atual do usuario', example: 'minha_senha_atual'),
                    new OA\Property(property: 'new_password', type: 'string', format: 'password', description: 'Nova senha (minimo 8 caracteres)', example: 'nova_senha_segura'),
                    new OA\Property(property: 'new_password_confirmation', type: 'string', format: 'password', description: 'Confirmacao da nova senha', example: 'nova_senha_segura'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Senha alterada com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Senha alterada com sucesso.'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente ou invalido',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.')]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Senha atual incorreta ou nova senha invalida',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Senha atual incorreta.'),
                        new OA\Property(property: 'errors', type: 'object', example: ['current_password' => ['Senha atual incorreta.']]),
                    ]
                )
            ),
        ]
    )]
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password'      => ['required', 'string'],
            'new_password'          => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Senha atual incorreta.'],
            ]);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }
}
