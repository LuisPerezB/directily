"""Integración: órdenes de compra abiertas por orden y con su fecha prevista (F1)."""

from __future__ import annotations

import pandas as pd
import pytest
from sqlalchemy import text

from predictily_engine.db.repository import TenantRepository

pytestmark = pytest.mark.integration


def _po(conn, tenant_id: int, branch_id: int, status: str, expected_at: str | None, items: list[tuple]) -> int:
    po_id = conn.execute(text(
        "INSERT INTO purchase_orders (tenant_id, branch_id, status, ordered_at, expected_at, created_at, updated_at) "
        "VALUES (:t, :b, :s, DATE '2026-10-01', CAST(:e AS date), now(), now()) RETURNING id"
    ), {"t": tenant_id, "b": branch_id, "s": status, "e": expected_at}).scalar_one()
    for product_id, ordered, received in items:
        conn.execute(text(
            "INSERT INTO purchase_order_items (tenant_id, purchase_order_id, product_id, quantity_ordered, "
            "quantity_received, created_at, updated_at) VALUES (:t, :po, :p, :q, :r, now(), now())"
        ), {"t": tenant_id, "po": po_id, "p": product_id, "q": ordered, "r": received})
    return po_id


def test_open_purchase_orders_keep_each_order_and_its_date(db_engine, tenants):
    a, b = tenants["A"], tenants["B"]
    with db_engine.connect() as conn:
        trans = conn.begin()
        try:
            branch = conn.execute(text("SELECT id FROM branches WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                  {"t": a}).scalar_one()
            product = conn.execute(text("SELECT id FROM products WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                   {"t": a}).scalar_one()
            other = conn.execute(text("SELECT id FROM products WHERE tenant_id = :t ORDER BY id OFFSET 1 LIMIT 1"),
                                 {"t": a}).scalar_one()
            b_branch = conn.execute(text("SELECT id FROM branches WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                    {"t": b}).scalar_one()
            b_product = conn.execute(text("SELECT id FROM products WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                     {"t": b}).scalar_one()
            late = _po(conn, a, branch, "ordered", "2026-11-15", [(product, 100, 20), (product, 10, 0)])
            undated = _po(conn, a, branch, "partially_received", None, [(product, 50, 0), (other, 5, 5)])
            _po(conn, a, branch, "received", "2026-10-20", [(product, 70, 70)])
            _po(conn, a, branch, "cancelled", "2026-10-20", [(product, 30, 0)])
            _po(conn, b, b_branch, "ordered", "2026-10-10", [(b_product, 999, 0)])

            po = TenantRepository(conn, a).open_purchase_orders()
            # Una fila por orden y producto (antes: suma de todas las órdenes con la fecha mínima).
            assert set(po["purchase_order_id"]) == {late, undated}
            by_id = po.set_index("purchase_order_id")
            assert by_id.loc[late, "qty_open"] == pytest.approx(90.0)  # 80 + 10 pendientes de la misma orden
            assert pd.Timestamp(by_id.loc[late, "expected_at"]) == pd.Timestamp("2026-11-15")
            assert by_id.loc[undated, "qty_open"] == pytest.approx(50.0)  # el ítem ya recibido no aparece
            assert pd.isna(by_id.loc[undated, "expected_at"])
            assert set(po["product_id"]) == {product}
        finally:
            trans.rollback()
