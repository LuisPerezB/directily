"""Variables y candidatos del Customer Core, calculados SOLO con información disponible al corte.

Regla de oro (instrucctions.md §6.A.6): toda característica del corte `c` usa eventos con día ≤ c; las
consultas pasan por `EventIndex.pos(grupo, c)`, que ignora lo posterior por construcción. El calendario
(feriados, quincenas) se conoce de antemano; las promociones futuras solo cuentan si ya se conocían al
corte (`promotions_as_of`, mismo contrato que la demanda).

Candidatos: relaciones comprador × producto con ≥ `min_relation_purchases` compras válidas hasta el corte
y última compra dentro de `candidate_max_recency_days`. Productos nunca comprados por el comprador y
compradores sin historial NO son candidatos (ver docs/architecture/v2/P2-comportamiento.md §2).
"""

from __future__ import annotations

import warnings
from dataclasses import dataclass
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.calendar.ecuador import EcuadorCalendar
from predictily_engine.calendar.regions import region_for_province
from predictily_engine.customer import SUPPORTED_HORIZONS
from predictily_engine.customer.data import CustomerData
from predictily_engine.customer.events import EventIndex, from_day, to_day
from predictily_engine.customer.references import reference_probability, reference_quantity
from predictily_engine.features.builder import promotions_as_of

GAP_K = 8  # últimos intervalos considerados
QTY_K = 5  # últimas compras para cantidades/precios

# Variables independientes del horizonte (orden estable = esquema del artefacto).
BASE_FEATURES: list[str] = [
    # recencia y frecuencia de la relación
    "rel_recency_days", "rel_tenure_days", "rel_n_purchases", "rel_n_30", "rel_n_90", "rel_n_180", "rel_n_365",
    "rel_rate_180", "rel_share_180",
    # distribución de intervalos
    "gap_last", "gap_median", "gap_mean", "gap_std", "gap_min", "gap_max", "gap_n", "gap_mean_all",
    "overdue_ratio", "overdue_ratio_mean",
    # cantidades recientes y tendencia
    "qty_last", "qty_mean5", "qty_median5", "qty_cv5", "qty_per_purchase_all", "qty_sum_90", "qty_sum_180",
    "n_ratio_90_prior", "qty_ratio_90_prior",
    # precio observado, variación y descuentos
    "price_last", "price_change_last", "price_cv5", "price_rel_to_product", "prod_price_trend",
    "disc_rate_last", "disc_rate_rel_180", "prod_disc_30", "days_since_price_change",
    # devoluciones (informativas; no restan de la cantidad comprada)
    "rel_return_days_180", "rel_return_qty_ratio_180",
    # comprador
    "cust_recency_any", "cust_tenure_days", "cust_n_days_30", "cust_n_days_90", "cust_n_days_180", "cust_n_days_365",
    "cust_gap_median", "cust_overdue_ratio", "cust_n_products_180",
    # producto (popularidad)
    "prod_buyers_30", "prod_buyers_90", "prod_buyer_share_90",
    # calendario del corte
    "cal_dow", "cal_dom", "cal_month", "cal_days_to_month_end",
    # disponibilidad en la sucursal habitual
    "avail_now", "unavail_days_30", "avail_share_90",
    # promoción conocida al corte
    "promo_now",
]
# Variables que dependen del horizonte H (se calculan por horizonte).
HORIZON_FEATURES: list[str] = ["cal_holidays_h", "cal_paydays_h", "cal_month_ends_h", "cal_season_days_h",
                               "promo_known_h"]
REFERENCE_COLUMNS: list[str] = ["ref_p", "ref_q"]
SEASON_FLAGS = ["is_decimo_tercero", "is_decimo_cuarto", "is_school_season", "is_mothers_day_window",
                "is_fathers_day_window", "is_black_friday_window", "is_christmas_window", "is_carnival_window"]
# Identificadores y soportes que NO son variables del modelo.
ID_COLUMNS = ["rel", "customer_id", "product_id", "branch_id", "currency_code"]
SUPPORT_COLUMNS = ["last_net_price", "last_list_price", "last_day"]


def model_feature_names(horizon: bool = True) -> list[str]:
    return BASE_FEATURES + (HORIZON_FEATURES if horizon else [])


def _sorted_stats(mat: np.ndarray) -> dict[str, np.ndarray]:
    """Mediana/media/desviación/mín/máx por fila ignorando NaN (sin advertencias de filas vacías)."""
    n = mat.shape[0]
    m = (~np.isnan(mat)).sum(axis=1)
    srt = np.sort(mat, axis=1)  # NaN al final
    rows = np.arange(n)
    has = m > 0
    lo = np.clip((m - 1) // 2, 0, mat.shape[1] - 1)
    hi = np.clip(m // 2, 0, mat.shape[1] - 1)
    median = np.where(has, (srt[rows, lo] + srt[rows, hi]) / 2.0, np.nan)
    total = np.nansum(mat, axis=1)
    mean = np.where(has, total / np.maximum(m, 1), np.nan)
    sq = np.nansum(mat ** 2, axis=1)
    var = np.where(m > 1, (sq - m * mean ** 2) / np.maximum(m - 1, 1), np.nan)
    std = np.sqrt(np.clip(var, 0, None))
    mn = np.where(has, srt[:, 0], np.nan)
    mx = np.where(has, srt[rows, np.clip(m - 1, 0, mat.shape[1] - 1)], np.nan)
    return {"n": m.astype(float), "median": median, "mean": mean, "std": std, "min": mn, "max": mx}


def _safe_div(a, b):
    a = np.asarray(a, dtype=np.float64)
    b = np.asarray(b, dtype=np.float64)
    with np.errstate(divide="ignore", invalid="ignore"):
        out = a / b
    return np.where(np.isfinite(out), out, np.nan)


class _CalendarIndex:
    """Sumas acumuladas de variables de calendario por (región, cantón)."""

    FLAGS = ["is_holiday", "is_payday_window", "is_month_end", *SEASON_FLAGS]

    def __init__(self, day0: int, day1: int, branches: pd.DataFrame) -> None:
        self.day0 = day0
        self.n_days = day1 - day0 + 1
        self.keys: dict[tuple, int] = {}
        self.cum: list[dict[str, np.ndarray]] = []
        self.branch_key: dict[int, int] = {}
        start, end = from_day(day0), from_day(day1)
        for row in branches.itertuples(index=False):
            region = region_for_province(row.province) if isinstance(row.province, str) else None
            canton = row.canton.lower() if isinstance(row.canton, str) else None
            key = (region, canton)
            if key not in self.keys:
                frame = EcuadorCalendar(region=region, canton=canton).frame(start, end)
                cum = {f: np.concatenate(([0], np.cumsum(frame[f].to_numpy(dtype=np.int64)))) for f in self.FLAGS}
                self.keys[key] = len(self.cum)
                self.cum.append(cum)
            self.branch_key[int(row.branch_id)] = self.keys[key]
        if not self.cum:  # sin sucursales: calendario nacional genérico
            frame = EcuadorCalendar().frame(start, end)
            self.cum.append({f: np.concatenate(([0], np.cumsum(frame[f].to_numpy(dtype=np.int64)))) for f in self.FLAGS})
            self.default = 0
        else:
            self.default = 0

    def key_for(self, branch_ids: np.ndarray) -> np.ndarray:
        return np.array([self.branch_key.get(int(b), self.default) for b in branch_ids], dtype=np.int64)

    def window(self, key_idx: np.ndarray, flags: list[str], c: int, h: int) -> np.ndarray:
        """Días con alguno de los indicadores en (c, c+h] (suma de indicadores)."""
        out = np.zeros(len(key_idx))
        i0 = int(np.clip(c - self.day0 + 1, 0, self.n_days))
        i1 = int(np.clip(c + h - self.day0 + 1, 0, self.n_days))
        for k in np.unique(key_idx):
            tot = sum(self.cum[int(k)][f][i1] - self.cum[int(k)][f][i0] for f in flags)
            out[key_idx == k] = tot
        return out


class _AvailabilityIndex:
    """Disponibilidad diaria (existencia − reservas > 0) por sucursal × producto: 1 sí, 0 no, -1 desconocida."""

    def __init__(self, availability: pd.DataFrame, day0: int, day1: int) -> None:
        self.day0 = day0
        self.n_days = day1 - day0 + 1
        self.pair: dict[tuple[int, int], int] = {}
        if availability is None or availability.empty:
            self.state = np.zeros((0, self.n_days), dtype=np.int8)
            self.cum_av = np.zeros((0, self.n_days + 1), dtype=np.int32)
            self.cum_kn = np.zeros((0, self.n_days + 1), dtype=np.int32)
            return
        av = availability[["branch_id", "product_id", "ds", "available"]].copy()
        av["day"] = to_day(av["ds"])
        av = av[(av["day"] >= day0) & (av["day"] <= day1)]
        codes, uniques = pd.MultiIndex.from_arrays([av["branch_id"].astype(int), av["product_id"].astype(int)]).factorize()
        self.pair = {(int(b), int(p)): i for i, (b, p) in enumerate(uniques)}
        state = np.full((len(uniques), self.n_days), -1, dtype=np.int8)
        state[codes, (av["day"].to_numpy() - day0)] = av["available"].astype(int).to_numpy().astype(np.int8)
        self.state = state
        self.cum_av = np.concatenate([np.zeros((len(uniques), 1), np.int32), np.cumsum(state == 1, axis=1, dtype=np.int32)], axis=1)
        self.cum_kn = np.concatenate([np.zeros((len(uniques), 1), np.int32), np.cumsum(state >= 0, axis=1, dtype=np.int32)], axis=1)

    def lookup(self, branch_ids: np.ndarray, product_ids: np.ndarray) -> np.ndarray:
        if not self.pair:
            return np.full(len(branch_ids), -1, dtype=np.int64)
        return np.array([self.pair.get((int(b), int(p)), -1) for b, p in zip(branch_ids, product_ids)], dtype=np.int64)

    def window(self, idx: np.ndarray, d_lo: int, d_hi: int) -> tuple[np.ndarray, np.ndarray]:
        """(días disponibles, días con inventario conocido) en (d_lo, d_hi]."""
        n = len(idx)
        if not self.pair:
            return np.zeros(n), np.zeros(n)
        i0 = int(np.clip(d_lo - self.day0 + 1, 0, self.n_days))
        i1 = int(np.clip(d_hi - self.day0 + 1, 0, self.n_days))
        ok = idx >= 0
        safe = np.where(ok, idx, 0)
        av = np.where(ok, self.cum_av[safe, i1] - self.cum_av[safe, i0], 0).astype(float)
        kn = np.where(ok, self.cum_kn[safe, i1] - self.cum_kn[safe, i0], 0).astype(float)
        return av, kn

    def state_on(self, idx: np.ndarray, day: int) -> np.ndarray:
        """Estado el día `day`: 1/0, NaN si desconocido."""
        n = len(idx)
        out = np.full(n, np.nan)
        if not self.pair or not (self.day0 <= day < self.day0 + self.n_days):
            return out
        ok = idx >= 0
        st = np.where(ok, self.state[np.where(ok, idx, 0), day - self.day0], -1)
        out[st >= 0] = st[st >= 0].astype(float)
        return out


@dataclass
class CutoffFrame:
    """Candidatos y variables de un corte. `base` + variables por horizonte."""

    cutoff: int
    base: pd.DataFrame
    horizon: dict[int, pd.DataFrame]


class FeatureBuilder:
    """Construye candidatos, variables y etiquetas a partir de un `CustomerData`."""

    def __init__(self, data: CustomerData, cfg: dict[str, Any], max_horizon: int = 90, last_day: int | None = None,
                 extend_to: int | None = None) -> None:
        self.cfg = cfg
        self.data = data
        p = data.purchases
        self.empty = p is None or len(p) == 0
        self.n_rels = 0
        if self.empty:
            return
        p = p.copy()
        p["day"] = to_day(p["ds"])
        key = ["customer_id", "product_id", "day"]
        agg = p.groupby(key, sort=False).agg(qty=("qty", "sum"), net=("net_amount", "sum"), gross=("gross_amount", "sum"),
                                              disc=("discount_amount", "sum")).reset_index()
        main = p.sort_values("qty", ascending=False, kind="mergesort").drop_duplicates(key)[key + ["branch_id", "currency"]]
        ev = agg.merge(main, on=key, how="left")
        cur_codes, cur_uniques = pd.factorize(ev["currency"].fillna("USD"))
        self.currencies: list[str] = [str(c) for c in cur_uniques]
        ev["cur"] = cur_codes
        rel_codes, rel_uniques = pd.MultiIndex.from_arrays([ev["customer_id"].astype(np.int64), ev["product_id"].astype(np.int64)]).factorize()
        self.n_rels = len(rel_uniques)
        self.rel_customer = np.asarray(rel_uniques.get_level_values(0), dtype=np.int64)
        self.rel_product = np.asarray(rel_uniques.get_level_values(1), dtype=np.int64)
        cust_codes, cust_uniques = pd.factorize(self.rel_customer)
        self.rel_ccode = cust_codes.astype(np.int64)
        self.n_cust = len(cust_uniques)
        prod_codes, prod_uniques = pd.factorize(self.rel_product)
        self.rel_pcode = prod_codes.astype(np.int64)
        self.n_prod = len(prod_uniques)
        self.rel_index = rel_uniques  # para mapear devoluciones

        qty = ev["qty"].to_numpy(dtype=float)
        net = ev["net"].to_numpy(dtype=float)
        gross = ev["gross"].to_numpy(dtype=float)
        self.ev = EventIndex(
            rel_codes, ev["day"].to_numpy(), self.n_rels, qty=qty, net=net, gross=gross, disc=ev["disc"].to_numpy(dtype=float),
            branch=ev["branch_id"].fillna(-1).to_numpy(dtype=float), cur=ev["cur"].to_numpy(dtype=float),
            price=_safe_div(net, qty), listp=_safe_div(gross, qty))
        # días de compra por comprador (cualquier producto)
        cd = ev.groupby(["customer_id", "day"], as_index=False).agg(net=("net", "sum"))
        ccodes = pd.Index(cust_uniques).get_indexer(cd["customer_id"].astype(np.int64))
        self.cstore = EventIndex(ccodes, cd["day"].to_numpy(), self.n_cust, net=cd["net"].to_numpy(dtype=float))
        self.cust_ids = np.asarray(cust_uniques, dtype=np.int64)  # código interno → customer_id
        # ventas por producto-día (todos los compradores modelados)
        pd_ = ev.assign(pcode=pd.Index(prod_uniques).get_indexer(ev["product_id"].astype(np.int64)))
        pagg = pd_.groupby(["pcode", "day"], sort=False).agg(qty=("qty", "sum"), net=("net", "sum"), gross=("gross", "sum"),
                                                             disc=("disc", "sum")).reset_index()
        self.pstore = EventIndex(pagg["pcode"].to_numpy(), pagg["day"].to_numpy(), self.n_prod,
                                 qty=pagg["qty"].to_numpy(), net=pagg["net"].to_numpy(), gross=pagg["gross"].to_numpy(),
                                 disc=pagg["disc"].to_numpy())
        self._price_changes()
        # devoluciones (aparte)
        r = data.returns
        if r is not None and len(r):
            r = r.copy()
            r["day"] = to_day(r["ds"])
            ridx = self.rel_index.get_indexer(pd.MultiIndex.from_arrays([r["customer_id"].astype(np.int64), r["product_id"].astype(np.int64)]))
            keep = ridx >= 0
            self.rev = EventIndex(ridx[keep], r["day"].to_numpy()[keep], self.n_rels, qty=r["qty"].to_numpy(dtype=float)[keep])
        else:
            self.rev = EventIndex(np.zeros(0), np.zeros(0), self.n_rels, qty=np.zeros(0))
        # primera/última fecha para calendarios e inventario
        self.day_min = int(self.ev.day.min())
        self.day_max = int(self.ev.day.max())
        last = to_day(data.last_complete_day()) if data.last_complete_day() is not None else self.day_max
        self.last_complete = int(last_day if last_day is not None else last)
        hi_day = max(self.day_max, self.last_complete, extend_to or 0) + max(max_horizon, 90) + 30
        lo_day = self.day_min - 400
        self.calendar = _CalendarIndex(max(lo_day, 0), hi_day, data.branches)
        self.avail = _AvailabilityIndex(data.availability, max(lo_day, 0), hi_day)
        products = data.products.set_index("product_id") if data.products is not None and len(data.products) else pd.DataFrame()
        self.product_category = (products["category_id"].to_dict() if len(products) and "category_id" in products else {})
        self._promo_cache: dict[int, pd.DataFrame] = {}

    # ── cambios de precio de lista observados (derivados de las ventas, solo pasado) ──
    def _price_changes(self) -> None:
        ps = self.pstore
        lp = _safe_div(ps.values["gross"], ps.values["qty"])
        prev = np.concatenate(([np.nan], lp[:-1]))
        same = np.concatenate(([False], ps.group[1:] == ps.group[:-1]))
        with np.errstate(invalid="ignore"):
            change = same & (np.abs(_safe_div(lp, prev) - 1.0) > 0.02)
        marked = np.where(change, ps.day.astype(float), -1.0)
        # máximo acumulado dentro de cada producto
        out = np.full(len(marked), -1.0)
        for g in range(self.n_prod):
            s, e = ps.start[g], ps.end[g]
            if e > s:
                out[s:e] = np.maximum.accumulate(marked[s:e])
        self.last_change = out

    # ── candidatos ──
    def candidate_rels(self, c: int) -> tuple[np.ndarray, np.ndarray]:
        """(relaciones candidatas al corte c, posición `hi` de cada una). Solo usa eventos ≤ c."""
        if self.empty:
            return np.zeros(0, dtype=np.int64), np.zeros(0, dtype=np.int64)
        rels = np.arange(self.n_rels)
        hi_all = self.ev.pos(rels, c)
        n_all = hi_all - self.ev.start
        last_day = np.where(n_all > 0, self.ev.day[np.clip(hi_all - 1, 0, self.ev.n - 1)], -10 ** 9)
        mask = (n_all >= int(self.cfg["min_relation_purchases"])) & ((c - last_day) <= int(self.cfg["candidate_max_recency_days"]))
        R = np.nonzero(mask)[0]
        cap = int(self.cfg["max_candidates_per_customer"])
        if len(R):
            # tope por comprador: se conservan las relaciones con compra más reciente
            order = np.lexsort((-last_day[R], self.rel_ccode[R]))
            Rs = R[order]
            cc = self.rel_ccode[Rs]
            first = np.concatenate(([0], np.nonzero(np.diff(cc))[0] + 1))
            rank = np.arange(len(Rs)) - np.repeat(first, np.diff(np.concatenate((first, [len(Rs)]))))
            R = np.sort(Rs[rank < cap])
        return R, hi_all[R]

    def eligibility_counts(self, c: int) -> dict[str, int]:
        """Conteos del corte para documentar exclusiones (no altera candidatos)."""
        if self.empty:
            return {"relations_with_history": 0, "candidates": 0, "excluded_stale": 0, "customers": 0}
        rels = np.arange(self.n_rels)
        hi_all = self.ev.pos(rels, c)
        n_all = hi_all - self.ev.start
        has = n_all > 0
        R, _ = self.candidate_rels(c)
        return {"relations_with_history": int(has.sum()), "candidates": int(len(R)),
                "excluded_stale": int(has.sum() - len(R)), "customers": int(len(np.unique(self.rel_ccode[R])))}

    # ── variables ──
    def compute(self, c: int, horizons: tuple[int, ...] = SUPPORTED_HORIZONS) -> CutoffFrame:
        R, hi = self.candidate_rels(c)
        n = len(R)
        if n == 0:
            return CutoffFrame(c, pd.DataFrame(columns=ID_COLUMNS + BASE_FEATURES + SUPPORT_COLUMNS),
                               {h: pd.DataFrame(columns=HORIZON_FEATURES + REFERENCE_COLUMNS) for h in horizons})
        ev, cs, ps = self.ev, self.cstore, self.pstore
        st = ev.start[R]
        last_idx = hi - 1
        last_day = ev.day[last_idx]
        first_day = ev.day[st]
        n_all = (hi - st).astype(float)
        recency = (c - last_day).astype(float)
        tenure = (c - first_day).astype(float)
        feats: dict[str, np.ndarray] = {"rel_recency_days": recency, "rel_tenure_days": tenure, "rel_n_purchases": n_all}
        pos_w = {w: ev.pos(R, c - w) for w in (30, 90, 180, 365)}
        for w, name in ((30, "rel_n_30"), (90, "rel_n_90"), (180, "rel_n_180"), (365, "rel_n_365")):
            feats[name] = (hi - pos_w[w]).astype(float)
        exposure = np.minimum(180.0, tenure + 1.0)
        feats["rel_rate_180"] = feats["rel_n_180"] / exposure

        cq = ev.cum["qty"]
        qty_all = cq[hi] - cq[st]
        feats["qty_per_purchase_all"] = _safe_div(qty_all, n_all)
        feats["qty_sum_90"] = cq[hi] - cq[pos_w[90]]
        feats["qty_sum_180"] = cq[hi] - cq[pos_w[180]]
        pos_p = ev.pos(R, c - 180)  # ventana previa (c-180, c-90]
        n_prior = (pos_w[90] - pos_p).astype(float)
        q_prior = cq[pos_w[90]] - cq[pos_p]
        feats["n_ratio_90_prior"] = (feats["rel_n_90"] + 0.5) / (n_prior + 0.5)
        feats["qty_ratio_90_prior"] = (feats["qty_sum_90"] + 1.0) / (q_prior + 1.0)

        # intervalos entre compras
        G = ev.last_gaps(hi, R, GAP_K)
        gs = _sorted_stats(G)
        feats["gap_last"] = G[:, 0]
        feats["gap_median"], feats["gap_mean"], feats["gap_std"] = gs["median"], gs["mean"], gs["std"]
        feats["gap_min"], feats["gap_max"], feats["gap_n"] = gs["min"], gs["max"], gs["n"]
        gn = ev.cum_gapn[hi] - ev.cum_gapn[st]
        feats["gap_mean_all"] = _safe_div(ev.cum_gap[hi] - ev.cum_gap[st], gn)
        feats["overdue_ratio"] = _safe_div(recency, gs["median"])
        feats["overdue_ratio_mean"] = _safe_div(recency, feats["gap_mean_all"])

        # cantidades y precios de las últimas compras
        Q = ev.last_values("qty", hi, R, QTY_K)
        qs = _sorted_stats(Q)
        feats["qty_last"], feats["qty_mean5"], feats["qty_median5"] = Q[:, 0], qs["mean"], qs["median"]
        feats["qty_cv5"] = _safe_div(qs["std"], qs["mean"])
        P5 = ev.last_values("price", hi, R, QTY_K)
        ps5 = _sorted_stats(P5)
        feats["price_last"] = P5[:, 0]
        feats["price_change_last"] = _safe_div(P5[:, 0], P5[:, 1]) - 1.0
        feats["price_cv5"] = _safe_div(ps5["std"], ps5["mean"])
        gross_l = ev.values["gross"][last_idx]
        feats["disc_rate_last"] = _safe_div(ev.values["disc"][last_idx], gross_l)
        cg, cd = ev.cum["gross"], ev.cum["disc"]
        feats["disc_rate_rel_180"] = _safe_div(cd[hi] - cd[pos_w[180]], cg[hi] - cg[pos_w[180]])

        # producto
        pc = self.rel_pcode[R]
        phi = ps.pos(pc, c)
        pq, pn = ps.cum["qty"], ps.cum["net"]
        p90, p7, p30 = ps.pos(pc, c - 90), ps.pos(pc, c - 7), ps.pos(pc, c - 30)
        price90 = _safe_div(pn[phi] - pn[p90], pq[phi] - pq[p90])
        price7 = _safe_div(pn[phi] - pn[p7], pq[phi] - pq[p7])
        feats["price_rel_to_product"] = _safe_div(feats["price_last"], price90)
        feats["prod_price_trend"] = _safe_div(price7, price90) - 1.0
        pg, pdc = ps.cum["gross"], ps.cum["disc"]
        feats["prod_disc_30"] = _safe_div(pdc[phi] - pdc[p30], pg[phi] - pg[p30])
        lc_idx = np.clip(phi - 1, 0, max(ps.n - 1, 0))
        lc = np.where((phi - 1) >= ps.start[pc], self.last_change[lc_idx], -1.0)
        feats["days_since_price_change"] = np.where(lc >= 0, c - lc, np.nan)
        n90_pos = feats["rel_n_90"] > 0
        feats["prod_buyers_90"] = np.bincount(pc[n90_pos], minlength=self.n_prod)[pc].astype(float)
        feats["prod_buyers_30"] = np.bincount(pc[feats["rel_n_30"] > 0], minlength=self.n_prod)[pc].astype(float)
        cc = self.rel_ccode[R]
        active_cust_90 = max(int(np.unique(cc[n90_pos]).size), 1)
        feats["prod_buyer_share_90"] = feats["prod_buyers_90"] / active_cust_90

        # comprador
        chi = cs.pos(cc, c)
        cstart = cs.start[cc]
        feats["cust_recency_any"] = (c - cs.day[chi - 1]).astype(float)
        feats["cust_tenure_days"] = (c - cs.day[cstart]).astype(float)
        for w in (30, 90, 180, 365):
            feats[f"cust_n_days_{w}"] = (chi - cs.pos(cc, c - w)).astype(float)
        CG = cs.last_gaps(chi, cc, GAP_K)
        cgs = _sorted_stats(CG)
        feats["cust_gap_median"] = cgs["median"]
        feats["cust_overdue_ratio"] = _safe_div(feats["cust_recency_any"], cgs["median"])
        in180 = feats["rel_n_180"] > 0
        feats["cust_n_products_180"] = np.bincount(cc[in180], minlength=self.n_cust)[cc].astype(float)
        cust_events_180 = np.bincount(cc, weights=feats["rel_n_180"], minlength=self.n_cust)[cc]
        feats["rel_share_180"] = _safe_div(feats["rel_n_180"], cust_events_180)

        # devoluciones (informativas)
        rn = self.rev.count(R, c - 180, c).astype(float)
        feats["rel_return_days_180"] = rn
        feats["rel_return_qty_ratio_180"] = _safe_div(self.rev.total("qty", R, c - 180, c), feats["qty_sum_180"])

        # sucursal habitual = sucursal de la última compra de la relación al corte
        branch = ev.values["branch"][last_idx].astype(np.int64)
        prod_ids = self.rel_product[R]
        aidx = self.avail.lookup(branch, prod_ids)
        feats["avail_now"] = self.avail.state_on(aidx, c)
        av30, kn30 = self.avail.window(aidx, c - 30, c)
        feats["unavail_days_30"] = np.where(kn30 > 0, kn30 - av30, np.nan)
        av90, kn90 = self.avail.window(aidx, c - 90, c)
        feats["avail_share_90"] = np.where(kn90 > 0, av90 / np.maximum(kn90, 1), np.nan)

        # calendario del corte
        d = from_day(c)
        feats["cal_dow"] = np.full(n, float(d.weekday()))
        feats["cal_dom"] = np.full(n, float(d.day))
        feats["cal_month"] = np.full(n, float(d.month))
        dim = pd.Timestamp(d).days_in_month
        feats["cal_days_to_month_end"] = np.full(n, float(dim - d.day))

        # promociones conocidas al corte
        promos = self._promos_as_of(c)
        cat = np.array([self.product_category.get(int(p), -1) for p in prod_ids], dtype=np.int64)
        feats["promo_now"] = self._promo_discount(promos, prod_ids, cat, branch, c, c)

        cur = ev.values["cur"][last_idx].astype(np.int64)
        base = pd.DataFrame({
            "rel": R.astype(np.int64), "customer_id": self.rel_customer[R], "product_id": prod_ids,
            "branch_id": branch, "currency_code": cur,
        })
        for name in BASE_FEATURES:
            base[name] = feats[name].astype(np.float32)
        base["last_net_price"] = ev.values["price"][last_idx]
        base["last_list_price"] = ev.values["listp"][last_idx]
        base["last_day"] = last_day.astype(np.int64)

        key_idx = self.calendar.key_for(branch)
        hfr: dict[int, pd.DataFrame] = {}
        for h in horizons:
            hf = pd.DataFrame({
                "cal_holidays_h": self.calendar.window(key_idx, ["is_holiday"], c, h),
                "cal_paydays_h": self.calendar.window(key_idx, ["is_payday_window"], c, h),
                "cal_month_ends_h": self.calendar.window(key_idx, ["is_month_end"], c, h),
                "cal_season_days_h": self.calendar.window(key_idx, SEASON_FLAGS, c, h),
                "promo_known_h": self._promo_discount(promos, prod_ids, cat, branch, c + 1, c + h),
            }).astype(np.float32)
            # referencias (no son variables del modelo)
            hf["ref_p"] = reference_probability(recency, tenure, n_all, G, h)
            hf["ref_q"] = reference_quantity(feats["qty_mean5"], feats["rel_rate_180"], h)
            hfr[h] = hf
        return CutoffFrame(c, base, hfr)

    # ── promociones as-of ──
    def _promos_as_of(self, c: int) -> pd.DataFrame:
        if c not in self._promo_cache:
            promos = self.data.promotions
            if promos is None or len(promos) == 0:
                self._promo_cache[c] = pd.DataFrame()
            else:
                self._promo_cache[c] = promotions_as_of(promos, self.data.promotion_revisions, pd.Timestamp(from_day(c)))
        return self._promo_cache[c]

    @staticmethod
    def _promo_discount(promos: pd.DataFrame, prod: np.ndarray, cat: np.ndarray, branch: np.ndarray, lo: int, hi: int) -> np.ndarray:
        """Máximo descuento de promociones conocidas que se solapan con [lo, hi] y aplican al producto/sucursal."""
        out = np.zeros(len(prod))
        if promos is None or len(promos) == 0:
            return out
        for pr in promos.itertuples(index=False):
            if pd.isna(pr.discount_pct):
                continue
            s, e = to_day(pr.starts_on), to_day(pr.ends_on)
            if e < lo or s > hi:
                continue
            m = np.ones(len(prod), dtype=bool)
            if not pd.isna(pr.product_id):
                m &= prod == int(pr.product_id)
            if not pd.isna(pr.category_id):
                m &= cat == int(pr.category_id)
            if not pd.isna(pr.branch_id):
                m &= branch == int(pr.branch_id)
            out[m] = np.maximum(out[m], float(pr.discount_pct))
        return out

    # ── etiquetas ──
    def labels(self, frame: pd.DataFrame, c: int, h: int, last_complete: int) -> pd.DataFrame:
        """Etiquetas de la ventana (c, c+h] para los candidatos de `frame` (mismas filas, mismo orden).

        status: 1 válida · 0 inmadura (c+h > último día completo) · 2 censurada (disponibilidad insuficiente
        en la sucursal habitual). Solo las filas con status 1 tienen `y` (0/1); el resto queda NaN.
        La cantidad es la SUMA de todas las compras válidas de la ventana; las devoluciones van aparte.
        """
        R = frame["rel"].to_numpy()
        hi = self.ev.pos(R, c)
        hi_h = self.ev.pos(R, c + h)
        n_pd = (hi_h - hi).astype(float)
        cq, cn = self.ev.cum["qty"], self.ev.cum["net"]
        qty = cq[hi_h] - cq[hi]
        net = cn[hi_h] - cn[hi]
        ret = self.rev.total("qty", R, c, c + h)
        status = np.ones(len(R), dtype=np.int8)
        known_share = np.full(len(R), np.nan)
        avail_share = np.full(len(R), np.nan)
        if c + h > last_complete:
            status[:] = 0
        else:
            aidx = self.avail.lookup(frame["branch_id"].to_numpy(), frame["product_id"].to_numpy())
            av, kn = self.avail.window(aidx, c, c + h)
            known_share = kn / h
            enough = known_share >= float(self.cfg["min_availability_known_share"])
            avail_share = np.where(enough, av / np.maximum(kn, 1), np.nan)
            cens = enough & (avail_share < float(self.cfg["min_availability_share"]))
            status[cens] = 2
        valid = status == 1
        y = np.where(valid, (n_pd > 0).astype(float), np.nan)
        return pd.DataFrame({
            "status": status, "y": y, "purchase_days": np.where(valid, n_pd, np.nan),
            "qty": np.where(valid, qty, np.nan), "net": np.where(valid, net, np.nan),
            "returned_qty": np.where(valid, ret, np.nan), "avail_share": avail_share,
        })
