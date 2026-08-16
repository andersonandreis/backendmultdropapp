import paramiko

HOST = "217.216.81.157"
USER = "root"
PASS = "PnV*e0a39gmwEalr"

# legacy_empresa_id dos suppliers no novo (com produtos)
SUPPLIERS = [
    (11, "Drop - SP"), (13, "Envio Nacional - RJ"), (20, "Drop Auto Peças - SP"),
    (25, "Envio Nacional"), (27, "Mix Variedades - SP"), (53, "DropRio/JTDrop"),
    (54, "Infinity Drop"), (58, "Titanium"), (61, "PlugLar/Plug Lar"),
    (66, "REDAGRO"), (373, "UPDROP"), (403, "Via/Nutri"), (410, "Clique RJ"),
    (426, "Smart Tech"), (430, "Bras Mania"), (432, "Drop2you RJ 1"),
    (437, "Drop2you SP 1"), (446, "Galpao 23 SP"), (447, "M&E store"),
    (454, "Teste"), (465, "Peg Comercial"), (485, "thiago teste"),
    (488, "atravessadorpro"), (498, "Multdrop"), (500, "LogiDrop SP"),
    (503, "SALES"), (600, "UnicDrop"), (608, "Letielly Shore"),
]

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASS, timeout=15)

def run(sql):
    cmd = (
        "kubectl exec -n production mysql-0 -- mysql -uroot -pncpfxmbOTXqTflm0ieHI1174OJMZkl9A "
        f"-D tudoonline_production -sN -e \"{sql}\" 2>&1"
    )
    _, stdout, _ = ssh.exec_command(cmd, timeout=60)
    out = stdout.read().decode("utf-8", errors="replace")
    return "\n".join(l for l in out.splitlines() if "Warning" not in l)

# Pra cada deposito: count total + count com estoque>0 + MAX data_update
print(f"{'dep':>5} {'nome':30} {'total_legado':>12} {'c/estq':>8} {'estq=0':>8} {'last_data_update':>20}")
print("-" * 90)
for dep, name in SUPPLIERS:
    sql = (
        f"SELECT COUNT(*), SUM(IF(estoque>0,1,0)), SUM(IF(estoque=0,1,0)), "
        f"DATE_FORMAT(MAX(data_update),'%Y-%m-%d %H:%i') "
        f"FROM sku_pai WHERE id_deposito={dep}"
    )
    r = run(sql).strip().split("\t")
    if len(r) >= 4:
        total, ce, ze, mx = r[0], r[1] or "0", r[2] or "0", r[3] or "-"
        print(f"{dep:>5} {name[:30]:30} {total:>12} {ce:>8} {ze:>8} {mx:>20}")

ssh.close()
