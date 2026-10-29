"""Lectura de datos de una empresa.

Aislamiento multi-tenant: `TenantRepository` se construye con un `tenant_id` y TODAS sus
consultas son parametrizadas e incluyen `:tenant_id`. `_read` rechaza cualquier SQL que no
filtre por empresa, de modo que un error de programación falla en lugar de mezclar datos.
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

VALID_SALE_DOCUMENT_TYPES = ("invoice", "sales_note", "other")


class TenantScopeError(RuntimeError):
    """Consulta construida sin filtro de empresa."""


class TenantRepository:
    def __init__(self, conn: Connection, tenant_id: int) -> None:
        if not isinstance(tenant_id, int) or tenant_id <= 0:
            raise TenantScopeError("tenant_id inválido")
        self.conn = conn
        self.tenant_id = tenant_id

    def analytic_reads(self) -> None:
        """Lecturas analíticas: evita nested loops (planes desastrosos con estadísticas
        desactualizadas justo después de una importación masiva). Solo afecta a la
        transacción actual (SET LOCAL)."""
        self.conn.execute(text("SET LOCAL enable_nestloop = off"))

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

    # ── empresa ──
    def tenant(self) -> dict[str, Any]:
        df = self._read(
            "SELECT id, name, sector, currency, timezone, settings, allow_shared_training "
            "FROM tenants WHERE id = :tenant_id AND deleted_at IS NULL"
        )
        if df.empty:
            raise LookupError(f"Empresa {self.tenant_id} no encontrada")
        row = df.iloc[0].to_dict()
        settings = row.get("settings")
        if isinstance(settings, str):
            settings = json.loads(settings or "{}")
        row["settings"] = settings or {}
        return row

    def forecast_run(self, run_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, tenant_id, version, run_type, status, horizon_days, cutoff_date, options, "
            "started_at, completed_at, selection, summary FROM forecast_runs "
            "WHERE id = :run_id AND tenant_id = :tenant_id",
            run_id=run_id,
        )
        return None if df.empty else df.iloc[0].to_dict()

    def training_job(self, job_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, tenant_id, forecast_run_id, job_type, status FROM training_jobs "
            "WHERE id = :job_id AND tenant_id = :tenant_id",
            job_id=job_id,
        )
        return None if df.empty else df.iloc[0].to_dict()

    # ── catálogos ──
    def branches(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS branch_id, code, name, province, canton FROM branches "
            "WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id"
        )

    def categories(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS category_id, name FROM product_categories WHERE tenant_id = :tenant_id ORDER BY id"
        )

    def products(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS product_id, sku, name, category_id, unit, current_price, current_cost, is_active "
            "FROM products WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id"
        )

    def suppliers(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS supplier_id, code, default_lead_time_days FROM suppliers "
            "WHERE tenant_id = :tenant_id AND deleted_at IS NULL"
        )

    # ── ventas ──
    def last_sale_date(self) -> date | None:
        return self._scalar(
            "SELECT MAX(s.sale_date) FROM sales s WHERE s.tenant_id = :tenant_id AND s.status = 'completed' "
            "AND s.document_type IN ('invoice', 'sales_note', 'other')"
        )

    def daily_sales(self, cutoff: date) -> pd.DataFrame:
        """Ventas válidas diarias por sucursal-producto (excluye anuladas y devoluciones)."""
        return self._read(
            """
            SELECT si.branch_id, si.product_id, si.sale_date AS ds,
                   SUM(si.quantity)::float8 AS units,
                   SUM(si.net_amount)::float8 AS net_amount,
                   SUM(si.quantity * si.unit_price)::float8 AS gross_amount,
                   SUM(si.discount_amount)::float8 AS discount_amount,
                   SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.quantity * si.unit_cost END)::float8 AS cost_amount,
                   SUM(CASE WHEN si.unit_cost IS NOT NULL THEN si.quantity END)::float8 AS cost_units,
                   COUNT(*) AS lines
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id
              AND s.status = 'completed'
              AND s.document_type IN ('invoice', 'sales_note', 'other')
              AND si.is_return = false
              AND si.sale_date <= :cutoff
            GROUP BY si.branch_id, si.product_id, si.sale_date
            ORDER BY si.branch_id, si.product_id, si.sale_date
            """,
            cutoff=cutoff,
        )

    def daily_returns(self, cutoff: date) -> pd.DataFrame:
        return self._read(
            """
            SELECT si.branch_id, si.product_id, si.sale_date AS ds, SUM(si.quantity)::float8 AS units
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.status = 'completed'
              AND (si.is_return = true OR s.document_type = 'credit_note')
              AND si.sale_date <= :cutoff
            GROUP BY si.branch_id, si.product_id, si.sale_date
            """,
            cutoff=cutoff,
        )

    def daily_actuals(self, date_from: date, date_to: date) -> pd.DataFrame:
        """Ventas reales (mismas reglas que el entrenamiento) para evaluar pronósticos."""
        return self._read(
            """
            SELECT si.branch_id, si.product_id, si.sale_date AS ds, SUM(si.quantity)::float8 AS units
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.status = 'completed'
              AND s.document_type IN ('invoice', 'sales_note', 'other')
              AND si.is_return = false
              AND si.sale_date BETWEEN :date_from AND :date_to
            GROUP BY si.branch_id, si.product_id, si.sale_date
            """,
            date_from=date_from,
            date_to=date_to,
        )

    def sales_stats(self) -> dict[str, Any]:
        df = self._read(
            """
            SELECT COUNT(*) AS lines,
                   COUNT(si.unit_cost) AS lines_with_cost,
                   MIN(si.sale_date) AS date_from, MAX(si.sale_date) AS date_to
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.status = 'completed' AND si.is_return = false
            """
        )
        return df.iloc[0].to_dict()

    # ── inventario y abastecimiento ──
    def inventory(self, date_from: date, date_to: date) -> pd.DataFrame:
        return self._read(
            """
            SELECT branch_id, product_id, snapshot_date AS ds, on_hand::float8, reserved::float8,
                   in_transit::float8, unit_cost::float8
            FROM inventory_snapshots
            WHERE tenant_id = :tenant_id AND snapshot_date BETWEEN :date_from AND :date_to
            ORDER BY branch_id, product_id, snapshot_date
            """,
            date_from=date_from,
            date_to=date_to,
        )

    def latest_inventory(self, cutoff: date) -> pd.DataFrame:
        return self._read(
            """
            SELECT DISTINCT ON (branch_id, product_id)
                   branch_id, product_id, snapshot_date AS ds, on_hand::float8, reserved::float8,
                   in_transit::float8, unit_cost::float8
            FROM inventory_snapshots
            WHERE tenant_id = :tenant_id AND snapshot_date <= :cutoff
            ORDER BY branch_id, product_id, snapshot_date DESC
            """,
            cutoff=cutoff,
        )

    def lead_times(self) -> pd.DataFrame:
        return self._read(
            "SELECT supplier_id, product_id, branch_id, lead_time_days, lead_time_std_days::float8, valid_from "
            "FROM supplier_lead_times WHERE tenant_id = :tenant_id ORDER BY valid_from NULLS FIRST, id"
        )

    def open_purchase_orders(self) -> pd.DataFrame:
        """Pendiente por recibir de cada orden abierta y producto, con SU fecha prevista.

        Una fila por orden (no se suman órdenes con fechas distintas): el inventario simula
        la llegada de cada una en `expected_at` (NULL = sin fecha; ver inventory.recommendations).
        """
        return self._read(
            """
            SELECT po.id AS purchase_order_id, po.branch_id, poi.product_id, po.supplier_id,
                   po.ordered_at, po.expected_at,
                   SUM(GREATEST(poi.quantity_ordered - poi.quantity_received, 0))::float8 AS qty_open
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.tenant_id = poi.tenant_id
            WHERE poi.tenant_id = :tenant_id AND po.tenant_id = :tenant_id
              AND po.status IN ('ordered', 'partially_received')
            GROUP BY po.id, po.branch_id, poi.product_id, po.supplier_id, po.ordered_at, po.expected_at
            HAVING SUM(GREATEST(poi.quantity_ordered - poi.quantity_received, 0)) > 0
            ORDER BY po.branch_id, poi.product_id, po.expected_at NULLS LAST, po.id
            """
        )

    # Zona horaria de la empresa: `known_at`, `deleted_at` y `recorded_at` se entregan en hora local
    # (sin zona) para compararlos con los cortes, que son fechas locales.
    _TENANT_TZ = ("JOIN (SELECT id, COALESCE(NULLIF(timezone, ''), 'America/Guayaquil') AS tz FROM tenants "
                  "WHERE id = :tenant_id) tz ON tz.id = {alias}.tenant_id")

    def promotions(self) -> pd.DataFrame:
        """Estado actual de las promociones, incluidas las dadas de baja (`deleted_at`), con su fecha de
        disponibilidad `known_at` (cuándo el negocio la conocía/registró)."""
        return self._read(
            "SELECT p.id AS promotion_id, p.discount_pct::float8 AS discount_pct, p.starts_on, p.ends_on, "
            "p.branch_id, p.product_id, p.category_id, (p.known_at AT TIME ZONE tz.tz) AS known_at, "
            "(p.deleted_at AT TIME ZONE tz.tz) AS deleted_at "
            f"FROM promotions p {self._TENANT_TZ.format(alias='p')} WHERE p.tenant_id = :tenant_id ORDER BY p.id"
        )

    def promotion_revisions(self) -> pd.DataFrame:
        """Versiones históricas de las promociones (alta, cada modificación y la baja)."""
        return self._read(
            "SELECT r.promotion_id, r.revision, r.discount_pct::float8 AS discount_pct, r.starts_on, r.ends_on, "
            "r.branch_id, r.product_id, r.category_id, r.deleted, (r.recorded_at AT TIME ZONE tz.tz) AS recorded_at "
            f"FROM promotion_revisions r {self._TENANT_TZ.format(alias='r')} WHERE r.tenant_id = :tenant_id "
            "ORDER BY r.promotion_id, r.revision"
        )

    # ── resultados previos ──
    def current_run(self) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, version, selection, summary FROM forecast_runs WHERE tenant_id = :tenant_id AND is_current"
        )
        return None if df.empty else df.iloc[0].to_dict()

    def completed_runs(self, limit: int) -> pd.DataFrame:
        return self._read(
            "SELECT id, version, run_type, cutoff_date, horizon_days FROM forecast_runs "
            "WHERE tenant_id = :tenant_id AND status = 'completed' AND cutoff_date IS NOT NULL "
            "ORDER BY id DESC LIMIT :limit",
            limit=limit,
        )

    def forecast_values_for_run(self, run_id: int, date_from: date, date_to: date) -> pd.DataFrame:
        return self._read(
            """
            SELECT node_key, level, branch_id, product_id, category_id, target_date AS ds, horizon_step,
                   yhat::float8, lo_80::float8, hi_80::float8
            FROM forecast_values
            WHERE tenant_id = :tenant_id AND forecast_run_id = :run_id AND granularity = 'day'
              AND target_date BETWEEN :date_from AND :date_to
            """,
            run_id=run_id,
            date_from=date_from,
            date_to=date_to,
        )

    def backtest_overall(self, run_id: int) -> float | None:
        return self._scalar(
            "SELECT wape::float8 FROM model_evaluations WHERE tenant_id = :tenant_id AND forecast_run_id = :run_id "
            "AND evaluation_type = 'backtest' AND level = 'overall' AND is_selected ORDER BY id LIMIT 1",
            run_id=run_id,
        )
