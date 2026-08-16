"""A3 — V2: gera SQL local, scp pro servidor, executa via mysql cli."""
import paramiko, sys, io, subprocess
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("217.216.81.157", username="root", password="PnV*e0a39gmwEalr", timeout=15)

def legado(sql):
    cmd = ("kubectl exec -n production mysql-0 -- mysql -uroot -pncpfxmbOTXqTflm0ieHI1174OJMZkl9A "
           f"-D tudoonline_production -sN -e \"{sql}\" 2>&1")
    _, stdout, _ = ssh.exec_command(cmd, timeout=120)
    out = stdout.read().decode("utf-8", errors="replace")
    return "\n".join(l for l in out.splitlines() if "Warning" not in l)

def novo_php(php):
    r = subprocess.run([
        "ssh","-i","C:/Users/ruani/.ssh/tokfy_claude","-o","StrictHostKeyChecking=no",
        "root@66.94.100.155",
        f"cd /home/api.hubai.io/public_html && /usr/local/lsws/lsphp83/bin/php -r '"
        f"require \"vendor/autoload.php\"; "
        f"$a=require \"bootstrap/app.php\"; "
        f"$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
        f"{php}'"
    ], capture_output=True, text=True, encoding='utf-8', errors='replace')
    return r.stdout

# Pegar todos os orfaos com supplier que tem legacy_empresa_id (ja inclui o que ja foi linkado em V1, vai ser idempotente)
print("Pegando orfaos...")
out = novo_php(
    'foreach(DB::select("SELECT p.id, p.sku, s.legacy_empresa_id FROM products p JOIN suppliers s ON s.id=p.supplier_id WHERE p.legacy_sku_pai_id IS NULL AND s.legacy_empresa_id IS NOT NULL AND p.sku IS NOT NULL AND p.sku<>\\"\\"") as $r) echo $r->id."|".$r->sku."|".$r->legacy_empresa_id.PHP_EOL;'
)
orfaos = {}  # dep -> {sku: pid}
for line in out.strip().splitlines():
    if "|" in line:
        parts = line.split("|", 2)
        if len(parts) >= 3:
            pid, sku, dep = parts
            orfaos.setdefault(int(dep), {})[sku] = int(pid)

total = sum(len(v) for v in orfaos.values())
print(f"  {total} orfaos em {len(orfaos)} depositos")

# Match por dep
all_matches = []  # (pid, sku_pai_id)
for dep, mp in orfaos.items():
    skus = list(mp.keys())
    for i in range(0, len(skus), 500):
        chunk = skus[i:i+500]
        csv = ",".join("'"+s.replace("'","\\'")+"'" for s in chunk)
        leg = legado(f"SELECT id, sku FROM sku_pai WHERE id_deposito={dep} AND sku IN ({csv})")
        for line in leg.splitlines():
            if "\t" in line:
                spid, sku = line.split("\t", 1)
                if sku in mp:
                    all_matches.append((mp[sku], int(spid)))
    matched = sum(1 for pid, _ in all_matches if pid in mp.values())
    print(f"  dep={dep}: {len(mp)} orfaos / matched (cumulative {len(all_matches)})")

print(f"\nTotal matches: {len(all_matches)}")

# Escrever SQL UPDATE em arquivo, scp, executar
if all_matches:
    sql_lines = []
    for pid, spid in all_matches:
        sql_lines.append(f"UPDATE products SET legacy_sku_pai_id={spid} WHERE id={pid};")
    sql_content = "\n".join(sql_lines)
    with open("C:/Users/ruani/supplier_core_audit/a3_updates.sql", "w", encoding="utf-8") as f:
        f.write(sql_content)
    print(f"  SQL salvo ({len(sql_lines)} statements). Subindo + executando...")

    subprocess.run([
        "scp","-i","C:/Users/ruani/.ssh/tokfy_claude","-o","StrictHostKeyChecking=no",
        "C:/Users/ruani/supplier_core_audit/a3_updates.sql",
        "root@66.94.100.155:/tmp/a3_updates.sql"
    ], capture_output=True, encoding='utf-8')

    r = subprocess.run([
        "ssh","-i","C:/Users/ruani/.ssh/tokfy_claude","-o","StrictHostKeyChecking=no",
        "root@66.94.100.155",
        "cd /home/api.hubai.io/public_html && cat /tmp/a3_updates.sql | /usr/local/lsws/lsphp83/bin/php -r 'require \"vendor/autoload.php\"; $a=require \"bootstrap/app.php\"; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $n=0; foreach(explode(\";\", file_get_contents(\"/tmp/a3_updates.sql\")) as $sql){ $sql=trim($sql); if($sql){ $n += DB::update($sql); }} echo \"updated=$n\".PHP_EOL;' && rm /tmp/a3_updates.sql"
    ], capture_output=True, text=True, encoding='utf-8', errors='replace')
    print(f"  resultado: {r.stdout.strip()}")
    if r.stderr: print(f"  stderr: {r.stderr[:300]}")

# Validacao
print("\n=== Estado final ===")
print(novo_php(
    'foreach(DB::select("SELECT s.id, s.company_name, COUNT(p.id) tot, SUM(CASE WHEN p.legacy_sku_pai_id IS NULL THEN 1 ELSE 0 END) orfaos FROM suppliers s JOIN products p ON p.supplier_id=s.id WHERE s.legacy_empresa_id IS NOT NULL GROUP BY s.id, s.company_name HAVING orfaos > 0 ORDER BY orfaos DESC") as $r) echo "  supplier#".$r->id." ".$r->company_name." total=".$r->tot." orfaos=".$r->orfaos.PHP_EOL;'
))

ssh.close()
