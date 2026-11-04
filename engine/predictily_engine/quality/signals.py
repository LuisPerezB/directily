"""Señales de calidad que NO están en memoria y se leen de la base con el MISMO corte as-of que los datos (ADR-V25-23).

- `ingestion`     : % de filas inválidas de los informes de ingestión (`data_quality_reports`) de la ventana, contando SOLO cargas que
                    entraron a Silver: importaciones `completed` (las validadas, rechazadas o fallidas también escriben informe pero no
                    escribieron datos), sincronizaciones `completed` y lotes `sales_batch` que escribieron documentos; los reprocesos
                    desde Bronze (que repiten filas ya contadas) se excluyen.
- `identity`      : proporción de unidades/líneas de compradores identificados, genéricos, anónimos y dados de baja (ventana).
- `id_stability`  : productos y clientes con alias de cambio de código / fusión / división recientes y proporción de códigos nuevos.
- `ingestion_lag` : distribución del desfase evento→ingestión de los documentos incrementales.

Regla de visibilidad (la de `data.asof`): una versión de línea es visible al corte C si `known_from < M(C+1)` y (`known_until` es NULL
o `known_until ≥ M(C+1)`), con `M(C+1)` la medianoche local del día siguiente; un informe, alias o documento cuenta solo si se creó o se
vio por primera vez ANTES de ese instante. Nada posterior al corte entra.

Cada lectura va en un SAVEPOINT: si falla (esquema sin migrar, etc.) la señal queda `available: false` con su motivo y la transacción
del llamador no se envenena; el gate no la da por buena (la regla queda en `not_evaluated`).
"""

from __future__ import annotations

import logging
from collections.abc import Sequence
from datetime import date, timezone
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.data.asof import knowledge_boundary, resolve_timezone

log = logging.getLogger(__name__)

VALID_DOCS = "('invoice', 'sales_note', 'other')"
NON_INGESTION_SCOPES = "('run', 'forecasting_readiness', 'data_core')"
CHANGE_KINDS = "('code_change', 'merge', 'split')"

_VISIBLE = ("v.tenant_id = :tenant_id AND v.status = 'completed' AND v.document_type IN " + VALID_DOCS + " "
            "AND v.is_return = false AND v.quantity > 0 AND v.sale_date <= CAST(:cutoff AS date) "
            "AND v.known_from < :boundary AND (v.known_until IS NULL OR v.known_until >= :boundary)")


def _read(conn: Connection, sql: str, tenant_id: int, **params: Any) -> pd.DataFrame:
    if ":tenant_id" not in sql:
        raise RuntimeError("Toda señal de calidad debe filtrar por :tenant_id")
    result = conn.execute(text(sql), {"tenant_id": tenant_id, **params})
    return pd.DataFrame(result.fetchall(), columns=list(result.keys()))


def _guarded(conn: Connection, name: str, fn: Any) -> dict[str, Any]:
    try:
        with conn.begin_nested():
            return fn()
    except Exception as exc:  # noqa: BLE001 - una señal opcional no debe tumbar la ejecución; queda registrada como no disponible
        log.warning("Señal de calidad %s no disponible: %s", name, exc)
        return {"available": False, "failed": True, "reason": f"query_failed:{type(exc).__name__}"}


def _has_table(conn: Connection, name: str) -> bool:
    return conn.execute(text("SELECT to_regclass(:name)"), {"name": name}).scalar() is not None


def _columns(conn: Connection, table: str) -> set[str]:
    rows = conn.execute(text("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :t"),
                        {"t": table}).scalars().all()
    return set(rows)


def _share(num: Any, den: Any) -> float | None:
    den = float(den or 0)
    return round(float(num or 0) / den, 6) if den > 0 else None


def collect_signals(conn: Connection, tenant_id: int, as_of: date, timezone_name: str | None, policy: dict[str, Any],
                    generic_codes: Sequence[str] | None = None, dataset: str = "sales", knowledge: date | None = None) -> dict[str, Any]:
    """Todas las señales de la base para `tenant_id` al corte `as_of` (última fecha de EVENTO incluida). `policy`: configuración efectiva
    de `quality_gates` (ventanas). `dataset`: dataset de los informes de ingestión que cuentan para el % inválido. `knowledge`: hasta qué
    día llega lo CONOCIDO (por defecto el propio corte; una ejecución vigente pasa HOY, igual que la lectura de ventas de `data.asof`).
    `generic_codes`: códigos de comprador genérico (por defecto los del core de Customer)."""
    from predictily_engine.platform.config import CORE_DEFAULTS

    tz = resolve_timezone(timezone_name)
    known = max(pd.Timestamp(as_of), pd.Timestamp(knowledge)) if knowledge is not None else pd.Timestamp(as_of)
    boundary = pd.Timestamp(knowledge_boundary(known, tz)).to_pydatetime().replace(tzinfo=timezone.utc)
    if generic_codes is None:
        generic_codes = CORE_DEFAULTS["customer"]["generic_buyer_codes"]
    ctx = {"cutoff": as_of, "boundary": boundary, "tz": tz, "codes": list(generic_codes)}
    return {
        "ingestion": _guarded(conn, "ingestion", lambda: _ingestion(conn, tenant_id, ctx, int(policy["invalid_lookback_days"]), dataset)),
        "identity": _guarded(conn, "identity", lambda: _identity(conn, tenant_id, ctx, int(policy["identity_window_days"]))),
        "id_stability": _guarded(conn, "id_stability", lambda: _id_stability(conn, tenant_id, ctx, int(policy["id_lookback_days"]))),
        "ingestion_lag": _guarded(conn, "ingestion_lag", lambda: _ingestion_lag(conn, tenant_id, ctx, int(policy["lag_lookback_days"]))),
    }


def _ingestion(conn: Connection, tenant_id: int, ctx: dict[str, Any], lookback: int, dataset: str) -> dict[str, Any]:
    """% de filas inválidas de las cargas que ENTRARON a Silver dentro de la ventana y eran conocidas al corte.

    Un informe cuenta si es de una importación `completed` (también la que originó una sincronización `completed`) o de un lote
    `sales_batch` que escribió documentos. Quedan fuera: importaciones validadas, rechazadas o fallidas (escriben informe sin escribir
    datos, y un mapeo equivocado reintentado tres veces no debe bloquear a una empresa con una carga confirmada limpia), bloqueos
    estructurales (sin `rows_total`) y reprocesos desde Bronze (`options.reprocess_of_bronze_object_id` de la importación,
    `reprocess_of_bronze_object_id` o `reprocess` en el `summary`/`metrics` del informe —la marca de W2-A— o dataset `<entidad>_reprocess`):
    repiten filas ya contadas por la carga original."""
    columns = _columns(conn, "data_quality_reports")
    expr = "COALESCE(r.dataset, j.entity_type)" if "dataset" in columns else "j.entity_type"
    # W2-A marca el informe de un reproceso con `reprocess_of_bronze_object_id` en `summary` y `metrics` y le pone el dataset `<entidad>_reprocess`
    # (que ya no coincide con `sales`); `reprocess` es una marca genérica equivalente.
    carriers = ["r.summary"] + (["r.metrics"] if "metrics" in columns else [])
    flags = [f"{c}->>'reprocess'" for c in carriers]
    marked = " AND ".join(f"{c}->'reprocess_of_bronze_object_id' IS NULL" for c in carriers)
    start = ctx["boundary"] - pd.Timedelta(days=lookback)
    df = _read(conn, f"""
        SELECT COUNT(*) AS reports,
               COALESCE(SUM(NULLIF(r.summary->>'rows_total', '')::numeric), 0)::float8 AS rows_total,
               COALESCE(SUM(NULLIF(r.summary->>'rows_error', '')::numeric), 0)::float8 AS rows_error
        FROM data_quality_reports r
        LEFT JOIN import_jobs j ON j.tenant_id = r.tenant_id AND j.id = r.import_job_id
        LEFT JOIN source_syncs sy ON sy.tenant_id = r.tenant_id AND sy.id = {"r.source_sync_id" if "source_sync_id" in columns else "NULL"}
        WHERE r.tenant_id = :tenant_id AND r.scope NOT IN {NON_INGESTION_SCOPES}
          AND {expr} = :dataset AND r.summary->>'rows_total' IS NOT NULL
          AND r.created_at < :boundary AND r.created_at >= :start
          AND (
                (r.import_job_id IS NOT NULL AND j.status = 'completed')
                OR (r.import_job_id IS NULL AND r.scope = 'sales_batch'
                    AND COALESCE(NULLIF(r.summary->>'created', '')::numeric, 0) + COALESCE(NULLIF(r.summary->>'updated', '')::numeric, 0)
                        + COALESCE(NULLIF(r.summary->>'unchanged', '')::numeric, 0) > 0)
              )
          AND ({"r.source_sync_id IS NULL OR sy.status = 'completed'" if "source_sync_id" in columns else "TRUE"})
          AND j.options->'reprocess_of_bronze_object_id' IS NULL AND {marked}
          AND COALESCE({", ".join(flags)}, 'false') NOT IN ('true', '1')
        """, tenant_id, dataset=dataset, boundary=ctx["boundary"], start=start)
    row = df.iloc[0]
    total, error = float(row["rows_total"]), float(row["rows_error"])
    return {"available": True, "dataset": dataset, "reports": int(row["reports"]), "rows_total": int(total), "rows_error": int(error),
            "invalid_pct": _share(error, total), "lookback_days": lookback,
            "counted": "importaciones completed, sincronizaciones completed y lotes con documentos escritos; sin reprocesos"}


def _identity(conn: Connection, tenant_id: int, ctx: dict[str, Any], window: int) -> dict[str, Any]:
    generic = "(c.is_generic OR c.external_code = ANY(CAST(:codes AS text[])))"
    df = _read(conn, f"""
        SELECT COUNT(*) AS lines, COALESCE(SUM(v.quantity), 0)::float8 AS units,
               COUNT(*) FILTER (WHERE v.customer_id IS NULL) AS anon_lines,
               COALESCE(SUM(v.quantity) FILTER (WHERE v.customer_id IS NULL), 0)::float8 AS anon_units,
               COUNT(*) FILTER (WHERE c.id IS NOT NULL AND {generic}) AS generic_lines,
               COALESCE(SUM(v.quantity) FILTER (WHERE c.id IS NOT NULL AND {generic}), 0)::float8 AS generic_units,
               COUNT(*) FILTER (WHERE c.id IS NOT NULL AND NOT {generic} AND c.deleted_at IS NOT NULL AND c.deleted_at < :boundary) AS deleted_lines,
               COALESCE(SUM(v.quantity) FILTER (WHERE c.id IS NOT NULL AND NOT {generic} AND c.deleted_at IS NOT NULL
                                                  AND c.deleted_at < :boundary), 0)::float8 AS deleted_units
        FROM sales_line_versions v
        LEFT JOIN customers c ON c.id = v.customer_id AND c.tenant_id = v.tenant_id
        WHERE {_VISIBLE} AND v.sale_date > CAST(:cutoff AS date) - CAST(:window AS integer)
        """, tenant_id, cutoff=ctx["cutoff"], boundary=ctx["boundary"], codes=ctx["codes"], window=window)
    r = df.iloc[0]
    lines, units = int(r["lines"]), float(r["units"])
    identified_lines = lines - int(r["anon_lines"])
    modeled_units = units - float(r["anon_units"]) - float(r["generic_units"]) - float(r["deleted_units"])
    return {
        "available": True, "window_days": window, "lines": lines, "units": round(units, 4),
        "lines_identified": identified_lines, "lines_deleted_customer": int(r["deleted_lines"]),
        "deleted_customer_share_lines": _share(r["deleted_lines"], identified_lines),
        "anonymous_share_units": _share(r["anon_units"], units), "generic_share_units": _share(r["generic_units"], units),
        "deleted_customer_share_units": _share(r["deleted_units"], units), "modeled_share_units": _share(modeled_units, units),
        "generic_share_lines": _share(r["generic_lines"], lines), "anonymous_share_lines": _share(r["anon_lines"], lines)}


def _id_stability(conn: Connection, tenant_id: int, ctx: dict[str, Any], lookback: int) -> dict[str, Any]:
    if not (_has_table(conn, "product_aliases") and _has_table(conn, "customer_aliases")):
        return {"available": False, "failed": True, "reason": "alias_tables_missing"}
    generic = "(c.is_generic OR c.external_code = ANY(CAST(:codes AS text[])))"
    params = {"cutoff": ctx["cutoff"], "boundary": ctx["boundary"], "codes": ctx["codes"], "lookback": lookback, "tz": ctx["tz"]}
    products = _read(conn, f"""
        WITH first_p AS (
            SELECT v.product_id, MIN(v.sale_date) AS first_day
            FROM sales_line_versions v WHERE {_VISIBLE} GROUP BY v.product_id
        )
        SELECT COUNT(*) AS products,
               COUNT(*) FILTER (WHERE first_day > CAST(:cutoff AS date) - CAST(:lookback AS integer)) AS new_products,
               (SELECT COUNT(DISTINCT a.product_id) FROM product_aliases a
                 WHERE a.tenant_id = :tenant_id AND a.kind IN {CHANGE_KINDS} AND a.created_at < :boundary
                   AND COALESCE(a.valid_from, CAST(a.created_at AT TIME ZONE CAST(:tz AS text) AS date)) > CAST(:cutoff AS date) - CAST(:lookback AS integer)
                   AND COALESCE(a.valid_from, CAST(a.created_at AT TIME ZONE CAST(:tz AS text) AS date)) <= CAST(:cutoff AS date)
                   AND a.product_id IN (SELECT product_id FROM first_p)) AS changed_products,
               MIN(first_day) AS first_company_day
        FROM first_p
        """, tenant_id, **params).iloc[0]
    customers = _read(conn, f"""
        WITH first_c AS (
            SELECT v.customer_id, MIN(v.sale_date) AS first_day
            FROM sales_line_versions v JOIN customers c ON c.id = v.customer_id AND c.tenant_id = v.tenant_id
            WHERE {_VISIBLE} AND NOT {generic} GROUP BY v.customer_id
        )
        SELECT COUNT(*) AS customers,
               (SELECT COUNT(DISTINCT a.customer_id) FROM customer_aliases a
                 WHERE a.tenant_id = :tenant_id AND a.kind IN {CHANGE_KINDS} AND a.created_at < :boundary
                   AND COALESCE(a.valid_from, CAST(a.created_at AT TIME ZONE CAST(:tz AS text) AS date)) > CAST(:cutoff AS date) - CAST(:lookback AS integer)
                   AND COALESCE(a.valid_from, CAST(a.created_at AT TIME ZONE CAST(:tz AS text) AS date)) <= CAST(:cutoff AS date)
                   AND a.customer_id IN (SELECT customer_id FROM first_c)) AS changed_customers
        FROM first_c
        """, tenant_id, **params).iloc[0]
    n_products, n_customers = int(products["products"]), int(customers["customers"])
    first_day = products["first_company_day"]
    history_days = (pd.Timestamp(ctx["cutoff"]) - pd.Timestamp(first_day)).days + 1 if first_day is not None and not pd.isna(first_day) else 0
    # «Código nuevo» solo es una señal si la empresa ya tenía al menos DOS ventanas de historia: con menos, una buena parte del catálogo
    # es «nuevo» simplemente porque el negocio es joven (o acaba de cargarse), no porque se reasignaran códigos.
    evaluable = history_days >= 2 * lookback
    return {
        "available": True, "lookback_days": lookback, "history_days": history_days,
        "products_with_sales": n_products, "products_with_change_alias": int(products["changed_products"]),
        "product_change_share": _share(products["changed_products"], n_products),
        "customers_with_sales": n_customers, "customers_with_change_alias": int(customers["changed_customers"]),
        "customer_change_share": _share(customers["changed_customers"], n_customers),
        "new_product_codes": int(products["new_products"]),
        "new_product_code_share": _share(products["new_products"], n_products) if evaluable else None,
        "new_code_evaluable": bool(evaluable)}


def _ingestion_lag(conn: Connection, tenant_id: int, ctx: dict[str, Any], lookback: int) -> dict[str, Any]:
    df = _read(conn, """
        WITH docs AS (
            SELECT GREATEST((CAST(s.first_seen_at AT TIME ZONE CAST(:tz AS text) AS date) - s.sale_date), 0) AS lag_days
            FROM sales s
            WHERE s.tenant_id = :tenant_id AND s.ingestion_mode = 'incremental' AND s.first_seen_at IS NOT NULL
              AND s.first_seen_at < :boundary AND s.sale_date <= CAST(:cutoff AS date)
              AND s.sale_date > CAST(:cutoff AS date) - CAST(:lookback AS integer)
        )
        SELECT COUNT(*) AS documents,
               percentile_cont(0.5) WITHIN GROUP (ORDER BY lag_days)::float8 AS p50_days,
               percentile_cont(0.9) WITHIN GROUP (ORDER BY lag_days)::float8 AS p90_days,
               MAX(lag_days) AS max_days,
               AVG((lag_days > 1)::int)::float8 AS share_gt_1d, AVG((lag_days > 7)::int)::float8 AS share_gt_7d
        FROM docs
        """, tenant_id, tz=ctx["tz"], boundary=ctx["boundary"], cutoff=ctx["cutoff"], lookback=lookback)
    r = df.iloc[0]
    n = int(r["documents"])

    def val(key: str) -> float | None:
        v = r[key]
        return None if n == 0 or pd.isna(v) else round(float(v), 6)

    return {"available": True, "documents": n, "p50_days": val("p50_days"), "p90_days": val("p90_days"),
            "max_days": None if n == 0 else int(r["max_days"]), "share_gt_1d": val("share_gt_1d"), "share_gt_7d": val("share_gt_7d"),
            "lookback_days": lookback,
            "note": "Solo documentos con ingestion_mode = incremental; la carga histórica (backfill) no tiene desfase significativo."}

