"""Fixtures SQL mínimos para las pruebas de integración de P4 (BD de pruebas aislada, datos ficticios)."""

from __future__ import annotations

import json
from datetime import date, timedelta

from sqlalchemy import text


def one(conn, sql: str, **p):
    return conn.execute(text(sql), p).scalar_one()


def new_tenant(conn, name: str, currency: str = "USD", settings: dict | None = None, sector: str | None = None) -> int:
    return one(conn, "INSERT INTO tenants (uuid, name, sector, currency, settings, status, created_at, updated_at) "
                     "VALUES (gen_random_uuid(), :n, :s, :c, CAST(:st AS jsonb), 'active', now(), now()) RETURNING id",
               n=name, s=sector, c=currency, st=json.dumps(settings or {}))


def add_branch(conn, t: int, code: str = "001") -> int:
    return one(conn, "INSERT INTO branches (tenant_id, code, name, province, canton, is_active, created_at, updated_at) "
                     "VALUES (:t, :c, :n, 'Pichincha', 'Quito', true, now(), now()) RETURNING id", t=t, c=code, n=f"Sucursal {code}")


def add_product(conn, t: int, sku: str, unit: str = "u", price: float | None = 3.0, cost: float | None = 2.0) -> int:
    return one(conn, "INSERT INTO products (tenant_id, sku, name, unit, current_price, current_cost, is_active, created_at, updated_at) "
                     "VALUES (:t, :s, :n, :u, :p, :c, true, now(), now()) RETURNING id", t=t, s=sku, n=f"Producto {sku}", u=unit, p=price, c=cost)


def add_supplier(conn, t: int, code: str, lead: int | None = None) -> int:
    return one(conn, "INSERT INTO suppliers (tenant_id, code, name, default_lead_time_days, is_active, created_at, updated_at) "
                     "VALUES (:t, :c, :n, :l, true, now(), now()) RETURNING id", t=t, c=code, n=f"Proveedor {code}", l=lead)


def add_inventory(conn, t: int, b: int, p: int, ds: date, on_hand: float, reserved: float = 0.0, in_transit: float = 0.0, cost: float | None = None):
    conn.execute(text("INSERT INTO inventory_snapshots (tenant_id, branch_id, product_id, snapshot_date, on_hand, reserved, in_transit, unit_cost, created_at, updated_at) "
                      "VALUES (:t, :b, :p, :d, :o, :r, :i, :c, now(), now())"), {"t": t, "b": b, "p": p, "d": ds, "o": on_hand, "r": reserved, "i": in_transit, "c": cost})


def add_forecast(conn, t: int, cutoff: date, series: dict[tuple[int, int], list[float]], run_type: str = "production", current: bool = True) -> int:
    version = one(conn, "SELECT COALESCE(MAX(version), 0) + 1 FROM forecast_runs WHERE tenant_id = :t", t=t)
    horizon = max(len(v) for v in series.values())
    rid = one(conn, "INSERT INTO forecast_runs (tenant_id, version, run_type, status, triggered_by, horizon_days, cutoff_date, is_current, options, created_at, updated_at) "
                    "VALUES (:t, :v, :rt, 'completed', 'user', :h, :c, :cur, '{}'::jsonb, now(), now()) RETURNING id",
              t=t, v=version, rt=run_type, h=horizon, c=cutoff, cur=current and run_type == "production")
    for (b, p), vals in series.items():
        for step, y in enumerate(vals, start=1):
            conn.execute(text("INSERT INTO forecast_values (tenant_id, forecast_run_id, node_key, level, branch_id, product_id, granularity, target_date, "
                              "period_days, horizon_step, yhat) VALUES (:t, :r, :nk, 'series', :b, :p, 'day', :d, 1, :s, :y)"),
                         {"t": t, "r": rid, "nk": f"series:{b}:{p}", "b": b, "p": p, "d": cutoff + timedelta(days=step), "s": step, "y": y})
    return rid


def add_offer(conn, t: int, supplier: int, product: int, cost: float, currency: str = "USD", unit: str = "u", lead: int | None = None,
              moq: float | None = None, multiple: float | None = None, capacity: float | None = None, cap_days: int = 7, fixed: float = 0.0,
              pct: float = 0.0, per_unit: float = 0.0, valid_from: date | None = None, valid_to: date | None = None,
              known_at: str | None = None, branch: int | None = None, active: bool = True) -> int:
    return one(conn, "INSERT INTO supplier_offers (tenant_id, supplier_id, product_id, branch_id, unit_cost, currency, unit, min_order_qty, order_multiple, "
                     "lead_time_days, capacity_per_period, capacity_period_days, fixed_order_cost, acquisition_cost_pct, acquisition_cost_per_unit, valid_from, valid_to, "
                     "known_at, is_active, created_at, updated_at) VALUES (:t, :s, :p, :b, :c, :cur, :u, :moq, :mul, :l, :cap, :cd, :f, :pct, :pu, :vf, :vt, "
                     "CAST(:ka AS timestamptz), :act, now(), now()) RETURNING id",
               t=t, s=supplier, p=product, b=branch, c=cost, cur=currency, u=unit, moq=moq, mul=multiple, l=lead, cap=capacity, cd=cap_days,
               f=fixed, pct=pct, pu=per_unit, vf=valid_from, vt=valid_to, ka=known_at, act=active)


def add_fx(conn, t: int, frm: str, to: str, rate: float, valid_on: date, source: str = "manual", known_at: str | None = None):
    conn.execute(text("INSERT INTO exchange_rates (tenant_id, from_currency, to_currency, rate, valid_on, source, known_at, created_at, updated_at) "
                      "VALUES (:t, :f, :to, :r, :v, :s, CAST(:k AS timestamptz), now(), now())"),
                 {"t": t, "f": frm, "to": to, "r": rate, "v": valid_on, "s": source, "k": known_at})


def add_conversion(conn, t: int, product: int | None, frm: str, to: str, factor: float):
    conn.execute(text("INSERT INTO unit_conversions (tenant_id, product_id, from_unit, to_unit, factor, created_at, updated_at) "
                      "VALUES (:t, :p, :f, :to, :x, now(), now())"), {"t": t, "p": product, "f": frm, "to": to, "x": factor})


def add_budget(conn, t: int, start: date, end: date, amount: float, currency: str = "USD", branch: int | None = None) -> int:
    return one(conn, "INSERT INTO purchase_budgets (tenant_id, branch_id, period_start, period_end, amount, currency, created_at, updated_at) "
                     "VALUES (:t, :b, :s, :e, :a, :c, now(), now()) RETURNING id", t=t, b=branch, s=start, e=end, a=amount, c=currency)


def add_core_run(conn, t: int, core: str, operation: str, options: dict | None = None, run_type: str = "production") -> tuple[int, int]:
    version = one(conn, "SELECT COALESCE(MAX(version), 0) + 1 FROM core_runs WHERE tenant_id = :t AND core = :c", t=t, c=core)
    rid = one(conn, "INSERT INTO core_runs (tenant_id, core, operation, version, run_type, status, options, created_at, updated_at) "
                    "VALUES (:t, :c, :o, :v, :rt, 'queued', CAST(:op AS jsonb), now(), now()) RETURNING id",
              t=t, c=core, o=operation, v=version, rt=run_type, op=json.dumps(options or {}))
    jid = one(conn, "INSERT INTO training_jobs (tenant_id, core_run_id, job_type, status, triggered_by, params, queued_at, created_at, updated_at) "
                    "VALUES (:t, :r, :jt, 'queued', 'user', '{}'::jsonb, now(), now(), now()) RETURNING id", t=t, r=rid, jt=f"{core}_{operation}")
    return int(rid), int(jid)
