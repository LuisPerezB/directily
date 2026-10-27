"""Carga un SyntheticCompany en PostgreSQL (solo pruebas y mediciones).

Simula lo que haría el importador de Laravel con las mismas reglas de normalización
(factura→invoice, nota de crédito→credit_note, anulada→voided, cantidades positivas).
"""

from __future__ import annotations

import hashlib
import io
import json
from zoneinfo import ZoneInfo

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.synthetic.generator import SyntheticCompany

TZ = ZoneInfo("America/Guayaquil")


def _copy(conn: Connection, table: str, df: pd.DataFrame) -> None:
    raw = conn.connection.driver_connection
    buf = io.StringIO()
    df.to_csv(buf, index=False, header=False, na_rep="\\N")
    buf.seek(0)
    with raw.cursor() as cur:
        with cur.copy(f"COPY {table} ({', '.join(df.columns)}) FROM STDIN WITH (FORMAT csv, NULL '\\N')") as cp:
            cp.write(buf.read())


def _next_ids(conn: Connection, table: str, n: int) -> list[int]:
    start = conn.execute(text(f"SELECT COALESCE(MAX(id), 0) FROM {table}")).scalar_one() + 1
    conn.execute(text(f"SELECT setval('{table}_id_seq', :v)"), {"v": start + n})
    return list(range(start, start + n))


def load_company(conn: Connection, company: SyntheticCompany, name: str | None = None,
                 with_inventory: bool = True, settings: dict | None = None) -> int:
    spec = company.spec
    tenant_id = conn.execute(text(
        "INSERT INTO tenants (uuid, name, sector, settings, status, created_at, updated_at) "
        "VALUES (gen_random_uuid(), :name, :sector, CAST(:settings AS jsonb), 'active', now(), now()) RETURNING id"
    ), {"name": name or spec.name, "sector": spec.sector, "settings": json.dumps(settings or {})}).scalar_one()

    b_map = {}
    for b in company.branches.itertuples(index=False):
        b_map[b.code] = conn.execute(text(
            "INSERT INTO branches (tenant_id, code, name, province, canton, commercial_zone, is_active, created_at, "
            "updated_at) VALUES (:t, :code, :name, :prov, :canton, :zone, true, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": b.code, "name": b.name, "prov": b.province, "canton": b.canton,
            "zone": b.commercial_zone}).scalar_one()
    c_map = {}
    for cat in sorted(company.products["category"].unique()):
        c_map[cat] = conn.execute(text(
            "INSERT INTO product_categories (tenant_id, name, created_at, updated_at) VALUES (:t, :n, now(), now()) "
            "RETURNING id"), {"t": tenant_id, "n": cat}).scalar_one()
    p_map = {}
    for p in company.products.itertuples(index=False):
        p_map[p.sku] = conn.execute(text(
            "INSERT INTO products (tenant_id, category_id, sku, name, unit, brand, current_price, current_cost, "
            "is_active, created_at, updated_at) VALUES (:t, :c, :sku, :name, :unit, :brand, :price, :cost, :active, "
            "now(), now()) RETURNING id"
        ), {"t": tenant_id, "c": c_map[p.category], "sku": p.sku, "name": p.name, "unit": p.unit, "brand": p.brand,
            "price": float(p.current_price), "cost": float(p.current_cost),
            "active": str(p.is_active).lower() == "true"}).scalar_one()
    s_map = {}
    for s in company.suppliers.itertuples(index=False):
        s_map[s.code] = conn.execute(text(
            "INSERT INTO suppliers (tenant_id, code, name, country, default_lead_time_days, is_active, created_at, "
            "updated_at) VALUES (:t, :code, :name, :country, :lt, true, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": s.code, "name": s.name, "country": s.country,
            "lt": int(s.default_lead_time_days)}).scalar_one()
    for lt in company.lead_times.itertuples(index=False):
        if lt.product_sku not in p_map:
            continue
        conn.execute(text(
            "INSERT INTO supplier_lead_times (tenant_id, supplier_id, product_id, branch_id, lead_time_days, "
            "lead_time_std_days, source, created_at, updated_at) VALUES (:t, :s, :p, :b, :d, :sd, 'import', now(), now())"
        ), {"t": tenant_id, "s": s_map.get(lt.supplier_code), "p": p_map[lt.product_sku],
            "b": b_map.get(lt.branch_code) if lt.branch_code else None, "d": int(lt.lead_time_days),
            "sd": float(lt.lead_time_std_days)})
    for pr in company.promotions.itertuples(index=False):
        conn.execute(text(
            "INSERT INTO promotions (tenant_id, name, promo_type, discount_pct, starts_on, ends_on, branch_id, "
            "product_id, category_id, created_at, updated_at) VALUES (:t, :n, :ty, :d, :s, :e, :b, :p, :c, now(), now())"
        ), {"t": tenant_id, "n": pr.name, "ty": pr.promo_type, "d": float(pr.discount_pct), "s": pr.starts_on,
            "e": pr.ends_on, "b": b_map.get(pr.branch_code) if pr.branch_code else None,
            "p": p_map.get(pr.product_sku) if pr.product_sku else None,
            "c": c_map.get(pr.category) if pr.category else None})

    s = company.sales.copy()
    s = s[s["product_sku"].isin(p_map)]
    s["document_type"] = s["document_type"].map({"factura": "invoice", "nota de crédito": "credit_note"})
    s["status"] = s["status"].fillna("").map(lambda v: "voided" if v == "anulada" else "completed")
    for col in ("quantity", "unit_price", "discount", "tax", "net_amount", "unit_cost"):
        s[col] = pd.to_numeric(s[col])
    docs = s.groupby(["document_type", "document_number"], sort=False).agg(
        branch_code=("branch_code", "first"), status=("status", "first"), issued_at=("issued_at", "first"),
        subtotal=("net_amount", "sum"), discount_total=("discount", "sum"), tax_total=("tax", "sum")).reset_index()
    ids = _next_ids(conn, "sales", len(docs))
    docs["id"] = ids
    local = pd.to_datetime(docs["issued_at"]).dt.tz_localize(TZ)
    sales = pd.DataFrame({
        "id": docs["id"], "tenant_id": tenant_id, "branch_id": docs["branch_code"].map(b_map),
        "document_type": docs["document_type"], "document_number": docs["document_number"], "status": docs["status"],
        "issued_at": local.map(lambda d: d.isoformat()), "sale_date": local.dt.date.astype(str), "currency": "USD",
        "subtotal": docs["subtotal"].round(4), "discount_total": docs["discount_total"].round(4),
        "tax_total": docs["tax_total"].round(4), "total": (docs["subtotal"] + docs["tax_total"]).round(4),
        "source_hash": [hashlib.sha256(f"{a}{b}".encode()).hexdigest() for a, b in
                        zip(docs["document_type"], docs["document_number"])],
        "created_at": "now", "updated_at": "now",
    })
    _copy(conn, "sales", sales)
    key = dict(zip(zip(docs["document_type"], docs["document_number"]), docs["id"]))
    s["sale_id"] = [key[(a, b)] for a, b in zip(s["document_type"], s["document_number"])]
    s["sale_date"] = pd.to_datetime(s["issued_at"]).dt.date.astype(str)
    s["line_number"] = s.groupby("sale_id").cumcount() + 1
    items = pd.DataFrame({
        "tenant_id": tenant_id, "sale_id": s["sale_id"], "line_number": s["line_number"],
        "product_id": s["product_sku"].map(p_map), "branch_id": s["branch_code"].map(b_map), "sale_date": s["sale_date"],
        "quantity": s["quantity"], "unit_price": s["unit_price"], "discount_amount": s["discount"],
        "net_amount": s["net_amount"], "tax_amount": s["tax"], "unit_cost": s["unit_cost"],
        "is_return": s["document_type"] == "credit_note", "created_at": "now", "updated_at": "now",
    })
    _copy(conn, "sale_items", items)

    if with_inventory:
        inv = company.inventory[company.inventory["product_sku"].isin(p_map)]
        _copy(conn, "inventory_snapshots", pd.DataFrame({
            "tenant_id": tenant_id, "branch_id": inv["branch_code"].map(b_map), "product_id": inv["product_sku"].map(p_map),
            "snapshot_date": inv["snapshot_date"], "on_hand": inv["on_hand"], "reserved": inv["reserved"],
            "in_transit": inv["in_transit"], "unit_cost": inv["unit_cost"], "created_at": "now", "updated_at": "now",
        }))
    return int(tenant_id)


def create_run(conn: Connection, tenant_id: int, run_type: str = "production", cutoff: str | None = None,
               status: str = "queued") -> tuple[int, int]:
    """Crea forecast_run + training_job como lo haría Laravel. Devuelve (run_id, job_id)."""
    version = conn.execute(text("SELECT COALESCE(MAX(version), 0) + 1 FROM forecast_runs WHERE tenant_id = :t"),
                           {"t": tenant_id}).scalar_one()
    run_id = conn.execute(text(
        "INSERT INTO forecast_runs (tenant_id, version, run_type, status, triggered_by, horizon_days, options, "
        "created_at, updated_at) VALUES (:t, :v, :rt, :st, 'user', 90, CAST(:opt AS jsonb), now(), now()) RETURNING id"
    ), {"t": tenant_id, "v": version, "rt": run_type, "st": status,
        "opt": json.dumps({"cutoff_date": cutoff} if cutoff else {})}).scalar_one()
    job_id = conn.execute(text(
        "INSERT INTO training_jobs (tenant_id, forecast_run_id, job_type, status, triggered_by, params, queued_at, "
        "created_at, updated_at) VALUES (:t, :r, 'forecast_pipeline', 'queued', 'user', '{}'::jsonb, now(), now(), now()) "
        "RETURNING id"), {"t": tenant_id, "r": run_id}).scalar_one()
    return int(run_id), int(job_id)
