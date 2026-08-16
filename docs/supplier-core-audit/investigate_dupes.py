import paramiko, sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("217.216.81.157", username="root", password="PnV*e0a39gmwEalr", timeout=15)

def run(sql):
    cmd = ("kubectl exec -n production mysql-0 -- mysql -uroot -pncpfxmbOTXqTflm0ieHI1174OJMZkl9A "
           f"-D tudoonline_production -e \"{sql}\" 2>&1")
    _, stdout, _ = ssh.exec_command(cmd, timeout=30)
    out = stdout.read().decode("utf-8", errors="replace")
    return "\n".join(l for l in out.splitlines() if "Warning" not in l)

print("=== TABELAS RELEVANTES ===")
print(run("SHOW TABLES LIKE 'deposito%'"))
print(run("SHOW TABLES LIKE 'loja%'"))

print("\n=== sku_pai cols principais ===")
print(run("DESCRIBE sku_pai").split("\n")[0])
print("\n".join([l for l in run("DESCRIBE sku_pai").split("\n") if any(k in l for k in ["id_deposito","id_empresa","sku","descricao","Field"])][:10]))

print("\n=== dep 53: amostra 5 produtos ===")
print(run("SELECT id, sku, LEFT(descricao,40) descr, id_empresa, id_deposito FROM sku_pai WHERE id_deposito=53 LIMIT 5"))

print("\n=== dep 61: amostra ===")
print(run("SELECT id, sku, LEFT(descricao,40) descr, id_empresa, id_deposito FROM sku_pai WHERE id_deposito=61 LIMIT 5"))

print("\n=== dep 13 e 25 (Envio Nacional duplicado?) ===")
print(run("SELECT id_deposito, COUNT(*) c, LEFT(descricao,40) sample FROM sku_pai WHERE id_deposito IN (13,25) GROUP BY id_deposito LIMIT 5"))

print("\n=== Tabela deposito do legado ===")
print(run("DESCRIBE deposito"))
print(run("SELECT * FROM deposito WHERE id IN (53,61,13,25,498)"))

print("\n=== empresas (id_empresa em sku_pai) pra deps ambíguos ===")
print(run("SELECT DISTINCT sp.id_deposito, sp.id_empresa, e.empresa, e.host FROM sku_pai sp LEFT JOIN empresas e ON e.id=sp.id_empresa WHERE sp.id_deposito IN (53,61,13,25,498) GROUP BY sp.id_deposito, sp.id_empresa, e.empresa, e.host"))

ssh.close()
