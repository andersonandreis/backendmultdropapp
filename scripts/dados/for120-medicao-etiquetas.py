#!/usr/bin/env python3
"""
FOR-120 — medicao das etiquetas e dos pedidos recentes, nos 3 bancos.

INVARIANTES (declaradas antes da primeira query):
  1. "recente" = NOW() - INTERVAL n DAY em CADA banco. Nunca CURDATE(): o fornecefy
     roda em UTC e hub/jtdrop em local — o corte por CURDATE ja devolveu "zero pedidos"
     por engano em 13/08.
  2. pedido != item != notificacao. Contagem sempre por orders.id.
  3. "etiqueta disponivel" e a definicao do BACKEND, nao uma minha:
     label_url NOT NULL e <> '' AND label_printed_at IS NULL AND shipped_at IS NULL.
  4. cancelado conta em coluna separada — etiqueta de pedido cancelado nao e trabalho.
  5. fonte da verdade da etiqueta = HUB (busca no marketplace e espelha pro WL).
     Ausencia no WL pode ser falha de espelho; ausencia no hub e ausencia real.
  6. correlacao entre bancos so por hubai_order_id.
"""
import io, subprocess

BANCOS = [
    ('hubai',     '/home/api.hubai.io/public_html',      3306),
    ('fornecefy', '/home/api.fornecefy.io/public_html',  3307),
    ('jtdrop',    '/home/api.jtdrop.com.br/public_html', 3306),
]
DISPONIVEL = "label_url IS NOT NULL AND label_url<>'' AND label_printed_at IS NULL AND shipped_at IS NULL"

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

def sql(e, port, q):
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:250])
    return r.stdout.strip()

print('=' * 78)
print('1. ETIQUETA DISPONIVEL  (definicao do backend)')
print('=' * 78)
print('%-11s %8s %8s %10s %12s' % ('banco', 'TOTAL', 'vivos', 'cancelados', 'sem custo'))
for nome, path, port in BANCOS:
    e = env_de(path)
    r = sql(e, port,
        "SELECT COUNT(*), SUM(canonical_status<>'cancelled'), SUM(canonical_status='cancelled'), "
        "SUM(COALESCE(supplier_total,0)=0) FROM orders WHERE %s;" % DISPONIVEL).split('\t')
    print('%-11s %8s %8s %10s %12s' % (nome, r[0], r[1], r[2], r[3]))

print()
print('=' * 78)
print('2. PEDIDOS RECENTES (7 dias) — quantos tem etiqueta')
print('=' * 78)
print('%-11s %8s %10s %12s %10s' % ('banco', 'pedidos', 'c/ etiq.', 'disponivel', 'cancelados'))
for nome, path, port in BANCOS:
    e = env_de(path)
    r = sql(e, port,
        "SELECT COUNT(*), SUM(label_url IS NOT NULL AND label_url<>''), "
        "SUM(%s), SUM(canonical_status='cancelled') "
        "FROM orders WHERE created_at >= NOW() - INTERVAL 7 DAY;" % DISPONIVEL).split('\t')
    print('%-11s %8s %10s %12s %10s' % (nome, r[0], r[1], r[2], r[3]))

print()
print('=' * 78)
print('3. PEDIDOS RECENTES DO FORNECEFY — onde eles estao')
print('=' * 78)
eh = env_de('/home/api.hubai.io/public_html')
ef = env_de('/home/api.fornecefy.io/public_html')
ej = env_de('/home/api.jtdrop.com.br/public_html')

hub_ids = sql(eh, 3306,
    "SELECT o.id FROM orders o JOIN marketplace_accounts a ON a.id=o.marketplace_account_id "
    "WHERE a.service='fornecefy' AND o.created_at >= NOW() - INTERVAL 7 DAY;").split()
print('no HUB, de contas fornecefy (7 dias): %d pedidos' % len(hub_ids))
if hub_ids:
    lista = ','.join(hub_ids)
    nf = sql(ef, 3307, "SELECT COUNT(*) FROM orders WHERE hubai_order_id IN (%s);" % lista)
    nj = sql(ej, 3306, "SELECT COUNT(*) FROM orders WHERE hubai_order_id IN (%s);" % lista)
    et = sql(eh, 3306, "SELECT SUM(label_url IS NOT NULL AND label_url<>'') FROM orders WHERE id IN (%s);" % lista)
    print('   espelhados no FORNECEFY : %s' % nf)
    print('   espelhados no JTDROP    : %s' % nj)
    print('   com etiqueta no hub     : %s' % (et or 0))

print()
print('   e o que o FORNECEFY criou por conta propria na mesma janela:')
r = sql(ef, 3307,
    "SELECT COUNT(*), SUM(hubai_order_id IS NULL), SUM(label_url IS NOT NULL AND label_url<>'') "
    "FROM orders WHERE created_at >= NOW() - INTERVAL 7 DAY;").split('\t')
print('   total %s · sem vinculo com o hub %s · com etiqueta %s' % (r[0], r[1], r[2]))
