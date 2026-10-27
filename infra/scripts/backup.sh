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
#
# Cron sugerido en el VPS (diario 03:00):
#   0 3 * * * cd /opt/predictily && infra/scripts/backup.sh >> /var/log/predictily-backup.log 2>&1
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -f "$ROOT/.env" ]]; then
  set -a; # shellcheck disable=SC1091
  source <(grep -E '^(DB_|BACKUP_)[A-Z_]*=' "$ROOT/.env"); set +a
fi

DB_DATABASE="${DB_DATABASE:-predictily}"
DB_USERNAME="${DB_USERNAME:-predictily}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
[[ "$BACKUP_DIR" = /* ]] || BACKUP_DIR="$ROOT/${BACKUP_DIR#./}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_GPG_RECIPIENT="${BACKUP_GPG_RECIPIENT:-}"
MODE="${BACKUP_MODE:-auto}"

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

sha256sum "$FILE" > "$FILE.sha256"
echo "[backup] OK $(du -h "$FILE" | cut -f1) $(cut -d' ' -f1 "$FILE.sha256")"

# Rotación: elimina respaldos más antiguos que BACKUP_RETENTION_DAYS.
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name "${DB_DATABASE}_*.dump*" \) -mtime +"$BACKUP_RETENTION_DAYS" -print -delete \
  | sed 's/^/[backup] rotado: /' || true
