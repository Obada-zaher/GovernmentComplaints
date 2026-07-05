#!/usr/bin/env bash
set -e

echo "Running Laravel deployment startup tasks..."

php artisan optimize:clear || true

php artisan migrate --force

php artisan db:seed --force

echo "Laravel deployment startup tasks completed."

exec "$@"
