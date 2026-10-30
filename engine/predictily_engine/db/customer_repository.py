"""Lectura de datos de UNA empresa para el Customer Core (etapa 2, P2).

Misma garantía de aislamiento que `TenantRepository`: toda consulta es parametrizada e incluye
`:tenant_id`; `_read` rechaza SQL que no filtre por empresa. Las compras válidas excluyen anuladas,
notas de crédito/devoluciones, ventas anónimas y compradores genéricos; las devoluciones se leen
aparte (nunca restan de la cantidad de compra).
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.db.repository import TenantRepository, TenantScopeError

VALID_DOCS = "('invoice', 'sales_note', 'other')"


class CustomerRepository:
    def __init__(self, conn: Connection, tenant_id: int) -> None:
        self.base = TenantRepository(conn, tenant_id)  # valida tenant_id
        self.conn = conn
        self.tenant_id = tenant_id

    # ── infraestructura ──
    def _read(self, sql: str, **params: Any) -> pd.DataFrame:
        if ":tenant_id" not in sql:
            raise TenantScopeError("Toda consulta del repositorio debe filtrar por :tenant_id")
        result = self.conn.execute(text(sql), {"tenant_id": self.tenant_id, **params})
        return pd.DataFrame(result.fetchall(), columns=list(result.keys()))

    def _scalar(self, sql: str, **params: Any) -> Any:
        if ":tenant_id" not in sql:
            raise TenantScopeError("Toda consulta del repositorio debe filtrar por :tenant_id")
        return self.conn.execute(text(sql), {"tenant_id": self.tenant_id, **params}).scalar()

    # ── empresa y ejecuciones ──
    def tenant(self) -> dict[str, Any]:
        return self.base.tenant()

    def core_run(self, run_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT * FROM core_runs WHERE id = :run_id AND tenant_id = :tenant_id AND core = 'customer'",
            run_id=run_id)
        if df.empty:
            return None
        row = df.iloc[0].to_dict()
        for k in ("options", "horizons", "summary", "assumptions", "limitations", "effective_config",
                  "evaluation_manifest", "model_versions", "target_definition"):
            v = row.get(k)
            if isinstance(v, str):
                row[k] = json.loads(v)
        return row

    def training_job(self, job_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, tenant_id, core_run_id, job_type, status FROM training_jobs "
            "WHERE id = :job_id AND tenant_id = :tenant_id", job_id=job_id)
        return None if df.empty else df.iloc[0].to_dict()

    # ── catálogos ──
    def products(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS product_id, category_id, unit, is_active, deleted_at IS NOT NULL AS deleted "
            "FROM products WHERE tenant_id = :tenant_id ORDER BY id")

    def branches(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS branch_id, province, canton FROM branches WHERE tenant_id = :tenant_id ORDER BY id")

    # ── ventas ──
    def sales_range(self) -> tuple[date | None, date | None]:
        """Primera y última fecha con ventas válidas (cualquier comprador, incluidos anónimos)."""
        df = self._read(
            f"SELECT MIN(s.sale_date) AS first_day, MAX(s.sale_date) AS last_day FROM sales s "
            f"WHERE s.tenant_id = :tenant_id AND s.status = 'completed' AND s.document_type IN {VALID_DOCS}")
        if df.empty:
            return None, None
        r = df.iloc[0]
        return (None if pd.isna(r["first_day"]) else r["first_day"], None if pd.isna(r["last_day"]) else r["last_day"])

    def purchases(self, through: date, exclude_generic: bool, generic_codes: list[str]) -> pd.DataFrame:
        """Compras válidas por comprador identificado × producto × sucursal × día (cantidades de todas las líneas)."""
        generic_sql = ("AND c.is_generic = false AND c.external_code <> ALL(CAST(:codes AS text[])) "
                       if exclude_generic else "")
        return self._read(
            f"""
            SELECT s.customer_id, si.product_id, si.branch_id, si.sale_date AS ds, s.currency,
                   SUM(si.quantity)::float8 AS qty,
                   SUM(si.net_amount)::float8 AS net_amount,
                   SUM(si.quantity * si.unit_price)::float8 AS gross_amount,
                   SUM(si.discount_amount)::float8 AS discount_amount
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
              AND s.status = 'completed' AND s.document_type IN {VALID_DOCS}
              AND si.is_return = false AND si.quantity > 0 AND c.deleted_at IS NULL
              {generic_sql}
              AND si.sale_date <= :through
            GROUP BY s.customer_id, si.product_id, si.branch_id, si.sale_date, s.currency
            ORDER BY s.customer_id, si.product_id, si.sale_date
            """,
            through=through, codes=list(generic_codes))

    def returns(self, through: date) -> pd.DataFrame:
        """Devoluciones (notas de crédito o líneas marcadas) de compradores identificados: se informan aparte."""
        return self._read(
            """
            SELECT s.customer_id, si.product_id, si.sale_date AS ds, SUM(si.quantity)::float8 AS qty
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND s.status = 'completed'
              AND s.customer_id IS NOT NULL
              AND (si.is_return = true OR s.document_type = 'credit_note')
              AND si.sale_date <= :through
            GROUP BY s.customer_id, si.product_id, si.sale_date
            """,
            through=through)

    def excluded_sales_stats(self, through: date, generic_codes: list[str]) -> dict[str, Any]:
        """Líneas y unidades de ventas válidas que NO se atribuyen a un comprador modelable (anónimas/genéricas)."""
        df = self._read(
            f"""
            SELECT CASE WHEN s.customer_id IS NULL THEN 'anonymous'
                        WHEN c.is_generic OR c.external_code = ANY(CAST(:codes AS text[])) THEN 'generic'
                        WHEN c.deleted_at IS NOT NULL THEN 'deleted_customer'
                        ELSE 'modeled' END AS kind,
                   COUNT(*) AS lines, SUM(si.quantity)::float8 AS units
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            LEFT JOIN customers c ON c.id = s.customer_id AND c.tenant_id = s.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND s.status = 'completed'
              AND s.document_type IN {VALID_DOCS} AND si.is_return = false AND si.quantity > 0
              AND si.sale_date <= :through AND si.sale_date > :through - 180
            GROUP BY 1
            """,
            through=through, codes=list(generic_codes))
        out = {k: {"lines": 0, "units": 0.0} for k in ("anonymous", "generic", "deleted_customer", "modeled")}
        for r in df.itertuples(index=False):
            out[r.kind] = {"lines": int(r.lines), "units": float(r.units or 0.0)}
        total = sum(v["units"] for v in out.values())
        out["modeled_share_units_180d"] = (out["modeled"]["units"] / total) if total > 0 else None
        out["window_days"] = 180
        return out

    def customer_codes(self, customer_ids: list[int]) -> pd.DataFrame:
        if not customer_ids:
            return pd.DataFrame(columns=["customer_id", "external_code"])
        return self._read(
            "SELECT id AS customer_id, external_code FROM customers WHERE tenant_id = :tenant_id AND id = ANY(:ids)",
            ids=[int(i) for i in customer_ids])

    # ── inventario y cobertura ──
    def availability(self, product_ids: list[int], date_from: date, date_to: date) -> pd.DataFrame:
        """Días con inventario conocido: disponible = existencia − reservas > 0 (sin snapshot = desconocido)."""
        if not product_ids:
            return pd.DataFrame(columns=["branch_id", "product_id", "ds", "available"])
        df = self._read(
            """
            SELECT branch_id, product_id, snapshot_date AS ds,
                   ((COALESCE(on_hand, 0) - COALESCE(reserved, 0)) > 0) AS available
            FROM inventory_snapshots
            WHERE tenant_id = :tenant_id AND product_id = ANY(:pids) AND snapshot_date BETWEEN :d1 AND :d2
            """,
            pids=[int(p) for p in product_ids], d1=date_from, d2=date_to)
        return df

    def coverage(self, entity: str = "sales") -> pd.DataFrame:
        return self._read(
            "SELECT entity, branch_id, complete_from, complete_through FROM data_coverage "
            "WHERE tenant_id = :tenant_id AND entity = :entity", entity=entity)

    # ── promociones (mismo contrato as-of que la demanda) ──
    def promotions(self) -> pd.DataFrame:
        return self.base.promotions()

    def promotion_revisions(self) -> pd.DataFrame:
        return self.base.promotion_revisions()

    # ── compromisos comerciales (los escribe P3; aquí solo se leen) ──
    def commitments(self) -> pd.DataFrame:
        """Líneas de cotizaciones/pedidos/contratos con fechas en la zona horaria de la empresa."""
        tz = TenantRepository._TENANT_TZ
        return self._read(
            f"""
            SELECT cc.id AS commitment_id, cc.customer_id, cc.commitment_type, cc.status, cc.currency,
                   (cc.issued_at AT TIME ZONE tz.tz)::date AS issued_on,
                   (cc.known_at AT TIME ZONE tz.tz)::date AS known_on,
                   (cc.created_at AT TIME ZONE tz.tz)::date AS ingested_on,
                   (cc.cancelled_at AT TIME ZONE tz.tz)::date AS cancelled_on,
                   cc.expected_delivery_date,
                   ci.id AS item_id, ci.product_id, ci.quantity::float8 AS quantity,
                   ci.quantity_fulfilled::float8 AS quantity_fulfilled, ci.unit, ci.unit_price::float8 AS unit_price,
                   ci.delivery_date, p.unit AS product_unit
            FROM commercial_commitments cc
            {tz.format(alias='cc')}
            JOIN commercial_commitment_items ci ON ci.commitment_id = cc.id AND ci.tenant_id = cc.tenant_id
            JOIN products p ON p.id = ci.product_id AND p.tenant_id = ci.tenant_id
            WHERE cc.tenant_id = :tenant_id AND ci.tenant_id = :tenant_id AND p.tenant_id = :tenant_id
            ORDER BY cc.id, ci.line_number
            """)

    def commitment_revisions(self) -> pd.DataFrame:
        """Versiones históricas de los compromisos (P3): cabecera y líneas tal como la fuente las conocía."""
        tz = TenantRepository._TENANT_TZ
        df = self._read(
            f"""
            SELECT r.commitment_id, r.revision, r.status, (r.known_at AT TIME ZONE tz.tz)::date AS known_on, r.snapshot
            FROM commercial_commitment_revisions r
            {tz.format(alias='r')}
            WHERE r.tenant_id = :tenant_id
            ORDER BY r.commitment_id, r.revision
            """)
        if len(df):
            df["snapshot"] = df["snapshot"].map(lambda v: json.loads(v) if isinstance(v, str) else v)
        return df

    def product_skus(self) -> dict[str, int]:
        df = self._read("SELECT id, upper(sku) AS sku FROM products WHERE tenant_id = :tenant_id")
        return {r.sku: int(r.id) for r in df.itertuples(index=False)}

    def unit_conversions(self) -> pd.DataFrame:
        return self._read(
            "SELECT product_id, from_unit, to_unit, factor::float8 AS factor, valid_from FROM unit_conversions "
            "WHERE tenant_id = :tenant_id")

    # ── demanda agregada vigente (para la reconciliación) ──
    def current_forecast_run(self) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, version, cutoff_date, status FROM forecast_runs "
            "WHERE tenant_id = :tenant_id AND is_current AND status = 'completed' ORDER BY id DESC LIMIT 1")
        return None if df.empty else df.iloc[0].to_dict()

    def aggregate_demand(self, forecast_run_id: int, product_ids: list[int], as_of: date, horizon_days: int) -> pd.DataFrame:
        """Suma de la demanda diaria pronosticada por producto (todas las sucursales) en (as_of, as_of + H]."""
        if not product_ids:
            return pd.DataFrame(columns=["product_id", "days", "yhat"])
        return self._read(
            """
            SELECT fv.product_id, COUNT(*) AS days, SUM(fv.yhat)::float8 AS yhat
            FROM forecast_values fv
            WHERE fv.tenant_id = :tenant_id AND fv.forecast_run_id = :run_id AND fv.level = 'product'
              AND fv.granularity = 'day' AND fv.product_id = ANY(:pids)
              AND fv.target_date > :as_of AND fv.target_date <= CAST(:as_of AS date) + CAST(:h AS integer)
            GROUP BY fv.product_id
            """,
            run_id=forecast_run_id, pids=[int(p) for p in product_ids], as_of=as_of, h=int(horizon_days))

    # ── registro de modelos ──
    def validated_references(self) -> pd.DataFrame:
        """Referencias validadas (más reciente primero): respaldo si falla el artefacto en producción."""
        return self._read(
            "SELECT id, version, model_family, role, status, hyperparameters, artifact_path, artifact_sha256, artifact_compat "
            "FROM model_registry WHERE tenant_id = :tenant_id AND core = 'customer' AND status IN ('validated', 'retired', 'production') "
            "AND hyperparameters->>'kind' = 'reference' AND artifact_path IS NOT NULL ORDER BY id DESC")

    def production_models(self) -> pd.DataFrame:
        return self._read(
            "SELECT id, version, model_family, role, status, hyperparameters, artifact_path, artifact_sha256, "
            "artifact_compat, core_run_id, training_data_to FROM model_registry "
            "WHERE tenant_id = :tenant_id AND core = 'customer' AND status = 'production' ORDER BY id")
