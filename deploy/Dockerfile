# syntax=docker/dockerfile:1
# Image produksi Kartu Anggota. Konteks build: root repo.
#   target "app" → PHP-FPM (juga dipakai worker & scheduler)
#   target "web" → Caddy (HTTPS otomatis) + berkas publik

# 1) Aset frontend (Vite: React + shadcn)
FROM node:24-alpine AS assets
WORKDIR /app
COPY backend/package.json backend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY backend/resources ./resources
COPY backend/public ./public
COPY backend/vite.config.js backend/tsconfig.json backend/components.json ./
RUN npm run build

# 2) Dependensi PHP (tanpa paket dev)
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-scripts

# 3) Aplikasi (PHP-FPM)
FROM php:8.5-fpm-alpine AS app
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis gd intl bcmath zip exif opcache pcntl
COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-kartu-anggota.ini"
WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
RUN php artisan package:discover --ansi \
 && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache
COPY deploy/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# 4) Web server (Caddy): sajikan berkas publik, teruskan PHP ke app:9000
FROM caddy:2-alpine AS web
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY backend/public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
