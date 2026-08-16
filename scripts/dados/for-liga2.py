import io, subprocess

def env_de(p):
    e = {}
    for ln in io.open(p + '/.env', encoding='utf-8', errors='ignore'):
        if '=' in ln and not ln.startswith('#'):
            k, v = ln.split('=', 1); e[k.strip()] = v.strip().strip('"')
    return e

def sql(e, port, q, tolerante=False):
    r = subprocess.run(['mysql','-u'+e['DB_USERNAME'],'-p'+e['DB_PASSWORD'],'-P'+str(port),
                        '-h127.0.0.1', e['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0:
        if tolerante: return None
        raise RuntimeError(r.stderr[:200])
    return r.stdout

fo = env_de('/home/api.fornecefy.io/public_html')
hu = env_de('/home/api.hubai.io/public_html')
jt = env_de('/home/api.jtdrop.com.br/public_html')

locais = []
for ln in io.open('/root/149.tsv', encoding='utf-8'):
    f = ln.rstrip('\n').split('\t')
    if len(f) >= 3 and (f[1] or f[2]): locais.append(f)

pagos = {}
for ln in sql(fo, 3307, "SELECT id, DATE_FORMAT(wallet_paid_at,'%Y-%m-%d %H:%i:%s') "
                        "FROM orders WHERE wallet_paid_at IS NOT NULL AND hubai_order_id IS NULL;").splitlines():
    f = ln.split('\t')
    if len(f) == 2: pagos[f[0]] = f[1]

for e, port in ((hu, 3306), (jt, 3306)):
    sql(e, port, "DROP TABLE IF EXISTS orders_bkp_for117;")
sql(hu, 3306, "CREATE TABLE orders_bkp_for117 AS SELECT id, external_order_id, wallet_paid_at, updated_at FROM orders WHERE 1=0;")
sql(jt, 3306, "CREATE TABLE orders_bkp_for117 AS SELECT id, hubai_order_id, wallet_paid_at, updated_at FROM orders WHERE 1=0;")

st = {'sem_no_hub': 0, 'hub_ja_ocupado': 0, 'vinculado': 0, 'marcado_hub': 0, 'marcado_jt': 0}
for fid, ext, mkt in locais:
    k = (ext or mkt).replace("'", "")
    if not k: continue
    hub_id = (sql(hu, 3306, "SELECT id FROM orders WHERE external_order_id='%s' OR marketplace_order_id='%s' LIMIT 1;" % (k, k)) or '').strip()
    if not hub_id:
        st['sem_no_hub'] += 1; continue

    r = sql(fo, 3307, "UPDATE orders SET hubai_order_id=%s WHERE id=%s AND hubai_order_id IS NULL;" % (hub_id, fid), tolerante=True)
    if r is None:
        st['hub_ja_ocupado'] += 1        # pedido do hub ja vinculado a outro pedido local
    else:
        st['vinculado'] += 1

    pago = pagos.get(fid)
    if not pago: continue

    antes = (sql(hu, 3306, "SELECT COUNT(*) FROM orders WHERE id=%s AND wallet_paid_at IS NULL;" % hub_id) or '0').strip()
    if antes == '1':
        sql(hu, 3306, "INSERT INTO orders_bkp_for117 SELECT id, external_order_id, wallet_paid_at, updated_at FROM orders WHERE id=%s;" % hub_id)
        sql(hu, 3306, "UPDATE orders SET wallet_paid_at='%s' WHERE id=%s AND wallet_paid_at IS NULL;" % (pago, hub_id))
        st['marcado_hub'] += 1

    antesj = (sql(jt, 3306, "SELECT COUNT(*) FROM orders WHERE hubai_order_id=%s AND wallet_paid_at IS NULL;" % hub_id) or '0').strip()
    if antesj != '0':
        sql(jt, 3306, "INSERT INTO orders_bkp_for117 SELECT id, hubai_order_id, wallet_paid_at, updated_at FROM orders WHERE hubai_order_id=%s AND wallet_paid_at IS NULL;" % hub_id)
        sql(jt, 3306, "UPDATE orders SET wallet_paid_at='%s' WHERE hubai_order_id=%s AND wallet_paid_at IS NULL;" % (pago, hub_id))
        st['marcado_jt'] += 1

for k, v in st.items(): print('  %-16s %d' % (k, v))
print('  backup hub    : %s' % sql(hu, 3306, "SELECT COUNT(*) FROM orders_bkp_for117;").strip())
print('  backup jtdrop : %s' % sql(jt, 3306, "SELECT COUNT(*) FROM orders_bkp_for117;").strip())
