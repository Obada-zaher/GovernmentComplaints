#!/usr/bin/env bash
set -e

echo "Running Laravel deployment startup tasks..."

mkdir -p \
  resources/views \
  storage/framework/views \
  storage/framework/cache \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/app/public \
  storage/logs \
  bootstrap/cache

chmod -R 775 storage bootstrap/cache resources/views || true

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan cache:clear || true
php artisan optimize:clear || true

php artisan storage:link || {
  if [ -L public/storage ]; then
    echo "Laravel public storage link already exists."
  else
    exit 1
  fi
}

php artisan migrate --force

php artisan db:seed --force

if [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
  echo "SEED_DEMO_DATA=true: seeding the full academic demo dataset..."
  php artisan db:seed --class=DemoDataSeeder --force
else
  echo "SEED_DEMO_DATA is not enabled: skipping the full academic demo dataset."
fi

echo "Laravel deployment startup tasks completed."

exec "$@"
