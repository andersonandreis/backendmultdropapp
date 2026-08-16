#!/usr/bin/env python3
"""
FOR-122 — auditoria da ultima semana: pedidos do Fornecefy que sao do JTDrop.

Cruza CINCO fontes por pedido: hub, fornecefy, jtdrop, webhook_deliveries e
pix_transactions/Shipay.

INVARIANTES
  1. universo = 7 dias · conta service='fornecefy' · MERCADORIA do JTDrop
     (>=1 item resolvendo products.supplier_id=13, is_active=1, price>0)
  2. correlacao entre bancos so por hubai_order_id
  3. fonte da verdade: pedido/roteamento=hub · etiqueta=hub · pagamento=wallet_paid_at
     do WL confirmado no Shipay · entrega=webhook_deliveries
  4. janela relativa por banco, nunca CURDATE (fornecefy roda UTC)
  5. Sigma das classes == total extraido
"""
import io, json, subprocess

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

HUB = ('/home/api.hubai.io/public_html', 3306)
FOR = ('/home/api.fornecefy.io/public_html', 3307)
JT  = ('/home/api.jtdrop.com.br/public_html', 3306)

def sql(alvo, q):
    p, port = alvo; e = env_de(p)
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:300])
    return r.stdout

# ---------------- EXTRAIR: universo, do hub ----------------
base = sql(HUB, """
SELECT o.id, COALESCE(o.external_order_id,''), COALESCE(a.account_name,''),
       DATE_FORMAT(COALESCE(o.marketplace_created_at,o.created_at),'%d/%m %H:%i'),
       COALESCE(o.canonical_status,''), o.total, COALESCE(o.supplier_total,0),
       IF(o.label_url IS NULL OR o.label_url='',0,1),
       IF(o.label_printed_at IS NULL,0,1), IF(o.shipped_at IS NULL,0,1),
       IF(o.wallet_paid_at IS NULL,0,1), COALESCE(o.label_status_reason,''),
       o.marketplace_account_id
FROM orders o JOIN marketplace_accounts a ON a.id=o.marketplace_account_id
WHERE a.service='fornecefy'
  AND o.created_at >= NOW() - INTERVAL 7 DAY
  AND EXISTS(SELECT 1 FROM order_items i JOIN products p ON p.id=i.product_id
             WHERE i.order_id=o.id AND p.supplier_id=13 AND p.is_active=1 AND p.price>0);
""").strip()
linhas = [l.split('\t') for l in base.split('\n') if l.strip()]
ids = [l[0] for l in linhas]
print('UNIVERSO: %d pedidos (7 dias · conta fornecefy · mercadoria do JTDrop)' % len(ids))
if not ids:
    raise SystemExit(0)
lista = ','.join(ids)

# ---------------- EXTRAIR: as outras fontes ----------------
def mapa(alvo, q, chave=0):
    d = {}
    for l in sql(alvo, q).strip().split('\n'):
        if not l.strip(): continue
        f = l.split('\t'); d[f[chave]] = f
    return d

forn = mapa(FOR, "SELECT hubai_order_id, id, IF(label_url IS NULL OR label_url='',0,1), "
                 "IF(wallet_paid_at IS NULL,0,1), COALESCE(canonical_status,'') "
                 "FROM orders WHERE hubai_order_id IN (%s);" % lista)
jtd  = mapa(JT,  "SELECT hubai_order_id, id, IF(label_url IS NULL OR label_url='',0,1), "
                 "IF(wallet_paid_at IS NULL,0,1), is_draft "
                 "FROM orders WHERE hubai_order_id IN (%s);" % lista)

entregas = {}
for l in sql(HUB, """
SELECT JSON_UNQUOTE(JSON_EXTRACT(d.payload,'$.data.order.id')) oid, t.slug, COUNT(*)
FROM webhook_deliveries d
JOIN tenant_webhook_endpoints e ON e.id=d.endpoint_id
JOIN tenants t ON t.id=e.tenant_id
WHERE d.created_at >= NOW() - INTERVAL 8 DAY AND d.status='success'
GROUP BY oid, t.slug;""").strip().split('\n'):
    if not l.strip(): continue
    f = l.split('\t')
    entregas.setdefault(f[0], set()).add(f[1])

pix = mapa(FOR, "SELECT o.hubai_order_id, t.status, t.external_id, t.amount "
                "FROM pix_transactions t JOIN orders o ON o.id=t.order_id "
                "WHERE o.hubai_order_id IN (%s) AND t.status='paid';" % lista)

# ---------------- CLASSIFICAR ----------------
regs, classes = [], {}
for l in linhas:
    (oid, ext, loja, dt, status, total, custo, tem_etiq,
     impressa, enviada, pago_hub, motivo, conta) = l
    f = forn.get(oid); j = jtd.get(oid)
    ent = entregas.get(oid, set())

    if status == 'cancelled':
        classe = 'CANCELADO'
    elif tem_etiq == '0':
        classe = 'SEM_ETIQUETA'
    elif impressa == '1' or enviada == '1':
        classe = 'JA_DESPACHADO'
    else:
        classe = 'ETIQUETA_DISPONIVEL'

    if classe != 'CANCELADO':
        if not f: classe += '__falta_no_FORNECEFY'
        elif not j: classe += '__falta_no_JTDROP'
        elif j and j[4] == '1': classe += '__oculto_no_JTDROP'

    classes[classe] = classes.get(classe, 0) + 1
    regs.append({
        'classe': classe, 'hub': oid, 'pedido': ext, 'loja': loja, 'data': dt,
        'status': status, 'venda': total, 'custo': custo,
        'etiqueta_hub': tem_etiq, 'impressa': impressa, 'enviada': enviada,
        'motivo': motivo,
        'no_fornecefy': '1' if f else '0', 'etiq_fornecefy': f[2] if f else '',
        'pago_fornecefy': f[3] if f else '',
        'no_jtdrop': '1' if j else '0', 'etiq_jtdrop': j[2] if j else '',
        'oculto_jtdrop': j[4] if j else '',
        'entregue_fornecefy': '1' if 'fornecefy' in ent else '0',
        'entregue_jtdrop': '1' if 'jtdrop' in ent else '0',
        'pix_pago': '1' if oid in pix else '0',
        'pix_external_id': pix[oid][2] if oid in pix else '',
    })

# ---------------- VALIDAR ----------------
print()
for k in sorted(classes, key=lambda x: -classes[x]):
    print('  %-42s %4d' % (k, classes[k]))
print('  %-42s %4d   Sigma==universo: %s' % ('TOTAL', sum(classes.values()), 
      'OK' if sum(classes.values()) == len(regs) else 'FALHOU'))

json.dump(regs, io.open('/root/for122-extracao.json','w',encoding='utf-8'), ensure_ascii=False, indent=1)
print('\nintermediario: /root/for122-extracao.json')
