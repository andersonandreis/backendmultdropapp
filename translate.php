<?php
$translations = [
    "'Name'" => "'Nome'",
    "'Title'" => "'Título'",
    "'Description'" => "'Descrição'",
    "'Image'" => "'Imagem'",
    "'Price'" => "'Preço'",
    "'Supplier'" => "'Fornecedor'",
    "'Category'" => "'Categoria'",
    "'Categories'" => "'Categorias'",
    "'Created at'" => "'Criado em'",
    "'Updated at'" => "'Atualizado em'",
    "'Settings'" => "'Configurações'",
    "'Active'" => "'Ativo'",
    "'Status'" => "'Status'",
    "'Document'" => "'Documento'",
    "'Value'" => "'Valor'",
    "'User'" => "'Usuário'",
    "'User ID'" => "'ID Usuário'",
    "'Company Name'" => "'Razão Social'",
    "'Document Number'" => "'CNPJ / CPF'",
    "'Phone'" => "'Telefone'",
    "'Stock'" => "'Estoque'",
    "'Platform'" => "'Plataforma'",
    "'Type'" => "'Tipo'",
    "'Discount'" => "'Desconto'",
    "->label('Parent')" => "->label('Categoria Pai')",
    "->label('Role')" => "->label('Função')",
    "->label('Email')" => "->label('E-mail')",
    "->label('Password')" => "->label('Senha')"
];

$slugTranslations = [
    'CategoryResource' => 'categorias',
    'ClientResource' => 'assinantes',
    'CouponResource' => 'cupons',
    'DocumentResource' => 'documentos',
    'ErpAccountResource' => 'contas-erp',
    'ForbiddenWordResource' => 'palavras-proibidas',
    'InventoryResource' => 'estoque',
    'MarketplaceAccountResource' => 'lojas-conectadas',
    'MarketplaceCategoryResource' => 'categorias-marketplace',
    'MarketplaceFeeResource' => 'taxas-marketplace',
    'OrderResource' => 'pedidos',
    'PlanDiscountResource' => 'descontos-plano',
    'PlanResource' => 'planos',
    'PlatformDiscountResource' => 'descontos-plataforma',
    'ProductResource' => 'produtos',
    'ProductVariationResource' => 'variacoes-produto',
    'SettingsResource' => 'configuracoes',
    'ShipmentResource' => 'remessas',
    'SupplierBalanceResource' => 'saldo-fornecedor',
    'SupplierDiscountResource' => 'desconto-fornecedor',
    'SupplierResource' => 'fornecedores',
    'SupplierTransactionResource' => 'transacoes-fornecedor',
    'TutorialResource' => 'tutoriais',
    'UserResource' => 'usuarios',
    'WebhookConfigResource' => 'configs-webhook',
    'WithdrawalRequestResource' => 'saques',
    'ClientProductResource' => 'meus-produtos'
];

function processDirectory($dir)
{
    global $translations, $slugTranslations;
    $files = glob($dir . '/*.php');
    foreach ($files as $file) {
        $content = file_get_contents($file);

        // Change english labels
        foreach ($translations as $en => $pt) {
            $content = str_replace($en, $pt, $content);
        }

        // Add slug if missing
        $matches = [];
        if (preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+Resource/', $content, $matches)) {
            $className = $matches[1];
            if (isset($slugTranslations[$className])) {
                $slug = $slugTranslations[$className];
                if (strpos($content, '$slug') === false) {
                    // search for protected static ?string $model =
                    $content = preg_replace(
                        '/(protected static \?string \$model\s*=\s*[A-Za-z0-9_\\\:]+;)/',
                        "$1\n    protected static ?string \$slug = '$slug';",
                        $content
                    );
                }
            }
        }

        // Specific fixes for missing model Label
        if (strpos($content, '$modelLabel') === false && preg_match('/class\s+([A-Za-z0-9_]+)\s+extends\s+Resource/', $content, $matches)) {
            $className = $matches[1];
            $content = preg_replace(
                '/(protected static \?string \$slug\s*=\s*.*?;)/',
                "$1\n    // protected static ?string \$modelLabel = '...';",
                $content
            );
        }

        file_put_contents($file, $content);
        echo "Processed: $file\n";
    }
}

processDirectory(__DIR__ . '/app/Filament/Resources');
processDirectory(__DIR__ . '/app/Filament/App/Resources');
echo "Done.\n";
