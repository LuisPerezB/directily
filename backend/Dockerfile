# syntax=docker/dockerfile:1.7
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · backend Laravel 13 sobre Ubuntu 24.04 LTS (PHP 8.3 de los repositorios oficiales).
# Una sola imagen para: api (php-fpm), queue (queue:work) y scheduler (schedule:work).
#
#   docker build -t predictily/backend .
#
# Se usa Ubuntu LTS porque trae PHP 8.3 y todas las extensiones empaquetadas (sin compilar),
# con parches de seguridad hasta 2029.
#
# Verificación detrás de un proxy TLS corporativo (opcional, NO necesario en producción):
#   docker build --network host --build-arg https_proxy=http://127.0.0.1:PORT \
#     --build-arg APT_HTTPS=true --secret id=extra_ca,src=/ruta/ca-bundle.crt -t predictily/backend .
# ─────────────────────────────────────────────────────────────────────────────
FROM ubuntu:24.04 AS php-base
ARG APT_HTTPS=false
ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN --mount=type=secret,id=extra_ca,required=false \
    set -eux; \
    if [ "$APT_HTTPS" = "true" ]; then sed -i 's|http://|https://|g' /etc/apt/sources.list.d/ubuntu.sources; fi; \
    if [ -f /run/secrets/extra_ca ]; then \
      install -m 0644 /run/secrets/extra_ca /tmp/extra-ca.crt; \
      echo 'Acquire::https::CAInfo "/tmp/extra-ca.crt";' > /etc/apt/apt.conf.d/99extra-ca; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates tzdata unzip libfcgi-bin \
        php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-intl php8.3-zip php8.3-gd \
        php8.3-mbstring php8.3-xml php8.3-bcmath php8.3-curl php8.3-opcache; \
    if [ -f /run/secrets/extra_ca ]; then \
      cp /run/secrets/extra_ca /usr/local/share/ca-certificates/extra-ca.crt && update-ca-certificates; \
    fi; \
    rm -f /etc/apt/apt.conf.d/99extra-ca /tmp/extra-ca.crt /etc/php/8.3/fpm/pool.d/www.conf; \
    apt-get clean; rm -rf /var/lib/apt/lists/*; \
    for m in pdo_pgsql redis intl zip gd pcntl bcmath mbstring; do php -m | grep -qix "$m"; done

COPY docker/php/php.ini      /etc/php/8.3/fpm/conf.d/90-predictily.ini
COPY docker/php/php.ini      /etc/php/8.3/cli/conf.d/90-predictily.ini
COPY docker/php/cli.ini      /etc/php/8.3/cli/conf.d/91-predictily-cli.ini
COPY docker/php/opcache.ini  /etc/php/8.3/fpm/conf.d/91-opcache.ini
COPY docker/php/php-fpm.conf /etc/php/8.3/fpm/php-fpm.conf
COPY docker/php/www.conf     /etc/php/8.3/fpm/pool.d/www.conf

# ── Dependencias Composer (sin dev) ──────────────────────────────────────────
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
# Secreto opcional "composer_auth" (JSON de COMPOSER_AUTH, p. ej. token de GitHub) para evitar
# límites de tasa en CI:  --secret id=composer_auth,src=auth.json
RUN --mount=type=secret,id=extra_ca,required=false \
    --mount=type=secret,id=composer_auth,required=false \
    --mount=type=cache,target=/root/.cache/composer \
    if [ -f /run/secrets/extra_ca ]; then export SSL_CERT_FILE=/run/secrets/extra_ca; fi; \
    if [ -f /run/secrets/composer_auth ]; then export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; fi; \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && rm -rf tests docker .env .env.* storage/logs/* storage/framework/cache/data/* \
           storage/framework/sessions/* storage/framework/views/* storage/app/private/*

# ── Imagen final ─────────────────────────────────────────────────────────────
FROM php-base AS app
LABEL org.opencontainers.image.title="predictily-backend" \
      org.opencontainers.image.description="Predictily · API pública Laravel, colas y programador"
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/predictily-entrypoint
RUN chmod +x /usr/local/bin/predictily-entrypoint \
 && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1
ENTRYPOINT ["predictily-entrypoint"]
CMD ["php-fpm8.3", "--nodaemonize"]
