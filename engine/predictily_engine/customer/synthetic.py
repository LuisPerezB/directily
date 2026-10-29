"""Generador sintético (FICTICIO) con procesos de compra de verdad conocida para el Customer Core.

NO son datos de ninguna empresa. Cada relación comprador × producto sigue un proceso de riesgo diario
conocido y reproducible (semilla):

    h(t) = clip(λ_ij · m(recencia) · calendario(t) · promoción(j, t) · vivo_i(t) · disponible(b, j, t), 0, 0.95)
    m(r)  = 0.25 + 1.75 · (1 − exp(−(r / τ_ij)²)),   τ_ij = 1 / λ_ij       (reposición: el riesgo crece con la recencia)

Por eso la probabilidad verdadera de comprar al menos una vez en (c, c+H] se puede calcular exactamente
(`true_probability`): 1 − Π_k (1 − h(c+k)). Incluye: compradores que dejan de comprar (churn), quiebres de
stock por sucursal (la compra es imposible), promociones conocidas con anticipación, cambios de precio,
devoluciones, facturas anuladas, ventas anónimas y de «consumidor final» (genérico).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.data import CustomerData

DOW_FACTOR = np.array([1.0, 1.1, 1.1, 1.0, 1.2, 0.8, 0.2])  # lunes..domingo
PAYDAY_DOMS = (1, 15, 16, 30, 31)
GENERIC_CODE = "9999999999999"


@dataclass
class SyntheticWorld:
    """Mundo ficticio generado (ids locales 1-based; el cargador a BD los traduce)."""

    seed: int
    start: date
    n_days: int
    customers: pd.DataFrame        # customer_id, external_code, is_generic, size, churn_day (verdad), home_branch
    products: pd.DataFrame         # product_id, sku, name, unit, category_id, price0
    branches: pd.DataFrame         # branch_id, code, province, canton
    sales: pd.DataFrame            # líneas: document_number, document_type, status, ds, branch_id, customer_id|NaN, product_id, ...
    inventory: pd.DataFrame        # branch_id, product_id, ds, on_hand, reserved
    promotions: pd.DataFrame       # promotion_id, product_id, discount_pct, starts_on, ends_on, known_at
    rel_customer: np.ndarray       # verdad: relaciones (índices 0-based a ids locales = índice+1)
    rel_product: np.ndarray
    lam: np.ndarray                # tasa objetivo diaria de cada relación
    last_after: np.ndarray         # [n_days, n_rels] día de la última compra tras cada día (verdad)
    alive_until: np.ndarray        # por comprador (0-based): último día con riesgo > 0 (n_days + 10**6 = nunca)
    promo_mult: np.ndarray         # [n_products, n_days + pad]
    avail: np.ndarray              # [n_branches, n_products, n_days + pad] bool
    cfg: dict[str, Any] = field(default_factory=dict)
    is_synthetic: bool = True
    label: str = "DATOS FICTICIOS · Customer Core (P2) · verdad conocida"

    PAD = 120  # días extra de calendario/promos/disponibilidad para calcular verdad hacia adelante

    # ── fechas ──
    def day_to_date(self, t: int) -> date:
        return self.start + timedelta(days=int(t))

    def date_to_day(self, d: date) -> int:
        return (pd.Timestamp(d).date() - self.start).days

    # ── verdad conocida ──
    def hazard(self, rel: np.ndarray, t: np.ndarray | int, rec: np.ndarray, assume_available: bool = True,
               include_churn: bool = False) -> np.ndarray:
        """Riesgo diario de compra de las relaciones `rel` el día `t` con recencia `rec` (días desde la última compra)."""
        rel = np.asarray(rel)
        t = np.broadcast_to(np.asarray(t), rel.shape).astype(int)
        tau = 1.0 / self.lam[rel]
        m = 0.25 + 1.75 * (1.0 - np.exp(-((np.maximum(rec, 0) / tau) ** 2)))
        dates = np.datetime64(self.start, "D") + t.astype("timedelta64[D]")
        wd = (dates.astype("datetime64[D]").astype(np.int64) + 3) % 7  # 1970-01-01 fue jueves (lunes = 0)
        dom = (dates - dates.astype("datetime64[M]").astype("datetime64[D]")).astype(np.int64) + 1
        dow = DOW_FACTOR[wd]
        pay = np.where(np.isin(dom, PAYDAY_DOMS), 1.3, 1.0)
        j = self.rel_product[rel]
        ti = np.clip(t, 0, self.promo_mult.shape[1] - 1)
        promo = self.promo_mult[j, ti]
        h = self.lam[rel] * m * dow * pay * promo
        if include_churn:
            h = np.where(t <= self.alive_until[self.rel_customer[rel]], h, 0.0)
        if not assume_available:
            home = self.customers["home_branch"].to_numpy()[self.rel_customer[rel]] - 1
            h = np.where(self.avail[home, j, ti], h, 0.0)
        return np.clip(h, 0.0, 0.95)

    def true_probability(self, rel: np.ndarray, cutoff_t: int, horizon: int, assume_available: bool = True) -> np.ndarray:
        """P(al menos una compra en (cutoff, cutoff+H]) dado el estado (última compra) al cierre del día `cutoff`."""
        rel = np.asarray(rel)
        last = self.last_after[cutoff_t, rel]
        surv = np.ones(len(rel))
        for k in range(1, horizon + 1):
            surv *= 1.0 - self.hazard(rel, cutoff_t + k, (cutoff_t + k - last), assume_available=assume_available)
        return 1.0 - surv

    def relation_ids(self) -> np.ndarray:
        return np.arange(len(self.rel_customer))

    # ── paquete para el motor ──
    def customer_data(self, through: date | None = None, coverage: pd.DataFrame | None = None) -> CustomerData:
        s = self.sales
        if through is not None:
            s = s[s["ds"] <= pd.Timestamp(through)]
        valid = s[(s["status"] == "completed") & (s["document_type"] == "invoice")]
        ident = valid[valid["customer_id"].notna()].copy()
        generic_ids = set(self.customers.loc[self.customers["is_generic"], "customer_id"])
        ident = ident[~ident["customer_id"].isin(generic_ids)]
        ident["customer_id"] = ident["customer_id"].astype(int)
        ident["currency"] = "USD"
        ident["gross_amount"] = ident["quantity"] * ident["unit_price"]
        purchases = ident.groupby(["customer_id", "product_id", "branch_id", "ds", "currency"], as_index=False).agg(
            qty=("quantity", "sum"), net_amount=("net_amount", "sum"), gross_amount=("gross_amount", "sum"),
            discount_amount=("discount", "sum"))
        ret = s[(s["status"] == "completed") & (s["document_type"] == "credit_note") & s["customer_id"].notna()]
        returns = ret.groupby(["customer_id", "product_id", "ds"], as_index=False).agg(qty=("quantity", "sum"))
        returns["customer_id"] = returns["customer_id"].astype(int)
        inv = self.inventory
        if through is not None:
            inv = inv[inv["ds"] <= pd.Timestamp(through)]
        availability = inv.assign(available=(inv["on_hand"] - inv["reserved"]) > 0)[["branch_id", "product_id", "ds", "available"]]
        promos = self.promotions
        promotions = pd.DataFrame({
            "promotion_id": promos["promotion_id"], "discount_pct": promos["discount_pct"], "starts_on": promos["starts_on"],
            "ends_on": promos["ends_on"], "branch_id": np.nan, "product_id": promos["product_id"], "category_id": np.nan,
            "known_at": pd.to_datetime(promos["known_at"]), "deleted_at": pd.NaT})
        revisions = pd.DataFrame({
            "promotion_id": promos["promotion_id"], "revision": 1, "discount_pct": promos["discount_pct"],
            "starts_on": promos["starts_on"], "ends_on": promos["ends_on"], "branch_id": np.nan,
            "product_id": promos["product_id"], "category_id": np.nan, "deleted": False,
            "recorded_at": pd.to_datetime(promos["known_at"])})
        products = self.products.assign(is_active=True)[["product_id", "category_id", "unit", "is_active"]]
        branches = self.branches[["branch_id", "province", "canton"]]
        first = valid["ds"].min().date() if len(valid) else None
        last = valid["ds"].max().date() if len(valid) else None
        anon = valid[valid["customer_id"].isna() | valid["customer_id"].isin(generic_ids)]
        excluded = {"anonymous": {"lines": int(valid["customer_id"].isna().sum())},
                    "generic": {"lines": int(valid["customer_id"].isin(generic_ids).sum())},
                    "units_not_modeled": float(anon["quantity"].sum())}
        return CustomerData(
            purchases=purchases, returns=returns, products=products, branches=branches, availability=availability,
            coverage=coverage if coverage is not None else pd.DataFrame(columns=["entity", "branch_id", "complete_from", "complete_through"]),
            promotions=promotions, promotion_revisions=revisions, sales_first=first, sales_last=last, excluded=excluded,
            tenant={"currency": "USD", "timezone": "America/Guayaquil", "sector": "ferreteria"})


def generate_world(seed: int = 7, n_customers: int = 220, n_products: int = 14, n_days: int = 500, n_branches: int = 2,
                   burn_in: int = 120, churn_share: float = 0.0, start: date = date(2025, 1, 6),
                   anonymous_rate: float = 1.2, generic_rate: float = 1.5, stockouts: bool = True,
                   mean_interval_range: tuple[float, float] = (12.0, 120.0)) -> SyntheticWorld:
    """Genera un mundo ficticio reproducible. `start` cae en lunes por defecto."""
    rng = np.random.default_rng(seed)
    pad = SyntheticWorld.PAD
    total_t = n_days + pad
    # ── catálogo ──
    branches = pd.DataFrame({"branch_id": np.arange(1, n_branches + 1), "code": [f"B{b:02d}" for b in range(1, n_branches + 1)],
                             "province": ["Pichincha", "Guayas", "Azuay", "Manabí"][:n_branches] if n_branches <= 4 else ["Pichincha"] * n_branches,
                             "canton": ["Quito", "Guayaquil", "Cuenca", "Portoviejo"][:n_branches] if n_branches <= 4 else ["Quito"] * n_branches})
    price0 = np.round(rng.lognormal(1.6, 0.7, n_products), 2) + 0.5
    products = pd.DataFrame({"product_id": np.arange(1, n_products + 1), "sku": [f"FICT-{j:03d}" for j in range(1, n_products + 1)],
                             "name": [f"Producto ficticio {j}" for j in range(1, n_products + 1)], "unit": "unidad",
                             "category_id": (np.arange(n_products) % 3) + 1, "price0": price0})
    customers = pd.DataFrame({
        "customer_id": np.arange(1, n_customers + 1), "external_code": [f"CLI-{i:05d}" for i in range(1, n_customers + 1)],
        "is_generic": False, "size": rng.lognormal(0.5, 0.5, n_customers),
        "home_branch": rng.integers(1, n_branches + 1, n_customers)})
    generic_id = n_customers + 1
    customers = pd.concat([customers, pd.DataFrame({
        "customer_id": [generic_id], "external_code": [GENERIC_CODE], "is_generic": [True], "size": [1.0], "home_branch": [1]})],
        ignore_index=True)
    churn_day = np.full(n_customers, n_days + 10 ** 6)
    n_churn = int(round(churn_share * n_customers))
    if n_churn:
        who = rng.choice(n_customers, n_churn, replace=False)
        churn_day[who] = rng.integers(int(n_days * 0.35), int(n_days * 0.8), n_churn)
    customers["churn_day"] = np.concatenate([churn_day, [n_days + 10 ** 6]])

    # ── relaciones y tasas ──
    popularity = rng.uniform(0.5, 1.5, n_products)
    affinity = (rng.random((n_customers, n_products)) < 0.4) * rng.uniform(0.5, 1.5, (n_customers, n_products))
    base = 1.0 / np.exp(rng.uniform(np.log(mean_interval_range[0]), np.log(mean_interval_range[1]), n_customers))
    lam_mat = base[:, None] * popularity[None, :] * affinity
    ci, pj = np.nonzero(lam_mat > 0)
    lam = np.clip(lam_mat[ci, pj], 1.0 / 400, 0.25)
    n_rels = len(ci)

    # ── promociones, precios, disponibilidad ──
    promos = []
    for k in range(max(3, n_products // 3)):
        j = int(rng.integers(0, n_products))
        s = int(rng.integers(30, max(31, n_days - 30)))
        promos.append({"promotion_id": k + 1, "product_id": j + 1, "discount_pct": float(rng.choice([0.10, 0.15, 0.20])),
                       "start_t": s, "end_t": s + 13, "known_t": s - 14})
    promo_mult = np.ones((n_products, total_t))
    promo_disc = np.zeros((n_products, total_t))
    for p in promos:
        promo_mult[p["product_id"] - 1, p["start_t"]:p["end_t"] + 1] = 1.0 + 3.0 * p["discount_pct"]
        promo_disc[p["product_id"] - 1, p["start_t"]:p["end_t"] + 1] = p["discount_pct"]
    promotions = pd.DataFrame({
        "promotion_id": [p["promotion_id"] for p in promos], "product_id": [p["product_id"] for p in promos],
        "discount_pct": [p["discount_pct"] for p in promos],
        "starts_on": [pd.Timestamp(start) + pd.Timedelta(days=p["start_t"]) for p in promos],
        "ends_on": [pd.Timestamp(start) + pd.Timedelta(days=p["end_t"]) for p in promos],
        "known_at": [pd.Timestamp(start) + pd.Timedelta(days=p["known_t"]) for p in promos]})
    price = np.empty((n_products, total_t))
    for j in range(n_products):
        p = price0[j]
        change_days = set(rng.integers(40, n_days, 4).tolist())
        for t in range(total_t):
            if t in change_days:
                p = round(p * float(rng.uniform(0.93, 1.08)), 2)
            price[j, t] = p
    avail = np.ones((n_branches, n_products, total_t), dtype=bool)
    if stockouts:
        for b in range(n_branches):
            for j in range(n_products):
                if rng.random() < 0.5:
                    s = int(rng.integers(0, max(1, n_days - 30)))
                    avail[b, j, s:s + int(rng.integers(6, 26))] = False
    seg_disc = rng.choice([0.0, 0.05, 0.10], n_customers)
    q_scale = rng.uniform(0.5, 3.0, n_products)

    world = SyntheticWorld(
        seed=seed, start=start, n_days=n_days, customers=customers, products=products, branches=branches,
        sales=pd.DataFrame(), inventory=pd.DataFrame(), promotions=promotions, rel_customer=ci, rel_product=pj, lam=lam,
        last_after=np.zeros((n_days, n_rels), dtype=np.int32), alive_until=churn_day,
        promo_mult=promo_mult, avail=avail,
        cfg={"burn_in": burn_in, "churn_share": churn_share, "n_customers": n_customers, "n_products": n_products})

    # ── simulación día a día (con burn-in para que la recencia inicial sea estacionaria) ──
    last = -rng.integers(1, np.maximum(2, (2.0 / lam).astype(int)), n_rels).astype(np.int64)
    events: list[tuple[int, int]] = []  # (t, rel)
    rel_all = np.arange(n_rels)
    home = customers["home_branch"].to_numpy()[ci] - 1
    for t in range(-burn_in, n_days):
        h = world.hazard(rel_all, t, (t - last), assume_available=False, include_churn=True)
        buy = rng.random(n_rels) < h
        idx = np.nonzero(buy)[0]
        if t >= 0:
            for r in idx:
                events.append((t, int(r)))
        last = np.where(buy, t, last)
        if t >= 0:
            world.last_after[t] = last
    ev = pd.DataFrame(events, columns=["t", "rel"]) if events else pd.DataFrame({"t": [], "rel": []}, dtype=int)

    # ── líneas de venta ──
    rows: list[dict[str, Any]] = []
    doc_seq = 0
    base_ts = pd.Timestamp(start)
    ev = ev.sort_values(["t", "rel"], kind="mergesort").reset_index(drop=True)
    for t, grp in ev.groupby("t"):
        by_cust: dict[tuple[int, int], list[int]] = {}
        for r in grp["rel"].to_numpy():
            i, j = int(ci[r]), int(pj[r])
            branch = int(home[r]) if rng.random() < 0.9 else int(rng.integers(0, n_branches))
            if not avail[branch, j, t]:
                branch = int(home[r])  # compró en su sucursal habitual (el riesgo ya impuso disponibilidad allí)
            by_cust.setdefault((i, branch), []).append(j)
        for (i, branch), js in by_cust.items():
            doc_seq += 1
            for line, j in enumerate(js, start=1):
                qty = 1 + int(rng.poisson(customers["size"].iloc[i] * q_scale[j]))
                unit_price = float(price[j, t])
                disc_pct = float(min(seg_disc[i] + promo_disc[j, t], 0.35))
                gross = qty * unit_price
                discount = round(gross * disc_pct, 2)
                rows.append({"document_number": f"{branch + 1:03d}-001-{doc_seq:09d}", "document_type": "invoice",
                             "status": "completed", "ds": base_ts + pd.Timedelta(days=int(t)), "branch_id": branch + 1,
                             "customer_id": i + 1, "product_id": j + 1, "quantity": float(qty), "unit_price": unit_price,
                             "discount": discount, "net_amount": round(gross - discount, 2), "line_number": line})
                if rng.random() < 0.03:  # devolución parcial 1–5 días después (se informa aparte)
                    rows.append({"document_number": f"{branch + 1:03d}-009-{doc_seq:09d}{line}", "document_type": "credit_note",
                                 "status": "completed", "ds": base_ts + pd.Timedelta(days=int(t) + int(rng.integers(1, 6))),
                                 "branch_id": branch + 1, "customer_id": i + 1, "product_id": j + 1, "quantity": 1.0,
                                 "unit_price": unit_price, "discount": 0.0, "net_amount": round(unit_price, 2), "line_number": 1})
                if rng.random() < 0.01:  # factura anulada (no es una compra)
                    rows.append({"document_number": f"{branch + 1:03d}-001-{doc_seq:09d}V{line}", "document_type": "invoice",
                                 "status": "voided", "ds": base_ts + pd.Timedelta(days=int(t)), "branch_id": branch + 1,
                                 "customer_id": i + 1, "product_id": j + 1, "quantity": float(qty), "unit_price": unit_price,
                                 "discount": 0.0, "net_amount": round(gross, 2), "line_number": 1})
    # ventas anónimas (sin comprador) y de consumidor final (genérico)
    for t in range(n_days):
        for j in range(n_products):
            for kind, rate in (("anon", anonymous_rate), ("generic", generic_rate)):
                k = int(rng.poisson(rate * popularity[j] / 4.0))
                for _ in range(k):
                    doc_seq += 1
                    branch = int(rng.integers(0, n_branches))
                    if not avail[branch, j, t]:
                        continue
                    qty = 1 + int(rng.poisson(0.8 * q_scale[j]))
                    unit_price = float(price[j, t])
                    rows.append({"document_number": f"{branch + 1:03d}-002-{doc_seq:09d}", "document_type": "invoice",
                                 "status": "completed", "ds": base_ts + pd.Timedelta(days=int(t)), "branch_id": branch + 1,
                                 "customer_id": (np.nan if kind == "anon" else generic_id), "product_id": j + 1,
                                 "quantity": float(qty), "unit_price": unit_price, "discount": 0.0,
                                 "net_amount": round(qty * unit_price, 2), "line_number": 1})
    sales = pd.DataFrame(rows).sort_values(["ds", "document_number", "line_number"], kind="mergesort").reset_index(drop=True)

    # ── inventario diario por sucursal × producto ──
    dates = pd.date_range(start, periods=n_days, freq="D")
    inv_rows = []
    for b in range(n_branches):
        for j in range(n_products):
            on_hand = np.where(avail[b, j, :n_days], 20.0, 0.0)
            inv_rows.append(pd.DataFrame({"branch_id": b + 1, "product_id": j + 1, "ds": dates, "on_hand": on_hand, "reserved": 0.0}))
    world.sales = sales
    world.inventory = pd.concat(inv_rows, ignore_index=True)
    return world
