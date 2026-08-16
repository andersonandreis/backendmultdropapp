#!/usr/bin/env python3
"""
FOR-123 — revisao de TUDO que foi medido e escrito em 13/08/2026.

Encerramento exigido pela skill dados-lote: invariantes verdes POS-aplicacao,
contagens fechando, relatorio antes x depois, backup nomeado, irresolveis entregues.

Cada bloco re-roda a invariante da tarefa original e diz VERDE ou VERMELHO.
"""
import io, subprocess

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

HUB = ('/home/api.hubai.io/public_html', 3306)
FOR = ('/home/api.fornecefy.io/public_html', 3307)
JT  = ('/home/api.jtdrop.com.br/public_html', 3306)

def q(alvo, s):
    p, port = alvo; e = env_de(p)
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',s], capture_output=True, text=True)
    return r.stdout.strip() if r.returncode == 0 else 'ERRO'

ok = ruim = 0
def check(tarefa, invariante, valor, esperado):
    global ok, ruim
    passou = str(valor) == str(esperado)
    if passou: ok += 1
    else: ruim += 1
    print('  [%s] %-9s %-52s %s' % ('VERDE' if passou else 'VERMELHO', tarefa, invariante,
          valor if passou else '%s (esperado %s)' % (valor, esperado)))

print('=' * 96)
print('REVISAO — invariantes re-rodadas em', q(HUB, 'SELECT NOW();'))
print('=' * 96)

# FOR-111 — custo aplicado
check('FOR-111', 'itens do escopo com custo > 0',
      q(HUB, "SELECT COUNT(*) FROM order_items WHERE id IN (SELECT id FROM order_items_bkp_for111) AND COALESCE(supplier_unit_cost,0)>0;"), 85)
check('FOR-111', 'cabecalho == soma dos itens (divergentes)',
      q(HUB, "SELECT COUNT(*) FROM orders o JOIN orders_bkp_for111 b ON b.id=o.id "
             "LEFT JOIN (SELECT order_id, ROUND(SUM(COALESCE(supplier_total_cost,0)),2) s FROM order_items GROUP BY order_id) t ON t.order_id=o.id "
             "WHERE ABS(COALESCE(o.supplier_total,0)-COALESCE(t.s,0)) >= 0.01;"), 0)

# FOR-115 — pagamentos
check('FOR-115', 'PIX pago com pedido NAO marcado',
      q(FOR, "SELECT COUNT(*) FROM pix_transactions t JOIN orders o ON o.id=t.order_id "
             "WHERE t.status='paid' AND o.wallet_paid_at IS NULL;"), 0)

# FOR-119 — datas e duplicatas
for nome, alvo in (('hub', HUB), ('fornecefy', FOR), ('jtdrop', JT)):
    check('FOR-119', 'criado depois de pago (%s)' % nome,
          q(alvo, "SELECT COUNT(*) FROM orders WHERE source='mercadolivre' AND paid_at IS NOT NULL "
                  "AND marketplace_created_at IS NOT NULL AND marketplace_created_at > paid_at "
                  "AND TIMESTAMPDIFF(MINUTE,paid_at,marketplace_created_at) BETWEEN 170 AND 190;"), 0)
    check('FOR-119', 'grupos de item duplicado identico (%s)' % nome,
          q(alvo, "SELECT COUNT(*) FROM (SELECT order_id FROM order_items WHERE external_item_id IS NOT NULL AND external_item_id<>'' "
                  "GROUP BY order_id, external_item_id HAVING COUNT(*)>1 "
                  "AND COUNT(DISTINCT CONCAT_WS('|',sku,name,quantity,unit_price,COALESCE(supplier_unit_cost,0)))=1) x;"), 0)

# FOR-121 — supplier pela mercadoria
check('FOR-121', 'conta fornecefy com supplier fixo',
      q(HUB, "SELECT COUNT(*) FROM marketplace_accounts WHERE service='fornecefy' AND supplier_id IS NOT NULL;"), 0)
check('FOR-121', 'supplier nao confere (excluindo os 36 pagos preservados)',
      q(HUB, "SELECT COUNT(*) FROM orders o JOIN marketplace_accounts a ON a.id=o.marketplace_account_id "
             "WHERE a.service='fornecefy' AND o.supplier_id IS NOT NULL "
             "AND NOT EXISTS(SELECT 1 FROM order_items i JOIN products p ON p.id=i.product_id "
             "WHERE i.order_id=o.id AND p.supplier_id=o.supplier_id AND p.is_active=1 AND p.price>0) AND o.wallet_paid_at IS NULL;"), 0)

# JT-019 / JT-020
check('JT-019', 'pedidos do backup ausentes do jtdrop',
      q(JT, "SELECT COUNT(*) FROM orders_bkp_jt016 b WHERE b.hubai_order_id IS NOT NULL "
            "AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);"), 0)
check('JT-020', 'marcados como oculto que NAO deviam (tem mercadoria jt)',
      q(JT, "SELECT COUNT(*) FROM orders o WHERE o.is_draft=1 AND o.draft_reason LIKE 'JT-020%%' "
            "AND EXISTS(SELECT 1 FROM order_items i JOIN products p ON p.id=i.product_id "
            "WHERE i.order_id=o.id AND p.supplier_id=13 AND p.is_active=1 AND p.price>0);"), 0)
check('JT-020', 'pedido PAGO ocultado',
      q(JT, "SELECT COUNT(*) FROM orders WHERE is_draft=1 AND draft_reason LIKE 'JT-020%%' AND wallet_paid_at IS NOT NULL;"), 0)

# integridade geral
for nome, alvo in (('hub', HUB), ('fornecefy', FOR), ('jtdrop', JT)):
    check('geral', 'itens orfaos (%s)' % nome,
          q(alvo, "SELECT COUNT(*) FROM order_items i LEFT JOIN orders o ON o.id=i.order_id WHERE o.id IS NULL;"), 0)
check('geral', 'hubai_order_id duplicado no jtdrop',
      q(JT, "SELECT COUNT(*) FROM (SELECT hubai_order_id FROM orders WHERE hubai_order_id IS NOT NULL "
            "GROUP BY hubai_order_id HAVING COUNT(*)>1) x;"), 0)

print('\n  VERDES: %d   VERMELHOS: %d' % (ok, ruim))
