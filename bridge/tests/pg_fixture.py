"""Base «ERP de origen» ficticia en PostgreSQL para las pruebas de integración del agente (tablas con prefijo ALEATORIO ``brg<hex>_``, para no chocar con otras bases/ejecuciones; TODOS los datos son ficticios).

Conexión por variables TEST_ERP_HOST / TEST_ERP_PORT / TEST_ERP_DATABASE / TEST_ERP_USERNAME / TEST_ERP_PASSWORD (las mismas del backend). Sin servidor
o sin base, las pruebas se OMITEN con un mensaje explícito (no es un fallo).
"""

from __future__ import annotations

import os
import uuid
from datetime import datetime, timedelta, timezone
from typing import Any

import pytest

SCHEMA = """
CREATE TABLE {p}facturas (
    id_factura serial PRIMARY KEY, num_factura varchar(30) UNIQUE NOT NULL, tipo varchar(20) NOT NULL, estado varchar(20) NOT NULL,
    fecha_emision timestamp NOT NULL, cod_sucursal varchar(10) NOT NULL, cliente varchar(20), canal varchar(20), modificado_en timestamptz NOT NULL);
CREATE TABLE {p}factura_lineas (
    id_factura int NOT NULL REFERENCES {p}facturas, linea int NOT NULL, cod_item varchar(30) NOT NULL, descripcion varchar(100), categoria varchar(50),
    cantidad numeric(14,4) NOT NULL, precio_unit numeric(14,4) NOT NULL, descuento numeric(14,4) NOT NULL DEFAULT 0, iva numeric(14,4) NOT NULL DEFAULT 0,
    subtotal numeric(14,4), costo_unit numeric(14,4), PRIMARY KEY (id_factura, linea));
"""

SALES_TEMPLATE_SQL_TPL = """
SELECT f.num_factura AS document_number, f.tipo AS document_type, f.estado AS status, f.fecha_emision AS issued_at, f.cod_sucursal AS branch_code,
       l.linea AS line_number, l.cod_item AS product_sku, l.descripcion AS product_name, l.categoria AS category, l.cantidad AS quantity,
       l.precio_unit AS unit_price, l.descuento AS discount, l.iva AS tax, l.subtotal AS net_amount, l.costo_unit AS unit_cost,
       f.cliente AS customer_code, f.canal AS channel, f.modificado_en AS source_recorded_at
FROM {p}facturas f JOIN {p}factura_lineas l ON l.id_factura = f.id_factura
WHERE f.modificado_en >= :since
"""


class Fixture:
    """Tablas del ERP ficticio con prefijo aleatorio."""

    def __init__(self, conn: Any) -> None:
        self.conn = conn
        self.p = "brg" + uuid.uuid4().hex[:8] + "_"

    @property
    def sales_sql(self) -> str:
        return SALES_TEMPLATE_SQL_TPL.replace("{p}", self.p)

    def t(self, name: str) -> str:
        return self.p + name


def settings() -> dict[str, Any]:
    return {
        "host": os.environ.get("TEST_ERP_HOST", "127.0.0.1"), "port": int(os.environ.get("TEST_ERP_PORT", "5432")), "dbname": os.environ.get("TEST_ERP_DATABASE", "predictily_erp_source_w6"),
        "user": os.environ.get("TEST_ERP_USERNAME", "predictily"), "password": os.environ.get("TEST_ERP_PASSWORD", ""),
    }


def connect_admin():  # type: ignore[no-untyped-def]
    try:
        import psycopg
    except ImportError:
        pytest.skip("OMITIDA SIN VERIFICAR (no es un fallo): falta psycopg; instale 'predictily-bridge[postgres]'.")
    try:
        return psycopg.connect(**settings(), connect_timeout=3, autocommit=True)
    except Exception as e:
        pytest.skip(f"OMITIDA SIN VERIFICAR (no es un fallo): no hay base «ERP de origen» PostgreSQL ({settings()['dbname']} en {settings()['host']}:{settings()['port']}): {type(e).__name__}. "
                    "Cree la base (createdb) y relance con TEST_ERP_DATABASE/TEST_ERP_PASSWORD si difieren.")


def seed(fx: Fixture, documents: int = 12, lines: int = 2) -> None:
    """``documents`` facturas con ``lines`` líneas; ``modificado_en`` (timestamptz) crece una hora por factura desde 2026-01-01 13:00 UTC."""
    conn = fx.conn
    conn.execute(SCHEMA.replace("{p}", fx.p))
    base = datetime(2026, 1, 1, 13, 0, 0, tzinfo=timezone.utc)
    for n in range(1, documents + 1):
        stamp = base + timedelta(hours=n)
        local = (stamp - timedelta(hours=5)).replace(tzinfo=None)  # hora de Guayaquil, ingenua (como la guarda el ERP)
        conn.execute(
            f"INSERT INTO {fx.t('facturas')} (num_factura, tipo, estado, fecha_emision, cod_sucursal, cliente, canal, modificado_en) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
            (f"001-001-{n:09d}", "FACTURA", "EMITIDA", local, "001", None, "LOCAL", stamp),
        )
        for ln in range(1, lines + 1):
            conn.execute(
                f"INSERT INTO {fx.t('factura_lineas')} (id_factura, linea, cod_item, descripcion, categoria, cantidad, precio_unit, descuento, iva, subtotal, costo_unit) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,0,%s,%s,%s)",
                (n, ln, f"SKU-{(n + ln) % 5}", f"Producto {(n + ln) % 5}", "Categoría ñ", n + ln, "10.5000", "1.2600", (n + ln) * 10.5, "7.0000"),
            )


def drop(fx: Fixture) -> None:
    fx.conn.execute(f"DROP TABLE IF EXISTS {fx.t('factura_lineas')}, {fx.t('facturas')} CASCADE")


def table_checksum(fx: Fixture) -> str:
    return str(fx.conn.execute(
        f"SELECT md5(coalesce((SELECT string_agg(f::text, '|' ORDER BY f.id_factura) FROM {fx.t('facturas')} f), '') || coalesce((SELECT string_agg(l::text, '|' ORDER BY l.id_factura, l.linea) FROM {fx.t('factura_lineas')} l), ''))"
    ).fetchone()[0])
