#!/usr/bin/env python3
"""
SEL-356 P1: kalodata_brand_detail.py
Scrape Kalodata /brand/{external_id} e persiste em kalodata_brand_detail.

Uso:
  python kalodata_brand_detail.py [--dry] [--country BR|US] [--limit N]

--dry  mostra o que faria mas nao salva no banco
"""
import sys, os, time, json, argparse, datetime
sys.path.insert(0, '/home/api.seller.global/public_html/scripts')

import pymysql
from dotenv import load_dotenv

# INF-067: senha do banco tirada do codigo (estava hardcoded em texto puro
# neste script, compartilhado por 7 backends). Le do .env do proprio repo por
# caminho absoluto (funciona independente do cwd de onde o script for chamado).
load_dotenv('/home/api.seller.global/public_html/.env')

# ── Config ──────────────────────────────────────────────────────────────────
DB_HOST = '127.0.0.1'
DB_PORT = 3307
DB_NAME = 'sellerapp_production'
DB_USER = 'sellerapp'
DB_PASS = os.environ.get('DB_PASSWORD', '')

# Kalodata cookies (copiados do browser autenticado)
KALODATA_COOKIE = os.environ.get('KALODATA_COOKIE', '')

RATE_LIMIT_S = 2.5  # req/s
MAX_RETRIES  = 3

# ── Helpers ─────────────────────────────────────────────────────────────────
def get_db():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, db=DB_NAME,
        user=DB_USER, password=DB_PASS,
        charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor
    )

def fetch_brand_detail(brand_id: str, country: str) -> dict | None:
    """Scrape /brand/{brand_id} no Kalodata."""
    import urllib.request
    url = f'https://www.kalodata.com/api/v1/brand/{brand_id}?country={country}'
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'application/json',
        'Referer': 'https://www.kalodata.com/brand',
        'Cookie': KALODATA_COOKIE,
    }
    for attempt in range(MAX_RETRIES):
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=15) as resp:
                data = json.loads(resp.read())
                if data.get('code') == 0:
                    return data.get('data', {})
                print(f'  WARN brand {brand_id}: code={data.get("code")} msg={data.get("msg")}')
                return None
        except Exception as e:
            print(f'  ERR brand {brand_id} attempt {attempt+1}: {e}')
            if attempt < MAX_RETRIES - 1:
                time.sleep(3)
    return None

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry',     action='store_true')
    parser.add_argument('--country', default='BR')
    parser.add_argument('--limit',   type=int, default=20)
    args = parser.parse_args()

    today = datetime.date.today().isoformat()
    country = args.country.upper()

    db = get_db()
    cur = db.cursor()

    # Busca marcas do snapshot mais recente
    cur.execute("""
        SELECT brand_id, brand_name
        FROM kalodata_brands
        WHERE country = %s
          AND snapshot_date = (SELECT MAX(snapshot_date) FROM kalodata_brands WHERE country = %s)
        ORDER BY id LIMIT %s
    """, (country, country, args.limit))
    brands = cur.fetchall()
    print(f'[SEL-356] Brands to scrape: {len(brands)} (country={country}, dry={args.dry})')

    for i, brand in enumerate(brands, 1):
        bid  = str(brand['brand_id'])
        name = brand['brand_name'] or ''
        print(f'  [{i}/{len(brands)}] {name} ({bid})...')

        # Pula se ja tem detalhe hoje
        cur.execute("""
            SELECT id FROM kalodata_brand_detail
            WHERE brand_external_id = %s AND snapshot_date = %s AND country = %s
        """, (bid, today, country))
        if cur.fetchone():
            print(f'    skip (ja existe hoje)')
            continue

        detail = fetch_brand_detail(bid, country)
        if not detail:
            print(f'    skip (sem dados)')
            time.sleep(RATE_LIMIT_S)
            continue

        top_products = json.dumps(detail.get('top_products', detail.get('products', [])), ensure_ascii=False)
        top_creators = json.dumps(detail.get('top_creators', detail.get('creators', [])), ensure_ascii=False)
        gmv_by_day   = json.dumps(detail.get('revenue_trend', detail.get('gmv_by_day', [])), ensure_ascii=False)

        if args.dry:
            print(f'    DRY: would save brand_detail for {bid}')
        else:
            cur.execute("""
                INSERT INTO kalodata_brand_detail
                  (brand_external_id, snapshot_date, country, top_products, top_creators, gmv_by_day, detail_payload, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  top_products   = VALUES(top_products),
                  top_creators   = VALUES(top_creators),
                  gmv_by_day     = VALUES(gmv_by_day),
                  detail_payload = VALUES(detail_payload),
                  updated_at     = NOW()
            """, (bid, today, country, top_products, top_creators, gmv_by_day,
                  json.dumps(detail, ensure_ascii=False)))
            db.commit()
            print(f'    saved: {len(detail.get("top_products", detail.get("products", [])))} products, {len(detail.get("top_creators", detail.get("creators", [])))} creators')

        time.sleep(RATE_LIMIT_S)

    cur.close()
    db.close()
    print('[SEL-356] kalodata_brand_detail done.')

if __name__ == '__main__':
    main()
