#!/usr/bin/env python3
"""
JT-019 — restaura no jtdrop os pedidos que a JT-013 apagou por classificacao errada.

O ERRO: a JT-013 classificou "de quem e o pedido" pelos ITENS — "nenhum item resolve
produto do supplier 13, logo nao e do JTDrop". Mas o campo que decide roteamento e o
`supplier_id` do PEDIDO, e ele dizia 13 em 902 dos 906 apagados. Nos pedidos cujo SKU
nao resolve (FOR-110: 82% dos anuncios fora do catalogo), o item parecia estrangeiro
e o pedido era do fornecedor.

Medido: 902 de 906 com supplier_id=13 no hub · 213 com etiqueta · R$ 45.310,47.

INVARIANTES:
  1. so restaura pedido cujo hubai_order_id NAO exista hoje no jtdrop (142 ja voltaram
     sozinhos pelo fan-out — reinserir criaria duplicata).
  2. id do backup pode estar ocupado (12 casos): deixa o MySQL atribuir id novo e
     preserva o hubai_order_id, que e a chave de correlacao entre bancos.
  3. os itens sao remapeados para o id novo do pedido; item orfao nao entra.
  4. reconciliacao: contagem por hubai_order_id, nunca por id.

Uso: python3 jt019-restaura-pedidos-apagados.py [--aplicar]
"""
import io, subprocess, sys

APLICAR = '--aplicar' in sys.argv
PATH = '/home/api.jtdrop.com.br/public_html'

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

E = env_de(PATH)
def sql(q):
    r = subprocess.run(['mysql','-u'+E['DB_USERNAME'],'-p'+E['DB_PASSWORD'],'-P3306',
                        '-h127.0.0.1', E['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:300])
    return r.stdout.strip()

cols = [c for c in sql("SELECT GROUP_CONCAT(column_name) FROM information_schema.columns "
                       "WHERE table_schema='%s' AND table_name='orders' ORDER BY ordinal_position;" % E['DB_DATABASE']).split(',')]
cols_sem_id = [c for c in cols if c != 'id']

alvo = sql("SELECT COUNT(*) FROM orders_bkp_jt016 b "
           "WHERE b.hubai_order_id IS NOT NULL "
           "AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);")
ja = sql("SELECT COUNT(*) FROM orders_bkp_jt016 b "
         "WHERE EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);")
etiq = sql("SELECT SUM(label_url IS NOT NULL AND label_url<>'') FROM orders_bkp_jt016 b "
           "WHERE NOT EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);")

print('no backup..................: %s' % sql("SELECT COUNT(*) FROM orders_bkp_jt016;"))
print('ja voltaram sozinhos.......: %s  (nao reinserir)' % ja)
print('A RESTAURAR................: %s' % alvo)
print('   destes, com etiqueta....: %s' % etiq)

if not APLICAR:
    print('\n[DRY-RUN] nada foi escrito. rode com --aplicar')
    raise SystemExit(0)

lista = ', '.join('`%s`' % c for c in cols_sem_id)
sql("INSERT INTO orders (%s) SELECT %s FROM orders_bkp_jt016 b "
    "WHERE b.hubai_order_id IS NOT NULL "
    "AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);" % (lista, lista))

icols = [c for c in sql("SELECT GROUP_CONCAT(column_name) FROM information_schema.columns "
                        "WHERE table_schema='%s' AND table_name='order_items' ORDER BY ordinal_position;" % E['DB_DATABASE']).split(',')]
icols_sem = [c for c in icols if c not in ('id', 'order_id')]
ilista = ', '.join('`%s`' % c for c in icols_sem)

sql("INSERT INTO order_items (order_id, %s) "
    "SELECT o.id, %s FROM order_items_bkp_jt016 i "
    "JOIN orders_bkp_jt016 b ON b.id = i.order_id "
    "JOIN orders o ON o.hubai_order_id = b.hubai_order_id "
    "WHERE NOT EXISTS(SELECT 1 FROM order_items x WHERE x.order_id=o.id AND x.external_item_id=i.external_item_id);"
    % (ilista, ', '.join('i.`%s`' % c for c in icols_sem)))

falta = sql("SELECT COUNT(*) FROM orders_bkp_jt016 b "
            "WHERE b.hubai_order_id IS NOT NULL "
            "AND NOT EXISTS(SELECT 1 FROM orders o WHERE o.hubai_order_id=b.hubai_order_id);")
tot = sql("SELECT COUNT(*) FROM orders;")
sem_item = sql("SELECT COUNT(*) FROM orders o JOIN orders_bkp_jt016 b ON b.hubai_order_id=o.hubai_order_id "
               "WHERE NOT EXISTS(SELECT 1 FROM order_items i WHERE i.order_id=o.id);")
print('\nRECONCILIACAO')
print('  ainda faltando restaurar : %s   (esperado 0)' % falta)
print('  total de pedidos agora...: %s' % tot)
print('  restaurados sem item.....: %s' % sem_item)
