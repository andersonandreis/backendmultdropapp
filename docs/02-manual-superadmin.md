# Manual Completo do Super Admin
**Acesso restrito em:** `seusite.com.br/admin`

Como dono do sistema (Super Admin), esta é a central de comando da operação B2B.

## 1. Gestão de Planos de Assinatura (Plans)
Toda a base de uso de seus Lojistas `/app` é governada pelos planos. 
* Em **Plans**, você cadastra pacotes (Ex. Plano "Basic R$49/mês" ou "Pro R$199/mês").
* A cobrança não é manual: você linkou o gateway Asaas via Webhooks do sistema. Se a pessoa pagar, o HubAI vira a chave virtual no servidor e o lojista consegue entrar no app no dia seguinte. Se não pagar, ele recebe bloqueio na tela de login de drop.

## 2. Inscrição de Novos Parceiros e Lojistas
* **Suppliers:** Aba onde se credencia os parceiros logísticos ou fabricantes do Brás/Pari e afins.
* **Clients:** Um CRM vitrine. Lista cada dropshipper espalhado no Brasil que se plugou na sua plataforma, juntamente do plano que ele assina e ao volume de vendas movimentado.

## 3. As Rédeas do Lucro: Descontos de Plataforma
Você pode fidelizar seus tops Dropshippers através dos **Platform Discounts**:
* Acesse **Discounts > Platform**.
* Crie Tiers (Faixas). Ex: "Quem enviar mais de 10.000 pedidos, ganha isenção ou 5% de desconto de custo nos SKUs da hubAI".

## 4. Auditoria de Integrações (`Sync Logs`)
Todo mês seus painéis podem apontar falhas com o Mercado Livre porque a API deles muda muito.
É pra isso que existe um olho de Deus no painel: A tabela **Sync Logs**. Tudo que trafega em modo "background" (Atualização de preços reversa, Shopee Tokens e Webhooks do Bling) é gravado lá e mantido por 30 dias na base pelo Robo (*Cron Job*) `CleanupSyncLogs` pra não inchar seu MySQL.

Caso o desenvolvedor precise de debug visual, a tela Admin provê leitura mastigada para os Erros API 403 e 500 dos Marketplaces.
