#!/usr/bin/env python3
"""
FOR-121 — o supplier do pedido passa a vir da MERCADORIA, nao da conta.

O PROBLEMA (criado por mim em 13/08): o backfill de contas inseriu 1.423 contas do
Fornecefy no hub com supplier_id = 13 FIXO. O pedido herda $account->supplier_id
(WebhookOrderService:221), entao TODO pedido dessas contas nasce marcado como mercadoria
do JTDrop — inclusive produto proprio do seller, que o JTDrop nao tem e nao despacha.

Consequencia medida: dos 906 pedidos que a JT-013 apagou do painel do JTDrop, ZERO tinham
mercadoria do JTDrop; 890 nao resolviam produto nenhum. E o hub continua reenviando
(308 order.created ao jtdrop nas ultimas 6h), entao limpar no WL nao segura.

CRITERIO (o que vai ao CSV para o Ruan validar):
    o pedido e do fornecedor X  <=>  ao menos um item resolve para products.supplier_id = X
    nao pela conta · nao pelo cabecalho · pela MERCADORIA

INVARIANTES:
  1. so pedidos de contas service='fornecefy' — nao tocar em multdrop/mestoredrop/etc
  2. produto so conta se for confiavel: is_active=1 AND price>0 (MUL-318)
  3. itens de fornecedores DIFERENTES no mesmo pedido => fica NULO e vai para a lista;
     o modelo nao sabe representar pedido multi-fornecedor (MUL-315)
  4. supplier NOVO == supplier ATUAL => nao escreve (evita update inutil)
  5. pedido ja PAGO ao fornecedor nao muda de supplier sem decisao do Ruan — mudar
     reatribuiria dinheiro ja movimentado
  6. Sigma das classes == total analisado

Uso: python3 for121-supplier-por-mercadoria.py [--aplicar] [--limite N]
"""
import io, subprocess, sys, csv

APLICAR = '--aplicar' in sys.argv
LIMITE = 0
for i, a in enumerate(sys.argv):
    if a == '--limite' and i + 1 < len(sys.argv): LIMITE = int(sys.argv[i + 1])

E = {}
for ln in io.open('/home/api.hubai.io/public_html/.env', encoding='utf-8', errors='ignore'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); E[k.strip()] = v.strip().strip('"')

def sql(q):
    r = subprocess.run(['mysql','-u'+E['DB_USERNAME'],'-p'+E['DB_PASSWORD'],'-h127.0.0.1',
                        E['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:300])
    return r.stdout

# ---------- EXTRAIR ----------
linhas = sql("""
SELECT o.id, o.supplier_id, o.canonical_status, o.total,
       IF(o.wallet_paid_at IS NULL,0,1) pago,
       COALESCE((SELECT GROUP_CONCAT(DISTINCT p.supplier_id)
                 FROM order_items i JOIN products p ON p.id=i.product_id
                 WHERE i.order_id=o.id AND p.is_active=1 AND p.price>0),'') suppliers_da_mercadoria,
       COALESCE(a.account_name,''), o.external_order_id, DATE(o.created_at)
FROM orders o JOIN marketplace_accounts a ON a.id=o.marketplace_account_id
WHERE a.service='fornecefy';""").strip().split('\n')

# ---------- CLASSIFICAR ----------
classes = {}
regs = []
for ln in linhas:
    f = ln.split('\t')
    if len(f) < 9: continue
    oid, atual, status, total, pago, sups, loja, ext, dia = f
    lista = [s for s in sups.split(',') if s]
    if len(lista) == 1:
        novo, classe = lista[0], ('MANTEM' if lista[0] == atual else 'TROCA')
    elif len(lista) > 1:
        novo, classe = '', 'MULTI_FORNECEDOR'
    else:
        novo, classe = '', ('JA_NULO' if atual in ('', 'NULL') else 'SEM_MERCADORIA')
    if pago == '1' and classe in ('TROCA', 'SEM_MERCADORIA'):
        classe = 'PAGO_' + classe
    classes[classe] = classes.get(classe, 0) + 1
    regs.append([classe, oid, ext, loja, dia, status, total, atual, novo, pago])

# ---------- VALIDAR ----------
print('EXTRAIDOS: %d pedidos de contas fornecefy' % len(regs))
print()
for k in sorted(classes, key=lambda x: -classes[x]):
    print('  %-22s %5d' % (k, classes[k]))
print('  %-22s %5d   (Sigma == extraidos: %s)' % ('TOTAL', sum(classes.values()),
      'OK' if sum(classes.values()) == len(regs) else 'FALHOU'))

# ---------- DRY-RUN CSV ----------
buf = io.StringIO()
w = csv.writer(buf, delimiter=';', lineterminator='\r\n')
w.writerow(['classe','pedido_hub','pedido_marketplace','loja','data','status','venda',
            'supplier_ATUAL','supplier_PELA_MERCADORIA','ja_pago_fornecedor','CONFIRMA'])
for r in regs:
    r2 = list(r); r2[6] = r2[6].replace('.', ',')
    w.writerow(r2 + [''])
io.open('/root/CONFERIR-for121-supplier.csv','w',encoding='utf-8-sig',newline='').write(buf.getvalue())
print('\nCSV: /root/CONFERIR-for121-supplier.csv (%d linhas)' % len(regs))

if not APLICAR:
    print('\n[DRY-RUN] nada escrito. rode com --aplicar')
    raise SystemExit(0)

# ---------- APLICAR ----------
alvo = [r for r in regs if r[0] in ('TROCA', 'SEM_MERCADORIA')]
if LIMITE: alvo = alvo[:LIMITE]
sql("CREATE TABLE IF NOT EXISTS orders_bkp_for121 AS SELECT id, supplier_id, tenant_slug, updated_at FROM orders WHERE 1=0;")
for r in alvo:
    oid, novo = r[1], r[8]
    sql("INSERT INTO orders_bkp_for121 SELECT id, supplier_id, tenant_slug, updated_at FROM orders WHERE id=%s;" % oid)
    sql("UPDATE orders SET supplier_id=%s WHERE id=%s;" % (novo if novo else 'NULL', oid))
print('\nAPLICADO em %d pedidos · backup orders_bkp_for121 (%s linhas)'
      % (len(alvo), sql("SELECT COUNT(*) FROM orders_bkp_for121;").strip()))
