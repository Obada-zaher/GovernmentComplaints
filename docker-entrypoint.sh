#!/usr/bin/env bash
set -e

echo "Running Laravel deployment startup tasks..."

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan cache:clear || true
php artisan optimize:clear || true

php artisan migrate --force

php artisan db:seed --force

echo "Laravel deployment startup tasks completed."

exec "$@"
