#!/usr/bin/env python3
"""
SEL-356 P1: kalodata_shop_detail.py
Scrape Kalodata /shop/{external_id} e persiste em kalodata_shop_detail.

Uso:
  python kalodata_shop_detail.py [--dry] [--country BR|US] [--limit N]
"""
import sys, os, time, json, argparse, datetime
import pymysql
from dotenv import load_dotenv

# INF-067: senha do banco tirada do codigo (estava hardcoded em texto puro
# neste script, compartilhado por 7 backends). Le do .env do proprio repo por
# caminho absoluto (funciona independente do cwd de onde o script for chamado).
load_dotenv('/home/api.seller.global/public_html/.env')

DB_HOST = '127.0.0.1'
DB_PORT = 3307
DB_NAME = 'sellerapp_production'
DB_USER = 'sellerapp'
DB_PASS = os.environ.get('DB_PASSWORD', '')

KALODATA_COOKIE = os.environ.get('KALODATA_COOKIE', '')
RATE_LIMIT_S    = 2.5
MAX_RETRIES     = 3

def get_db():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, db=DB_NAME,
        user=DB_USER, password=DB_PASS,
        charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor
    )

def fetch_shop_detail(shop_id: str, country: str) -> dict | None:
    import urllib.request
    # Tenta os endpoints mais comuns do Kalodata para lojas
    for endpoint in [
        f'https://www.kalodata.com/api/v1/shop/{shop_id}?country={country}',
        f'https://www.kalodata.com/api/v1/shop/queryShopProfile?shop_id={shop_id}&country={country}',
    ]:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept': 'application/json',
            'Referer': 'https://www.kalodata.com/shop',
            'Cookie': KALODATA_COOKIE,
        }
        for attempt in range(MAX_RETRIES):
            try:
                req = urllib.request.Request(endpoint, headers=headers)
                with urllib.request.urlopen(req, timeout=15) as resp:
                    data = json.loads(resp.read())
                    if data.get('code') == 0 and data.get('data'):
                        return data['data']
            except Exception as e:
                if attempt == MAX_RETRIES - 1:
                    print(f'  ERR endpoint {endpoint}: {e}')
                time.sleep(1)
    return None

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry',     action='store_true')
    parser.add_argument('--country', default='BR')
    parser.add_argument('--limit',   type=int, default=20)
    args = parser.parse_args()

    today   = datetime.date.today().isoformat()
    country = args.country.upper()

    db  = get_db()
    cur = db.cursor()

    # Busca lojas do snapshot mais recente da kalodata_raw type=shops
    cur.execute("""
        SELECT external_id,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.shop_name')) as shop_name
        FROM kalodata_raw
        WHERE type = 'shops'
          AND snapshot_date = (SELECT MAX(snapshot_date) FROM kalodata_raw WHERE type='shops')
        ORDER BY id LIMIT %s
    """, (args.limit,))
    shops = cur.fetchall()
    print(f'[SEL-356] Shops to scrape: {len(shops)} (country={country}, dry={args.dry})')

    for i, shop in enumerate(shops, 1):
        sid  = str(shop['external_id'])
        name = shop['shop_name'] or ''
        print(f'  [{i}/{len(shops)}] {name} ({sid})...')

        cur.execute("""
            SELECT id FROM kalodata_shop_detail
            WHERE shop_external_id = %s AND snapshot_date = %s AND country = %s
        """, (sid, today, country))
        if cur.fetchone():
            print(f'    skip (ja existe hoje)')
            continue

        detail = fetch_shop_detail(sid, country)
        if not detail:
            print(f'    skip (sem dados)')
            time.sleep(RATE_LIMIT_S)
            continue

        top_products = json.dumps(detail.get('top_products', detail.get('products', [])), ensure_ascii=False)
        top_creators = json.dumps(detail.get('top_creators', detail.get('creators', [])), ensure_ascii=False)
        gmv_by_day   = json.dumps(detail.get('revenue_trend', detail.get('gmv_by_day', [])), ensure_ascii=False)

        if args.dry:
            print(f'    DRY: would save shop_detail for {sid}')
        else:
            cur.execute("""
                INSERT INTO kalodata_shop_detail
                  (shop_external_id, snapshot_date, country, top_products, top_creators, gmv_by_day, detail_payload, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  top_products   = VALUES(top_products),
                  top_creators   = VALUES(top_creators),
                  gmv_by_day     = VALUES(gmv_by_day),
                  detail_payload = VALUES(detail_payload),
                  updated_at     = NOW()
            """, (sid, today, country, top_products, top_creators, gmv_by_day,
                  json.dumps(detail, ensure_ascii=False)))
            db.commit()
            print(f'    saved')

        time.sleep(RATE_LIMIT_S)

    cur.close()
    db.close()
    print('[SEL-356] kalodata_shop_detail done.')

if __name__ == '__main__':
    main()
