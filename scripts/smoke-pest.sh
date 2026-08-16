#!/usr/bin/env bash
#
# scripts/smoke-pest.sh — NOV-182 / INF-030 Fase 4
#
# Roda a suite de smoke tests HTTP (tests/Smoke) contra um dos 4 backends
# do ecossistema HubAI. Testa contra o ambiente JA NO AR via HTTP puro
# (Guzzle standalone) — nao bootstrapa app, nao usa banco, nao autentica
# de verdade. Feito pra rodar apos qualquer deploy.
#
# Uso:
#   scripts/smoke-pest.sh <site>
#
# <site> aceita:
#   - alias curto: hubai | multdrop | fornecefy | mestoredrop
#   - URL completa: https://api.hubai.io
#
# Exemplos:
#   scripts/smoke-pest.sh hubai
#   scripts/smoke-pest.sh https://api.multdrop.app
#
# Requer PHP 8.3+ (usa /usr/local/lsws/lsphp83/bin/php se disponivel,
# senao cai pro `php` do PATH) e vendor/ instalado (composer install).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ "$#" -ne 1 ]; then
    echo "Uso: $0 <site>" >&2
    echo "  <site>: hubai | multdrop | fornecefy | mestoredrop | URL completa (https://...)" >&2
    exit 1
fi

SITE_ARG="$1"

case "$SITE_ARG" in
    hubai)
        BASE_URL="https://api.hubai.io"
        ;;
    multdrop)
        BASE_URL="https://api.multdrop.app"
        ;;
    fornecefy)
        BASE_URL="https://api.fornecefy.io"
        ;;
    mestoredrop)
        BASE_URL="https://api.mestoredrop.com.br"
        ;;
    http://*|https://*)
        BASE_URL="$SITE_ARG"
        ;;
    *)
        echo "Site desconhecido: '$SITE_ARG'" >&2
        echo "Use: hubai | multdrop | fornecefy | mestoredrop | URL completa (https://...)" >&2
        exit 1
        ;;
esac

if [ -x /usr/local/lsws/lsphp83/bin/php ]; then
    PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
else
    PHP_BIN="php"
fi

if [ ! -x "$REPO_ROOT/vendor/bin/pest" ]; then
    echo "vendor/bin/pest nao encontrado em $REPO_ROOT. Rode 'composer install' primeiro." >&2
    exit 1
fi

echo "==> Smoke tests contra: $BASE_URL"
echo "==> PHP: $($PHP_BIN -v | head -1)"
echo ""

cd "$REPO_ROOT"
SMOKE_BASE_URL="$BASE_URL" "$PHP_BIN" vendor/bin/pest tests/Smoke "${@:2}"
