#!/usr/bin/env python3
"""
FOR-119 — corrige duas coisas nos 3 bancos, com backup e reconciliacao.

1. DATA EM UTC: marketplace_created_at do Mercado Livre foi gravado com ->utc()
   enquanto o resto do sistema fala America/Sao_Paulo. Sintoma visivel: o pedido
   aparece criado DEPOIS de ter sido pago.

   Invariantes:
     - so source='mercadolivre' (Shopee ja grava local desde MUL-329)
     - so onde marketplace_created_at > paid_at
     - so onde a diferenca e de EXATAMENTE 3h (170..190 min) — o resto fica
     - subtrai 3h; nunca recalcula da API
     - paid_at, created_at e wallet_paid_at nao sao tocados

2. ITEM DUPLICADO: mesma (order_id, external_item_id) gravada 2x, inflando o
   supplier_total do pedido.

   Invariantes:
     - duplicata = sku, nome, quantidade, preco e custo TODOS iguais
     - preserva a linha de MENOR id
     - grupo com qualquer campo diferente NAO e duplicata e fica intacto
     - depois recalcula supplier_total pela soma dos itens restantes

Uso:  python3 for119-corrige-datas-e-duplicatas.py [--aplicar]
      Sem --aplicar, so mostra o que faria.
"""
import io, subprocess, sys

APLICAR = '--aplicar' in sys.argv

BANCOS = [
    ('jtdrop',    '/home/api.jtdrop.com.br/public_html',  3306),
    ('fornecefy', '/home/api.fornecefy.io/public_html',   3307),
    ('hubai',     '/home/api.hubai.io/public_html',       3306),
]

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

def sql(e, port, q):
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:300])
    return r.stdout

COND_DATA = ("source='mercadolivre' AND paid_at IS NOT NULL AND marketplace_created_at IS NOT NULL "
             "AND marketplace_created_at > paid_at "
             "AND TIMESTAMPDIFF(MINUTE, paid_at, marketplace_created_at) BETWEEN 170 AND 190")

for nome, path, port in BANCOS:
    e = env_de(path)
    print('===== %s =====' % nome)

    alvo = sql(e, port, "SELECT COUNT(*) FROM orders WHERE %s;" % COND_DATA).strip()
    print('  datas a corrigir : %s' % alvo)

    grupos = sql(e, port,
        "SELECT COUNT(*) FROM (SELECT order_id, external_item_id FROM order_items "
        "WHERE external_item_id IS NOT NULL AND external_item_id<>'' "
        "GROUP BY order_id, external_item_id "
        "HAVING COUNT(*) > 1 AND COUNT(DISTINCT CONCAT_WS('|',sku,name,quantity,unit_price,COALESCE(supplier_unit_cost,0)))=1) x;").strip()
    print('  grupos duplicados identicos : %s' % grupos)

    if not APLICAR:
        continue

    # --- 1. datas ---
    sql(e, port, "DROP TABLE IF EXISTS orders_bkp_for119;")
    sql(e, port, "CREATE TABLE orders_bkp_for119 AS SELECT id, marketplace_created_at, paid_at FROM orders WHERE %s;" % COND_DATA)
    sql(e, port, "UPDATE orders SET marketplace_created_at = marketplace_created_at - INTERVAL 3 HOUR WHERE %s;" % COND_DATA)
    restam = sql(e, port, "SELECT COUNT(*) FROM orders WHERE %s;" % COND_DATA).strip()
    bk = sql(e, port, "SELECT COUNT(*) FROM orders_bkp_for119;").strip()
    ok = sql(e, port, "SELECT COUNT(*) FROM orders o JOIN orders_bkp_for119 b ON b.id=o.id "
                      "WHERE o.marketplace_created_at < o.paid_at OR o.marketplace_created_at = o.paid_at;").strip()
    print('  DATAS   backup %s · restaram no padrao %s · agora criado<=pago %s' % (bk, restam, ok))

    # --- 2. duplicatas ---
    sql(e, port, "DROP TABLE IF EXISTS order_items_bkp_for119;")
    sql(e, port,
        "CREATE TABLE order_items_bkp_for119 AS SELECT i.* FROM order_items i "
        "JOIN (SELECT order_id, external_item_id, MIN(id) manter FROM order_items "
        "      WHERE external_item_id IS NOT NULL AND external_item_id<>'' "
        "      GROUP BY order_id, external_item_id "
        "      HAVING COUNT(*)>1 AND COUNT(DISTINCT CONCAT_WS('|',sku,name,quantity,unit_price,COALESCE(supplier_unit_cost,0)))=1) g "
        "  ON g.order_id=i.order_id AND g.external_item_id=i.external_item_id AND i.id > g.manter;")
    extras = sql(e, port, "SELECT COUNT(*) FROM order_items_bkp_for119;").strip()
    if extras != '0':
        sql(e, port, "DELETE FROM order_items WHERE id IN (SELECT id FROM order_items_bkp_for119);")
        sql(e, port,
            "UPDATE orders o SET o.supplier_total = "
            "(SELECT ROUND(SUM(COALESCE(i.supplier_total_cost,0)),2) FROM order_items i WHERE i.order_id=o.id) "
            "WHERE o.id IN (SELECT DISTINCT order_id FROM order_items_bkp_for119);")
    sobra = sql(e, port,
        "SELECT COUNT(*) FROM (SELECT order_id, external_item_id FROM order_items "
        "WHERE external_item_id IS NOT NULL AND external_item_id<>'' GROUP BY order_id, external_item_id "
        "HAVING COUNT(*)>1 AND COUNT(DISTINCT CONCAT_WS('|',sku,name,quantity,unit_price,COALESCE(supplier_unit_cost,0)))=1) x;").strip()
    print('  ITENS   removidos %s · grupos identicos restantes %s' % (extras, sobra))
