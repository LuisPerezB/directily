#!/bin/sh
# Se ejecuta SOLO la primera vez que se inicializa el volumen de PostgreSQL.
# Crea bases adicionales (pruebas) listadas en POSTGRES_EXTRA_DATABASES (separadas por coma).
set -eu
extra="${POSTGRES_EXTRA_DATABASES:-}"
[ -z "$extra" ] && exit 0
for db in $(echo "$extra" | tr ',' ' '); do
  echo "[predictily] creando base de datos $db"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
SELECT 'CREATE DATABASE "$db" OWNER "$POSTGRES_USER"'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$db')\gexec
SQL
done
