#!/bin/sh
# Disiapkan sebelum proses utama (php-fpm / queue:work / schedule:work).
set -e
cd /var/www/html

if [ "$(id -u)" = "0" ]; then
  # Volume storage baru masih kosong & milik root: siapkan struktur & pemilik.
  mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
  chown -R www-data:www-data storage
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force --no-interaction
fi

if [ "${CACHE_CONFIG:-0}" = "1" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
fi

exec "$@"
