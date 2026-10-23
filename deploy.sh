#!/usr/bin/env bash
set -e

cd /var/www/muninn

echo "==> git pull"
git pull origin main

echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> npm build"
npm ci
npm run build

echo "==> migrate"
php artisan migrate --force

echo "==> optimize"
php artisan optimize

echo "==> done"
