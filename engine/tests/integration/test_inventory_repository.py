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


def test_open_purchase_orders_as_of_a_historical_cutoff(db_engine, tenants):
    """Corte retroactivo: no se usan órdenes emitidas después ni recibidas antes del corte."""
    a = tenants["A"]
    with db_engine.connect() as conn:
        trans = conn.begin()
        try:
            branch = conn.execute(text("SELECT id FROM branches WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                  {"t": a}).scalar_one()
            product = conn.execute(text("SELECT id FROM products WHERE tenant_id = :t ORDER BY id LIMIT 1"),
                                   {"t": a}).scalar_one()

            def po(ordered_at: str, received_at: str | None, status: str, qty: float, received: float) -> int:
                po_id = conn.execute(text(
                    "INSERT INTO purchase_orders (tenant_id, branch_id, status, ordered_at, expected_at, received_at, "
                    "created_at, updated_at) VALUES (:t, :b, :s, CAST(:o AS date), DATE '2026-09-20', "
                    "CAST(:r AS date), now(), now()) RETURNING id"
                ), {"t": a, "b": branch, "s": status, "o": ordered_at, "r": received_at}).scalar_one()
                conn.execute(text(
                    "INSERT INTO purchase_order_items (tenant_id, purchase_order_id, product_id, quantity_ordered, "
                    "quantity_received, created_at, updated_at) VALUES (:t, :po, :p, :q, :rq, now(), now())"
                ), {"t": a, "po": po_id, "p": product, "q": qty, "rq": received})
                return po_id

            pending_then = po("2026-09-01", "2026-09-20", "received", 40, 40)   # recibida DESPUÉS del corte
            po("2026-08-01", "2026-09-05", "received", 70, 70)                  # recibida ANTES del corte
            po("2026-09-12", None, "ordered", 25, 0)                            # emitida DESPUÉS del corte
            po("2026-09-02", None, "cancelled", 30, 0)                          # cancelada
            still_open = po("2026-09-03", None, "partially_received", 60, 20)   # abierta (parcial sin fecha)

            repo = TenantRepository(conn, a)
            hist = repo.open_purchase_orders(as_of=pd.Timestamp("2026-09-10").date()).set_index("purchase_order_id")
            assert set(hist.index) == {pending_then, still_open}
            assert hist.loc[pending_then, "qty_open"] == pytest.approx(40.0)  # pendiente completa al corte
            assert hist.loc[still_open, "qty_open"] == pytest.approx(60.0)    # recepción parcial sin fecha: tras el corte
            current = repo.open_purchase_orders().set_index("purchase_order_id")
            assert set(current.index) >= {still_open} and pending_then not in current.index
            assert current.loc[still_open, "qty_open"] == pytest.approx(40.0)
        finally:
            trans.rollback()
