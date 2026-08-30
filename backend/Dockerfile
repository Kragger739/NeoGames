# Runs the whole backend - PHP-FPM, nginx, Reverb's websocket server, and
# the queue worker - as one container, since a single free web-service slot
# (Render, etc.) only gets one process group. nginx is the sole public
# entrypoint: it fastcgi-proxies normal requests to PHP-FPM and reverse-
# proxies /app/* (Reverb's websocket upgrade path) to Reverb's internal,
# loopback-only port - see docker/nginx.conf.template.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor postgresql-dev libzip-dev oniguruma-dev icu-dev bash \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring bcmath pcntl zip intl

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 10000

ENTRYPOINT ["/entrypoint.sh"]
