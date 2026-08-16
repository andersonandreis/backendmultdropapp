import io, subprocess
env = {}
for ln in io.open('/home/api.jtdrop.com.br/public_html/.env', encoding='utf-8', errors='ignore'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); env[k.strip()] = v.strip().strip('"')

def sql(q):
    r = subprocess.run(['mysql','-u'+env['DB_USERNAME'],'-p'+env['DB_PASSWORD'],'-h127.0.0.1',
                        env['DB_DATABASE'],'-sNe',q], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(r.stderr[:250])
    return r.stdout

sql("DROP TABLE IF EXISTS order_items_bkp_jt017;")
sql("CREATE TABLE order_items_bkp_jt017 AS SELECT i.* FROM order_items i "
    "JOIN orders o ON o.id=i.order_id WHERE i.product_id IS NULL;")
print('backup:', sql("SELECT COUNT(*) FROM order_items_bkp_jt017;").strip(), 'itens')

feitos = ja = sem_produto = sem_item = 0
for ln in io.open('/root/jt-restaura.tsv', encoding='utf-8'):
    f = ln.rstrip('\n').split('\t')
    if len(f) < 5: continue
    hub_oid, ext_item, sku, custo, qtd = f[0], f[1], f[2], float(f[3] or 0), int(f[4] or 1)
    pid = sql("SELECT id FROM products WHERE sku='%s' AND supplier_id=13 LIMIT 1;" % sku.replace("'","")).strip()
    if not pid: sem_produto += 1; continue
    cond = "o.hubai_order_id=%s" % hub_oid
    if ext_item: cond += " AND i.external_item_id='%s'" % ext_item.replace("'","")
    alvo = sql("SELECT i.id FROM order_items i JOIN orders o ON o.id=i.order_id "
               "WHERE %s AND i.product_id IS NULL LIMIT 1;" % cond).strip()
    if not alvo: sem_item += 1; continue
    sql("UPDATE order_items SET product_id=%s, sku='%s', supplier_unit_cost=%.2f, "
        "supplier_total_cost=%.2f WHERE id=%s;" % (pid, sku.replace("'",""), custo, round(custo*qtd,2), alvo))
    feitos += 1

print('vinculos restaurados: %d | sem produto local: %d | item ja resolvido/ausente: %d'
      % (feitos, sem_produto, sem_item))
