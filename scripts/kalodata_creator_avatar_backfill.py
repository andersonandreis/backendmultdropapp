#!/usr/bin/env python3
"""
SEL-356 Parte 3: kalodata_creator_avatar_backfill.py
Backfill de avatar_url em kalodata_ads e kalodata_lives_ranking.

Estrategia (sem Kalodata API):
  1. Tenta tabela ai_creators (ja temos avatar_url real)
  2. Tenta kalodata_raw type=creators (campo avatar)
  3. Usa unavatar.io/tiktok/{handle} como fallback (URL CDN)

Uso:
  python kalodata_creator_avatar_backfill.py [--dry] [--limit N]

Task scheduler: daily 05:00
"""
import sys, os, time, argparse
import pymysql
from dotenv import load_dotenv

# INF-067: senha do banco tirada do codigo (estava hardcoded em texto puro
# neste script, compartilhado por 7 backends). Le do .env do proprio repo por
# caminho absoluto (funciona independente do cwd de onde o script for chamado
# — este roda via cron diario 05:00).
load_dotenv('/home/api.seller.global/public_html/.env')

DB_HOST = '127.0.0.1'
DB_PORT = 3307
DB_NAME = 'sellerapp_production'
DB_USER = 'sellerapp'
DB_PASS = os.environ.get('DB_PASSWORD', '')

RATE_LIMIT_S = 0.3   # conservador — usa unavatar.io (CDN publica)
MAX_HANDLES  = 500   # por rodada


def get_db():
    return pymysql.connect(
        host=DB_HOST, port=DB_PORT, db=DB_NAME,
        user=DB_USER, password=DB_PASS,
        charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor
    )


def unavatar_url(handle: str) -> str:
    """Constroi URL de avatar via unavatar.io com fallback DiceBear."""
    import urllib.parse
    seed     = urllib.parse.quote(handle)
    fallback = urllib.parse.quote(
        f'https://api.dicebear.com/7.x/personas/png?seed={seed}&backgroundColor=b6e3f4,c0aede,d1d4f9'
    )
    return f'https://unavatar.io/tiktok/{handle}?fallback={fallback}'


def build_avatar_index(db, handles: list[str]) -> dict[str, str]:
    """Constroi indice handle -> avatar_url das nossas fontes locais."""
    cur = db.cursor()
    index = {}

    if not handles:
        cur.close()
        return index

    placeholders = ','.join(['%s'] * len(handles))

    # 1) ai_creators (melhor fonte — URL real)
    cur.execute(f"""
        SELECT handle, avatar_url FROM ai_creators
        WHERE handle IN ({placeholders}) AND avatar_url IS NOT NULL AND avatar_url != ''
    """, handles)
    for row in cur.fetchall():
        index[row['handle']] = row['avatar_url']

    # 2) kalodata_raw type=creators (campo avatar no payload)
    missing = [h for h in handles if h not in index]
    if missing:
        placeholders2 = ','.join(['%s'] * len(missing))
        cur.execute(f"""
            SELECT external_id,
                   JSON_UNQUOTE(JSON_EXTRACT(payload, '$.avatar')) as avatar
            FROM kalodata_raw
            WHERE type = 'creators'
              AND external_id IN ({placeholders2})
              AND JSON_EXTRACT(payload, '$.avatar') IS NOT NULL
        """, missing)
        for row in cur.fetchall():
            if row['avatar'] and row['external_id'] not in index:
                index[row['external_id']] = row['avatar']

    cur.close()
    return index


def backfill_table(db, table: str, id_col: str, handle_col: str, dry: bool, limit: int):
    """Preenche coluna avatar em `table` para linhas sem avatar."""
    cur = db.cursor()
    cur.execute(f"""
        SELECT DISTINCT {handle_col} FROM {table}
        WHERE ({handle_col} IS NOT NULL AND {handle_col} != '')
          AND (avatar IS NULL OR avatar = '')
        LIMIT %s
    """, (limit,))
    handles = [row[handle_col] for row in cur.fetchall()]
    if not handles:
        print(f'  {table}: nothing to backfill')
        cur.close()
        return

    print(f'  {table}: {len(handles)} handles sem avatar')
    avatar_index = build_avatar_index(db, handles)

    updated = 0
    for handle in handles:
        if handle in avatar_index:
            avatar = avatar_index[handle]
        else:
            # Usa unavatar.io como fallback (URL publica)
            avatar = unavatar_url(handle)
            time.sleep(RATE_LIMIT_S)

        if dry:
            print(f'    DRY: {handle} -> {avatar[:60]}...')
        else:
            cur.execute(f"""
                UPDATE {table} SET avatar = %s
                WHERE {handle_col} = %s AND (avatar IS NULL OR avatar = '')
            """, (avatar, handle))
            db.commit()
            updated += 1

    cur.close()
    print(f'  {table}: {updated} rows updated (dry={dry})')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry',   action='store_true')
    parser.add_argument('--limit', type=int, default=MAX_HANDLES)
    args = parser.parse_args()

    db = get_db()
    print(f'[SEL-356 avatar backfill] dry={args.dry} limit={args.limit}')

    backfill_table(db, 'kalodata_ads',            'video_id', 'creator_handle', args.dry, args.limit)
    backfill_table(db, 'kalodata_lives_ranking',   'live_id',  'creator_handle', args.dry, args.limit)

    db.close()
    print('[SEL-356] avatar backfill done.')


if __name__ == '__main__':
    main()
