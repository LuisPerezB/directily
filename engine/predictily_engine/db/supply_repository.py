"""Lectura y escritura del core de Abastecimiento y decisiones comunes (etapa 2 · P4).

Hereda de `TenantRepository`: toda consulta es parametrizada, incluye `:tenant_id` y `_read` rechaza SQL sin ese
filtro. Las marcas `known_at` se entregan en hora local de la empresa (sin zona), igual que las promociones.
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.db.repository import TenantRepository
from predictily_engine.db.writer import jdump

_TZ = ("JOIN (SELECT id, COALESCE(NULLIF(timezone, ''), 'America/Guayaquil') AS tz FROM tenants "
       "WHERE id = :tenant_id) tz ON tz.id = {alias}.tenant_id")


class SupplyRepository(TenantRepository):
    # ── ejecuciones ──
    def core_run(self, run_id: int) -> dict[str, Any] | None:
        df = self._read("SELECT * FROM core_runs WHERE id = :run_id AND tenant_id = :tenant_id", run_id=run_id)
        if df.empty:
            return None
        row = df.iloc[0].to_dict()
        for k in ("options", "summary", "effective_config", "assumptions", "limitations"):
            if isinstance(row.get(k), str):
                row[k] = json.loads(row[k])
        return row

    def forecast_run_for_plan(self, forecast_run_id: int | None) -> dict[str, Any] | None:
        """Pronóstico de demanda a usar: el indicado, o el vigente, o el último completado."""
        if forecast_run_id is not None:
            df = self._read(
                "SELECT id, version, run_type, status, horizon_days, cutoff_date FROM forecast_runs "
                "WHERE id = :rid AND tenant_id = :tenant_id AND status = 'completed'", rid=forecast_run_id)
        else:
            df = self._read(
                "SELECT id, version, run_type, status, horizon_days, cutoff_date FROM forecast_runs "
                "WHERE tenant_id = :tenant_id AND status = 'completed' AND cutoff_date IS NOT NULL "
                "ORDER BY is_current DESC, id DESC LIMIT 1")
        return None if df.empty else df.iloc[0].to_dict()

    def series_forecast(self, run_id: int) -> pd.DataFrame:
        """Pronóstico diario por sucursal × producto (nivel serie) de una ejecución de demanda."""
        return self._read(
            "SELECT branch_id, product_id, target_date AS ds, horizon_step, yhat::float8 AS yhat "
            "FROM forecast_values WHERE tenant_id = :tenant_id AND forecast_run_id = :run_id AND level = 'series' "
            "AND granularity = 'day' AND branch_id IS NOT NULL AND product_id IS NOT NULL "
            "ORDER BY branch_id, product_id, horizon_step", run_id=run_id)

    def safety_stock(self, run_id: int) -> pd.DataFrame:
        """Stock de seguridad de las recomendaciones de la etapa 1 (nulo si la cobertura era insuficiente)."""
        df = self._read(
            "SELECT branch_id, product_id, safety_stock::float8 AS safety_stock, assumptions "
            "FROM recommendations WHERE tenant_id = :tenant_id AND forecast_run_id = :run_id "
            "AND safety_stock IS NOT NULL", run_id=run_id)
        if df.empty:
            return pd.DataFrame(columns=["branch_id", "product_id", "safety_stock", "uncertainty"])

        def unc(v):
            if isinstance(v, str):
                v = json.loads(v or "{}")
            return (v or {}).get("uncertainty")

        df["uncertainty"] = df["assumptions"].map(unc)
        return df[["branch_id", "product_id", "safety_stock", "uncertainty"]]

    def po_history(self, as_of: date) -> pd.DataFrame:
        """Órdenes de compra (una fila por orden) para la evidencia de plazos observados y retrasos."""
        return self._read(
            "SELECT id AS purchase_order_id, supplier_id, branch_id, status, ordered_at, expected_at, received_at "
            "FROM purchase_orders WHERE tenant_id = :tenant_id AND ordered_at <= :as_of", as_of=as_of)

    # ── configuración de decisiones ──
    def supplier_offers(self) -> pd.DataFrame:
        return self._read(
            "SELECT o.id AS offer_id, o.supplier_id, o.product_id, o.branch_id, o.unit_cost::float8 AS unit_cost, "
            "o.currency, o.unit, o.min_order_qty::float8 AS min_order_qty, o.order_multiple::float8 AS order_multiple, "
            "o.lead_time_days, o.lead_time_std_days::float8 AS lead_time_std_days, "
            "o.capacity_per_period::float8 AS capacity_per_period, o.capacity_period_days, "
            "o.fixed_order_cost::float8 AS fixed_order_cost, o.acquisition_cost_pct::float8 AS acquisition_cost_pct, "
            "o.acquisition_cost_per_unit::float8 AS acquisition_cost_per_unit, o.valid_from, o.valid_to, "
            "(o.known_at AT TIME ZONE tz.tz) AS known_at, o.is_active "
            f"FROM supplier_offers o {_TZ.format(alias='o')} WHERE o.tenant_id = :tenant_id ORDER BY o.id")

    def exchange_rates(self) -> pd.DataFrame:
        return self._read(
            "SELECT r.from_currency, r.to_currency, r.rate::float8 AS rate, r.valid_on, r.source, "
            "(r.known_at AT TIME ZONE tz.tz) AS known_at "
            f"FROM exchange_rates r {_TZ.format(alias='r')} WHERE r.tenant_id = :tenant_id")

    def unit_conversions(self) -> pd.DataFrame:
        return self._read("SELECT product_id, from_unit, to_unit, factor::float8 AS factor, valid_from "
                          "FROM unit_conversions WHERE tenant_id = :tenant_id")

    def location_capacities(self) -> pd.DataFrame:
        return self._read("SELECT branch_id, warehouse_id, capacity::float8 AS capacity, capacity_unit, valid_from, valid_to "
                          "FROM location_capacities WHERE tenant_id = :tenant_id")

    def warehouses(self) -> pd.DataFrame:
        return self._read("SELECT branch_id, storage_capacity::float8 AS storage_capacity, capacity_unit "
                          "FROM warehouses WHERE tenant_id = :tenant_id AND is_active")

    def purchase_budgets(self) -> pd.DataFrame:
        return self._read("SELECT id AS budget_id, branch_id, period_start, period_end, amount::float8 AS amount, currency "
                          "FROM purchase_budgets WHERE tenant_id = :tenant_id ORDER BY period_start, id")

    def production_resources(self) -> pd.DataFrame:
        return self._read("SELECT id AS resource_id, code, branch_id, capacity_per_period::float8 AS capacity_per_period, "
                          "capacity_unit, period_days, is_active FROM production_resources WHERE tenant_id = :tenant_id")

    def production_recipes(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS recipe_id, code, output_product_id, branch_id, output_quantity::float8 AS output_quantity, "
            "yield_rate::float8 AS yield_rate, production_days, resource_id, "
            "resource_usage_per_batch::float8 AS resource_usage_per_batch, cost_per_batch::float8 AS cost_per_batch, "
            "max_batches_per_period::float8 AS max_batches_per_period, is_active "
            "FROM production_recipes WHERE tenant_id = :tenant_id")

    def production_recipe_lines(self) -> pd.DataFrame:
        return self._read("SELECT recipe_id, input_product_id, quantity_per_batch::float8 AS quantity_per_batch "
                          "FROM production_recipe_lines WHERE tenant_id = :tenant_id")

    # ── costos ──
    def purchase_cost_history(self) -> pd.DataFrame:
        """Costos de compra con su disponibilidad (`known_at`, hora local) y origen; sin filtrar por corte."""
        return self._read(
            "SELECT h.id AS cost_id, h.product_id, h.supplier_id, h.unit_cost::float8 AS unit_cost, h.currency, "
            "h.unit, h.valid_from, (h.known_at AT TIME ZONE tz.tz) AS known_at, h.source, "
            "(h.created_at AT TIME ZONE tz.tz) AS ingested_at "
            f"FROM purchase_cost_history h {_TZ.format(alias='h')} WHERE h.tenant_id = :tenant_id "
            "ORDER BY h.product_id, h.valid_from, h.id")

    def sales_cost_history(self, cutoff: date) -> pd.DataFrame:
        """Costo unitario histórico de las ventas (por línea) agregado por mes, ponderado por cantidad."""
        return self._read(
            """
            SELECT si.product_id, date_trunc('month', si.sale_date)::date AS period, 
                   SUM(si.quantity * si.unit_cost)::float8 / NULLIF(SUM(si.quantity), 0) AS unit_cost,
                   SUM(si.quantity)::float8 AS quantity
            FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND s.status = 'completed'
              AND si.is_return = false AND si.unit_cost IS NOT NULL AND si.quantity > 0 AND si.sale_date <= :cutoff
            GROUP BY si.product_id, date_trunc('month', si.sale_date)
            ORDER BY si.product_id, period
            """, cutoff=cutoff)

    def last_purchase_costs(self, as_of: date, retroactive: bool) -> dict[int, dict]:
        """Último costo conocido por producto para ofertas implícitas: compras con disponibilidad conocida al corte;
        si no hay, el costo actual del catálogo (solo ejecuciones vigentes); si no, el del inventario."""
        out: dict[int, dict] = {}
        hist = self.purchase_cost_history()
        if not hist.empty:
            from predictily_engine.supply.availability import known_by, to_date

            hist = hist.copy()
            hist["_ok"] = [
                known_by(r.known_at, as_of, retroactive, fallback=to_date(r.valid_from) if r.source == "purchase_order" else None)[0]
                and to_date(r.valid_from) <= as_of for r in hist.itertuples(index=False)]
            hist = hist[hist["_ok"]].sort_values(["product_id", "valid_from", "cost_id"])
            for pid, g in hist.groupby("product_id"):
                last = g.iloc[-1]
                out[int(pid)] = {"unit_cost": float(last["unit_cost"]), "currency": str(last["currency"]),
                                 "supplier_id": None if pd.isna(last["supplier_id"]) else int(last["supplier_id"]),
                                 "source": f"purchase_cost_history:{last['valid_from']}"}
        if not retroactive:
            prod = self.products()
            for r in prod.itertuples(index=False):
                if int(r.product_id) not in out and pd.notna(r.current_cost):
                    out[int(r.product_id)] = {"unit_cost": float(r.current_cost), "supplier_id": None, "source": "products.current_cost"}
        return out

    def recent_net_prices(self, as_of: date, days: int) -> pd.DataFrame:
        """Precio neto unitario promedio ponderado de las ventas válidas de los últimos `days` días al corte."""
        return self._read(
            """
            SELECT si.product_id, SUM(si.net_amount)::float8 / NULLIF(SUM(si.quantity), 0) AS price
            FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
            WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND s.status = 'completed'
              AND si.is_return = false AND si.quantity > 0 AND si.sale_date <= :as_of
              AND si.sale_date > :as_of - make_interval(days => :days)
            GROUP BY si.product_id
            """, as_of=as_of, days=days)

    def plan_lines(self, core_run_id: int) -> pd.DataFrame:
        return self._read(
            "SELECT l.branch_id, l.product_id, l.supplier_id, l.line_type, l.quantity::float8 AS quantity, "
            "l.expected_receipt_date, l.unit_cost::float8 AS unit_cost, l.order_date "
            "FROM supply_plan_lines l JOIN supply_plans p ON p.id = l.supply_plan_id AND p.tenant_id = l.tenant_id "
            "WHERE l.tenant_id = :tenant_id AND p.tenant_id = :tenant_id AND p.core_run_id = :run_id", run_id=core_run_id)

    def cost_forecast_rows(self, core_run_id: int | None = None) -> pd.DataFrame:
        """Pronósticos de costos de una corrida (o de la última completada)."""
        if core_run_id is None:
            rid = self._scalar("SELECT id FROM core_runs WHERE tenant_id = :tenant_id AND core = 'cost' AND status = 'completed' "
                               "ORDER BY id DESC LIMIT 1")
            if rid is None:
                return pd.DataFrame()
            core_run_id = int(rid)
        df = self._read("SELECT product_id, supplier_id, cost_source, currency, unit, method, quality_status, points "
                        "FROM cost_forecasts WHERE tenant_id = :tenant_id AND core_run_id = :run_id", run_id=core_run_id)
        if not df.empty:
            df["points"] = df["points"].map(lambda v: json.loads(v) if isinstance(v, str) else v)
        return df

    # ── nombres para presentación ──
    def supplier_names(self) -> dict[int, str]:
        df = self._read("SELECT id, name FROM suppliers WHERE tenant_id = :tenant_id")
        return {int(r.id): str(r.name) for r in df.itertuples(index=False)}

    def product_names(self) -> dict[int, str]:
        df = self._read("SELECT id, name FROM products WHERE tenant_id = :tenant_id")
        return {int(r.id): str(r.name) for r in df.itertuples(index=False)}

    def tenant_row(self) -> dict[str, Any]:
        return self.tenant()


# ───────────────────────────── escritura ─────────────────────────────

def write_plan(conn: Connection, tenant_id: int, run_id: int, forecast_run_id: int | None, plan: dict[str, Any],
               lines: list[dict[str, Any]], shortfalls: list[dict[str, Any]]) -> int:
    """Reemplaza (idempotente) el plan de la corrida: borra y vuelve a insertar plan, líneas y faltantes."""
    conn.execute(text("DELETE FROM supply_plans WHERE tenant_id = :t AND core_run_id = :r"), {"t": tenant_id, "r": run_id})
    plan_id = conn.execute(text(
        """
        INSERT INTO supply_plans (tenant_id, core_run_id, forecast_run_id, objective, coverage_mode, solver_status, mip_gap,
            objective_value, solve_seconds, currency, total_purchase_cost, total_holding_cost, total_shortage_penalty,
            unmet_units_total, demand_units_total, details, created_at, updated_at)
        VALUES (:t, :r, :f, :objective, :coverage, :status, :gap, :obj, :secs, :cur, :tp, :th, :ts, :um, :dm,
                CAST(:details AS jsonb), now(), now()) RETURNING id
        """), {"t": tenant_id, "r": run_id, "f": forecast_run_id, "objective": plan["objective"],
               "coverage": plan["coverage_mode"], "status": plan["solver_status"], "gap": plan.get("mip_gap"),
               "obj": plan.get("objective_value"), "secs": plan.get("solve_seconds"), "cur": plan["currency"],
               "tp": plan.get("total_purchase_cost"), "th": plan.get("total_holding_cost"),
               "ts": plan.get("total_shortage_penalty"), "um": plan.get("unmet_units_total"),
               "dm": plan.get("demand_units_total"), "details": jdump(plan.get("details") or {})}).scalar_one()
    for ln in lines:
        conn.execute(text(
            """
            INSERT INTO supply_plan_lines (tenant_id, supply_plan_id, line_type, branch_id, product_id, supplier_id, offer_id,
                recipe_id, quantity, unit, offer_quantity, offer_unit, order_date, expected_receipt_date, unit_cost, line_cost,
                currency, actionable_now, binding_constraints, justification, assumptions, created_at, updated_at)
            VALUES (:t, :p, :lt, :b, :pr, :s, :o, :rc, :q, :u, :oq, :ou, :od, :er, :uc, :lc, :cur, :an,
                    CAST(:bc AS jsonb), :j, CAST(:a AS jsonb), now(), now())
            """), {"t": tenant_id, "p": plan_id, "lt": ln["line_type"], "b": ln["branch_id"], "pr": ln["product_id"],
                   "s": ln.get("supplier_id"), "o": ln.get("offer_id"), "rc": ln.get("recipe_id"), "q": ln["quantity"],
                   "u": ln.get("unit"), "oq": ln.get("offer_quantity"), "ou": ln.get("offer_unit"),
                   "od": ln["order_date"], "er": ln["expected_receipt_date"], "uc": ln.get("unit_cost"),
                   "lc": ln.get("line_cost"), "cur": ln["currency"], "an": bool(ln["actionable_now"]),
                   "bc": jdump(ln.get("binding_constraints") or []), "j": ln.get("justification"),
                   "a": jdump(ln.get("assumptions") or {})})
    for sf in shortfalls:
        conn.execute(text(
            "INSERT INTO supply_plan_shortfalls (tenant_id, supply_plan_id, branch_id, product_id, period_start, period_end, "
            "demand, unmet, reason) VALUES (:t, :p, :b, :pr, :ps, :pe, :d, :u, :r)"),
            {"t": tenant_id, "p": plan_id, "b": sf["branch_id"], "pr": sf["product_id"], "ps": sf["period_start"],
             "pe": sf["period_end"], "d": sf["demand"], "u": sf["unmet"], "r": sf["reason"]})
    return int(plan_id)
