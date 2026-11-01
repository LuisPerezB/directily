"""Ayudas de las pruebas de tiempo de conocimiento (ADR-V25-23 · T02).

Insertan empresas, documentos y versiones DIRECTAMENTE en `sales`, `sale_items`, `sales_document_revisions` y
`sale_item_revisions`, tal como fija el contrato de la migración `2026_03_01_000100_add_sales_knowledge_time_and_revisions`:

- versión vigente: `sales.version_known_from` (NULL = inicio del día local de `sale_date`, regla de carga histórica) e
  `ingestion_mode` (`backfill` por defecto en filas que no lo fijan, `incremental` = conocida desde su ingestión);
- versión reemplazada: una fila de `sales_document_revisions` + `sale_item_revisions` con `known_until` = `known_from` de la versión
  que la reemplazó.

Todo es FICTICIO. Se usa dentro de transacciones que las pruebas revierten.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import date, datetime, time
from zoneinfo import ZoneInfo

from sqlalchemy import Connection, text

TZ_NAME = "America/Guayaquil"
TZ = ZoneInfo(TZ_NAME)
GENERIC_CODE = "9999999999999"


@dataclass
class World:
    tenant_id: int
    branch_ids: list[int]
    product_ids: list[int]
    customer_ids: list[int]
    generic_customer_id: int | None = None
    timezone: str = TZ_NAME
    docs: dict[str, int] = field(default_factory=dict)  # número de documento → sale_id


def local(day: str | date, hour: int = 10, minute: int = 0, tz: str = TZ_NAME) -> datetime:
    """Instante con zona a las `hour:minute` locales de `day` (fecha ISO o `date`)."""
    d = date.fromisoformat(day) if isinstance(day, str) else day
    return datetime.combine(d, time(hour, minute), tzinfo=ZoneInfo(tz))


def make_world(conn: Connection, n_branches: int = 2, n_products: int = 2, n_customers: int = 3, generic: bool = False,
               timezone: str = TZ_NAME, name: str = "Empresa T02 (ficticia)") -> World:
    tenant_id = int(conn.execute(text(
        "INSERT INTO tenants (uuid, name, sector, timezone, settings, status, created_at, updated_at) "
        "VALUES (gen_random_uuid(), :name, 'ferreteria', :tz, CAST(:settings AS jsonb), 'active', now(), now()) RETURNING id"
    ), {"name": name, "tz": timezone, "settings": json.dumps({})}).scalar_one())
    branches = [int(conn.execute(text(
        "INSERT INTO branches (tenant_id, code, name, province, canton, is_active, created_at, updated_at) "
        "VALUES (:t, :code, :name, 'Pichincha', 'Quito', true, now(), now()) RETURNING id"
    ), {"t": tenant_id, "code": f"S{i + 1}", "name": f"Sucursal {i + 1}"}).scalar_one()) for i in range(n_branches)]
    cat = int(conn.execute(text(
        "INSERT INTO product_categories (tenant_id, name, created_at, updated_at) VALUES (:t, 'General', now(), now()) RETURNING id"
    ), {"t": tenant_id}).scalar_one())
    products = [int(conn.execute(text(
        "INSERT INTO products (tenant_id, category_id, sku, name, unit, current_price, is_active, created_at, updated_at) "
        "VALUES (:t, :c, :sku, :name, 'unidad', 10, true, now(), now()) RETURNING id"
    ), {"t": tenant_id, "c": cat, "sku": f"T02-{i + 1:03d}", "name": f"Producto T02 {i + 1}"}).scalar_one()) for i in range(n_products)]
    customers = [int(conn.execute(text(
        "INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) "
        "VALUES (:t, :code, 'company', false, now(), now()) RETURNING id"
    ), {"t": tenant_id, "code": f"CLI-{i + 1:05d}"}).scalar_one()) for i in range(n_customers)]
    generic_id = None
    if generic:
        generic_id = int(conn.execute(text(
            "INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) "
            "VALUES (:t, :code, 'final_consumer', true, now(), now()) RETURNING id"
        ), {"t": tenant_id, "code": GENERIC_CODE}).scalar_one())
    return World(tenant_id, branches, products, customers, generic_id, timezone)


def _totals(lines: list[tuple]) -> tuple[float, float]:
    net = sum(round(q * p, 4) for _, q, p, *_ in lines)
    return round(net, 4), round(net, 4)


def add_document(conn: Connection, w: World, *, number: str, sale_date: str, lines: list[tuple], branch: int = 0,
                 customer: int | None = None, doc_type: str = "invoice", status: str = "completed", mode: str | None = None,
                 known_from: datetime | None = None, hour: int = 10, currency: str = "USD", is_return: bool = False,
                 unit_cost: float | None = None, explicit_defaults: bool = False) -> int:
    """Inserta un documento con sus líneas (`lines` = (índice de producto, cantidad, precio unitario)).

    Sin `mode` ni `known_from` el documento se inserta con los VALORES POR DEFECTO de las columnas nuevas (fixtures existentes:
    `ingestion_mode = 'backfill'`, `version_known_from` NULL). Con `mode='incremental'` y `known_from` es una carga rutinaria
    conocida desde ese instante. `customer`: índice en `w.customer_ids`, `-1` = el comprador genérico, None = anónimo."""
    issued = local(sale_date, hour, tz=w.timezone)
    cust = None
    if customer is not None:
        cust = w.generic_customer_id if customer == -1 else w.customer_ids[customer]
    subtotal, total = _totals(lines)
    cols = ("tenant_id, branch_id, customer_id, document_type, document_number, status, issued_at, sale_date, currency, subtotal, "
            "discount_total, tax_total, total, source_hash, created_at, updated_at")
    vals = (":t, :b, :c, :dt, :num, :st, :iss, :sd, :cur, :sub, 0, 0, :tot, :hash, now(), now()")
    params = {"t": w.tenant_id, "b": w.branch_ids[branch], "c": cust, "dt": doc_type, "num": number, "st": status, "iss": issued,
              "sd": sale_date, "cur": currency, "sub": subtotal, "tot": total,
              "hash": hashlib.sha256(f"{doc_type}{number}0".encode()).hexdigest()}
    if mode is not None or known_from is not None or explicit_defaults:
        cols += ", ingestion_mode, version_known_from, first_seen_at"
        vals += ", :mode, :kf, COALESCE(:kf, now())"
        params |= {"mode": mode or "backfill", "kf": known_from}
    sale_id = int(conn.execute(text(f"INSERT INTO sales ({cols}) VALUES ({vals}) RETURNING id"), params).scalar_one())
    _insert_lines(conn, w, sale_id, "sale_items", None, sale_date, branch, lines, is_return or doc_type == "credit_note", unit_cost)
    w.docs[number] = sale_id
    return sale_id


def _insert_lines(conn: Connection, w: World, sale_id: int, table: str, revision: int | None, sale_date: str, branch: int,
                  lines: list[tuple], is_return: bool, unit_cost: float | None) -> None:
    for n, (p_idx, qty, price, *extra) in enumerate(lines, start=1):
        cost = extra[0] if extra else unit_cost
        base = {"t": w.tenant_id, "s": sale_id, "n": n, "p": w.product_ids[p_idx], "b": w.branch_ids[branch], "sd": sale_date,
                "q": qty, "up": price, "net": round(qty * price, 4), "cost": cost, "ret": is_return, "rev": revision}
        if table == "sale_items":
            conn.execute(text(
                "INSERT INTO sale_items (tenant_id, sale_id, line_number, product_id, branch_id, sale_date, quantity, unit_price, "
                "discount_amount, net_amount, tax_amount, unit_cost, is_return, created_at, updated_at) VALUES "
                "(:t, :s, :n, :p, :b, :sd, :q, :up, 0, :net, 0, :cost, :ret, now(), now())"), base)
        else:
            conn.execute(text(
                "INSERT INTO sale_item_revisions (tenant_id, sale_id, revision, line_number, product_id, branch_id, sale_date, quantity, "
                "unit_price, discount_amount, net_amount, tax_amount, unit_cost, is_return) VALUES "
                "(:t, :s, :rev, :n, :p, :b, :sd, :q, :up, 0, :net, 0, :cost, :ret)"), base)


def amend_document(conn: Connection, w: World, number: str, *, known_from: datetime, status: str | None = None,
                   lines: list[tuple] | None = None, change_kind: str = "status_changed") -> None:
    """Reemplaza la versión vigente de un documento por otra conocida desde `known_from` (anulación o corrección posterior).

    La versión anterior pasa a `sales_document_revisions`/`sale_item_revisions` con `known_until = known_from`; la nueva queda
    en `sales`/`sale_items` con `ingestion_mode = 'incremental'` y `version_known_from = known_from`."""
    sale_id = w.docs[number]
    row = conn.execute(text("SELECT current_revision, sale_date, branch_id FROM sales WHERE id = :id AND tenant_id = :t"),
                       {"id": sale_id, "t": w.tenant_id}).one()
    revision, sale_date = int(row[0]), row[1].isoformat()
    conn.execute(text(
        "INSERT INTO sales_document_revisions (tenant_id, sale_id, revision, document_type, status, issued_at, sale_date, branch_id, "
        "customer_id, channel, currency, subtotal, discount_total, tax_total, total, source_hash, source_recorded_at, ingestion_mode, "
        "known_from, known_until, change_kind) SELECT tenant_id, id, current_revision, document_type, status, issued_at, sale_date, "
        "branch_id, customer_id, channel, currency, subtotal, discount_total, tax_total, total, source_hash, source_recorded_at, "
        "ingestion_mode, version_known_from, :ku, :ck FROM sales WHERE id = :id AND tenant_id = :t"),
        {"id": sale_id, "t": w.tenant_id, "ku": known_from, "ck": change_kind})
    conn.execute(text(
        "INSERT INTO sale_item_revisions (tenant_id, sale_id, revision, line_number, product_id, branch_id, sale_date, quantity, unit_price, "
        "discount_amount, net_amount, tax_amount, unit_cost, is_return, promotion_id) SELECT tenant_id, sale_id, :rev, line_number, "
        "product_id, branch_id, sale_date, quantity, unit_price, discount_amount, net_amount, tax_amount, unit_cost, is_return, "
        "promotion_id FROM sale_items WHERE sale_id = :id AND tenant_id = :t"), {"id": sale_id, "t": w.tenant_id, "rev": revision})
    if lines is not None:
        branch = w.branch_ids.index(int(row[2]))
        was_return = bool(conn.execute(text("SELECT bool_or(is_return) FROM sale_items WHERE sale_id = :id"), {"id": sale_id}).scalar())
        conn.execute(text("DELETE FROM sale_items WHERE sale_id = :id AND tenant_id = :t"), {"id": sale_id, "t": w.tenant_id})
        _insert_lines(conn, w, sale_id, "sale_items", None, sale_date, branch, lines, was_return, None)
        subtotal, total = _totals(lines)
        conn.execute(text("UPDATE sales SET subtotal = :s, total = :tot WHERE id = :id AND tenant_id = :t"),
                     {"s": subtotal, "tot": total, "id": sale_id, "t": w.tenant_id})
    conn.execute(text(
        "UPDATE sales SET status = COALESCE(:st, status), version_known_from = :kf, ingestion_mode = 'incremental', "
        "current_revision = current_revision + 1, updated_at = now() WHERE id = :id AND tenant_id = :t"),
        {"st": status, "kf": known_from, "id": sale_id, "t": w.tenant_id})
