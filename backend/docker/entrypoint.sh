#!/bin/sh
# Entrypoint común para api (php-fpm), queue y scheduler.
#   RUN_MIGRATIONS=true   ejecuta migraciones (activar SOLO en el servicio "backend").
#   CACHE_CONFIG=true     cachea config/rutas/vistas/eventos (por defecto en producción).
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
  echo "[predictily] ERROR: APP_KEY no está definido. Genere uno con: php artisan key:generate --show" >&2
  exit 1
fi

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

if [ "${CACHE_CONFIG:-$([ "${APP_ENV:-production}" = "production" ] && echo true || echo false)}" = "true" ]; then
  # No es fatal: si falla (p. ej. closures en rutas) se continúa sin caché.
  php artisan optimize --no-interaction >/dev/null || echo "[predictily] aviso: 'artisan optimize' falló; se continúa sin caché" >&2
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  # Reintenta mientras PostgreSQL termina de arrancar.
  i=0
  until php artisan migrate --force --no-interaction; do
    i=$((i + 1))
    if [ "$i" -ge 10 ]; then echo "[predictily] migraciones fallidas" >&2; exit 1; fi
    echo "[predictily] esperando base de datos ($i)…"; sleep 3
  done
fi

exec "$@"
