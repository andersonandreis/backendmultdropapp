# Manual Logístico: Fornecedor e Galpão
**Acesso exclusivo:** `seusite.com.br/admin`

A figura do Fornecedor é quem insere os produtos faturados no galpão, definindo a cadeia de valores do sistema. Se um produto for mal configurado pelo Fornecedor aqui, centenas de lojistas replicarão o erro.

## 1. Cadastro do Produto Mestre (Catalog)
1. Vá até a guia lateral **Products**.
2. Clique no título "Create Product". Você preencherá o SKU, EAN Código de Barras (fundamental para o fulfillment logo à frente) e Nome Base. 
3. O item mais sagrado: **O Preço de Custo (Supplier Price)**. Se a caneta custa R$ 2 pra ser enviada, você informa R$ 2. Este valor será travado na hora da separação, e creditado a você por cada item que despacharmos.
4. Preencha Peso e Dimensões (C x L x A). Sem esses campos o lojista será taxado incorretamente pelo Mercado Envios.

## 2. Abastecendo o Galpão HubAI (Shipments)
**Sou fornecedor, quero enviar para a HubAI a mercadoria**.
1. Em **Shipments**, clique "New Shipment". 
2. Você declara formalmente: *"Estou mandando 50 Camisas do Homem-Aranha"* através da NF #201300. 
3. O Caminhão encosta no Galpão. Ninguém tem saldo ainda.
4. O Operador logístico da HubAI clica em **Scan Shipment**, seleciona a NF do passo anterior e Bipa as caixas. Bipou verde as 50? O estoque flui pra dentro do banco unificado do sistema. Seus Dropshipers já podem começar a lucrar com elas.

## 3. Expedição Mágica (Fulfillment Diário)
No dia a dia: Onde os pedidos batem? 
* Vá na aba **Process Orders**. Esse grid exibe as ordens unificadas (Meli/Shopee) que já foram PAGA pelo consumidor final deles.
* Clique em imprimir etiquetas para chamar o base64 nativo das plataformas num PDF agrupado.
* Vá para **Scan Barcode**. 
  * O estoquista pegou a camisa; Bipou o painel! 
  * Bipou o pacote faturado. O Sistema atesta: Ordem 229 fechada. Status muda de `Preparing` -> `Shipped`.

## 4. Retirando seus Lucros (Saques / Withdrawals)
Ninguém é bobo de depositar estoque no Brás sem retorno automático.
* No menu lateral **Financial > Withdrawals**.
* O "Reconciliation Service" do Back-end somou centavo por centavo todo bip/Shipment que o Galpão executou com suas mercadorias.
* Solicite o Extrato e peça resgate financeiro via PIX. A solicitação ficará "Aguardando Aprovação", e o admin autoriza e limpa seu extrato no Gateway atrelado (Shipay).
