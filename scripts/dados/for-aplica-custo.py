import io, subprocess

env = {}
for ln in io.open('/home/api.hubai.io/public_html/.env', encoding='utf-8', errors='ignore'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); env[k.strip()] = v.strip().strip('"')

def sql(q):
    r = subprocess.run(['mysql', '-u'+env['DB_USERNAME'], '-p'+env['DB_PASSWORD'], '-h127.0.0.1',
                        env['DB_DATABASE'], '-sNe', q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr[:300])
    return r.stdout

linhas = []
for ln in io.open('/root/for111-custos.tsv', encoding='utf-8'):
    f = ln.rstrip('\n').split('\t')
    if len(f) >= 14: linhas.append(f)

aplicaveis = [f for f in linhas if float(f[13] or 0) > 0]
print('candidatos %d | com custo real %d | ignorados (custo 0) %d'
      % (len(linhas), len(aplicaveis), len(linhas) - len(aplicaveis)))

feitos = 0
for f in aplicaveis:
    item_id = int(f[2]); qtd = int(f[8] or 1); custo = float(f[13]); sku_pai = f[12].replace("'", "")
    pid = sql("SELECT id FROM products WHERE sku='%s' AND supplier_id=13 LIMIT 1;" % sku_pai).strip()
    total = round(custo * qtd, 2)
    sets = ["supplier_unit_cost=%.2f" % custo, "supplier_total_cost=%.2f" % total]
    if pid:
        sets.append("product_id=%s" % pid)
        sets.append("sku='%s'" % sku_pai)   # FOR-111: o pedido passa a carregar o SKU PAI
    sql("UPDATE order_items SET %s WHERE id=%d;" % (", ".join(sets), item_id))
    feitos += 1

print('itens atualizados: %d' % feitos)
