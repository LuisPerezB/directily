"""Fixtures del Customer Core (P2): carga mundos ficticios (verdad conocida) y la demo en PostgreSQL.

Todo es FICTICIO. Se usa solo en pruebas y en `predictily_engine.customer.demo_report`.
"""

from __future__ import annotations

import hashlib
import json
from zoneinfo import ZoneInfo

import pandas as pd
from sqlalchemy import Connection, text

from loader import _copy, _next_ids
from predictily_engine.customer.synthetic import SyntheticWorld

TZ = ZoneInfo("America/Guayaquil")


def load_world(conn: Connection, world: SyntheticWorld, name: str = "Empresa P2 (ficticia)", settings: dict | None = None,
               with_inventory: bool = True, coverage_through: str | None = None, sector: str = "ferreteria") -> dict:
    """Inserta el mundo sintético como una empresa. Devuelve ids de BD y mapas desde los ids locales."""
    tenant_id = conn.execute(text(
        "INSERT INTO tenants (uuid, name, sector, settings, status, created_at, updated_at) "
        "VALUES (gen_random_uuid(), :name, :sector, CAST(:settings AS jsonb), 'active', now(), now()) RETURNING id"
    ), {"name": name, "sector": sector, "settings": json.dumps(settings or {})}).scalar_one()
    b_map, p_map, c_map = {}, {}, {}
    for b in world.branches.itertuples(index=False):
        b_map[int(b.branch_id)] = conn.execute(text(
            "INSERT INTO branches (tenant_id, code, name, province, canton, is_active, created_at, updated_at) "
            "VALUES (:t, :code, :name, :prov, :canton, true, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": b.code, "name": f"Sucursal {b.code}", "prov": b.province, "canton": b.canton}).scalar_one()
    cat_map = {}
    for cat in sorted(world.products["category_id"].unique()):
        cat_map[int(cat)] = conn.execute(text(
            "INSERT INTO product_categories (tenant_id, name, created_at, updated_at) VALUES (:t, :n, now(), now()) RETURNING id"
        ), {"t": tenant_id, "n": f"Categoría ficticia {cat}"}).scalar_one()
    for p in world.products.itertuples(index=False):
        p_map[int(p.product_id)] = conn.execute(text(
            "INSERT INTO products (tenant_id, category_id, sku, name, unit, current_price, is_active, created_at, updated_at) "
            "VALUES (:t, :c, :sku, :name, :unit, :price, true, now(), now()) RETURNING id"
        ), {"t": tenant_id, "c": cat_map[int(p.category_id)], "sku": p.sku, "name": p.name, "unit": p.unit,
            "price": float(p.price0)}).scalar_one()
    for c in world.customers.itertuples(index=False):
        c_map[int(c.customer_id)] = conn.execute(text(
            "INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) "
            "VALUES (:t, :code, :ctype, :gen, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": c.external_code, "ctype": "final_consumer" if c.is_generic else "company",
            "gen": bool(c.is_generic)}).scalar_one()

    s = world.sales.copy()
    docs = s.groupby(["document_type", "document_number"], sort=False).agg(
        branch_id=("branch_id", "first"), status=("status", "first"), ds=("ds", "first"), customer_id=("customer_id", "first"),
        subtotal=("net_amount", "sum"), discount_total=("discount", "sum")).reset_index()
    ids = _next_ids(conn, "sales", len(docs))
    docs["id"] = ids
    issued = pd.to_datetime(docs["ds"]) + pd.Timedelta(hours=10)
    local = issued.dt.tz_localize(TZ)
    sales = pd.DataFrame({
        "id": docs["id"], "tenant_id": tenant_id, "branch_id": docs["branch_id"].map(b_map), "customer_id": docs["customer_id"].map(
            lambda v: c_map.get(int(v)) if pd.notna(v) else None).astype("Int64"),
        "document_type": docs["document_type"], "document_number": docs["document_number"], "status": docs["status"],
        "issued_at": local.map(lambda d: d.isoformat()), "sale_date": pd.to_datetime(docs["ds"]).dt.date.astype(str), "currency": "USD",
        "subtotal": docs["subtotal"].round(4), "discount_total": docs["discount_total"].round(4), "tax_total": 0.0,
        "total": docs["subtotal"].round(4),
        "source_hash": [hashlib.sha256(f"{a}{b}".encode()).hexdigest() for a, b in zip(docs["document_type"], docs["document_number"])],
        "created_at": "now", "updated_at": "now"})
    _copy(conn, "sales", sales)
    key = dict(zip(zip(docs["document_type"], docs["document_number"]), docs["id"]))
    s["sale_id"] = [key[(a, b)] for a, b in zip(s["document_type"], s["document_number"])]
    s["line_number"] = s.groupby("sale_id").cumcount() + 1
    items = pd.DataFrame({
        "tenant_id": tenant_id, "sale_id": s["sale_id"], "line_number": s["line_number"], "product_id": s["product_id"].map(p_map),
        "branch_id": s["branch_id"].map(b_map), "sale_date": pd.to_datetime(s["ds"]).dt.date.astype(str), "quantity": s["quantity"],
        "unit_price": s["unit_price"], "discount_amount": s["discount"], "net_amount": s["net_amount"], "tax_amount": 0.0,
        "is_return": s["document_type"] == "credit_note", "created_at": "now", "updated_at": "now"})
    _copy(conn, "sale_items", items)
    if with_inventory and len(world.inventory):
        inv = world.inventory
        _copy(conn, "inventory_snapshots", pd.DataFrame({
            "tenant_id": tenant_id, "branch_id": inv["branch_id"].map(b_map), "product_id": inv["product_id"].map(p_map),
            "snapshot_date": pd.to_datetime(inv["ds"]).dt.date.astype(str), "on_hand": inv["on_hand"], "reserved": inv["reserved"],
            "in_transit": 0.0, "unit_cost": 1.0, "created_at": "now", "updated_at": "now"}))
    for pr in world.promotions.itertuples(index=False):
        pid = conn.execute(text(
            "INSERT INTO promotions (tenant_id, name, promo_type, discount_pct, starts_on, ends_on, product_id, known_at, created_at, updated_at) "
            "VALUES (:t, :n, 'percentage', :d, :s, :e, :p, CAST(:k AS timestamp) AT TIME ZONE 'America/Guayaquil', now(), now()) RETURNING id"
        ), {"t": tenant_id, "n": f"Promo ficticia {pr.promotion_id}", "d": float(pr.discount_pct), "s": pd.Timestamp(pr.starts_on).date(),
            "e": pd.Timestamp(pr.ends_on).date(), "p": p_map[int(pr.product_id)], "k": pd.Timestamp(pr.known_at).isoformat()}).scalar_one()
        conn.execute(text(
            "INSERT INTO promotion_revisions (tenant_id, promotion_id, revision, name, promo_type, discount_pct, starts_on, ends_on, "
            "product_id, known_at, deleted, source, recorded_at) SELECT tenant_id, id, 1, name, promo_type, discount_pct, starts_on, "
            "ends_on, product_id, known_at, false, 'import', known_at FROM promotions WHERE tenant_id = :t AND id = :id"
        ), {"t": tenant_id, "id": pid})
    if coverage_through:
        conn.execute(text(
            "INSERT INTO data_coverage (tenant_id, entity, complete_from, complete_through, basis, created_at, updated_at) "
            "VALUES (:t, 'sales', :f, :c, 'declared', now(), now())"
        ), {"t": tenant_id, "f": world.start, "c": coverage_through})
    return {"tenant_id": int(tenant_id), "branches": b_map, "products": p_map, "customers": c_map}


def attach_demo_customers(conn: Connection, tenant_id: int, company) -> dict:
    """Crea los compradores de la demo ficticia (columna customer_code) y los asocia a sus documentos."""
    sales = company.sales
    codes = sorted(sales["customer_code"].dropna().unique())
    c_map = {}
    for code in codes:
        generic = str(code) == "9999999999999"
        c_map[code] = conn.execute(text(
            "INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) "
            "VALUES (:t, :code, :ct, :g, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": str(code), "ct": "final_consumer" if generic else "company", "g": generic}).scalar_one()
    docs = sales[["document_type", "document_number", "customer_code"]].drop_duplicates(["document_type", "document_number"])
    docs = docs.assign(document_type=docs["document_type"].map({"factura": "invoice", "nota de crédito": "credit_note"}),
                       customer_id=docs["customer_code"].map(c_map))
    conn.execute(text("CREATE TEMP TABLE tmp_doc_customer (document_type text, document_number text, customer_id bigint) ON COMMIT DROP"))
    _copy(conn, "tmp_doc_customer", docs[["document_type", "document_number", "customer_id"]])
    conn.execute(text(
        "UPDATE sales s SET customer_id = t.customer_id FROM tmp_doc_customer t WHERE s.tenant_id = :t "
        "AND s.document_type = t.document_type AND s.document_number = t.document_number"), {"t": tenant_id})
    return c_map


def create_core_run(conn: Connection, tenant_id: int, operation: str, horizons: list[int] | None = None, as_of: str | None = None,
                    run_type: str = "production", options: dict | None = None) -> tuple[int, int]:
    """Crea core_run + training_job como lo haría Laravel. Devuelve (run_id, job_id)."""
    version = conn.execute(text("SELECT COALESCE(MAX(version), 0) + 1 FROM core_runs WHERE tenant_id = :t AND core = 'customer'"),
                           {"t": tenant_id}).scalar_one()
    run_id = conn.execute(text(
        "INSERT INTO core_runs (tenant_id, core, operation, version, run_type, status, as_of, horizons, options, created_at, updated_at) "
        "VALUES (:t, 'customer', :op, :v, :rt, 'queued', :as_of, CAST(:h AS jsonb), CAST(:o AS jsonb), now(), now()) RETURNING id"
    ), {"t": tenant_id, "op": operation, "v": version, "rt": run_type, "as_of": as_of, "h": json.dumps(horizons or [7, 30, 90]),
        "o": json.dumps(options or {})}).scalar_one()
    job_id = conn.execute(text(
        "INSERT INTO training_jobs (tenant_id, core_run_id, job_type, status, triggered_by, params, queued_at, created_at, updated_at) "
        "VALUES (:t, :r, :jt, 'queued', 'user', '{}'::jsonb, now(), now(), now()) RETURNING id"
    ), {"t": tenant_id, "r": run_id, "jt": f"customer_{operation}"}).scalar_one()
    return int(run_id), int(job_id)
