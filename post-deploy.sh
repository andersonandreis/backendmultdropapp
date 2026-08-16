#!/bin/bash
# post-deploy.sh — Rodar manualmente após deploys que precisam de rebuild
# USO: bash post-deploy.sh
# NÃO executar automaticamente via webhook ou cron
# Criado: 2026-04-24

set -e

PHP="/usr/local/lsws/lsphp82/bin/php"
ARTISAN="$PHP artisan"
DIR="/home/api.hubai.io/public_html"

cd "$DIR"

echo "=== HubAI Post-Deploy Script ==="
echo "Data: $(date)"
echo "Dir: $DIR"
echo ""

echo "[1/5] Instalando dependências PHP (sem dev, otimizado)..."
$PHP /usr/bin/composer install --no-dev --optimize-autoloader

echo ""
echo "[2/5] Rodando migrations pendentes..."
# SEGURANÇA: apenas migrations CREATE/ADD — NUNCA migrate:fresh, migrate:rollback ou migrate:refresh
$ARTISAN migrate --force

echo ""
echo "[3/5] Limpando e recacheando config/rotas/views..."
$ARTISAN config:cache
$ARTISAN route:cache-atomic
$ARTISAN view:cache

echo ""
echo "[4/5] Otimizando Filament..."
$ARTISAN filament:optimize

echo ""
echo "[5/5] Limpando cache de aplicação..."
$ARTISAN cache:clear

echo ""
echo "=== Deploy concluído com sucesso! ==="
echo "Timestamp: $(date)"
