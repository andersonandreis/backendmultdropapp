# HubAI - Plataforma Saas de Dropshipping

HubAI é um sistema multi-tenant focado em conectar Produtores (Fornecedores) a Lojistas (Sellers Dropshipping).

## Requisitos de Servidor
- CyberPanel com OpenLiteSpeed
- PHP 8.3
- MySQL 8+
- Redis (Opcional, porém recomendado para Cache)

## Instalação e Deploy (Produção CyberPanel)

1. Clone o repositório na pasta `public_html` do seu site no CyberPanel:
   ```bash
   git clone <repo_url> .
   ```
2. Instale as dependências:
   ```bash
   composer install --optimize-autoloader --no-dev
   npm install && npm run build
   ```
3. Configure o `.env` copiando o exemplo:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. Configure as variaveis base como Banco de Dados e as Gateways (`BUNNYCDN_ACCESS_KEY`, `ML_APP_ID`, `SHOPEE_PARTNER_ID`).
5. Execute as Migrations:
   ```bash
   php artisan migrate --force
   ```
6. Otimize a aplicação:
   ```bash
   php artisan optimize
   php artisan filament:optimize
   ```

## Acessos Locais do Painel

O sistema contruído divide-se por Filaments:

- **Hub Principal (Administradores/Fornecedores/Galpão):** `/admin`
- **Painel do Seller/Lojista:** `/app`

## LSCache e Otimização

A aplicação dispõe de integração direta via Middleware (`LscacheMiddleware`) para controle de Header PublicCache. Alterações em produtos via admin limparão o respectivo cache na borda. Certifique-se nas Configurações do CyberPanel que o módulo LSCache do App Laravel esteja ativo.
