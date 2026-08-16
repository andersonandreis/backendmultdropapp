<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Supplier;
use App\Models\Client;
use App\Models\Category;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        // 0. Criar Planos
        $plans = [
            [
                'name' => 'Plano Basic',
                'slug' => 'basic',
                'description' => 'Ideal para quem está começando.',
                'max_skus' => 50,
                'max_marketplace_connections' => 2,
                'max_erp_connections' => 1,
                'price_monthly' => 97.00,
                'price_yearly' => 997.00,
                'trial_days' => 7,
                'is_active' => true,
            ],
            [
                'name' => 'Plano Pro',
                'slug' => 'pro',
                'description' => 'Para lojistas em escala.',
                'max_skus' => 500,
                'max_marketplace_connections' => 10,
                'max_erp_connections' => 3,
                'price_monthly' => 247.00,
                'price_yearly' => 2497.00,
                'trial_days' => 7,
                'is_active' => true,
            ],
            [
                'name' => 'Plano Enterprise',
                'slug' => 'enterprise',
                'description' => 'Poder ilimitado.',
                'max_skus' => 9999,
                'max_marketplace_connections' => 99,
                'max_erp_connections' => 99,
                'price_monthly' => 497.00,
                'price_yearly' => 4997.00,
                'trial_days' => 0,
                'is_active' => true,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::firstOrCreate(['slug' => $planData['slug']], $planData);
        }

        // 1. Forçar senha do Super Admin para 12345678
        $admin = User::firstOrCreate(
            ['email' => 'admin@hubai.com.br'],
            [
                'name' => 'Super Admin HubAI',
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );
        $admin->password = Hash::make('12345678');
        $admin->save();

        // 2. Criar Fornecedor de Teste (Supplier) -> Acessa o painel Admin
        $supplierUser = User::firstOrCreate(
            ['email' => 'fornecedor@hubai.com.br'],
            [
                'name' => 'Fornecedor Teste',
                'password' => Hash::make('12345678'),
                'role' => 'supplier',
                'is_active' => true,
            ]
        );

        $supplier = Supplier::firstOrCreate(
            ['user_id' => $supplierUser->id],
            [
                'type' => 'CNPJ',
                'company_name' => 'Tech Distribuidora LTDA',
                'document' => '12.345.678/0001-99',
                'phone' => '11999999999',
                'description' => 'Maior distribuidora de eletrônicos do Brasil.',
                'city' => 'São Paulo',
                'state' => 'SP',
                'is_active' => true,
            ]
        );

        // 3. Criar Lojista de Teste (Client) -> Acessa o painel App (Seller)
        $clientUser = User::firstOrCreate(
            ['email' => 'lojista@hubai.com.br'],
            [
                'name' => 'Lojista Teste',
                'password' => Hash::make('12345678'),
                'role' => 'client',
                'is_active' => true,
            ]
        );

        $client = Client::firstOrCreate(
            ['user_id' => $clientUser->id],
            [
                'company_name' => 'Lojinha do Sucesso',
                'document' => '123.456.789-00',
                'phone' => '11888888888',
                'is_active' => true,
                'listing_mode' => 'manual',
            ]
        );

        // 4. Criar Categorias
        $categories = [
            ['name' => 'Eletrônicos', 'slug' => \Illuminate\Support\Str::slug('Eletrônicos')],
            ['name' => 'Casa Inteligente', 'slug' => \Illuminate\Support\Str::slug('Casa Inteligente')],
            ['name' => 'Acessórios', 'slug' => \Illuminate\Support\Str::slug('Acessórios')],
        ];

        $categoryModels = [];
        foreach ($categories as $cat) {
            $catModel = Category::firstOrCreate(['name' => $cat['name']], $cat);
            $categoryModels[] = $catModel;
        }

        // 5. Criar Produtos Fakes e Variações Padrão (Fornecedor)
        $products = [];
        $variations = [];
        for ($i = 1; $i <= 10; $i++) {
            $product = Product::firstOrCreate(
                ['sku' => 'PROD-00' . $i],
                [
                    'supplier_id' => $supplier->id,
                    'name' => 'Produto de Teste ' . $i,
                    'description' => 'Descrição completa do incrível Produto de Teste ' . $i . ' com alta conversão em dropshipping.',
                    'price' => rand(99, 599) + 0.99,
                    'cost' => rand(40, 80),
                    'brand' => 'HubGen',
                    'weight_kg' => 0.5,
                    'category_id' => $categoryModels[array_rand($categoryModels)]->id,
                    'condition' => 'new',
                    'is_active' => true,
                ]
            );
            $products[] = $product;

            // Criar uma variação primária para testar as tabelas
            $variation = \App\Models\ProductVariation::firstOrCreate(
                ['product_id' => $product->id, 'sku' => $product->sku . '-VAR1'],
                [
                    'name' => 'Cor Padrão',
                    'price' => $product->price,
                    'cost' => $product->cost,
                    'is_active' => true,
                ]
            );
            $variations[$product->id] = $variation;

            // Criar mídia (foto) principal para o produto
            \App\Models\ProductMedia::firstOrCreate(
                ['product_id' => $product->id, 'product_variation_id' => $variation->id],
                [
                    'type' => 'image',
                    'url' => 'https://ui-avatars.com/api/?name=' . urlencode($product->name) . '&background=random&color=fff&size=512',
                    'position' => 0,
                ]
            );
        }

        // 6. Criar Lojas Simuladas para o Lojista (Marketplace Accounts)
        $storeOne = \App\Models\MarketplaceAccount::firstOrCreate(
            ['client_id' => $client->id, 'account_name' => 'Mercado Livre Oficial'],
            ['platform' => 'mercadolivre', 'seller_id' => 'ML-12345', 'status' => 'active']
        );

        $storeTwo = \App\Models\MarketplaceAccount::firstOrCreate(
            ['client_id' => $client->id, 'account_name' => 'Shopee Tech Store'],
            ['platform' => 'shopee', 'shop_id' => 'SHP-98765', 'status' => 'active']
        );

        $stores = [$storeOne, $storeTwo];

        // 7. Simular a Importação de Produtos (ClientProduct)
        $clientProducts = [];
        foreach ($products as $product) {
            $variation = $variations[$product->id] ?? null;
            $store = $stores[array_rand($stores)]; // Escolhe uma loja aleatória para importar

            // Markup realista para o Dropshipping atual (cobrir comissões de até 20% + taxas fixas)
            // Multiplicador do custo: de 2.2x até 3.5x
            $markupRatio = rand(220, 350) / 100;
            $retailPrice = $product->cost * $markupRatio;

            // Calculo da Margem Real percentual ((Preco - Custo) / Preco) * 100
            $margin = (($retailPrice - $product->cost) / $retailPrice) * 100;

            $clientProduct = \App\Models\ClientProduct::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'product_id' => $product->id,
                    'marketplace_account_id' => $store->id, // A loja escolhida!
                ],
                [
                    'product_variation_id' => $variation ? $variation->id : null,
                    'custom_sku' => 'MEU-SKU-' . $product->id,
                    'custom_title' => 'Oferta Imbatível - ' . $product->name,
                    'custom_price' => $retailPrice,
                    'profit_margin' => $margin,
                    'is_active' => true,
                ]
            );
            $clientProducts[] = $clientProduct;
        }

        // 8. Criar Pedidos Fakes (Orders) Conectados com a Realidade
        $statuses = ['pending', 'paid', 'shipped', 'delivered'];

        for ($i = 1; $i <= 15; $i++) {
            $randStatus = $statuses[array_rand($statuses)];
            $totalAmount = rand(100, 1000) + 0.50;

            // Escolhe um ClientProduct importado aleatório como isca para o pedido
            $baseClientProduct = $clientProducts[array_rand($clientProducts)];
            $orderStore = $baseClientProduct->marketplaceAccount; // A loja que vendeu!

            $order = Order::firstOrCreate(
                ['order_number' => 'ORD-' . str_pad($i, 5, '0', STR_PAD_LEFT)],
                [
                    'external_order_id' => strtoupper(Str::random(10)),
                    'client_id' => $client->id,
                    'supplier_id' => $supplier->id,
                    'source' => $orderStore->platform ?? 'mercadolivre',
                    'customer_name' => 'Comprador Fictício ' . $i,
                    'customer_email' => 'comprador' . $i . '@exemplo.com',
                    'subtotal' => 0, // Será calculado
                    'shipping_cost' => 20,
                    'total' => 0, // Será calculado
                    'currency' => 'BRL',
                    'status' => $randStatus,
                    'created_at' => Carbon::now()->subDays(rand(1, 30)),
                ]
            );

            // Adicionar 1 a 3 itens por pedido (buscando dos ClientProducts da mesma loja)
            $numItems = rand(1, 3);
            $supplierTotal = 0;
            $orderSubtotal = 0;

            // Filtra os produtos configurados para a loja do pedido
            $storeProducts = array_filter($clientProducts, fn($cp) => $cp->marketplace_account_id == $orderStore->id);
            if (empty($storeProducts))
                $storeProducts = [$baseClientProduct]; // Fallback

            for ($j = 0; $j < $numItems; $j++) {
                $randCp = $storeProducts[array_rand($storeProducts)];
                $originalProduct = $randCp->product;
                $variation = $variations[$originalProduct->id] ?? null;
                $qty = rand(1, 2);

                $supplierUnitCost = $originalProduct->cost;
                $supplierTotalCost = $supplierUnitCost * $qty;
                $supplierTotal += $supplierUnitCost * $qty;

                $itemTotal = $randCp->custom_price * $qty;
                $orderSubtotal += $itemTotal;

                OrderItem::firstOrCreate(
                    [
                        'order_id' => $order->id,
                        'client_product_id' => $randCp->id,
                    ],
                    [
                        'product_id' => $originalProduct->id,
                        'product_variation_id' => $variation ? $variation->id : null,
                        'sku' => $originalProduct->sku,
                        'variation_sku' => $variation ? $variation->sku : null,
                        'name' => $randCp->custom_title ?? $originalProduct->name,
                        'quantity' => $qty,
                        'unit_price' => $randCp->custom_price,
                        'total' => $itemTotal,
                        'supplier_unit_cost' => $supplierUnitCost,
                        'supplier_total_cost' => $supplierTotalCost,
                    ]
                );
            }

            // Atualizar o total de custo do fornecedor e o total final no pedido
            $order->update([
                'subtotal' => $orderSubtotal,
                'total' => $orderSubtotal + 20, // + shipping
                'supplier_total' => $supplierTotal
            ]);
        }
    }
}
