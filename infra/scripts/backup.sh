#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · respaldo de PostgreSQL (pg_dump formato custom) con rotación.
#
# Uso:
#   infra/scripts/backup.sh                 # autodetecta: docker compose si el servicio
#                                           # "postgres" está corriendo; si no, pg_dump local
#   BACKUP_MODE=native infra/scripts/backup.sh
#   BACKUP_MODE=docker infra/scripts/backup.sh
#
# Variables (se leen de .env en la raíz si existe):
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST (nativo, def. 127.0.0.1), DB_PORT (5432)
#   BACKUP_DIR (./backups), BACKUP_RETENTION_DAYS (14), BACKUP_GPG_RECIPIENT (opcional)
#   BACKUP_COUNTS (true): además del respaldo escribe <archivo>.counts, un manifiesto con el esquema y el número de filas
#                         de cada tabla, que infra/scripts/recovery-check.sh usa para comprobar una restauración.
#   BACKUP_IGNORE_ENV_FILE (false): no leer el .env de la raíz (útil en pruebas y en CI).
# Las variables ya definidas en el entorno TIENEN PRECEDENCIA sobre las del .env (como en docker compose); así una
# ejecución de prueba nunca respalda por accidente la base de datos del .env.
#
# Verificación de la restauración (respaldar → restaurar en una base vacía → comparar conteos):
#   infra/scripts/recovery-check.sh cycle <base>        # ver docs/operations/RECOVERY.md
#
# Cron sugerido en el VPS (diario 03:00):
#   0 3 * * * cd /opt/predictily && infra/scripts/backup.sh >> /var/log/predictily-backup.log 2>&1
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Carga DB_* y BACKUP_* del .env SOLO si no están ya definidas en el entorno.
load_env_file() {
  local file="$1" line key value
  [[ "${BACKUP_IGNORE_ENV_FILE:-false}" == "true" ]] && return 0
  [[ -f "$file" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^(DB_|BACKUP_)[A-Z_]*= ]] || continue
    key="${line%%=*}"; value="${line#*=}"
    value="${value%$'\r'}"                                   # fin de línea de Windows
    value="${value%"${value##*[![:space:]]}"}"                # espacios finales
    case "$value" in
      \"*\"|\'*\') value="${value:1:${#value}-2}" ;;          # valor entre comillas
      \"*|\'*) value="${value#?}" ;;                           # comilla sin cerrar: se descarta solo la inicial
      *) value="${value%%[[:space:]]#*}"; value="${value%"${value##*[![:space:]]}"}" ;;   # sin comillas: sin comentario ni espacios finales
    esac
    if [[ -z "$(printenv "$key" || true)" ]]; then export "$key=$value"; fi
  done < "$file"
}
load_env_file "$ROOT/.env"

sha256_of() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }

DB_DATABASE="${DB_DATABASE:-predictily}"
DB_USERNAME="${DB_USERNAME:-predictily}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
[[ "$BACKUP_DIR" = /* ]] || BACKUP_DIR="$ROOT/${BACKUP_DIR#./}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_GPG_RECIPIENT="${BACKUP_GPG_RECIPIENT:-}"
MODE="${BACKUP_MODE:-auto}"
BACKUP_COUNTS="${BACKUP_COUNTS:-true}"

if [[ "$MODE" == "auto" ]]; then
  if command -v docker >/dev/null 2>&1 && (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx postgres); then
    MODE=docker
  else
    MODE=native
  fi
fi

mkdir -p "$BACKUP_DIR"
umask 077
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$BACKUP_DIR/${DB_DATABASE}_${STAMP}.dump"

echo "[backup] $(date -u +%FT%TZ) modo=$MODE base=$DB_DATABASE destino=$FILE"
if [[ "$MODE" == "docker" ]]; then
  (cd "$ROOT" && docker compose exec -T postgres \
     pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom --compress=6 --no-owner --no-privileges) > "$FILE"
else
  PGPASSWORD="${DB_PASSWORD:-}" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" \
     --format=custom --compress=6 --no-owner --no-privileges --file="$FILE"
fi

# Verificación mínima: el archivo debe tener encabezado de pg_dump custom ("PGDMP").
if [[ "$(head -c 5 "$FILE")" != "PGDMP" ]]; then
  echo "[backup] ERROR: el respaldo no tiene formato pg_dump válido" >&2
  rm -f "$FILE"; exit 1
fi

if [[ -n "$BACKUP_GPG_RECIPIENT" ]]; then
  gpg --batch --yes --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --output "$FILE.gpg" --encrypt "$FILE"
  rm -f "$FILE"; FILE="$FILE.gpg"
fi

(cd "$(dirname "$FILE")" && sha256_of "$(basename "$FILE")" > "$(basename "$FILE").sha256")
echo "[backup] OK $(du -h "$FILE" | cut -f1) $(cut -d' ' -f1 "$FILE.sha256")"

# Manifiesto de conteos (esquema + filas por tabla) para comprobar después la restauración (recovery-check.sh verify).
# Contiene nombres de tablas y volumen de filas (no datos de clientes, pero sí información de negocio): con BACKUP_GPG_RECIPIENT
# se cifra igual que el respaldo (<archivo>.counts.gpg) y no queda copia en claro.
if [[ "$BACKUP_COUNTS" == "true" ]]; then
  COUNTS_FILE="${FILE%.gpg}.counts"
  if env DB_DATABASE="$DB_DATABASE" DB_USERNAME="$DB_USERNAME" DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" BACKUP_MODE="$MODE" BACKUP_IGNORE_ENV_FILE=true \
       "$ROOT/infra/scripts/recovery-check.sh" manifest "$DB_DATABASE" > "$COUNTS_FILE.tmp"; then
    if [[ -n "$BACKUP_GPG_RECIPIENT" ]]; then
      gpg --batch --yes --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --output "$FILE.counts.gpg" --encrypt "$COUNTS_FILE.tmp"
      rm -f "$COUNTS_FILE.tmp"
      echo "[backup] manifiesto de conteos (cifrado): $FILE.counts.gpg"
    else
      mv "$COUNTS_FILE.tmp" "$FILE.counts"
      echo "[backup] manifiesto de conteos: $FILE.counts"
    fi
  else
    rm -f "$COUNTS_FILE.tmp"
    echo "[backup] AVISO: no se pudo escribir el manifiesto de conteos (el respaldo es válido)" >&2
  fi
fi

# Rotación: elimina respaldos más antiguos que BACKUP_RETENTION_DAYS.
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name "${DB_DATABASE}_*.dump*" \) -mtime +"$BACKUP_RETENTION_DAYS" -print -delete \
  | sed 's/^/[backup] rotado: /' || true
