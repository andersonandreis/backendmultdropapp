# scripts/dados — operacoes em lote sobre dado de producao

A skill `dados-lote` exige script **versionado**, nao heredoc perdido de sessao. Estes
arquivos sao os que rodaram de fato em 13/08/2026, salvos depois de rodar — nao antes,
que era o certo.

| script | tarefa | o que faz |
|---|---|---|
| `for-confere82.php` | [[FOR-115]] | consulta o Shipay de producao, 1 chamada por PIX pago, e gera o CONFERIR CSV |
| `for-aplica-custo.py` | [[FOR-111]] | preenche custo, `product_id` e SKU pai nos itens de pedido |
| `for-restaura.py` | [[JT-013]] | restaura vinculo produto->item no espelho do jtdrop, casando por SKU |
| `for-propaga.py` | FOR-116 | propaga `wallet_paid_at` do fornecefy para hub e jtdrop |
| `for-liga2.py` | FOR-117 | vincula pedido local ao hub por numero de marketplace e propaga pagamento |

## Licoes destes scripts, para o proximo

- **`for-liga2.py` existe porque `for-liga.py` quebrou duas vezes em producao**: mascara de
  data com `%%` que virou literal, e `UNIQUE` de `hubai_order_id` violado quando o pedido
  do hub ja estava vinculado a outro pedido local. **Os dois teriam aparecido num dry-run.**
  A versao final tolera o duplicado e conta como `hub_ja_ocupado` em vez de abortar.
- **`UPDATE` cru nao mexe em `updated_at`.** Reconciliar por `updated_at >= NOW()-INTERVAL`
  devolve zero e da a impressao falsa de que nada foi aplicado. Reconciliar sempre contra a
  tabela de backup.
- **Todo backup nomeado com o ID da tarefa** (`orders_bkp_for116`, `orders_bkp_for117`), e
  a reconciliacao e "quantos do backup estao no estado novo", nunca contagem global.
