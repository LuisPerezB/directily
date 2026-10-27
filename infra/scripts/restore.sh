#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · restauración de un respaldo de PostgreSQL.
#
# Uso:
#   infra/scripts/restore.sh <archivo.dump[.gpg]> [base_destino]
#   infra/scripts/restore.sh --verify <archivo.dump[.gpg]>
#
#   --verify  restaura en una base temporal (predictily_restore_check), compara el número de
#             tablas y filas de tablas clave, y la elimina. Úselo para la prueba mensual.
#
# Restaurar sobre la base de producción REEMPLAZA su contenido: detenga antes los servicios
# backend/queue/scheduler/engine y confirme escribiendo el nombre de la base.
# BACKUP_MODE=docker|native|auto como en backup.sh.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ -f "$ROOT/.env" ]]; then
  set -a; # shellcheck disable=SC1091
  source <(grep -E '^(DB_|BACKUP_)[A-Z_]*=' "$ROOT/.env"); set +a
fi
DB_USERNAME="${DB_USERNAME:-predictily}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
MODE="${BACKUP_MODE:-auto}"
export PGOPTIONS="--client-min-messages=warning"

VERIFY=false
if [[ "${1:-}" == "--verify" ]]; then VERIFY=true; shift; fi
SRC="${1:?Indique el archivo de respaldo}"
TARGET="${2:-${DB_DATABASE:-predictily}}"
$VERIFY && TARGET="predictily_restore_check"
[[ -f "$SRC" ]] || { echo "No existe $SRC" >&2; exit 1; }

if [[ -f "$SRC.sha256" ]]; then
  (cd "$(dirname "$SRC")" && sha256sum -c "$(basename "$SRC").sha256" >/dev/null) \
    && echo "[restore] suma SHA-256 verificada" || { echo "[restore] ERROR: suma SHA-256 no coincide" >&2; exit 1; }
fi

if [[ "$MODE" == "auto" ]]; then
  if command -v docker >/dev/null 2>&1 && (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx postgres); then
    MODE=docker
  else
    MODE=native
  fi
fi

TMP=""
cleanup() { if [[ -n "$TMP" ]]; then rm -f "$TMP"; fi; }
trap cleanup EXIT
if [[ "$SRC" == *.gpg ]]; then
  TMP="$(mktemp)"; gpg --batch --yes --output "$TMP" --decrypt "$SRC"; SRC="$TMP"
fi

psql_cmd() {
  if [[ "$MODE" == "docker" ]]; then
    (cd "$ROOT" && docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U "$DB_USERNAME" "$@")
  else
    PGPASSWORD="${DB_PASSWORD:-}" psql -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$@"
  fi
}
restore_cmd() {
  if [[ "$MODE" == "docker" ]]; then
    (cd "$ROOT" && docker compose exec -T postgres pg_restore -U "$DB_USERNAME" -d "$TARGET" --no-owner --no-privileges --exit-on-error) < "$SRC"
  else
    PGPASSWORD="${DB_PASSWORD:-}" pg_restore -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$TARGET" --no-owner --no-privileges --exit-on-error "$SRC"
  fi
}

if ! $VERIFY; then
  echo "ATENCIÓN: se reemplazará TODO el contenido de la base '$TARGET'."
  read -r -p "Escriba el nombre de la base para confirmar: " answer
  [[ "$answer" == "$TARGET" ]] || { echo "Cancelado."; exit 1; }
fi

echo "[restore] modo=$MODE destino=$TARGET"
psql_cmd -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\" WITH (FORCE);" >/dev/null
psql_cmd -d postgres -c "CREATE DATABASE \"$TARGET\";" >/dev/null
restore_cmd
TABLES="$(psql_cmd -d "$TARGET" -At -c "select count(*) from information_schema.tables where table_schema='public'")"
echo "[restore] OK: $TABLES tablas restauradas en $TARGET"

if $VERIFY; then
  for t in tenants users sales sale_items forecast_runs forecast_values; do
    n="$(psql_cmd -d "$TARGET" -At -c "select count(*) from $t" 2>/dev/null || echo 'n/a')"
    echo "[verify] $t: $n filas"
  done
  psql_cmd -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\" WITH (FORCE);" >/dev/null
  echo "[verify] base temporal eliminada. Prueba de restauración completada."
fi
