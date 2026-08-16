<?php

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'HubAI API',
    version: '1.0.0',
    description: 'API REST do sistema HubAI. Endpoints protegidos exigem Bearer token via POST /api/login.',
    contact: new OA\Contact(email: 'suporte@hubai.io')
)]
#[OA\Server(url: 'https://api.hubai.io', description: 'Producao')]
#[OA\Server(url: 'http://localhost:8000', description: 'Local')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum Token'
)]
#[OA\Tag(name: 'Auth', description: 'Autenticacao e token Sanctum')]
#[OA\Tag(name: 'Lojas', description: 'Contas de Marketplace (Stores)')]
#[OA\Tag(name: 'Fornecedores', description: 'Suppliers e Catalogo')]
#[OA\Tag(name: 'Produtos', description: 'Produtos do Lojista (ClientProducts)')]
#[OA\Tag(name: 'Pedidos', description: 'Pedidos (Orders)')]
#[OA\Tag(name: 'Financeiro', description: 'Saldo e Transacoes')]
#[OA\Tag(name: 'Publico', description: 'Endpoints sem autenticacao — health, catalogo, fornecedores e contas de marketplace')]
#[OA\Tag(name: 'OAuth', description: 'Conexao com marketplaces via OAuth2 (MercadoLivre, Bling, Shopee)')]
#[OA\Tag(name: 'Webhooks', description: 'Callbacks de plataformas externas — pagamentos, pedidos e sincronizacao de estoque')]
#[OA\Tag(name: 'Perfil', description: 'Perfil e senha do lojista')]
#[OA\Tag(name: 'Planos', description: 'Planos e assinatura')]
#[OA\Tag(name: 'Fornecedor', description: 'Painel do fornecedor — produtos, estoque e pedidos')]
#[OA\Tag(name: 'Configuracoes', description: 'Webhook configs e event subscriptions')]
#[OA\Tag(name: 'Marketplace', description: 'Conexao e publicacao em marketplaces (direto ou via ponte goolhub)')]
class OpenApi
{
}
