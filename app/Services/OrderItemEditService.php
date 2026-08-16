<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Product;
use App\Observers\ProductObserver;
use App\Policies\OrderEditPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MUL-298 -- Edicao de pedido por item, enquanto nao houve repasse ao fornecedor.
 *
 * Substitui o comportamento destrutivo do OrderSwapProductService, que apagava
 * TODOS os itens do pedido para criar um. Ver Arquitetura/15.
 *
 * Convencoes de erro:
 *   DomainException          -> 409 (pedido travado, item de kit, ambiguidade, ultimo item)
 *   InvalidArgumentException -> 422 (quantidade invalida, produto fora do catalogo)
 */
class OrderItemEditService
{
    public function findItemById(Order $order, int $itemId): OrderItem
    {
        $item = OrderItem::where('order_id', $order->id)->find($itemId);
        if (!$item) {
            throw new \InvalidArgumentException("Item {$itemId} nao pertence ao pedido {$order->id}.");
        }
        return $item;
    }

    /**
     * Identidade entre bancos e por SKU -- id local nao significa nada fora do
     * proprio banco (MUL-272). Havendo mais de uma linha com o mesmo SKU no
     * pedido, recusa em vez de adivinhar.
     */
    public function findItemBySku(Order $order, string $sku, ?string $variationSku = null): OrderItem
    {
        $q = OrderItem::where('order_id', $order->id)->where('sku', $sku);
        if ($variationSku !== null && $variationSku !== '') {
            $q->where('variation_sku', $variationSku);
        }
        $rows = $q->get();

        if ($rows->isEmpty()) {
            throw new \InvalidArgumentException("Item de SKU {$sku} nao encontrado no pedido {$order->id}.");
        }
        if ($rows->count() > 1) {
            throw new \DomainException("item_ambiguo: o pedido {$order->id} tem {$rows->count()} linhas com o SKU {$sku}. Resolver a duplicidade antes de editar.");
        }
        return $rows->first();
    }

    public function addItem(Order $order, string $productSku, int $quantity, ?int $actorUserId, bool $validarPlano = true): array
    {
        return $this->transacao($order, function (Order $order) use ($productSku, $quantity, $actorUserId, $validarPlano) {
            $this->assertQuantidade($quantity);
            $product = $this->resolveProduct($order, $productSku, $validarPlano);

            $existente = OrderItem::where('order_id', $order->id)->where('sku', $product->sku)->get();

            if ($existente->count() > 1) {
                throw new \DomainException("item_ambiguo: o pedido ja tem {$existente->count()} linhas com o SKU {$product->sku}.");
            }

            if ($existente->count() === 1) {
                $item    = $existente->first();
                $this->assertNaoKit($item);
                $qtdDe   = (int) $item->quantity;
                $novaQtd = $qtdDe + $quantity;
                $this->aplicaQuantidade($item, $novaQtd);
                $this->evento($order, 'item_quantity_changed', $actorUserId, [
                    'motivo'          => 'add_sku_existente',
                    'item_id'         => $item->id,
                    'sku'             => $item->sku,
                    'quantidade_de'   => $qtdDe,
                    'quantidade_para' => $novaQtd,
                ]);
            } else {
                $item = $this->criaItem($order, $product, $quantity);
                $this->evento($order, 'item_added', $actorUserId, [
                    'item_id'    => $item->id,
                    'sku'        => $item->sku,
                    'nome'       => $item->name,
                    'quantidade' => $quantity,
                    'unit_price' => (float) $item->unit_price,
                ]);
            }

            $this->recalcula($order);
            return ['order' => $order->fresh(['items']), 'item' => $item->fresh()];
        });
    }

    public function updateItem(Order $order, OrderItem $item, ?string $novoSku, ?int $quantity, ?int $actorUserId, bool $validarPlano = true): array
    {
        return $this->transacao($order, function (Order $order) use ($item, $novoSku, $quantity, $actorUserId, $validarPlano) {
            $item = $item->fresh();
            $this->assertNaoKit($item);

            if ($novoSku === null && $quantity === null) {
                throw new \InvalidArgumentException('Informe product_sku, quantity, ou os dois.');
            }

            $antes = [
                'item_id'    => $item->id,
                'sku'        => $item->sku,
                'nome'       => $item->name,
                'quantidade' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ];

            $qtdFinal = $quantity !== null ? $quantity : (int) $item->quantity;
            $this->assertQuantidade($qtdFinal);

            // Mandou product_sku? Entao releia o catalogo e atualize o CUSTO -- mesmo que
            // o SKU seja o mesmo. "Trocar pelo mesmo SKU" e justamente a acao de
            // recalcular custo depois de mexer no preco do produto (regra do Ruan,
            // 31/07/2026). Antes disso, SKU igual caia no ramo de quantidade e o custo
            // nunca era relido -- o pedido ia a zero.
            if ($novoSku !== null) {
                $product  = $this->resolveProduct($order, $novoSku, $validarPlano);
                $mudouSku = $novoSku !== $item->sku;

                if ($mudouSku) {
                    $colisao = OrderItem::where('order_id', $order->id)
                        ->where('sku', $product->sku)
                        ->where('id', '<>', $item->id)
                        ->exists();
                    if ($colisao) {
                        throw new \DomainException("sku_duplicado: o pedido ja tem uma linha com o SKU {$product->sku}. Ajuste a quantidade naquela linha.");
                    }
                }

                // unit_price e total sao a venda do marketplace e NAO mudam. O que a
                // troca altera e o que o fornecedor separa e cobra.
                $unitVenda = (float) $item->unit_price;
                $custo     = (float) ($product->price ?? 0);

                $campos = [
                    'product_id'          => $product->id,
                    'sku'                 => $product->sku,
                    'quantity'            => $qtdFinal,
                    'total'               => round($unitVenda * $qtdFinal, 2),
                    'supplier_unit_cost'  => $custo,
                    'supplier_total_cost' => round($custo * $qtdFinal, 2),
                    'legacy_sku_pai_id'   => $product->legacy_sku_pai_id,
                ];
                if ($mudouSku) {
                    $campos['name'] = $product->name;
                }
                $item->fill($campos)->save();

                $tipo = $mudouSku ? 'item_product_swapped' : 'item_cost_refreshed';
            } else {
                $this->aplicaQuantidade($item, $qtdFinal);
                $tipo = 'item_quantity_changed';
            }

            $this->evento($order, $tipo, $actorUserId, [
                'antes'  => $antes,
                'depois' => [
                    'item_id'    => $item->id,
                    'sku'        => $item->sku,
                    'nome'       => $item->name,
                    'quantidade' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ],
            ]);

            $this->recalcula($order);
            return ['order' => $order->fresh(['items']), 'item' => $item->fresh()];
        });
    }

    public function removeItem(Order $order, OrderItem $item, ?int $actorUserId): array
    {
        return $this->transacao($order, function (Order $order) use ($item, $actorUserId) {
            $item = $item->fresh();
            $this->assertNaoKit($item);

            $total = OrderItem::where('order_id', $order->id)->count();
            if ($total <= 1) {
                throw new \DomainException('ultimo_item: pedido sem item quebra totais, etiqueta e NF-e. Cancele o pedido em vez de esvazia-lo.');
            }

            $snapshot = [
                'item_id'    => $item->id,
                'sku'        => $item->sku,
                'nome'       => $item->name,
                'quantidade' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total'      => (float) $item->total,
            ];

            $item->delete();
            $this->evento($order, 'item_removed', $actorUserId, $snapshot);
            $this->recalcula($order);

            return ['order' => $order->fresh(['items']), 'item' => null];
        });
    }

    /**
     * Lock no pedido + revalidacao da policy DEPOIS do lock. Cobranca forcada
     * carimba wallet_paid_at e pode fechar o pedido no meio da edicao.
     */
    private function transacao(Order $order, \Closure $fn): array
    {
        return DB::transaction(function () use ($order, $fn) {
            $travado = Order::where('id', $order->id)->lockForUpdate()->first();

            if (!OrderEditPolicy::podeEditar($travado)) {
                throw new \DomainException(OrderEditPolicy::motivo($travado) . ": pedido {$order->id} nao pode mais ser editado.");
            }

            $wasDisabled = ProductObserver::$disableSync;
            ProductObserver::$disableSync = true;
            try {
                return $fn($travado);
            } finally {
                ProductObserver::$disableSync = $wasDisabled;
            }
        });
    }

    private function criaItem(Order $order, Product $product, int $quantity): OrderItem
    {
        $unit = (float) ($product->price ?? 0);
        return OrderItem::create([
            'order_id'            => $order->id,
            'product_id'          => $product->id,
            'sku'                 => $product->sku,
            'name'                => $product->name,
            'quantity'            => $quantity,
            'unit_price'          => $unit,
            'total'               => round($unit * $quantity, 2),
            'supplier_unit_cost'  => $unit,
            'supplier_total_cost' => round($unit * $quantity, 2),
            'legacy_sku_pai_id'   => $product->legacy_sku_pai_id,
        ]);
    }

    private function aplicaQuantidade(OrderItem $item, int $quantity): void
    {
        $unit      = (float) $item->unit_price;
        $custoUnit = (float) ($item->supplier_unit_cost ?? 0);
        $item->fill([
            'quantity'            => $quantity,
            'total'               => round($unit * $quantity, 2),
            'supplier_total_cost' => round($custoUnit * $quantity, 2),
        ])->save();
    }

    /**
     * A edicao mexe em UM unico total: supplier_total.
     *
     * Regra do Ruan, 31/07/2026: `subtotal` e `total` vem do MARKETPLACE e nao
     * mudam -- sao a venda que o comprador ja fechou (no pedido 97758, subtotal
     * 102.48 e total 68.75, a diferenca sendo cupom). O que a edicao altera e o
     * que o seller vai pagar ao fornecedor, porque e isso que entra na nota que o
     * fornecedor emite para ele.
     *
     * Historico: a primeira versao fazia total = subtotal + frete e levou o pedido
     * de 68.75 para 191.90 no teste. A segunda ainda recalculava o subtotal.
     */
    private function recalcula(Order $order): void
    {
        $itens = OrderItem::where('order_id', $order->id)->get();

        $order->update([
            'supplier_total' => round($itens->sum(function ($i) { return (float) $i->supplier_total_cost; }), 2),
        ]);
    }

    /**
     * Fornecedores cujo catalogo o cliente acessa: client_supplier INTERSECAO
     * plan_supplier do plano ativo. Permissao vem do ACESSO AO CATALOGO, nao de
     * bater com o fornecedor do pedido (decisao do Ruan, 31/07/2026).
     */
    public function suppliersLiberados(Order $order): array
    {
        return DB::table('client_supplier as cs')
            ->join('subscriptions as sub', function ($j) {
                $j->on('sub.client_id', '=', 'cs.client_id')->where('sub.status', '=', 'active');
            })
            ->join('plan_supplier as ps', function ($j) {
                $j->on('ps.plan_id', '=', 'sub.plan_id')->on('ps.supplier_id', '=', 'cs.supplier_id');
            })
            ->where('cs.client_id', $order->client_id)
            ->pluck('cs.supplier_id')
            ->toArray();
    }

    /**
     * Validacao de catalogo feita NO WL, que e onde plano e assinatura existem.
     *
     * Medido em 31/07/2026: o hub tem 23.258 clientes e 189 assinaturas ativas;
     * dos 200 pedidos nao repassados mais recentes, 13 de 13 clientes ficavam com
     * intersecao VAZIA no hub. Reavaliar a regra no hub recusava 100% dos casos
     * reais -- e o CLAUDE.md ja dizia que cliente e plano sao sempre locais do WL.
     */
    public function assertCatalogoLocal(Order $order, string $sku): void
    {
        $this->resolveProduct($order, $sku, true);
    }

    /**
     * $validarPlano = false nas chamadas vindas de federation: o WL ja validou o
     * plano do cliente dele, e o hub nao tem esse dado. O hub segue sendo fonte de
     * verdade do PEDIDO, nao do plano.
     */
    private function resolveProduct(Order $order, string $sku, bool $validarPlano = true): Product
    {
        $candidatos = Product::where('sku', $sku)->get();
        if ($candidatos->isEmpty()) {
            throw new \InvalidArgumentException("Produto de SKU {$sku} nao encontrado.");
        }

        // Placeholder de importacao: quando chega um SKU que o catalogo nao conhece,
        // nasce uma linha inativa com preco 0. Sao 875 delas nos suppliers 30/31 do hub.
        // Aceitar uma dessas zera o custo do pedido em silencio -- foi o que aconteceu
        // em 31/07/2026. Recusa com motivo, em vez de gravar zero.
        $ativos = $candidatos->where('is_active', 1);
        if ($ativos->isEmpty()) {
            throw new \InvalidArgumentException(
                "produto_inativo: o SKU {$sku} existe no catalogo mas esta INATIVO (provavel placeholder de importacao, preco zero). Corrija o cadastro antes de usar no pedido."
            );
        }

        if (!$validarPlano) {
            $escolhido = $ativos->firstWhere('supplier_id', $order->supplier_id) ?? $ativos->first();
            return $this->assertTemPreco($escolhido);
        }

        $permitidos = $this->suppliersLiberados($order);

        // Plano e assinatura sao locais do WL. Num banco onde o cliente nao tem plano
        // registrado (o hub tem 23.258 clientes e 189 assinaturas), a intersecao vem
        // sempre vazia e a regra recusaria 100% dos casos. Nao da para validar o que
        // nao existe: sem dado de plano, valem apenas as travas de produto ativo e
        // preco > 0. Onde o dado existe, a regra continua valendo.
        if (empty($permitidos)) {
            Log::info('[MUL-298] cliente sem plano neste banco -- validacao de catalogo ignorada', [
                'order_id'  => $order->id,
                'client_id' => $order->client_id,
                'sku'       => $sku,
            ]);
            $escolhido = $ativos->firstWhere('supplier_id', $order->supplier_id) ?? $ativos->first();
            return $this->assertTemPreco($escolhido);
        }

        $liberados = $ativos->filter(function ($p) use ($permitidos) {
            return in_array($p->supplier_id, $permitidos, true);
        });

        if ($liberados->isEmpty()) {
            throw new \InvalidArgumentException("Produto {$sku} nao pertence a nenhum catalogo liberado para este cliente.");
        }

        $escolhido = $liberados->firstWhere('supplier_id', $order->supplier_id) ?? $liberados->first();
        return $this->assertTemPreco($escolhido);
    }

    private function assertTemPreco(Product $p): Product
    {
        if ((float) ($p->price ?? 0) <= 0) {
            throw new \InvalidArgumentException(
                "preco_zerado: o produto {$p->sku} esta com preco 0 no catalogo. Corrija o preco antes de usar no pedido, senao o custo do pedido vai a zero."
            );
        }
        return $p;
    }

    private function assertQuantidade(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantidade deve ser maior que zero.');
        }
    }

    private function assertNaoKit(OrderItem $item): void
    {
        if ((int) $item->is_kit_component === 1 || $item->kit_source_item_id !== null) {
            throw new \DomainException('item_de_kit: editar componente de kit desfaz a relacao do kit (MUL-243). Nao suportado nesta versao.');
        }
    }

    /**
     * user_id e LOCAL de cada banco, como supplier_id e como order_items.id.
     * Numa chamada de federation o ator vem do WL e nao existe no hub -- gravar
     * esse id em order_events.user_id estoura a foreign key e derruba a edicao
     * inteira em rollback. O ator externo fica no metadata; a FK so recebe id que
     * existe neste banco.
     */
    private function evento(Order $order, string $tipo, ?int $actorUserId, array $meta): void
    {
        $userIdLocal = null;
        if ($actorUserId !== null && DB::table('users')->where('id', $actorUserId)->exists()) {
            $userIdLocal = $actorUserId;
        }

        OrderEvent::create([
            'order_id'    => $order->id,
            'event_type'  => $tipo,
            'description' => "MUL-298 {$tipo} pelo usuario #" . ($actorUserId ?? 0)
                             . ($userIdLocal === null && $actorUserId !== null ? ' (ator de outro banco)' : ''),
            'user_id'     => $userIdLocal,
            'metadata'    => $meta + [
                'actor_user_id'       => $actorUserId,
                'actor_user_id_local' => $userIdLocal,
            ],
        ]);

        Log::info("[MUL-298] {$tipo}", ['order_id' => $order->id, 'actor' => $actorUserId] + $meta);
    }
}
