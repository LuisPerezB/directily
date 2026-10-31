"""Lectura de datos de una empresa.

Aislamiento multi-tenant: `TenantRepository` se construye con un `tenant_id` y TODAS sus
consultas son parametrizadas e incluyen `:tenant_id`. `_read` rechaza cualquier SQL que no
filtre por empresa, de modo que un error de programación falla en lugar de mezclar datos.

Ventas as-of (ADR-V25-23): `daily_sales(cutoff)`, `daily_returns(cutoff)`, `last_sale_date(cutoff)` y `sales_stats(cutoff)`
devuelven lo que se CONOCÍA al corte (versión del documento vigente ese día, no su estado actual). Todas se derivan de UNA
lectura de la vista `sales_line_versions` hasta el corte final (`sales_versions`), que el repositorio conserva mientras vive.
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.data.asof import SalesVersions, knowledge_boundary, resolve_timezone, to_day, with_knowledge

VALID_SALE_DOCUMENT_TYPES = ("invoice", "sales_note", "other")


class TenantScopeError(RuntimeError):
    """Consulta construida sin filtro de empresa."""


class TenantRepository:
    def __init__(self, conn: Connection, tenant_id: int) -> None:
        if not isinstance(tenant_id, int) or tenant_id <= 0:
            raise TenantScopeError("tenant_id inválido")
        self.conn = conn
        self.tenant_id = tenant_id
        self._versions: SalesVersions | None = None
        self._timezone: str | None = None

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
            "SELECT id AS branch_id, code, name, province, canton, is_active FROM branches "
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

    # ── ventas (as-of: lo que se conocía al corte) ──
    def timezone(self) -> str:
        """Zona horaria de la empresa (define la medianoche local de cada corte)."""
        if self._timezone is None:
            self._timezone = resolve_timezone(self.tenant().get("timezone"))
        return self._timezone

    def sales_versions(self, upto: date) -> SalesVersions:
        """Versiones de las líneas de venta COMPLETADAS con fecha ≤ `upto` y conocidas antes de la medianoche local de
        `upto + 1` (vista `sales_line_versions`). Es la ÚNICA lectura de ventas de una ejecución: cada vista as-of
        (`daily_sales(c)` con c ≤ upto) se deriva de ella en pandas. Se conserva: pedir un corte menor o igual no vuelve
        a leer; uno mayor reemplaza la lectura."""
        final = to_day(upto)
        if self._versions is not None and self._versions.final >= final:
            return self._versions
        tz = self.timezone()
        limit = pd.Timestamp(knowledge_boundary(final, tz)).to_pydatetime()
        df = self._read(
            """
            SELECT v.sale_id, v.line_number, v.branch_id, v.product_id, v.sale_date AS ds,
                   v.quantity::float8 AS quantity, v.unit_price::float8 AS unit_price,
                   v.discount_amount::float8 AS discount_amount, v.net_amount::float8 AS net_amount,
                   v.unit_cost::float8 AS unit_cost, v.is_return, v.document_type, v.ingestion_mode,
                   (v.known_from AT TIME ZONE 'UTC') AS known_from, (v.known_until AT TIME ZONE 'UTC') AS known_until
            FROM sales_line_versions v
            WHERE v.tenant_id = :tenant_id AND v.status = 'completed' AND v.sale_date <= :upto
              AND v.known_from < (CAST(:limit AS timestamp) AT TIME ZONE 'UTC')
            """,
            upto=final.date(), limit=limit,
        )
        self._versions = SalesVersions(df, tz, final)
        return self._versions

    def last_sale_date(self, cutoff: date | None = None) -> date | None:
        """Última fecha con ventas válidas. Sin `cutoff`: la conocida AHORA (estado vigente de los documentos);
        con `cutoff`: la conocida al corte (as-of)."""
        if cutoff is not None:
            return self.sales_versions(cutoff).last_sale_date(cutoff)
        return self._scalar(
            "SELECT MAX(s.sale_date) FROM sales s WHERE s.tenant_id = :tenant_id AND s.status = 'completed' "
            "AND s.document_type IN ('invoice', 'sales_note', 'other')"
        )

    def daily_sales(self, cutoff: date) -> pd.DataFrame:
        """Ventas válidas diarias por sucursal-producto tal como se conocían al corte (excluye anuladas y devoluciones).

        As-of: un documento cargado después del corte no aparece; uno anulado o corregido después aparece con la versión
        que tenía ese día. Los marcos llevan la huella de las ventanas de conocimiento (`attrs`) para la huella de datos."""
        versions = self.sales_versions(cutoff)
        return with_knowledge(versions.sales_cells(cutoff), versions)

    def daily_returns(self, cutoff: date) -> pd.DataFrame:
        """Devoluciones diarias conocidas al corte (líneas marcadas o notas de crédito)."""
        return self.sales_versions(cutoff).return_cells(cutoff)

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

    def sales_stats(self, cutoff: date | None = None) -> dict[str, Any]:
        """Líneas válidas, líneas con costo y rango de fechas. Con `cutoff`, lo conocido a ese corte."""
        if cutoff is not None:
            return self.sales_versions(cutoff).stats(cutoff)
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

    def open_purchase_orders(self, as_of: date | None = None) -> pd.DataFrame:
        """Pendiente por recibir de cada orden abierta y producto, con SU fecha prevista.

        Una fila por orden (no se suman órdenes con fechas distintas): el inventario simula
        la llegada de cada una en `expected_at` (NULL = sin fecha; ver inventory.recommendations).

        `as_of` (ejecuciones con corte retroactivo): solo órdenes emitidas hasta ese día y no
        recibidas antes de él, para no usar compras que todavía no existían. Las recepciones
        parciales no tienen fecha propia: se asume que ocurrieron después del corte (la orden
        cuenta completa como pendiente). Sin `as_of` se usa el estado actual.
        """
        if as_of is not None:
            return self._read(
                """
                SELECT po.id AS purchase_order_id, po.branch_id, poi.product_id, po.supplier_id,
                       po.ordered_at, po.expected_at,
                       SUM(poi.quantity_ordered)::float8 AS qty_open
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.tenant_id = poi.tenant_id
                WHERE poi.tenant_id = :tenant_id AND po.tenant_id = :tenant_id
                  AND po.status NOT IN ('draft', 'cancelled')
                  AND po.ordered_at <= :as_of
                  AND (po.received_at IS NULL OR po.received_at > :as_of)
                GROUP BY po.id, po.branch_id, poi.product_id, po.supplier_id, po.ordered_at, po.expected_at
                HAVING SUM(poi.quantity_ordered) > 0
                ORDER BY po.branch_id, poi.product_id, po.expected_at NULLS LAST, po.id
                """,
                as_of=as_of,
            )
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

    # ── cobertura de fuentes (etapa 2 · demanda; el calendario se lee con extensions.data_core) ──
    def sales_coverage(self) -> pd.DataFrame:
        """Completitud declarada de la fuente de ventas (`data_coverage`, entidad `sales`), por fuente y sucursal.
        Vacío = nada declarado (el motor conserva el comportamiento anterior y lo informa)."""
        return self._read(
            "SELECT data_source_id, branch_id, complete_from, complete_through, basis FROM data_coverage "
            "WHERE tenant_id = :tenant_id AND entity = 'sales' ORDER BY id"
        )

    def _has_table(self, name: str) -> bool:
        return self.conn.execute(text("SELECT to_regclass(:name)"), {"name": name}).scalar() is not None

    def sales_coverage_gaps(self) -> pd.DataFrame:
        """Huecos internos de la fuente de ventas (`data_coverage_gaps`, entidad `sales`; declarados o inferidos por un
        conector): sus días son `missing_source`, nunca ceros. `branch_id` nulo = toda la empresa (o toda la fuente)."""
        cols = ["data_source_id", "branch_id", "gap_from", "gap_to", "reason", "basis"]
        if not self._has_table("data_coverage_gaps"):
            return pd.DataFrame(columns=cols)
        return self._read(
            "SELECT data_source_id, branch_id, gap_from, gap_to, reason, basis FROM data_coverage_gaps "
            "WHERE tenant_id = :tenant_id AND entity = 'sales' ORDER BY gap_from, id"
        )

    def closure_events(self) -> pd.DataFrame:
        """Cierres del calendario de la empresa (`calendar_events.category = 'closure'`, última revisión de cada evento,
        sin los cancelados) de alcance empresa (`scope = 'tenant'`) o sucursal (`scope = 'branch'`, `scope_ref = 'branch:<id>'`).
        Devuelve `branch_id` (nulo = toda la empresa), `starts_on`, `ends_on`, `code`. Referencias de sucursal no válidas se
        ignoran y se cuentan en `attrs['ignored']`."""
        cols = ["branch_id", "starts_on", "ends_on", "code"]
        if not self._has_table("calendar_events"):
            return pd.DataFrame(columns=cols)
        raw = self._read(
            """
            SELECT DISTINCT ON (scope, COALESCE(scope_ref, ''), code, starts_on)
                   scope, scope_ref, code, starts_on, ends_on, status
            FROM calendar_events
            WHERE tenant_id = :tenant_id AND category = 'closure' AND scope IN ('tenant', 'branch')
            ORDER BY scope, COALESCE(scope_ref, ''), code, starts_on, revision DESC
            """
        )
        raw = raw[raw["status"] != "cancelled"] if len(raw) else raw
        ignored = 0
        rows = []
        for r in raw.itertuples(index=False):
            if r.scope == "tenant":
                rows.append((None, r.starts_on, r.ends_on, r.code))
                continue
            ref = str(r.scope_ref or "")
            if ref.startswith("branch:") and ref[7:].isdigit():
                rows.append((int(ref[7:]), r.starts_on, r.ends_on, r.code))
            else:
                ignored += 1
        out = pd.DataFrame(rows, columns=cols)
        out.attrs["ignored"] = ignored
        return out

    def branches_by_source(self, source_ids: list[int]) -> dict[int, list[int]]:
        """Sucursales que alimenta cada fuente de datos (según los documentos de venta que trajo)."""
        if not source_ids:
            return {}
        df = self._read(
            "SELECT DISTINCT data_source_id, branch_id FROM sales WHERE tenant_id = :tenant_id "
            "AND data_source_id = ANY(:ids) ORDER BY 1, 2", ids=[int(i) for i in source_ids])
        out: dict[int, list[int]] = {}
        for r in df.itertuples(index=False):
            out.setdefault(int(r.data_source_id), []).append(int(r.branch_id))
        return out

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
