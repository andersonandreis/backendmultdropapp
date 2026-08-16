#!/bin/bash
set -e
PHP=/usr/local/lsws/lsphp82/bin/php8.2
DIR=/home/api.hubai.io/public_html

echo "[deploy] Pulling latest code..."
cd $DIR && git pull origin main

echo "[deploy] Installing dependencies..."
$PHP /usr/bin/composer install --no-dev --optimize-autoloader 2>/dev/null || echo "Composer skip"

echo "[deploy] Running migrations..."
$PHP $DIR/artisan migrate --force

echo "[deploy] Clearing caches..."
$PHP $DIR/artisan config:cache
$PHP $DIR/artisan route:cache-atomic
$PHP $DIR/artisan view:cache
$PHP $DIR/artisan filament:optimize

echo "[deploy] Done! $(date)"
