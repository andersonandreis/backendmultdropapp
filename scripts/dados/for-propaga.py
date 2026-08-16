import io, subprocess

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

def sql(e, port, q):
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:200])
    return r.stdout

pares = []
for ln in io.open('/root/prop.tsv', encoding='utf-8'):
    f = ln.rstrip('\n').split('\t')
    if len(f) == 2 and f[0] and f[1]: pares.append((f[0], f[1]))

for nome, path, port, col in [
    ('HUB',    '/home/api.hubai.io/public_html',       3306, 'id'),
    ('JTDROP', '/home/api.jtdrop.com.br/public_html',  3306, 'hubai_order_id'),
]:
    e = env_de(path)
    ids = ','.join(p[0] for p in pares)
    sql(e, port, "DROP TABLE IF EXISTS orders_bkp_for116;")
    sql(e, port, "CREATE TABLE orders_bkp_for116 AS SELECT id, %s AS ref, wallet_paid_at, updated_at "
                 "FROM orders WHERE %s IN (%s) AND wallet_paid_at IS NULL;" % (col, col, ids))
    bk = sql(e, port, "SELECT COUNT(*) FROM orders_bkp_for116;").strip()

    feitos = 0
    for ref, pago in pares:
        r = sql(e, port, "UPDATE orders SET wallet_paid_at='%s' WHERE %s=%s AND wallet_paid_at IS NULL;"
                         % (pago, col, ref))
        feitos += 1
    restam = sql(e, port, "SELECT COUNT(*) FROM orders WHERE %s IN (%s) AND wallet_paid_at IS NULL;" % (col, ids)).strip()
    agora  = sql(e, port, "SELECT COUNT(*) FROM orders WHERE %s IN (%s) AND wallet_paid_at IS NOT NULL;" % (col, ids)).strip()
    print('%-7s backup %s · marcados agora %s · ainda sem marcar %s' % (nome, bk, agora, restam))
