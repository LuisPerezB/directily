"""Generador reproducible de datos comerciales FICTICIOS con rasgos de Ecuador.

El proceso simula, por sucursal y producto:
1. Una demanda latente diaria (tendencia, semana, estacionalidad anual, quincenas, fin de
   mes, feriados con traslados, décimos según región, temporada escolar, Navidad,
   promociones y cambios de precio).
2. Una política de inventario (s, S) con plazos de proveedor y retrasos aleatorios, que
   produce quiebres de stock: las ventas observadas = min(demanda, stock) → ventas censuradas.
3. Documentos a nivel línea: facturas numeradas 001-001-000012345, notas de crédito
   (devoluciones), facturas anuladas y reemitidas, clientes por segmento y canal.

La demanda latente se conserva en `true_demand` solo para pruebas del motor; nunca se
exporta como dato de negocio.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path

import numpy as np
import pandas as pd

from predictily_engine.calendar.ecuador import EcuadorCalendar, rest_days
from predictily_engine.synthetic.catalogs import COMPANIES, CompanySpec, ProductSpec

SALES_COLUMNS = [
    "document_number", "document_type", "status", "issued_at", "branch_code", "product_sku",
    "product_name", "category", "quantity", "unit_price", "discount", "tax", "net_amount",
    "unit_cost", "customer_code", "customer_segment", "customer_province", "channel",
    "currency", "line_number",
]
FINAL_CONSUMER = "9999999999999"  # código genérico del SRI para "consumidor final"


@dataclass
class SyntheticCompany:
    spec: CompanySpec
    branches: pd.DataFrame
    products: pd.DataFrame
    sales: pd.DataFrame
    sales_with_errors: pd.DataFrame
    inventory: pd.DataFrame
    lead_times: pd.DataFrame
    suppliers: pd.DataFrame
    promotions: pd.DataFrame
    true_demand: pd.DataFrame  # branch_code, product_sku, ds, demand, sold, on_hand


# ───────────────────────────── demanda latente ─────────────────────────────

def _annual(profile: str, doy: np.ndarray, month: np.ndarray, dom: np.ndarray, region: str) -> np.ndarray:
    two_pi = 2 * math.pi
    if profile == "construction":
        peak = 215 if region == "sierra" else 250  # temporada seca
        return 1 + 0.15 * np.cos(two_pi * (doy - peak) / 365.25)
    if profile == "paint":
        base = 1 + 0.06 * np.cos(two_pi * (doy - 215) / 365.25)
        xmas = ((month == 11) & (dom >= 15)) | ((month == 12) & (dom <= 23))
        return base * np.where(xmas, 1.35, 1.0)
    if profile in ("tools", "power_tools"):
        return np.ones_like(doy, dtype=float)
    if profile == "christmas_lights":
        season = ((month == 11) & (dom >= 10)) | ((month == 12) & (dom <= 24))
        ramp = np.where(month == 11, 25.0, 60.0)
        return np.where(season, ramp, 1.0)
    if profile == "christmas_candy":
        season = ((month == 11) & (dom >= 20)) | ((month == 12) & (dom <= 24))
        return np.where(season, 7.0, 1.0)
    if profile == "christmas_food":
        return np.where((month == 12) & (dom <= 24), 1.6, 1.0)
    if profile == "beverages":
        return 1 + 0.22 * np.cos(two_pi * (doy - 60) / 365.25)  # época calurosa en la Costa
    if profile == "school":
        return np.ones_like(doy, dtype=float)  # se aplica con el calendario escolar regional
    if profile == "staples":
        return 1 + 0.03 * np.cos(two_pi * (doy - 340) / 365.25)
    return 1 + 0.05 * np.cos(two_pi * (doy - 200) / 365.25)


def _event_multiplier(profile: str, cal: pd.DataFrame, holiday_names: pd.Series, closed: tuple[str, ...]) -> np.ndarray:
    m = np.ones(len(cal))
    payday_lift = 1.18 if profile == "staples" else 1.10
    m *= np.where(cal["is_payday_window"] == 1, payday_lift, 1.0)
    m *= np.where(cal["is_month_end"] == 1, 1.04, 1.0)
    m *= np.where(cal["is_decimo_tercero"] == 1, 1.12, 1.0)
    m *= np.where(cal["is_decimo_cuarto"] == 1, 1.09, 1.0)
    m *= np.where(cal["is_pre_holiday"] == 1, 1.12, 1.0)
    m *= np.where(cal["is_post_holiday"] == 1, 0.95, 1.0)
    if profile in ("tools", "power_tools"):
        m *= np.where(cal["is_fathers_day_window"] == 1, 1.7, 1.0)
        m *= np.where(cal["is_christmas_window"] == 1, 1.5, 1.0)
        m *= np.where(cal["is_black_friday_window"] == 1, 1.8 if profile == "power_tools" else 1.3, 1.0)
    if profile == "school":
        m *= np.where(cal["is_school_season"] == 1, 9.0, 1.0)
    if profile in ("beverages",):
        m *= np.where(cal["is_carnival_window"] == 1, 1.25, 1.0)
    elif profile in ("construction", "paint"):
        m *= np.where(cal["is_carnival_window"] == 1, 0.85, 1.0)
    if profile in ("christmas_food", "beverages", "staples"):
        m *= np.where(cal["is_mothers_day_window"] == 1, 1.08, 1.0)
    # Feriados: cerrado en los días definidos; atención reducida en el resto.
    is_closed = holiday_names.isin(closed).to_numpy()
    m *= np.where(cal["is_holiday"] == 1, 0.55, 1.0)
    m[is_closed] = 0.0
    return m


def _price_path(rng: np.random.Generator, p: ProductSpec, n_days: int) -> np.ndarray:
    """Precio de lista diario con incrementos escalonados (inflación + ajustes de proveedor)."""
    price = np.full(n_days, p.base_price)
    day = int(rng.integers(30, 160))
    while day < n_days:
        step = 1 + rng.uniform(0.01, 0.055)
        price[day:] *= step
        day += int(rng.integers(120, 260))
    return np.round(price, 2)


# ───────────────────────────── generador principal ─────────────────────────────

def generate_company(
    spec: CompanySpec | str,
    seed: int = 42,
    n_products: int | None = None,
    start: str | None = None,
    end: str | None = None,
) -> SyntheticCompany:
    """Genera todos los datasets de una empresa ficticia (determinista dada la semilla)."""
    if isinstance(spec, str):
        spec = COMPANIES[spec]
    # Semilla derivada por empresa para que A y B sean independientes y estables.
    rng = np.random.default_rng([seed, sum(map(ord, spec.slug))])
    start_d = date.fromisoformat(start or spec.start)
    end_d = date.fromisoformat(end or spec.end)
    dates = pd.date_range(start_d, end_d, freq="D")
    n = len(dates)
    products = list(spec.products[:n_products] if n_products else spec.products)

    doy = dates.dayofyear.to_numpy()
    month = dates.month.to_numpy()
    dom = dates.day.to_numpy()
    dow = dates.dayofweek.to_numpy()
    years_elapsed = (np.arange(n) / 365.25)

    sales_rows: list[list] = []
    inv_rows: list[tuple] = []
    truth_rows: list[tuple] = []
    seq_inv = {b.code: int(rng.integers(8000, 20000)) for b in spec.branches}
    seq_cn = {b.code: int(rng.integers(300, 900)) for b in spec.branches}

    customers = _customer_pool(rng, spec)
    promo_masks = _promo_masks(spec, dates)

    # Precios y costos por producto (comunes a sucursales).
    price_paths = {p.sku: _price_path(rng, p, n) for p in products}
    cost_paths = {}
    for p in products:
        drift = 1 + np.cumsum(rng.normal(0, 0.0006, n))
        cost_paths[p.sku] = np.round(price_paths[p.sku] * p.cost_ratio * np.clip(drift, 0.9, 1.1), 4)
    product_trend = {p.sku: rng.uniform(-0.08, 0.18) for p in products}

    # Ventas por (sucursal, día) → lista de (producto, unidades)
    daily_items: dict[tuple[str, int], list[tuple[ProductSpec, int]]] = {}

    for b in spec.branches:
        cal = EcuadorCalendar(region=spec.region, canton=b.canton).frame(start_d, end_d)
        hol_names = {}
        for y in range(start_d.year, end_d.year + 1):
            hol_names.update(rest_days(y, b.canton))
        holiday_names = pd.Series([hol_names.get(d.date(), "") for d in dates])
        weekly = np.array(spec.weekly)[dow]
        branch_closed = _random_closures(rng, n)  # p. ej. inventario anual, corte de energía
        for p in products:
            static = b.size * rng.lognormal(0, 0.25)
            annual = _annual(p.profile, doy, month, dom, spec.region)
            events = _event_multiplier(p.profile, cal, holiday_names, spec.closed_holidays)
            trend = (1 + spec.trend_per_year + product_trend[p.sku]) ** years_elapsed
            price = price_paths[p.sku]
            price_effect = (price / price[0]) ** -0.4
            promo_pct, promo_lift = promo_masks(p, b.code)
            lam = p.base_demand * static * weekly * annual * events * trend * price_effect * promo_lift
            lam = lam * branch_closed
            if p.launch:
                lam[dates < pd.Timestamp(p.launch)] = 0.0
                # adopción gradual
                since = np.clip((dates - pd.Timestamp(p.launch)).days.to_numpy(), 0, None)
                lam *= np.where(dates >= pd.Timestamp(p.launch), np.minimum(1.0, 0.3 + since / 45), 1)
            if p.discontinue:
                lam[dates > pd.Timestamp(p.discontinue)] = 0.0
            noise = rng.gamma(shape=5.0, scale=1 / 5.0, size=n)
            demand = rng.poisson(lam * noise)

            sold, on_hand, reserved, in_transit = _simulate_inventory(
                rng, demand, lead_time=p.lead_time, launch_idx=_launch_index(dates, p),
                discontinue_idx=_discontinue_index(dates, p),
                reserve=p.category in ("Cemento y agregados", "Hierro y varillas"),
            )
            cost = cost_paths[p.sku]
            for i in range(n):
                if p.launch and dates[i] < pd.Timestamp(p.launch):
                    continue
                if p.discontinue and dates[i] > pd.Timestamp(p.discontinue) + pd.Timedelta(days=45):
                    continue
                inv_rows.append((dates[i].date().isoformat(), b.code, p.sku, float(on_hand[i]),
                                 float(reserved[i]), float(in_transit[i]), float(cost[i])))
                truth_rows.append((b.code, p.sku, dates[i].date(), int(demand[i]), int(sold[i]), float(on_hand[i])))
                if sold[i] > 0:
                    daily_items.setdefault((b.code, i), []).append((p, int(sold[i])))

    # Documentos de venta
    branch_by_code = {b.code: b for b in spec.branches}
    returns_pool: list[dict] = []
    for (bcode, i), items in sorted(daily_items.items(), key=lambda kv: (kv[0][1], kv[0][0])):
        b = branch_by_code[bcode]
        lines: list[tuple[ProductSpec, int]] = []
        for p, units in items:
            k = 1 + rng.poisson(max(0.0, units / (p.purchase_size * 2.2) - 1))
            k = int(min(k, units))
            split = np.ones(k, dtype=int) + rng.multinomial(units - k, np.ones(k) / k)
            lines.extend((p, int(q)) for q in split)
        order = rng.permutation(len(lines))
        lines = [lines[j] for j in order]
        invoices: list[list[tuple[ProductSpec, int]]] = []
        pos = 0
        while pos < len(lines):
            size = int(rng.choice([1, 2, 3, 4, 5, 6], p=[0.36, 0.28, 0.17, 0.10, 0.05, 0.04]))
            invoices.append(lines[pos:pos + size])
            pos += size
        day = dates[i]
        times = sorted(_random_time(rng, day) for _ in invoices)
        docs = []
        for inv_lines, ts in zip(invoices, times):
            seg = _pick_segment(rng, spec)
            docs.append((ts, inv_lines, seg, "", False))
            if rng.random() < 0.004:  # factura anulada (y reemitida en la siguiente)
                docs.append((ts - timedelta(minutes=int(rng.integers(3, 20))), inv_lines, seg, "anulada", True))
        docs.sort(key=lambda d: d[0])
        for ts, inv_lines, seg, status, _voided in docs:
            seq_inv[bcode] += 1
            number = f"{bcode}-{spec.doc_prefix_point}-{seq_inv[bcode]:09d}"
            seg_code, seg_name, _prob, channel, seg_disc = seg
            cust = FINAL_CONSUMER if seg_code == "CF" else str(rng.choice(customers[seg_code]))
            province = b.province if rng.random() < 0.9 else str(rng.choice(_neighbor_provinces(b.province)))
            for line_no, (p, qty) in enumerate(inv_lines, start=1):
                price = price_paths[p.sku][i]
                promo_pct, _ = promo_masks(p, bcode)
                disc_pct = seg_disc + float(promo_pct[i])
                gross = qty * price
                discount = round(gross * disc_pct, 2)
                net = round(gross - discount, 2)
                tax = round(net * p.iva, 2)
                row = [number, "factura", status, ts.strftime("%Y-%m-%d %H:%M:%S"), bcode, p.sku, p.name,
                       p.category, qty, f"{price:.2f}", f"{discount:.2f}", f"{tax:.2f}", f"{net:.2f}",
                       f"{cost_paths[p.sku][i]:.4f}", cust, seg_name, province, channel, "USD", line_no]
                sales_rows.append(row)
                if status == "" and rng.random() < 0.008:
                    returns_pool.append({"row": row, "i": i, "bcode": bcode, "qty": qty, "price": price,
                                         "disc_pct": disc_pct, "p": p})

    # Notas de crédito (devoluciones) 1–10 días después
    for ret in sorted(returns_pool, key=lambda r: (r["i"], r["bcode"])):
        j = ret["i"] + int(rng.integers(1, 11))
        if j >= n:
            continue
        b = ret["bcode"]
        seq_cn[b] += 1
        qty = int(rng.integers(1, ret["qty"] + 1))
        gross = qty * ret["price"]
        discount = round(gross * ret["disc_pct"], 2)
        net = round(gross - discount, 2)
        src = ret["row"]
        ts = _random_time(rng, dates[j])
        sales_rows.append([
            f"{b}-{spec.credit_point}-{seq_cn[b]:09d}", "nota de crédito", "", ts.strftime("%Y-%m-%d %H:%M:%S"), b,
            src[5], src[6], src[7], qty, f"{ret['price']:.2f}", f"{discount:.2f}", f"{round(net * ret['p'].iva, 2):.2f}",
            f"{net:.2f}", src[13], src[14], src[15], src[16], src[17], "USD", 1,
        ])

    sales = pd.DataFrame(sales_rows, columns=SALES_COLUMNS)
    sales = sales.sort_values(["issued_at", "document_number", "line_number"], kind="stable").reset_index(drop=True)

    branches = pd.DataFrame(
        [(b.code, b.name, b.province, b.canton, b.commercial_zone) for b in spec.branches],
        columns=["code", "name", "province", "canton", "commercial_zone"],
    )
    products_df = pd.DataFrame([
        (p.sku, p.name, p.category, p.unit, "Genérica",
         f"{price_paths[p.sku][-1]:.2f}", f"{cost_paths[p.sku][-1]:.4f}",
         "false" if p.discontinue else "true")
        for p in products
    ], columns=["sku", "name", "category", "unit", "brand", "current_price", "current_cost", "is_active"])
    inventory = pd.DataFrame(inv_rows, columns=["snapshot_date", "branch_code", "product_sku", "on_hand",
                                                "reserved", "in_transit", "unit_cost"])
    lead_rows = []
    for p in products:
        lead_rows.append((p.sku, p.supplier, "", p.lead_time, round(max(1.0, p.lead_time * 0.25), 1)))
    # Sucursal con logística más lenta para productos importados (ejercita la resolución de plazos).
    slow_branch = spec.branches[-1].code
    for p in products:
        if p.lead_time >= 21:
            lead_rows.append((p.sku, p.supplier, slow_branch, p.lead_time + 5, round(p.lead_time * 0.3, 1)))
    lead_times = pd.DataFrame(lead_rows, columns=["product_sku", "supplier_code", "branch_code",
                                                  "lead_time_days", "lead_time_std_days"])
    suppliers = pd.DataFrame([(s.code, s.name, s.country, s.default_lead_time_days) for s in spec.suppliers],
                             columns=["code", "name", "country", "default_lead_time_days"])
    promotions = pd.DataFrame([
        (pr.name, "percentage", pr.discount_pct, pr.starts_on, pr.ends_on, pr.branch_code or "", pr.sku or "",
         pr.category or "")
        for pr in spec.promotions
    ], columns=["name", "promo_type", "discount_pct", "starts_on", "ends_on", "branch_code", "product_sku", "category"])
    true_demand = pd.DataFrame(truth_rows, columns=["branch_code", "product_sku", "ds", "demand", "sold", "on_hand"])
    errors = _sales_with_errors(rng, sales)
    return SyntheticCompany(spec, branches, products_df, sales, errors, inventory, lead_times, suppliers,
                            promotions, true_demand)


# ───────────────────────────── auxiliares ─────────────────────────────

def _launch_index(dates: pd.DatetimeIndex, p: ProductSpec) -> int:
    if not p.launch:
        return 0
    return int(np.searchsorted(dates.values, np.datetime64(p.launch)))


def _discontinue_index(dates: pd.DatetimeIndex, p: ProductSpec) -> int | None:
    if not p.discontinue:
        return None
    return int(np.searchsorted(dates.values, np.datetime64(p.discontinue)))


def _simulate_inventory(rng, demand: np.ndarray, lead_time: int, launch_idx: int,
                        discontinue_idx: int | None, reserve: bool):
    """Política (s, S) basada en ventas recientes (no anticipa picos estacionales)."""
    n = len(demand)
    sold = np.zeros(n, dtype=int)
    on_hand = np.zeros(n)
    reserved = np.zeros(n)
    in_transit = np.zeros(n)
    start_level = max(5.0, demand[launch_idx:launch_idx + 30].mean() * (lead_time + 25)) if n > launch_idx else 5.0
    stock = float(math.ceil(start_level))
    pipeline: list[tuple[int, float]] = []  # (día de llegada, cantidad)
    # Interrupciones de abastecimiento del proveedor (quiebres prolongados).
    disruptions = np.zeros(n, dtype=bool)
    if rng.random() < 0.25:
        s = int(rng.integers(60, max(61, n - 30)))
        disruptions[s:s + int(rng.integers(7, 20))] = True
    for i in range(n):
        arrived = [q for d, q in pipeline if d == i]
        pipeline = [(d, q) for d, q in pipeline if d != i]
        stock += sum(arrived)
        s_i = int(min(demand[i], max(stock, 0)))
        sold[i] = s_i
        stock -= s_i
        on_hand[i] = stock
        if reserve and stock > 10 and rng.random() < 0.06:
            reserved[i] = float(math.floor(stock * rng.uniform(0.05, 0.2)))
        if i < launch_idx or (discontinue_idx is not None and i >= discontinue_idx):
            in_transit[i] = sum(q for _, q in pipeline)
            continue
        lo = max(launch_idx, i - 27)
        avg = sold[lo:i + 1].mean() if i >= lo else 0.0
        avg = max(avg, 0.05)
        s_level = avg * (lead_time + 4)
        S_level = s_level + avg * 21
        position = stock + sum(q for _, q in pipeline)
        if position <= s_level and not disruptions[i]:
            qty = float(math.ceil(S_level - position))
            delay = lead_time + (int(rng.integers(3, 16)) if rng.random() < 0.12 else 0)
            if qty > 0:
                pipeline.append((i + max(1, delay), qty))
        in_transit[i] = sum(q for _, q in pipeline)
    return sold, on_hand, reserved, in_transit


def _random_closures(rng, n: int) -> np.ndarray:
    """Cierres puntuales (inventario anual, cortes de energía): 1 = abierto, 0 = cerrado."""
    open_ = np.ones(n)
    for _ in range(int(rng.integers(1, 4))):
        open_[int(rng.integers(0, n))] = 0.0
    return open_


def _random_time(rng, day: pd.Timestamp) -> datetime:
    hours = np.arange(8, 20)
    weights = np.array([3, 6, 8, 8, 7, 5, 5, 7, 8, 7, 5, 3], dtype=float)
    h = int(rng.choice(hours, p=weights / weights.sum()))
    return datetime(day.year, day.month, day.day, h, int(rng.integers(0, 60)), int(rng.integers(0, 60)))


def _pick_segment(rng, spec: CompanySpec):
    probs = np.array([s[2] for s in spec.segments])
    return spec.segments[int(rng.choice(len(spec.segments), p=probs / probs.sum()))]


def _customer_pool(rng, spec: CompanySpec) -> dict[str, list[str]]:
    pool = {}
    for code, *_ in spec.segments:
        size = 400 if code in ("MO", "TB") else 120
        pool[code] = [f"CLI-{code}-{k:05d}" for k in rng.choice(90000, size=size, replace=False)]
    return pool


def _neighbor_provinces(province: str) -> list[str]:
    neighbors = {
        "Pichincha": ["Imbabura", "Cotopaxi", "Santo Domingo de los Tsáchilas"],
        "Azuay": ["Cañar", "Loja", "Morona Santiago"],
        "Tungurahua": ["Cotopaxi", "Chimborazo", "Pastaza"],
        "Guayas": ["Los Ríos", "Santa Elena", "El Oro"],
        "Manabí": ["Esmeraldas", "Santo Domingo de los Tsáchilas", "Guayas"],
    }
    return neighbors.get(province, [province])


def _promo_masks(spec: CompanySpec, dates: pd.DatetimeIndex):
    cache: dict[tuple[str, str], tuple[np.ndarray, np.ndarray]] = {}

    def masks(p: ProductSpec, branch_code: str):
        key = (p.sku, branch_code)
        if key not in cache:
            pct = np.zeros(len(dates))
            lift = np.ones(len(dates))
            for pr in spec.promotions:
                if pr.sku and pr.sku != p.sku:
                    continue
                if pr.category and pr.category != p.category:
                    continue
                if pr.branch_code and pr.branch_code != branch_code:
                    continue
                active = (dates >= pd.Timestamp(pr.starts_on)) & (dates <= pd.Timestamp(pr.ends_on))
                pct = np.where(active, pr.discount_pct, pct)
                lift = np.where(active, pr.lift, lift)
            cache[key] = (pct, lift)
        return cache[key]

    return masks


def _sales_with_errors(rng, sales: pd.DataFrame) -> pd.DataFrame:
    """Archivo pequeño con problemas reales de calidad para demostrar la validación.

    Contiene líneas de los últimos días (mismos documentos que el archivo principal: al
    reimportarlas no deben duplicarse) y filas alteradas con errores típicos.
    """
    last_days = sales[sales["issued_at"] >= sales["issued_at"].max()[:10]]
    base = last_days[last_days["document_type"] == "factura"].head(260).copy()
    base["_issue"] = ""
    rows = base.to_dict("records")
    bad: list[dict] = []

    def mutate(idx: int, issue: str, **changes):
        r = dict(rows[idx % len(rows)])
        r.update(changes)
        r["_issue"] = issue
        bad.append(r)

    mutate(3, "required", product_sku="", document_number="001-001-999000010")
    mutate(7, "invalid_date", issued_at="2026-13-45 10:00:00", document_number="001-001-999000001")
    mutate(11, "invalid_number", quantity="diez", document_number="001-001-999000002")
    mutate(15, "negative_price", unit_price="-4.50", document_number="001-001-999000003")
    mutate(19, "unknown_branch", branch_code="099", document_number="099-001-000000001")
    mutate(23, "currency_mismatch", currency="EUR", document_number="001-001-999000004")
    mutate(27, "net_amount_mismatch", net_amount="999.99", document_number="001-001-999000005")
    mutate(31, "future_date", issued_at="2027-01-15 09:30:00", document_number="001-001-999000006")
    mutate(35, "negative_quantity", quantity=-2, document_number="001-001-999000007")
    mutate(39, "zero_quantity", quantity=0, document_number="001-001-999000008")
    mutate(43, "unknown_product", product_sku="NO-EXISTE-001", product_name="Producto nuevo sin catálogo",
           document_number="001-001-999000009")
    dup = dict(rows[47 % len(rows)])
    dup["_issue"] = "duplicate_in_file"
    bad.append(dup)
    conflict = dict(rows[51 % len(rows)])
    conflict["quantity"] = int(conflict["quantity"]) + 5
    conflict["_issue"] = "document_conflict"
    bad.append(conflict)
    out = pd.DataFrame(rows + bad)
    # Las primeras filas son válidas (y ya existen); las alteradas van al final.
    return out[SALES_COLUMNS + ["_issue"]].rename(columns={"_issue": "nota_calidad"})


# ───────────────────────────── escritura ─────────────────────────────

def write_company(company: SyntheticCompany, out_dir: Path) -> dict[str, int]:
    out_dir.mkdir(parents=True, exist_ok=True)
    slug = company.spec.slug
    files = {
        f"{slug}_branches.csv": company.branches,
        f"{slug}_products.csv": company.products,
        f"{slug}_sales.csv": company.sales,
        f"{slug}_sales_with_errors.csv": company.sales_with_errors,
        f"{slug}_inventory.csv": company.inventory,
        f"{slug}_lead_times.csv": company.lead_times,
        f"{slug}_suppliers.csv": company.suppliers,
        f"{slug}_promotions.csv": company.promotions,
    }
    counts = {}
    for name, df in files.items():
        df.to_csv(out_dir / name, index=False, encoding="utf-8")
        counts[name] = len(df)
    (out_dir / f"{slug}_README.md").write_text(_readme(company, counts), encoding="utf-8")
    return counts


def _readme(c: SyntheticCompany, counts: dict[str, int]) -> str:
    s = c.sales
    docs = s.drop_duplicates(["document_type", "document_number"])
    td = c.true_demand
    lost = int((td["demand"] - td["sold"]).clip(lower=0).sum())
    stockout_days = int((td["on_hand"] <= 0).sum())
    lines = [
        f"# {c.spec.name}",
        "",
        "> **DATOS FICTICIOS.** Empresa, sucursales, clientes, proveedores y transacciones son",
        "> inventados y generados por `predictily_engine.synthetic` con semilla fija. No",
        "> representan a ninguna empresa real. Úsense solo para demostración y pruebas.",
        "",
        f"- Sector: `{c.spec.sector}` · Región: {c.spec.region} · Moneda: USD",
        f"- Período: {c.spec.start} → {c.spec.end}",
        f"- Sucursales: {len(c.branches)} · Productos: {len(c.products)}",
        f"- Líneas de venta: {len(s):,} · Documentos: {len(docs):,}",
        f"- Facturas anuladas: {int((docs['status'] == 'anulada').sum())} · Notas de crédito: "
        f"{int((docs['document_type'] == 'nota de crédito').sum())}",
        f"- Días producto-sucursal con stock ≤ 0: {stockout_days:,} (ventas censuradas;"
        f" demanda no atendida simulada: {lost:,} unidades)",
        "",
        "## Archivos",
        "",
        "| Archivo | Filas | Tipo de importación |",
        "|---|---|---|",
    ]
    kinds = {
        "branches": "sucursales (alta manual o importación)", "products": "products", "sales": "sales",
        "sales_with_errors": "sales (demostración de validación)", "inventory": "inventory",
        "lead_times": "lead_times", "suppliers": "proveedores", "promotions": "promociones",
    }
    for name, count in counts.items():
        key = name.replace(f"{c.spec.slug}_", "").replace(".csv", "")
        lines.append(f"| `{name}` | {count:,} | {kinds.get(key, key)} |")
    lines += [
        "",
        "## Fenómenos incluidos",
        "",
        "Tendencia, estacionalidad semanal y anual, quincenas y fin de mes, feriados de Ecuador",
        "con traslados (y locales de Quito/Guayaquil), décimo tercer sueldo (diciembre), décimo",
        "cuarto (marzo en Costa, agosto en Sierra), temporada escolar regional, Navidad, Black",
        "Friday, cambios escalonados de precio, promociones con descuento (incluida una futura),",
        "demanda intermitente, un producto lanzado recientemente (historia corta), uno",
        "descontinuado, quiebres de stock con retrasos de proveedor, devoluciones (notas de",
        "crédito, cantidades positivas) y facturas anuladas y reemitidas.",
        "",
        "`*_sales_with_errors.csv` repite líneas reales de los últimos días (reimportarlas no",
        "debe duplicar ventas) y agrega filas con errores típicos; la columna `nota_calidad`",
        "indica el problema introducido y debe ignorarse en el mapeo.",
        "",
        "Regenerar: `cd engine && .venv/bin/python -m predictily_engine.cli generate-demo --out ../data/demo --seed 42`",
    ]
    return "\n".join(lines) + "\n"


def generate_demo(out_dir: Path, seed: int = 42) -> dict[str, dict[str, int]]:
    return {slug: write_company(generate_company(spec, seed), out_dir) for slug, spec in COMPANIES.items()}
