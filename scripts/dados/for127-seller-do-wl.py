"""
FOR-127 - o Seller aparece vazio no painel para pedido vindo do Fornecefy.

O PROBLEMA: o painel monta o Seller por orders.client_id -> clients -> users.name.
Nos pedidos do Fornecefy o client_id e NULL, porque cliente e LOCAL de cada WL: dos 1.026
sellers por tras das contas, 896 nao existem no hub - e nao devem existir. A identidade
esta preservada em marketplace_accounts.wl_client_id, mas ninguem traduz.

O QUE ESTE SCRIPT FAZ: le o nome/email do seller no banco do Fornecefy (fonte da verdade)
e grava em colunas NOVAS no hub. Nenhum cliente e criado no hub.

INVARIANTES (ver FOR-127.md):
  1. universo: service='fornecefy' AND client_id IS NULL AND wl_client_id IS NOT NULL
  2. fonte: fornecefyapp_production clients.id = wl_client_id -> users via clients.user_id
  3. fallback SO nesta ordem: users.name -> users.full_name -> trade_name -> legal_name
  4. PROIBIDO usar/gravar document (88% e placeholder 00000000000000)
  5. PROIBIDO escrever em seller_nickname (tem dono: apelido no marketplace, 1.486 cheios),
     account_name, client_id, supplier_id
  6. destino: colunas NOVAS wl_client_name / wl_client_email
  7. correlacao entre bancos SO por wl_client_id
  8. so escreve em campo destino vazio -> idempotente
  9. nada e apagado
 10. Sigma classes == total extraido

USO:  python3 for127-seller-do-wl.py            (dry-run, gera CSV)
      python3 for127-seller-do-wl.py --apply    (aplica em lotes)
"""
import sys
import csv
import re

import pymysql

APLICA = '--apply' in sys.argv
CSV_OUT = '/tmp/CONFERIR-for127-seller-do-wl.csv'
LOTE = 200

HUB_ENV = '/home/api.hubai.io/public_html/.env'
FOR_ENV = '/home/api.fornecefy.io/public_html/.env'


def env(caminho, chave):
    for linha in open(caminho, encoding='utf-8', errors='ignore'):
        if linha.startswith(chave + '='):
            return linha.split('=', 1)[1].strip().strip('"')
    return None


hub = pymysql.connect(host='127.0.0.1', port=3306, user=env(HUB_ENV, 'DB_USERNAME'),
                      password=env(HUB_ENV, 'DB_PASSWORD'), database=env(HUB_ENV, 'DB_DATABASE'),
                      charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)
wl = pymysql.connect(host='127.0.0.1', port=3307, user=env(FOR_ENV, 'DB_USERNAME'),
                     password=env(FOR_ENV, 'DB_PASSWORD'), database=env(FOR_ENV, 'DB_DATABASE'),
                     charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor)

# ---------- 0. as colunas de destino existem? ----------
with hub.cursor() as cur:
    cur.execute("SHOW COLUMNS FROM marketplace_accounts LIKE 'wl_client_name'")
    tem_coluna = cur.fetchone() is not None

if not tem_coluna:
    if not APLICA:
        print('!! colunas wl_client_name/wl_client_email ainda NAO existem '
              '(serao criadas no --apply)\n')
    else:
        with hub.cursor() as cur:
            cur.execute('ALTER TABLE marketplace_accounts '
                        'ADD COLUMN wl_client_name VARCHAR(191) NULL AFTER wl_client_id, '
                        'ADD COLUMN wl_client_email VARCHAR(191) NULL AFTER wl_client_name')
        hub.commit()
        print('colunas wl_client_name / wl_client_email criadas')
        tem_coluna = True

# ---------- 1. EXTRAIR ----------
campos = 'id, wl_client_id, account_name, platform, seller_nickname'
if tem_coluna:
    campos += ', wl_client_name, wl_client_email'

with hub.cursor() as cur:
    cur.execute('SELECT ' + campos + ' FROM marketplace_accounts '
                "WHERE service='fornecefy' AND client_id IS NULL AND wl_client_id IS NOT NULL")
    contas = cur.fetchall()
print('EXTRAIR   contas no universo: {}'.format(len(contas)))

ids = sorted({r['wl_client_id'] for r in contas})
sellers = {}
if ids:
    marcas = ','.join(['%s'] * len(ids))
    with wl.cursor() as cur:
        cur.execute('SELECT cl.id, cl.trade_name, cl.legal_name, u.name, u.full_name, u.email '
                    'FROM clients cl LEFT JOIN users u ON u.id = cl.user_id '
                    'WHERE cl.id IN (' + marcas + ')', ids)
        for r in cur.fetchall():
            sellers[r['id']] = r
print('          sellers distintos: {} | encontrados no fornecefy: {}'.format(len(ids), len(sellers)))


# ---------- 2. CLASSIFICAR ----------
def limpo(valor):
    valor = (valor or '').strip()
    return valor if valor else None


def escolhe_nome(seller):
    """fallback SO na ordem declarada na invariante 3"""
    for campo in ('name', 'full_name', 'trade_name', 'legal_name'):
        valor = limpo(seller.get(campo))
        if (valor and not re.fullmatch(r'\d+', valor)
                and valor != '00000000000000' and len(valor) >= 2):
            return valor, campo
    return None, None


classes = {}
linhas = []
escrever = []

for r in contas:
    seller = sellers.get(r['wl_client_id'])
    origem = None
    nome = None
    email = None
    if seller is None:
        classe = 'IRRESOLVIVEL: seller nao existe no fornecefy'
    else:
        nome, origem = escolhe_nome(seller)
        email = limpo(seller.get('email'))
        if email and ('@' not in email or not 5 <= len(email) <= 191):
            email = None
        if nome is None:
            classe = 'IRRESOLVIVEL: seller sem nome utilizavel'
        elif email and nome == email:
            classe = 'nome == email (cadastro do fornecefy e assim)'
        else:
            classe = 'nome de ' + origem

    ja_tem = limpo(r.get('wl_client_name')) if tem_coluna else None
    if ja_tem:
        classe = 'ja preenchido - nao toca (idempotencia)'

    classes[classe] = classes.get(classe, 0) + 1
    linhas.append({
        'conta': r['id'], 'platform': r['platform'], 'account_name': r['account_name'],
        'seller_nickname': r['seller_nickname'], 'wl_client_id': r['wl_client_id'],
        'nome_atual': ja_tem or '', 'nome_novo': nome or '', 'email_novo': email or '',
        'origem': origem or '', 'classe': classe,
    })
    if nome and not ja_tem:
        escrever.append((nome, email, r['id']))

print('\nCLASSIFICAR')
for chave, valor in sorted(classes.items(), key=lambda x: -x[1]):
    print('   {:.<58} {:5d}'.format(chave, valor))

# ---------- 3. VALIDAR ----------
soma = sum(classes.values())
print('\nVALIDAR')
print('   Sigma classes == total extraido ....... {} == {}  {}'.format(
    soma, len(contas), 'OK' if soma == len(contas) else 'FALHA'))
maus = [l for l in linhas if l['nome_novo'] and (
    len(l['nome_novo']) > 191 or len(l['nome_novo']) < 2 or re.fullmatch(r'\d+', l['nome_novo']))]
print('   nomes fora da faixa declarada ......... {}  {}'.format(
    len(maus), 'OK' if not maus else 'FALHA'))
maus_email = [l for l in linhas if l['email_novo'] and '@' not in l['email_novo']]
print('   emails sem arroba ..................... {}  {}'.format(
    len(maus_email), 'OK' if not maus_email else 'FALHA'))
print('   a escrever ............................ {}'.format(len(escrever)))

# ---------- 4. DRY-RUN CSV ----------
with open(CSV_OUT, 'w', encoding='utf-8-sig', newline='') as arquivo:
    escritor = csv.DictWriter(arquivo, fieldnames=list(linhas[0].keys()) + ['CONFIRMA'],
                              delimiter=';')
    escritor.writeheader()
    for l in linhas:
        linha = dict(l)
        linha['CONFIRMA'] = ''
        escritor.writerow(linha)
print('\nDRY-RUN   {}  ({} linhas)'.format(CSV_OUT, len(linhas)))

# ---------- 5. APLICAR ----------
if not APLICA:
    print('\n(dry-run - nada foi escrito. rode com --apply depois da conferencia)')
    sys.exit(0)

with hub.cursor() as cur:
    cur.execute('DROP TABLE IF EXISTS marketplace_accounts_bkp_for127')
    cur.execute('CREATE TABLE marketplace_accounts_bkp_for127 AS '
                'SELECT id, wl_client_id, wl_client_name, wl_client_email, '
                'seller_nickname, account_name FROM marketplace_accounts '
                "WHERE service='fornecefy' AND client_id IS NULL AND wl_client_id IS NOT NULL")
hub.commit()
print('\nAPLICAR   backup: marketplace_accounts_bkp_for127')

feitos = 0
for i in range(0, len(escrever), LOTE):
    lote = escrever[i:i + LOTE]
    with hub.cursor() as cur:
        cur.executemany('UPDATE marketplace_accounts '
                        'SET wl_client_name=%s, wl_client_email=%s '
                        "WHERE id=%s AND (wl_client_name IS NULL OR wl_client_name='')", lote)
    hub.commit()
    feitos += len(lote)
    print('   lote {}: {}/{}'.format(i // LOTE + 1, feitos, len(escrever)))

# ---------- 6. RECONCILIAR ----------
with hub.cursor() as cur:
    cur.execute("SELECT COUNT(*) n, SUM(wl_client_name IS NOT NULL AND wl_client_name<>'') cheio "
                'FROM marketplace_accounts '
                "WHERE service='fornecefy' AND client_id IS NULL AND wl_client_id IS NOT NULL")
    resumo = cur.fetchone()
    cur.execute('SELECT COUNT(*) n FROM marketplace_accounts a '
                'JOIN marketplace_accounts_bkp_for127 b ON b.id = a.id '
                "WHERE COALESCE(a.seller_nickname,'') <> COALESCE(b.seller_nickname,'') "
                "OR COALESCE(a.account_name,'') <> COALESCE(b.account_name,'')")
    tocou = cur.fetchone()['n']

print('\nRECONCILIAR')
print('   contas no universo .................... {}'.format(resumo['n']))
print('   com wl_client_name preenchido ......... {}'.format(resumo['cheio']))
print('   campos com dono alterados (deve ser 0)  {}  {}'.format(
    tocou, 'OK' if tocou == 0 else 'FALHA'))
