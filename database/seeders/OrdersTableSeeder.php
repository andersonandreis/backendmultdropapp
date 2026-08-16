<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OrdersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Obter o cliente de testes (Lojista 1) e um fornecedor válido
        $client = \App\Models\Client::find(1);
        if (!$client) {
            $this->command->error('Cliente ID 1 (Lojista Teste) não encontrado. Rode o ClientSeeder primeiro.');
            return;
        }

        $supplier = \App\Models\Supplier::first();
        if (!$supplier) {
            $this->command->error('Nenhum fornecedor encontrado no banco.');
            return;
        }

        $this->command->info("Criando 10 pedidos de teste para o Cliente: {$client->name} com Fornecedor: {$supplier->company_name}");

        $products = \App\Models\Product::where('supplier_id', $supplier->id)->limit(5)->get();
        if ($products->isEmpty()) {
            $this->command->error('O fornecedor selecionado não possui produtos para gerar pedidos.');
            return;
        }

        // 2. Definir a estrutura dos 10 pedidos (variando status para a timeline SLA)
        $orderScenarios = [
            // Aguardando Pagamento (Lojista precisa pagar via PIX Shipay)
            ['status' => 'pending', 'order_processing_status' => 'pending', 'paid_at' => null, 'separated_at' => null],
            ['status' => 'pending', 'order_processing_status' => 'pending', 'paid_at' => null, 'separated_at' => null],

            // Pago, aguardando fornecedor iniciar separação (Impressão de Etiqueta)
            ['status' => 'paid', 'order_processing_status' => 'awaiting_label', 'paid_at' => now()->subHours(2), 'separated_at' => null],
            ['status' => 'paid', 'order_processing_status' => 'awaiting_label', 'paid_at' => now()->subHours(1), 'separated_at' => null],

            // Separação (Fornecedor bipou para separar)
            ['status' => 'paid', 'order_processing_status' => 'processing', 'paid_at' => now()->subDay(), 'separated_at' => now()->subHours(5)],
            ['status' => 'paid', 'order_processing_status' => 'processing', 'paid_at' => now()->subDay(), 'separated_at' => now()->subHours(2)],

            // Despachado (Entregue para transportadora)
            ['status' => 'shipped', 'order_processing_status' => 'shipped', 'paid_at' => now()->subDays(2), 'separated_at' => now()->subDays(1)->subHours(5), 'shipped_at' => now()->subHours(10)],
            ['status' => 'shipped', 'order_processing_status' => 'shipped', 'paid_at' => now()->subDays(2), 'separated_at' => now()->subDays(1)->subHours(2), 'shipped_at' => now()->subHours(8)],

            // Entregue ao comprador
            ['status' => 'delivered', 'order_processing_status' => 'delivered', 'paid_at' => now()->subDays(5), 'separated_at' => now()->subDays(4), 'shipped_at' => now()->subDays(3), 'delivered_at' => now()->subHours(12)],

            // Cancelado
            ['status' => 'cancelled', 'order_processing_status' => 'cancelled', 'paid_at' => null, 'separated_at' => null, 'cancelled_at' => now()->subHours(2), 'cancel_reason' => 'Cliente desistiu da compra.'],
        ];

        // 3. Criar os registros
        foreach ($orderScenarios as $index => $scenario) {
            $order = \App\Models\Order::create([
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'order_number' => 'TEST-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'source' => $index % 2 == 0 ? 'MercadoLivre' : 'Shopee',
                'external_order_id' => 'EXT-' . rand(1000000, 9999999),
                'buyer_nickname' => 'COMPRADOR_TESTE_' . $index,
                'customer_name' => 'Cliente Consumidor Final ' . $index,
                'customer_document_number' => '1112223334' . $index,
                'status' => $scenario['status'],
                'order_processing_status' => $scenario['order_processing_status'],
                'paid_at' => $scenario['paid_at'] ?? null,
                'separated_at' => $scenario['separated_at'] ?? null,
                'shipped_at' => $scenario['shipped_at'] ?? null,
                'delivered_at' => $scenario['delivered_at'] ?? null,
                'cancelled_at' => $scenario['cancelled_at'] ?? null,
                'cancel_reason' => $scenario['cancel_reason'] ?? null,
                'subtotal' => 0,
                'shipping_cost' => 20.00,
                'total' => 0,
            ]);

            // Adicionar 1 a 3 itens para cada pedido
            $numItems = rand(1, 3);
            $subtotal = 0;

            for ($i = 0; $i < $numItems; $i++) {
                $product = $products->random();
                $quantity = rand(1, 2);
                $price = $product->cost_price > 0 ? $product->cost_price * 1.5 : 50.00;

                \App\Models\OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'external_item_id' => 'EXT-ITEM-' . rand(1000, 9999),
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total' => $price * $quantity,
                ]);

                $subtotal += ($price * $quantity);
            }

            // Atualiza total do pedido
            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal + $order->shipping_cost,
            ]);
        }

        $this->command->info('10 Pedidos de teste criados com sucesso!');
    }
}
