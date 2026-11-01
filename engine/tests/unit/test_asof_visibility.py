"""ADR-V25-23 · regla de visibilidad as-of de las versiones de documentos de venta (sin base de datos).

Una versión es visible al corte C (fecha local de la empresa) si `known_from < medianoche local de (C + 1)` y
(`known_until` es NULL o `known_until >= medianoche local de (C + 1)`). Todo con datos FICTICIOS.
"""

from __future__ import annotations

import hashlib

import numpy as np
import pandas as pd
import pytest

from predictily_engine.data.asof import (
    PurchaseVersions,
    SalesVersions,
    knowledge_boundary,
    local_midnight_after,
    with_knowledge,
)
from predictily_engine.platform.snapshot import frame_hash

TZ = "America/Guayaquil"  # UTC-5 todo el año: la medianoche local de C+1 es 05:00 UTC


def utc(text: str) -> pd.Timestamp:
    """Instante UTC sin zona (como lo entrega la lectura de versiones)."""
    return pd.Timestamp(text)


def line(sale_id: int, ds: str, qty: float, known_from: str | None, known_until: str | None = None, *, price: float = 10.0,
         doc: str = "invoice", is_return: bool = False, cost: float | None = None, line_number: int = 1, branch: int = 1,
         product: int = 1, mode: str = "incremental") -> dict:
    """Una versión de línea. `known_from=None` = carga histórica (conocida desde el inicio del día local de `ds`)."""
    kf = pd.Timestamp(ds) + pd.Timedelta(hours=5) if known_from is None else utc(known_from)
    return {"sale_id": sale_id, "line_number": line_number, "branch_id": branch, "product_id": product, "ds": pd.Timestamp(ds),
            "quantity": qty, "unit_price": price, "discount_amount": 0.0, "net_amount": qty * price,
            "unit_cost": np.nan if cost is None else cost, "is_return": is_return, "document_type": doc,
            "ingestion_mode": "backfill" if known_from is None else mode, "known_from": kf,
            "known_until": pd.NaT if known_until is None else utc(known_until)}


def versions(rows: list[dict], final: str = "2026-10-31", tz: str = TZ, knowledge: str | None = None) -> SalesVersions:
    """`knowledge` (≥ `final`): hasta qué día llega lo conocido; sin él, el propio corte final (reconstrucción histórica)."""
    return SalesVersions.from_lines(pd.DataFrame(rows), tz, final, knowledge)


def units_at(v: SalesVersions, cutoff: str) -> float:
    cells = v.sales_cells(cutoff)
    return float(cells["units"].sum()) if len(cells) else 0.0


@pytest.mark.parametrize("tz", ["America/Guayaquil", "UTC", "Europe/Madrid", "Asia/Tokyo"])
def test_visibility_rule_is_strict_on_known_from_and_inclusive_on_known_until(tz):
    """known_from < M(C+1) (estricto) y known_until >= M(C+1) (inclusivo), con la medianoche local de la zona de la empresa."""
    boundary = pd.Timestamp(knowledge_boundary("2026-10-20", tz))  # M(C+1) para C = 2026-10-20
    eps = pd.Timedelta(nanoseconds=1)
    rows = {
        "conocida 1 ns antes": ({"known_from": boundary - eps}, True, True),            # (visible el 20, visible el 21)
        "conocida justo en M(C+1)": ({"known_from": boundary}, False, True),           # NO visible el 20
        "vigente hasta M(C+1) inclusive": ({"known_until": boundary}, True, False),
        "reemplazada 1 ns antes": ({"known_until": boundary - eps}, False, False),
    }
    for name, (patch, on_20, on_21) in rows.items():
        v = SalesVersions.from_lines(pd.DataFrame([{**line(1, "2026-10-01", 1, None), **patch}]), tz, "2026-10-31")
        got = (bool(v.visible("2026-10-20").any()), bool(v.visible("2026-10-21").any()))
        assert got == (on_20, on_21), (name, got)


@pytest.mark.parametrize("tz", ["America/Guayaquil", "UTC", "Europe/Madrid", "Asia/Tokyo", "America/Santiago", "America/New_York"])
def test_the_day_window_is_equivalent_to_the_literal_instant_rule(tz):
    """La lectura agrega por ventana en DÍAS (`c_from`, `c_to`): debe coincidir con la regla literal sobre instantes para cualquier
    versión, incluidos los instantes justo en la frontera y alrededor de los cambios de horario."""
    rng = np.random.default_rng(11)
    cuts = pd.date_range("2026-03-01", "2026-04-20")                                  # incluye cambios de hora de varias zonas
    n = 1500
    ds = pd.Timestamp("2026-03-05") + pd.to_timedelta(rng.integers(0, 25, n), unit="D")
    kf = (pd.Timestamp("2026-03-02") + pd.to_timedelta(rng.integers(0, 40 * 24 * 60, n), unit="m")).to_numpy("datetime64[ns]").copy()
    ku = (pd.Series(kf) + pd.to_timedelta(rng.integers(0, 15 * 24 * 60, n), unit="m")).to_numpy("datetime64[ns]").copy()
    ku[rng.random(n) < 0.3] = np.datetime64("NaT")
    for i in range(0, 300):                                                            # frontera exacta, 1 ns antes y 1 ns después
        b = knowledge_boundary(cuts[(i * 7) % len(cuts)], tz)
        (kf if i % 2 else ku)[i] = b + np.timedelta64((-1, 0, 1)[i % 3], "ns")
    c_from, c_to = SalesVersions.window(ds, kf, ku, tz)
    for c in cuts:
        b = knowledge_boundary(c, tz)
        literal = (kf < b) & (ds <= c) & (np.isnat(ku) | (ku >= b))
        in_days = (c_from <= np.datetime64(c, "ns")) & (np.isnat(c_to) | (np.datetime64(c, "ns") <= c_to))
        assert (literal == in_days).all(), (tz, c.date(), int((literal != in_days).sum()))


def test_the_boundary_is_the_local_midnight_expressed_in_utc():
    assert pd.Timestamp(knowledge_boundary("2026-10-30", "America/Guayaquil")) == utc("2026-10-31 05:00")
    assert pd.Timestamp(knowledge_boundary("2026-10-30", "UTC")) == utc("2026-10-31 00:00")
    assert pd.Timestamp(knowledge_boundary("2026-10-30", "Europe/Madrid")) == utc("2026-10-30 23:00")  # CET (UTC+1) desde el 25-oct
    assert pd.Timestamp(knowledge_boundary("2026-07-30", "Europe/Madrid")) == utc("2026-07-30 22:00")  # CEST (UTC+2)
    several = local_midnight_after(pd.to_datetime(["2026-10-01", "2026-10-02"]), "America/Guayaquil")
    assert list(map(pd.Timestamp, several)) == [utc("2026-10-02 05:00"), utc("2026-10-03 05:00")]
    # una zona inválida o ausente cae en la de Ecuador (la que usa el repositorio)
    assert pd.Timestamp(knowledge_boundary("2026-10-30", None)) == utc("2026-10-31 05:00")
    assert pd.Timestamp(knowledge_boundary("2026-10-30", "Nada/Valido")) == utc("2026-10-31 05:00")


def test_backfill_document_is_visible_from_its_business_day():
    """Carga histórica: conocida desde el inicio de su día local; invisible el día anterior, visible desde su fecha."""
    v = versions([line(1, "2026-10-10", 5, None)])
    assert units_at(v, "2026-10-09") == 0.0
    assert units_at(v, "2026-10-10") == 5.0 and units_at(v, "2026-10-31") == 5.0
    assert not v.has_dynamic, "un backfill no depende del corte: la vista as-of coincide con la verdad en todo corte"


def test_incremental_document_loaded_late_is_invisible_before_its_ingestion_and_visible_after():
    """Documento del 1-oct recibido el 5-oct a las 10:00 UTC (05:00 hora local): no existía en los cortes del 1 al 4."""
    v = versions([line(1, "2026-10-01", 3, None), line(2, "2026-10-01", 7, "2026-10-05 10:00")])
    assert [units_at(v, f"2026-10-0{d}") for d in (1, 2, 3, 4, 5, 6)] == [3.0, 3.0, 3.0, 3.0, 10.0, 10.0]
    assert v.has_dynamic
    assert v.difference("2026-10-04") == {"late": 1, "superseded": 0}
    assert v.difference("2026-10-05") == {"late": 0, "superseded": 0}
    assert v.differs_from_truth("2026-10-02") and not v.differs_from_truth("2026-10-20")


def test_a_voided_document_is_counted_before_the_voiding_and_excluded_after():
    """Solo se leen versiones `completed`: la anulación del 6-oct cierra la ventana de la versión vigente (known_until)."""
    v = versions([line(1, "2026-10-01", 3, None), line(2, "2026-10-02", 100, None, known_until="2026-10-06 12:00")])
    assert units_at(v, "2026-10-05") == 103.0, "al corte del 5-oct la factura aún estaba vigente"
    assert units_at(v, "2026-10-06") == 3.0, "el 6-oct (12:00 UTC) ya estaba anulada"
    assert v.difference("2026-10-05") == {"late": 0, "superseded": 1}


def test_a_correction_replaces_the_quantity_from_its_knowledge_date():
    v = versions([line(1, "2026-10-02", 5, None, known_until="2026-10-06 12:00"),
                  line(1, "2026-10-02", 9, "2026-10-06 12:00")])
    assert [units_at(v, d) for d in ("2026-10-02", "2026-10-05", "2026-10-06", "2026-10-31")] == [5.0, 5.0, 9.0, 9.0]


def test_what_happens_after_the_final_cutoff_cannot_change_a_past_view():
    """Reproducibilidad: una corrección posterior al corte final no altera ninguna vista ≤ final ni su huella."""
    today = versions([line(1, "2026-10-02", 5, None)], final="2026-10-10")
    later = versions([line(1, "2026-10-02", 5, None, known_until="2026-11-20 09:00"),     # reemplazada DESPUÉS del corte final
                      line(1, "2026-10-02", 99, "2026-11-20 09:00"),                       # conocida después del corte final
                      line(2, "2026-11-05", 4, None)],                                     # fecha posterior al corte final
                     final="2026-10-10")
    assert len(later) == 1, "las versiones posteriores al corte final se descartan"
    pd.testing.assert_frame_equal(today.sales_cells("2026-10-10"), later.sales_cells("2026-10-10"))
    assert today.digest() == later.digest()


def test_empty_knowledge_windows_are_dropped():
    """Una re-carga backfill que colapsa versiones deja known_until = known_from: esa versión nunca fue visible."""
    v = versions([line(1, "2026-10-02", 5, "2026-10-04 10:00", known_until="2026-10-04 10:00"), line(1, "2026-10-02", 6, "2026-10-04 10:00")])
    assert len(v) == 1 and units_at(v, "2026-10-04") == 6.0


def test_cells_follow_the_definition_of_valid_sales_and_returns():
    """Ventas = invoice/sales_note/other completadas y no devolución; devoluciones = línea marcada o nota de crédito."""
    rows = [
        line(1, "2026-10-01", 2, None, price=10.0, cost=4.0),
        line(2, "2026-10-01", 3, None, price=10.0, doc="sales_note"),
        line(3, "2026-10-01", 5, None, price=10.0, doc="other", cost=6.0),
        line(4, "2026-10-01", 7, None, price=10.0, doc="credit_note", is_return=True),
        line(5, "2026-10-01", 11, None, price=10.0, doc="credit_note"),            # nota de crédito con línea no marcada: devolución, no venta
        line(6, "2026-10-01", 13, None, price=10.0, is_return=True),               # línea marcada como devolución
        line(7, "2026-10-01", 17, None, price=10.0, branch=2),                      # otra sucursal, mismo producto y día
    ]
    v = versions(rows)
    cells = v.sales_cells("2026-10-01")
    assert list(cells.columns) == ["branch_id", "product_id", "ds", "units", "net_amount", "gross_amount", "discount_amount",
                                   "cost_amount", "cost_units", "lines"]
    b1 = cells[cells["branch_id"] == 1].iloc[0]
    assert b1["units"] == 10.0 and b1["lines"] == 3 and b1["net_amount"] == 100.0 and b1["gross_amount"] == 100.0
    assert b1["cost_amount"] == 2 * 4.0 + 5 * 6.0 and b1["cost_units"] == 7.0, "el costo solo suma las líneas que lo traen"
    assert cells[cells["branch_id"] == 2].iloc[0]["units"] == 17.0
    assert np.isnan(cells[cells["branch_id"] == 2].iloc[0]["cost_amount"]), "sin ninguna línea con costo queda desconocido, no cero"
    rets = v.return_cells("2026-10-01")
    assert float(rets["units"].sum()) == 7 + 11 + 13
    stats = v.stats("2026-10-01")
    assert stats["lines"] == 5 and stats["lines_with_cost"] == 2 and str(stats["date_from"]) == "2026-10-01"  # todas menos las marcadas como devolución
    assert str(v.last_sale_date("2026-10-01")) == "2026-10-01" and v.last_sale_date("2026-09-30") is None


def test_the_digest_covers_knowledge_windows_not_only_values():
    """Mismas cifras con distinto conocimiento ⇒ distinta huella; el orden de las filas no importa."""
    backfill = versions([line(1, "2026-10-01", 3, None), line(2, "2026-10-02", 4, None)])
    late = versions([line(1, "2026-10-01", 3, None), line(2, "2026-10-02", 4, "2026-10-09 10:00")])
    reordered = versions([line(2, "2026-10-02", 4, None), line(1, "2026-10-01", 3, None)])
    pd.testing.assert_frame_equal(backfill.sales_cells("2026-10-31"), late.sales_cells("2026-10-31"))
    assert backfill.digest() != late.digest()
    assert backfill.digest() == reordered.digest()
    # la superseded que no cambia la verdad pero sí lo que vieron las ventanas anteriores también cambia la huella
    amended_a = versions([line(1, "2026-10-01", 5, None, known_until="2026-10-08 10:00"), line(1, "2026-10-01", 9, "2026-10-08 10:00")])
    amended_b = versions([line(1, "2026-10-01", 6, None, known_until="2026-10-08 10:00"), line(1, "2026-10-01", 9, "2026-10-08 10:00")])
    pd.testing.assert_frame_equal(amended_a.sales_cells("2026-10-31"), amended_b.sales_cells("2026-10-31"))
    assert amended_a.digest() != amended_b.digest()
    assert amended_a.summary()["superseded_lines"] == 1 and amended_a.summary()["late_lines"] == 1


def test_at_final_restricts_a_read_to_an_earlier_final_cutoff_without_rereading():
    v = versions([line(1, "2026-10-01", 3, None), line(2, "2026-10-05", 4, None),
                  line(3, "2026-10-02", 8, "2026-10-12 10:00")], final="2026-10-20")
    earlier = v.at_final("2026-10-10")
    assert earlier.final == pd.Timestamp("2026-10-10") and len(earlier) == 2, "lo conocido después del 10-oct y lo posterior quedan fuera"
    assert units_at(earlier, "2026-10-10") == units_at(v, "2026-10-10") == 3.0 + 4.0
    assert earlier.at_final("2026-10-10") is earlier
    with pytest.raises(ValueError):
        v.at_final("2026-11-01")


def test_frame_hash_mixes_the_knowledge_digest_and_keeps_unmarked_frames_unchanged():
    df = pd.DataFrame({"a": [1.0, 2.0], "b": ["x", "y"]})
    legacy = hashlib.sha256(pd.util.hash_pandas_object(df.sort_values(["a", "b"], kind="mergesort").reset_index(drop=True),
                                                       index=False).to_numpy().tobytes()).hexdigest()
    assert frame_hash(df) == legacy, "un marco sin marca de conocimiento se hashea como siempre"
    v1 = versions([line(1, "2026-10-01", 3, None)])
    v2 = versions([line(1, "2026-10-01", 3, "2026-10-04 10:00")])
    a, b = with_knowledge(df.copy(), v1), with_knowledge(df.copy(), v2)
    assert frame_hash(a) != legacy and frame_hash(a) != frame_hash(b)
    assert frame_hash(a) == frame_hash(with_knowledge(df.sample(frac=1.0, random_state=1).reset_index(drop=True), v1)), "orden de filas irrelevante"
    assert frame_hash(df, knowledge=v1.digest()) == frame_hash(a), "también se puede pasar de forma explícita"
    empty = df.iloc[:0]
    assert frame_hash(empty) != frame_hash(with_knowledge(empty.copy(), v1))
    assert a.attrs["knowledge_digest"] == v1.digest()
    survived = a[a["a"] > 0].reset_index(drop=True)
    assert survived.attrs.get("knowledge_digest") == v1.digest(), "la marca sobrevive a filtrar y reindexar"


def purchase(sale_id: int, ds: str, qty: float, known_from: str | None, known_until: str | None = None, *, customer: int = 1,
             product: int = 1, branch: int = 1, is_ret: bool = False, currency: str = "USD", line_number: int = 1) -> dict:
    kf = pd.Timestamp(ds) + pd.Timedelta(hours=5) if known_from is None else utc(known_from)
    return {"sale_id": sale_id, "line_number": line_number, "is_ret": is_ret, "customer_id": customer, "product_id": product,
            "branch_id": branch, "ds": pd.Timestamp(ds), "currency": currency, "qty": qty, "net_amount": qty * 10.0,
            "gross_amount": qty * 12.0, "discount_amount": qty * 2.0, "known_from": kf,
            "known_until": pd.NaT if known_until is None else utc(known_until)}


def test_purchase_versions_give_the_customer_core_the_same_visibility_rule():
    v = PurchaseVersions.from_lines(pd.DataFrame([
        purchase(1, "2026-10-01", 2, None),
        purchase(2, "2026-10-01", 3, "2026-10-05 10:00"),                    # compra recibida tarde
        purchase(3, "2026-10-02", 4, None, known_until="2026-10-06 12:00"),  # anulada después
        purchase(4, "2026-10-02", 1, None, is_ret=True),                     # devolución conocida desde su día
        purchase(5, "2026-10-02", 6, None, branch=2),                        # misma relación, otra sucursal, mismo día: se suma
    ]), TZ, "2026-10-31")
    assert float(v.purchase_cells("2026-10-04")["qty"].sum()) == 2 + 4 + 6
    assert float(v.purchase_cells("2026-10-05")["qty"].sum()) == 2 + 3 + 4 + 6
    assert float(v.purchase_cells("2026-10-31")["qty"].sum()) == 2 + 3 + 6
    day2 = v.purchase_cells("2026-10-31")
    assert len(day2[day2["ds"] == "2026-10-02"]) == 1 and list(day2.columns) == [
        "customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount", "gross_amount", "discount_amount"]
    assert float(v.return_cells("2026-10-31")["qty"].sum()) == 1.0 and float(v.return_cells("2026-10-01")["qty"].sum()) == 0.0
    assert v.difference("2026-10-04") == {"late": 1, "superseded": 1}, "la del día 1 aún no se conocía y la anulada después sí"
    assert v.has_dynamic and v.digest() != PurchaseVersions.from_lines(pd.DataFrame([purchase(1, "2026-10-01", 2, None)]), TZ, "2026-10-31").digest()


# ───────────── conocimiento del corte final: ejecución vigente vs reconstrucción histórica ─────────────

def test_a_live_final_view_knows_what_arrived_after_the_midnight_of_the_last_day():
    """Ejecución vigente: la carga nocturna de ayer (recibida a las 03:00 locales del día siguiente) cuenta para el período final; la
    reconstrucción histórica del mismo corte no la conocía. Sin esto, con carga incremental el último día aparecería vacío."""
    rows = [line(1, "2026-10-09", 4, None),
            line(2, "2026-10-10", 6, "2026-10-11 08:00")]                    # recibida el 11-oct a las 03:00 hora local
    historic = versions(rows, final="2026-10-10")
    live = versions(rows, final="2026-10-10", knowledge="2026-10-11")
    assert len(historic) == 1 and not historic.has_dynamic, "histórica: la venta del 10 aún no se conocía al cierre del corte"
    assert units_at(historic, "2026-10-10") == 4.0
    assert float(live.sales_cells("2026-10-10", "2026-10-11")["units"].sum()) == 10.0, "vigente: se conoce hasta hoy"
    assert live.knowledge == pd.Timestamp("2026-10-11") and historic.knowledge == pd.Timestamp("2026-10-10")
    # la vista ESTRICTA de un corte (sin `knowledge`) es la misma en ambas: solo cambia la verdad del corte final
    assert units_at(live, "2026-10-10") == 4.0 and units_at(live, "2026-10-09") == 4.0
    assert live.has_dynamic and live.difference("2026-10-10") == {"late": 1, "superseded": 0}
    assert live.difference("2026-10-09") == {"late": 0, "superseded": 0}, "la venta del 10 es posterior al corte del 9: no es tardía para él"
    assert live.summary()["knowledge_date"] == "2026-10-11" and historic.summary()["knowledge_date"] == "2026-10-10"
    assert live.stats("2026-10-10", "2026-10-11")["lines"] == 2 and historic.stats("2026-10-10")["lines"] == 1
    assert str(live.last_sale_date("2026-10-10", "2026-10-11")) == "2026-10-10"
    assert float(live.return_cells("2026-10-10", "2026-10-11")["units"].sum() if len(live.return_cells("2026-10-10", "2026-10-11")) else 0) == 0.0


def test_a_live_view_applies_a_correction_received_after_the_final_cutoff_but_windows_keep_the_version_in_force():
    """La corrección del 12-oct sobre una venta del 2-oct: la ejecución vigente (hoy 14-oct) usa la corregida para su período final y
    los reales de sus ventanas; el corte del 5-oct sigue viendo la versión que regía ese día. En una reconstrucción histórica del corte
    del 10-oct la corrección no existía."""
    rows = [line(1, "2026-10-02", 5, None, known_until="2026-10-12 12:00"),
            line(1, "2026-10-02", 9, "2026-10-12 12:00")]
    historic = versions(rows, final="2026-10-10")
    live = versions(rows, final="2026-10-10", knowledge="2026-10-14")
    assert len(historic) == 1 and units_at(historic, "2026-10-10") == 5.0
    assert float(live.sales_cells("2026-10-10", "2026-10-14")["units"].sum()) == 9.0
    assert units_at(live, "2026-10-10") == 5.0 and units_at(live, "2026-10-05") == 5.0
    assert live.difference("2026-10-05") == {"late": 1, "superseded": 1}
    assert live.differs_from_truth("2026-10-05") and not live.differs_from_truth("2026-10-12")
    assert live.digest() != historic.digest(), "la huella cubre lo que la ejecución vigente conoció de más"


@pytest.mark.parametrize("tz", ["America/Guayaquil", "Europe/Madrid", "Asia/Tokyo", "America/Santiago"])
def test_the_live_view_matches_the_literal_rule_applied_at_the_knowledge_day(tz):
    """La verdad de una ejecución vigente = la regla literal aplicada al día de conocimiento K a las ventas con fecha ≤ corte final F,
    incluidos los instantes justo en la frontera y los cambios de horario cercanos."""
    rng = np.random.default_rng(23)
    n = 900
    final, know = pd.Timestamp("2026-03-25"), pd.Timestamp("2026-03-29")           # el 29-mar cambia la hora en Europa
    ds = pd.Timestamp("2026-03-02") + pd.to_timedelta(rng.integers(0, 40, n), unit="D")
    kf = (pd.Timestamp("2026-03-01") + pd.to_timedelta(rng.integers(0, 33 * 24 * 60, n), unit="m")).to_numpy("datetime64[ns]").copy()
    ku = (pd.Series(kf) + pd.to_timedelta(rng.integers(0, 6 * 24 * 60, n), unit="m")).to_numpy("datetime64[ns]").copy()
    ku[rng.random(n) < 0.4] = np.datetime64("NaT")
    boundary = knowledge_boundary(know, tz)
    for i in range(0, 240):                                                          # frontera exacta, 1 ns antes y 1 ns después
        (kf if i % 2 else ku)[i] = boundary + np.timedelta64((-1, 0, 1)[i % 3], "ns")
    qty = rng.integers(1, 9, n).astype(float)
    rows = [line(i, str(ds[i].date()), qty[i], str(pd.Timestamp(kf[i])), None if np.isnat(ku[i]) else str(pd.Timestamp(ku[i])))
            for i in range(n)]
    v = SalesVersions.from_lines(pd.DataFrame(rows), tz, final, know)
    literal = (ds <= final) & (kf < boundary) & (np.isnat(ku) | (ku >= boundary))
    want = pd.Series(qty[literal], index=ds[literal]).groupby(level=0).sum()
    got = v.sales_cells(final, know).groupby("ds")["units"].sum()
    assert got.index.equals(want.index) and np.allclose(got.to_numpy(), want.to_numpy()), tz
    assert float(units_at(v, "2026-03-25")) == float(
        pd.Series(qty[(ds <= final) & (kf < knowledge_boundary(final, tz)) & (np.isnat(ku) | (ku >= knowledge_boundary(final, tz)))]).sum()), \
        "la vista estricta del corte final no cambia por conocer más"


def test_knowledge_arguments_are_validated_against_what_was_read():
    v = versions([line(1, "2026-10-01", 3, None)], final="2026-10-10", knowledge="2026-10-12")
    with pytest.raises(ValueError):
        v.visible("2026-10-10", "2026-10-13")
    with pytest.raises(ValueError):
        v.visible("2026-10-13")
    with pytest.raises(ValueError):
        v.at_final("2026-10-10", "2026-10-13")
    assert v.at_final("2026-10-10", "2026-10-12") is v
    assert v.at_final("2026-10-08", "2026-10-11").knowledge == pd.Timestamp("2026-10-11")
    assert v.at_final("2026-10-08").knowledge == pd.Timestamp("2026-10-08"), "por defecto el conocimiento llega hasta el nuevo corte"
    assert v.at_final("2026-10-10", "2026-10-01").knowledge == pd.Timestamp("2026-10-10"), "un conocimiento anterior al corte se sube al corte"
    assert v.at_final("2026-10-08", "2026-10-11") is v.at_final("2026-10-08", "2026-10-11"), "las vistas derivadas se conservan"
    assert v.restrict(np.array([True])).knowledge == v.knowledge


def test_the_digest_depends_on_what_was_known_not_on_the_day_the_run_happens():
    """Dos ejecuciones vigentes de días distintos sin cargas nuevas entre ellas tienen la misma huella de datos; una carga nueva la cambia."""
    rows = [line(1, "2026-10-01", 3, None), line(2, "2026-10-02", 5, "2026-10-05 10:00")]
    a, b, c = (versions(rows, "2026-10-10", knowledge=k) for k in ("2026-10-10", "2026-10-11", "2026-10-20"))
    assert a.digest() == b.digest() == c.digest()
    later = rows + [line(3, "2026-10-09", 7, "2026-10-12 10:00")]               # llega el 12-oct, para una venta del 9
    assert versions(later, "2026-10-10", knowledge="2026-10-11").digest() == a.digest(), "aún no había llegado"
    assert versions(later, "2026-10-10", knowledge="2026-10-13").digest() != a.digest()
    assert versions(later, "2026-10-10").digest() == a.digest(), "una reconstrucción histórica nunca la ve"


def test_purchase_versions_accept_the_same_knowledge_limit():
    rows = [purchase(1, "2026-10-09", 2, None), purchase(2, "2026-10-10", 5, "2026-10-11 08:00"),
            purchase(3, "2026-10-10", 1, "2026-10-11 09:00", is_ret=True)]
    hist = PurchaseVersions.from_lines(pd.DataFrame(rows), TZ, "2026-10-10")
    live = PurchaseVersions.from_lines(pd.DataFrame(rows), TZ, "2026-10-10", "2026-10-11")
    assert float(hist.purchase_cells("2026-10-10")["qty"].sum()) == 2.0 and hist.return_cells("2026-10-10").empty
    assert float(live.purchase_cells("2026-10-10", "2026-10-11")["qty"].sum()) == 7.0
    assert float(live.return_cells("2026-10-10", "2026-10-11")["qty"].sum()) == 1.0
    assert float(live.purchase_cells("2026-10-10")["qty"].sum()) == 2.0 and live.return_cells("2026-10-10").empty
    assert float(live.all_purchase_cells()["qty"].sum()) == 7.0 and live.summary()["knowledge_date"] == "2026-10-11"
    assert live.difference("2026-10-10") == {"late": 2, "superseded": 0}
