#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Predictily · comprobación de la RECUPERACIÓN de PostgreSQL (respaldar → restaurar → comparar).
#
#   infra/scripts/recovery-check.sh manifest <base>          escribe en stdout el manifiesto (esquema + filas por tabla)
#   infra/scripts/recovery-check.sh verify <respaldo.dump[.gpg]> [manifiesto]   (si no se indica, <respaldo>.counts o .counts.gpg)
#                                                            restaura el respaldo en una base TEMPORAL vacía, calcula
#                                                            su manifiesto y lo compara con <respaldo>.counts
#   infra/scripts/recovery-check.sh cycle <base>             respalda <base> con backup.sh (en un directorio temporal),
#                                                            restaura y compara; elimina todo al terminar
#
# Códigos de salida: 0 = restauración idéntica · 1 = diferencias (la recuperación NO es fiable) · 2 = uso o configuración
# incorrectos · 3 = fallo al respaldar o restaurar.
#
# Seguridad: solo LEE la base de origen. La base destino siempre es nueva y se llama predictily_recovery_check_<pid>;
# el script se niega a tocar cualquier otra. Con `cycle` se niega a usar la base `predictily` (la de desarrollo/producción
# del .env) salvo --allow-live-source; para un respaldo real use `verify` sobre el archivo generado por backup.sh.
#
# Variables (como en backup.sh): DB_USERNAME, DB_PASSWORD, DB_HOST (127.0.0.1), DB_PORT (5432), BACKUP_MODE=native|docker|auto,
# BACKUP_IGNORE_ENV_FILE. Las del entorno tienen precedencia sobre las del .env de la raíz.
# Funciona con bash 3.2 (macOS) y con herramientas cliente de PostgreSQL 15 o superior.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

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

DB_USERNAME="${DB_USERNAME:-predictily}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
MODE="${BACKUP_MODE:-auto}"
export PGOPTIONS="--client-min-messages=warning"

if [[ "$MODE" == "auto" ]]; then
  if command -v docker >/dev/null 2>&1 && (cd "$ROOT" && docker compose ps --status running --services 2>/dev/null | grep -qx postgres); then
    MODE=docker
  else
    MODE=native
  fi
fi

die() { echo "[recovery-check] ERROR: $1" >&2; exit "${2:-2}"; }
log() { echo "[recovery-check] $*" >&2; }

psql_db() { # psql_db <base> [argumentos de psql...]
  local db="$1"; shift
  if [[ "$MODE" == "docker" ]]; then
    (cd "$ROOT" && docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 -U "$DB_USERNAME" -d "$db" "$@")
  else
    PGPASSWORD="${DB_PASSWORD:-}" psql -X -v ON_ERROR_STOP=1 -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$db" "$@"
  fi
}

restore_file() { # restore_file <archivo> <base destino>
  local src="$1" target="$2"
  if [[ "$MODE" == "docker" ]]; then
    (cd "$ROOT" && docker compose exec -T postgres pg_restore -U "$DB_USERNAME" -d "$target" --no-owner --no-privileges --exit-on-error) < "$src"
  else
    PGPASSWORD="${DB_PASSWORD:-}" pg_restore -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$target" --no-owner --no-privileges --exit-on-error "$src"
  fi
}

# Manifiesto: líneas `clave=valor` (esquema) y `count|tabla|filas` ordenadas por tabla. Solo lectura.
manifest() {
  local db="$1" counts_sql
  psql_db "$db" -At -c "select 'tables=' || count(*) from pg_tables where schemaname = 'public'"
  psql_db "$db" -At -c "select 'indexes=' || count(*) from pg_indexes where schemaname = 'public'"
  psql_db "$db" -At -c "select 'constraints=' || count(*) from pg_constraint c join pg_namespace n on n.oid = c.connamespace where n.nspname = 'public'"
  psql_db "$db" -At -c "select 'sequences=' || count(*) from pg_sequences where schemaname = 'public'"
  psql_db "$db" -At -c "select 'schema_md5=' || md5(coalesce(string_agg(table_name || '.' || column_name || ':' || data_type || ':' || is_nullable, ',' order by table_name, ordinal_position), '')) from information_schema.columns where table_schema = 'public'"
  counts_sql="$(psql_db "$db" -At -c "select coalesce(string_agg(format('select %L as t, count(*) as n from public.%I', tablename, tablename), ' union all ' order by tablename), '') from pg_tables where schemaname = 'public'")"
  if [[ -n "$counts_sql" ]]; then
    psql_db "$db" -At -F '|' -c "select 'count|' || t || '|' || n from ($counts_sql) q order by t"
  fi
  # Valor actual de cada secuencia (last_value; «null» si nunca se usó): una restauración que dejara las secuencias en 1 haría que
  # el siguiente INSERT chocara con ids existentes aunque los conteos de filas coincidan.
  psql_db "$db" -At -c "select 'seq|' || sequencename || '|' || coalesce(last_value::text, 'null') from pg_sequences where schemaname = 'public' order by sequencename"
}

write_manifest() { # write_manifest <base> <archivo salida>
  { echo "# predictily-recovery-manifest v1"; manifest "$1"; } > "$2"
}

compare_manifests() { # compare_manifests <esperado> <obtenido>: imprime el informe; devuelve 0 si son idénticos
  local expected="$1" got="$2" diffs=0 tables rows
  if diff <(grep -v '^#' "$expected") <(grep -v '^#' "$got") > "$got.diff"; then
    tables="$(grep -c '^count|' "$got" || true)"
    rows="$(grep '^count|' "$got" | awk -F'|' '{s += $3} END {print s + 0}')"
    log "IDÉNTICO: $tables tablas, $rows filas; coinciden el esquema (huella de columnas), el número de índices, restricciones y secuencias, el valor actual de cada secuencia y las filas de cada tabla"
    return 0
  fi
  diffs="$(grep -c '^[<>]' "$got.diff" || true)"
  log "DIFERENCIAS ($diffs líneas): < esperado (respaldo) · > obtenido (restauración)"
  sed 's/^/    /' "$got.diff" >&2
  return 1
}

TMP_DIR=""
TARGET_DB=""
cleanup() {
  if [[ -n "$TARGET_DB" ]]; then
    psql_db postgres -c "drop database if exists \"$TARGET_DB\" with (force)" >/dev/null 2>&1 || log "AVISO: no se pudo eliminar la base temporal $TARGET_DB"
  fi
  if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then rm -rf "$TMP_DIR"; fi
}
trap cleanup EXIT

verify() { # verify <respaldo> [manifiesto]
  local src="$1" expected="${2:-}" plain started
  [[ -f "$src" ]] || die "no existe el respaldo $src"
  [[ -n "$TMP_DIR" ]] || TMP_DIR="$(mktemp -d)"
  if [[ -z "$expected" ]]; then
    if [[ -f "$src.counts" ]]; then
      expected="$src.counts"
    elif [[ -f "$src.counts.gpg" ]]; then
      expected="$TMP_DIR/esperado.counts"
      gpg --batch --yes --output "$expected" --decrypt "$src.counts.gpg" || die "no se pudo descifrar el manifiesto de conteos" 3
    fi
  fi
  [[ -n "$expected" && -f "$expected" ]] || die "no existe el manifiesto de conteos de $src (el respaldo se hizo con BACKUP_COUNTS=false o es anterior)"
  if [[ -f "$src.sha256" ]]; then
    local want have
    want="$(cut -d' ' -f1 "$src.sha256")"
    if command -v sha256sum >/dev/null 2>&1; then have="$(sha256sum "$src" | cut -d' ' -f1)"; else have="$(shasum -a 256 "$src" | cut -d' ' -f1)"; fi
    [[ "$want" == "$have" ]] || die "la suma SHA-256 del respaldo no coincide: el archivo está alterado o incompleto" 3
    log "suma SHA-256 verificada"
  else
    log "AVISO: no hay archivo .sha256 junto al respaldo"
  fi
  plain="$src"
  if [[ "$src" == *.gpg ]]; then
    plain="$TMP_DIR/respaldo.dump"
    gpg --batch --yes --output "$plain" --decrypt "$src" || die "no se pudo descifrar el respaldo" 3
  fi
  TARGET_DB="predictily_recovery_check_$$"
  started="$(date +%s)"
  psql_db postgres -c "create database \"$TARGET_DB\"" >/dev/null || die "no se pudo crear la base temporal" 3
  restore_file "$plain" "$TARGET_DB" || die "pg_restore falló" 3
  log "restauración terminada en $(( $(date +%s) - started )) s (base temporal $TARGET_DB)"
  write_manifest "$TARGET_DB" "$TMP_DIR/restaurado.counts"
  compare_manifests "$expected" "$TMP_DIR/restaurado.counts"
}

cmd="${1:-}"
case "$cmd" in
  manifest)
    [[ -n "${2:-}" ]] || die "uso: recovery-check.sh manifest <base>"
    manifest "$2"
    ;;
  verify)
    [[ -n "${2:-}" ]] || die "uso: recovery-check.sh verify <respaldo.dump[.gpg]> [manifiesto]"
    verify "$2" "${3:-}" || exit $?
    ;;
  cycle)
    SOURCE="${2:-}"; ALLOW_LIVE=false
    [[ -n "$SOURCE" ]] || die "uso: recovery-check.sh cycle <base> [--allow-live-source]"
    [[ "${3:-}" == "--allow-live-source" ]] && ALLOW_LIVE=true
    if [[ "$SOURCE" == "predictily" && "$ALLOW_LIVE" != "true" ]]; then
      die "«predictily» es la base de desarrollo/producción: use una base de pruebas, o --allow-live-source si es intencional"
    fi
    TMP_DIR="$(mktemp -d)"
    log "respaldando $SOURCE con backup.sh → $TMP_DIR"
    env DB_DATABASE="$SOURCE" DB_USERNAME="$DB_USERNAME" DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_PASSWORD="${DB_PASSWORD:-}" \
        BACKUP_MODE="$MODE" BACKUP_DIR="$TMP_DIR" BACKUP_COUNTS=true BACKUP_IGNORE_ENV_FILE=true BACKUP_GPG_RECIPIENT="${BACKUP_GPG_RECIPIENT:-}" \
        "$ROOT/infra/scripts/backup.sh" >&2 || die "backup.sh falló" 3
    DUMP="$(ls "$TMP_DIR"/"${SOURCE}"_*.dump* 2>/dev/null | grep -v '\.sha256$\|\.counts$\|\.counts\.gpg$' | head -1)"
    [[ -n "$DUMP" ]] || die "backup.sh no produjo un archivo de respaldo" 3
    verify "$DUMP" || exit $?
    ;;
  *)
    sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 2
    ;;
esac
