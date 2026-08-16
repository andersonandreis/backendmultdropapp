# SEL-186 — Proposta: Nova Estrutura de Planos seller.global

**Data:** 2026-07-17
**Status:** aguarda_aprovacao_Ruan
**Baseado em:** SEL-185 (analise funil) + decisoes Ruan 17/07 13:53 + codigo real (migrations + middleware)

---

## 1. Matriz Features x Planos Atuais

| Feature | tiktok_free | start (R$97) | scaling (R$197) | pro (R$297) | supplier (R$49,90) |
|---|---|---|---|---|---|
| Trends TikTok Shop | ✅ (504 items) | ✅ | ✅ | ✅ | ❌ |
| Catalogo fornecedores (amostra 30) | ✅ | ✅ | ✅ | ✅ | — |
| Catalogo completo | ❌ | ✅ limitado | ✅ | ✅ | — |
| max_skus publicaveis | 0 | 100 | 200 | 300+ | — |
| Bonus 50% primeiras vendas | ✅ (universal SEL-179) | ✅ | ✅ | ✅ | — |
| IA imagens (ai_credits_balance) | 0 creditos | baseline | baseline | alto | — |
| Geracao video (Kling AI) | ❌ | ? | ? | ✅ ai_features | — |
| Grupo VIP WhatsApp | ❌ | ❌ | ❌ | ✅ (auto_invite SEL-113) | — |
| max_marketplace_connections | 0 | 1-2 | 3? | 5+ | — |
| max_erp_connections | 0 | 1 | 1 | 2+ | — |
| Drop Internacional | ❌ | ? | ? | ✅ has_drop_internacional | — |
| Subsidios premium | ❌ | parcial | parcial | ✅ | — |
| Push notificacoes (admin+client) | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | — |
| Prioridade suporte | ❌ | ❌ | ❌ | ✅ | — |
| **Assinantes ativos (17/07)** | **761** | **6** | **0** | **13** | **0** |

Fonte das colunas: migrations (2024_01_01_000000, 2026_07_12, 2026_07_16_004500, 2026_06_06, 2026_06_27, 2026_07_11).

---

## 2. Proposta Nova Estrutura

### 2A. Planos publicos na home

| Plano | Slug | Preco | Publico? |
|---|---|---|---|
| **Free** | tiktok_free | R$0 | ✅ mantido |
| **Premium** | premium | R$297/mes | ✅ unico plano pago na home |
| ~~Start~~ | start | R$97 | ❌ remover da home (manter ativo pra 6 assinantes) |
| ~~Scaling~~ | scaling | R$197 | ❌ desativar (0 assinantes) — is_active=false |
| ~~Supplier~~ | supplier | R$49,90 | ❌ desativar (0 assinantes) — is_active=false |

### 2B. Tabela de features Free vs Premium

| Feature | Free | Premium (R$297) |
|---|---|---|
| Trends TikTok Shop (504 items) | ✅ | ✅ |
| Bonus 50% primeiras vendas (SEL-179) | ✅ | ✅ |
| Amostra catalogo (30 produtos) | ✅ (cap RestrictFreeAccess) | — |
| Catalogo completo + 300+ SKUs ativos | ❌ | ✅ |
| IA imagens ilimitada | 3 creditos gratis (SEL-185 rec #4) | ✅ ilimitado |
| Geracao video Kling AI | ❌ | ✅ (ai_features JSON) |
| Grupo VIP WhatsApp (auto_invite SEL-113) | ❌ | ✅ |
| Drop Internacional | ❌ | ✅ |
| max_marketplace_connections | 0 | 5+ |
| Subsidios premium catálogo | ❌ | ✅ |
| Prioridade suporte | ❌ | ✅ |

### 2C. Cupons — nova tabela (SEL-186 Parte B ja implementada)

Tabela `coupons` ja existia; adicionamos:
- `applies_to_plan_slug` (restringe ao plano, ex: 'premium')
- `description` (anotacao interna do Ruan)
- Tabela `subscription_coupons` (auditoria de descontos aplicados)
- `CouponsController` com GET `/api/checkout/coupons/{code}` e POST `/api/checkout/apply-coupon`
- Filament `CouponResource` atualizado com novos campos

---

## 3. Precificacao Premium — Racional

**Opcao A — Preco direto R$297/mes (mantém teto atual)**

Argumento: os 13 assinantes Pro ja pagam R$297. Criar plano novo com mesmo
preco e mais features (agrupa tudo) e movimento sem risco de canibalizar.
Nao precisa convencer ninguem de pagar mais — ja pagam isso.

**Opcao B — Anchor R$497 riscado → R$297/mes (recomendacao)**

Argumento copy (Big Black Book — 4 U's + ancoragem Cialdini):
- "De R$497 por R$297/mes" comunica valor sem mudar o preco real.
- R$497 funciona como ancora: faz R$297 parecer desconto, nao custo.
- Ancora cria enquadramento de "perda evitada" (aversao a perda > ganho).
- Racional real: R$497 seria o preco sem cupom de lancamento; grupo paga R$297.
  Isso e verdadeiro se o Ruan quiser segurar a logica (cupom LANCAMENTO permanente).

**Recomendacao:** Opcao B. Usar R$497 como preco de tabela da home,
com rodape "com cupom de lancamento" → R$297. Grupo fecha o cupom certo.

---

## 4. Cupons — 3 Exemplos Pro Grupo do Ruan

Esses cupons serao criados via /admin → Cupons quando Ruan aprovar.
Copy para o Ruan usar na mensagem do grupo:

---

### Cupom 1 — `GRUPO50`
**Desconto:** 50% off por 3 meses (implementar como fixed: R$148,50 por 3 ciclos*)
**Preco final:** R$148,50/mes pelos primeiros 3 meses, depois R$297 normal

**Copy pro grupo:**
> Quem ta aqui dentro nao paga cheio. So dessa vez: GRUPO50 no checkout
> tira 50% do Premium pelos primeiros 3 meses. De R$297 voce paga R$148,50.
> Link: seller.global/planos — cupom: GRUPO50. Vale ate [data]. Depois some.

Gatilhos ativados: reciprocidade (grupo exclusivo), escassez (data), prova social (so do grupo).

---

### Cupom 2 — `LANCE97`
**Desconto:** fixed R$200 (preco final R$97/mes por 1 mes)
**Anchor:** "Start voltou — por 1 mes"

**Copy pro grupo:**
> Quem ficou de fora do Start R$97 — da licenca. Mes que vem: LANCE97 no checkout
> e o Premium sai por R$97 no primeiro mes. Tudo incluso: video, IA, grupo VIP.
> Depois que esse mes fechar, volta pra R$297. Aproveita enquanto tem vaga.
> Link: seller.global/planos

Gatilhos ativados: ancoragem (R$97 anchor historico), escassez (1 ciclo), lisonja (quem ficou de fora).

---

### Cupom 3 — `VIP7DIAS`
**Desconto:** fixed R$290 (preco final R$7 por 7 dias de trial)
**Modelo:** trial de R$7 — muito baixo pra recusar, cria comprometimento (Cialdini)

**Copy pro grupo:**
> Pra quem ainda ta na duvida: R$7 da acesso completo ao Premium por 7 dias.
> Video com IA, grupo, catalogo todo, subsidios. Testa tudo de verdade.
> Entra com VIP7DIAS no checkout. Cancela se nao gostar. Se ficar, e R$297 no mes seguinte.
> seller.global/planos

Gatilhos ativados: comprometimento/coerencia (quem paga R$7 ja se comprometeu),
aversao a perda (se nao renovar, perde acesso ao que ja esta usando),
reciprocidade (voce "entregou" 7 dias reais de valor).

*Nota tecnica: desconto por 3 ciclos requer logica de subscription_coupons
com campo cycle_count — nao implementado neste PR (seria overcomplexo sem
a integracao Pagar.me confirmar ciclos). Na pratica, criar 1 ciclo de desconto
e monitorar manualmente ou usar o max_uses_per_client=1 + ativar via coupon
apenas 1x no Pagar.me metadata. SEL-187 pode implementar ciclos recorrentes.

---

## 5. O que NAO fazer agora (decisao protegida)

- **NAO deletar planos Start/Scaling/Supplier**: os 6 Start ativos precisam
  continuar vigentes. Start fica is_active=true mas removido da landing.
  Scaling e Supplier: is_active=false (0 assinantes, sem risco).
- **NAO tocar no frontend seller-global** neste PR (conflito com Agent A SEL-190).
  SEL-187 vai reorganizar landing/planos no frontend.
- **NAO implementar ciclos recorrentes de desconto** agora — Pagar.me nao
  tem suporte simples a "desconto por N ciclos" sem subscription items separados.

---

## 6. Evidencia de Implementacao (Parte B)

Arquivos criados/modificados neste PR (feature/sel-186-planos-cupons):

| Arquivo | Tipo | O que faz |
|---|---|---|
| `database/migrations/2026_07_17_140000_sel186_add_plan_slug_to_coupons.php` | migration | Adiciona `applies_to_plan_slug` e `description` em `coupons` |
| `database/migrations/2026_07_17_140500_sel186_create_subscription_coupons_table.php` | migration | Cria tabela `subscription_coupons` (auditoria) |
| `app/Models/SubscriptionCoupon.php` | model | Model da trilha de descontos |
| `app/Models/Coupon.php` | model update | Adiciona `validateForCheckout()`, `calculateDiscount()`, nova FK |
| `app/Http/Controllers/Api/CouponsController.php` | controller | GET coupons/{code} + POST apply-coupon |
| `app/Filament/Resources/CouponResource.php` | resource update | Campos `description` e `applies_to_plan_slug` no admin |
| `routes/api.php` | routes | Registra as 2 rotas publicas de cupom |

**O que NAO foi feito:**
- Integracao do desconto no fluxo de pagamento do CheckoutController
  (depende de decisao de como o Pagar.me recebe preco descontado — SEL-187)
- Desativacao de planos Scaling/Supplier no banco (depende de OK Ruan)
- Frontend seller-global (SEL-187)
- Criacao dos cupons GRUPO50/LANCE97/VIP7DIAS no banco
  (Ruan faz pelo /admin apos aprovacao)
