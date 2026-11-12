"""Cargadores de las bases públicas para el banco de pruebas de factores (los datos no se versionan; ver README)."""

from __future__ import annotations

import numpy as np
import pandas as pd

import harness
from harness import Arm, Dataset


def _cutoffs(last: str, n: int) -> list[pd.Timestamp]:
    end = pd.Timestamp(last)
    return [end - pd.Timedelta(days=28 * k) for k in range(n - 1, -1, -1)]


# ---------------------------------------------------------------- Favorita (Ecuador): producto × tienda, diario
FAV_BRANCHES = pd.DataFrame(
    {
        "branch_id": [1, 2, 3, 4],
        "canton": ["Puyo", "Cuenca", "Quito", "Guayaquil"],
        "province": ["Pastaza", "Azuay", "Pichincha", "Guayas"],
        "station": ["84179099999", "84239099999", "84072599999", "84203099999"],
    }
)


def _noaa(directory: str, station: str, start, end) -> pd.DataFrame:
    """Clima diario de una estación (NOAA GSOD): temperatura media (°C) y lluvia (mm). Faltantes: temperatura interpolada, lluvia NaN."""
    parts = []
    for year in range(start.year, end.year + 1):
        f = pd.read_csv(f"{directory}/{station}_{year}.csv", usecols=["DATE", "TEMP", "PRCP"])
        parts.append(f)
    w = pd.concat(parts)
    w["ds"] = pd.to_datetime(w["DATE"])
    w["temp_c"] = (pd.to_numeric(w["TEMP"], errors="coerce").where(lambda s: s < 900) - 32) * 5 / 9
    w["prcp_mm"] = pd.to_numeric(w["PRCP"], errors="coerce").where(lambda s: s < 90) * 25.4
    w = w.set_index("ds")[["temp_c", "prcp_mm"]].reindex(pd.date_range(start, end, freq="D"))
    w["temp_c"] = w["temp_c"].interpolate(limit=7)
    w["prcp_3d"] = w["prcp_mm"].rolling(3, min_periods=1).sum()
    return w.rename_axis("ds").reset_index()


def favorita(pkl: str, noaa_dir: str) -> Dataset:
    from predictily_engine.calendar.ecuador import EcuadorCalendar
    from predictily_engine.calendar.regions import region_for_province
    from predictily_engine.features.builder import calendar_by_branch

    d = pd.read_pickle(pkl)
    p = d["panel"][["unique_id", "branch_id", "product_id", "category_id", "ds", "y", "stockout"]].copy()
    p["ds"] = pd.to_datetime(p["ds"])
    p["evaluable"] = ~p["stockout"].astype(bool) & p["y"].notna()
    p["y"] = p["y"].astype(float).fillna(0.0)
    start, end = p["ds"].min(), p["ds"].max()
    branches = FAV_BRANCHES.assign(region=FAV_BRANCHES["province"].map(region_for_province))
    cal = calendar_by_branch(branches, start.date(), end.date())
    cal["ds"] = pd.to_datetime(cal["ds"])
    cal_cols = list(EcuadorCalendar.FEATURES)
    cal[cal_cols] = cal[cal_cols].astype(int)
    p = p.merge(cal, on=["branch_id", "ds"], how="left")
    promo = d["promo"][["branch_id", "product_id", "ds"]].copy()
    promo["ds"] = pd.to_datetime(promo["ds"])
    p = p.merge(promo.assign(promo=1), on=["branch_id", "product_id", "ds"], how="left")
    p["promo"] = p["promo"].fillna(0).astype(int)
    weather = pd.concat([_noaa(noaa_dir, r.station, start, end).assign(branch_id=r.branch_id) for r in FAV_BRANCHES.itertuples()])
    p = p.merge(weather, on=["branch_id", "ds"], how="left")
    payroll = ["is_payday_window", "is_month_end", "is_decimo_tercero", "is_decimo_cuarto"]
    school = ["is_school_season"]
    festive = [c for c in cal_cols if c not in payroll + school]
    clima = ["temp_c", "prcp_mm", "prcp_3d"]
    p["m_feriado"] = (p["is_holiday"] + p["is_pre_holiday"]) > 0
    p["m_navidad"] = p["is_christmas_window"] > 0
    p["m_quincena"] = p["is_payday_window"] > 0
    p["m_escolar"] = p["is_school_season"] > 0
    p["m_promo"] = p["promo"] > 0
    p["m_lluvia"] = p["prcp_mm"].fillna(0) >= 5
    arms = [
        Arm("base", []),
        Arm("base (otra semilla)", [], seed=7),
        Arm("+ feriados y fechas festivas", festive),
        Arm("+ quincena, fin de mes y décimos", payroll),
        Arm("+ temporada escolar", school),
        Arm("+ promociones", ["promo"]),
        Arm("+ clima observado", clima),
        Arm("+ todo", festive + payroll + school + ["promo"] + clima),
        Arm("+ todo menos clima", festive + payroll + school + ["promo"]),
    ]
    harness.MASK_ARMS = {
        "+ feriados y fechas festivas": ["m_feriado", "m_navidad"],
        "+ quincena, fin de mes y décimos": ["m_quincena"],
        "+ temporada escolar": ["m_escolar"],
        "+ promociones": ["m_promo"],
        "+ clima observado": ["m_lluvia"],
        "+ todo": ["m_feriado", "m_navidad", "m_quincena", "m_escolar", "m_promo", "m_lluvia"],
        "+ todo menos clima": [],
    }
    masks = {
        "feriado o víspera": "m_feriado",
        "ventana de Navidad": "m_navidad",
        "quincena y fin de mes": "m_quincena",
        "temporada escolar": "m_escolar",
        "día en promoción": "m_promo",
        "día de lluvia (≥ 5 mm)": "m_lluvia",
    }
    p["branch_num"] = p["branch_id"].astype(int)
    p["category_num"] = p["category_id"].astype(int)
    return Dataset(
        "Favorita (Ecuador, supermercado, producto × tienda)",
        p,
        ["branch_num", "category_num"],
        arms,
        masks,
        _cutoffs("2017-07-18", 9),
        notes="4 tiendas (Quito, Guayaquil, Cuenca, Puyo), 160 productos; clima real de NOAA por ciudad, entregado como conocido (techo).",
    )


# ---------------------------------------------------------------- M5 (Walmart, EE. UU.): producto × tienda, diario, con precios
def m5(directory: str, stores=("CA_1", "TX_1", "WI_1"), days: int = 1100) -> Dataset:
    head = pd.read_csv(f"{directory}/sales_train_evaluation.csv", nrows=0).columns
    dcols = [c for c in head if c.startswith("d_")][-days:]
    s = pd.read_csv(f"{directory}/sales_train_evaluation.csv", usecols=["item_id", "dept_id", "cat_id", "store_id", "state_id", *dcols])
    s = s[s["store_id"].isin(stores)]
    vol = s.groupby("item_id")[dcols[-730:]].sum().sum(axis=1).sort_values()
    rng = np.random.default_rng(42)
    rest = vol.iloc[:-20]
    bins = pd.qcut(rest.rank(method="first"), 10, labels=False)
    pick = list(vol.index[-20:]) + [i for b in range(10) for i in rng.choice(rest.index[bins == b], 18, replace=False)]
    s = s[s["item_id"].isin(pick)]
    long = s.melt(id_vars=["item_id", "dept_id", "cat_id", "store_id", "state_id"], var_name="d", value_name="y")
    cal = pd.read_csv(f"{directory}/calendar.csv")
    cal["d"] = ["d_" + str(i + 1) for i in range(len(cal))]
    cal["ds"] = pd.to_datetime(cal["date"])
    for t in ("Cultural", "National", "Religious", "Sporting"):
        cal[f"ev_{t.lower()}"] = ((cal["event_type_1"] == t) | (cal["event_type_2"] == t)).astype(int)
    cal["ev_any"] = cal[["ev_cultural", "ev_national", "ev_religious", "ev_sporting"]].max(axis=1)
    cal["ev_pre"] = cal["ev_any"].shift(-1).fillna(0).astype(int)
    long = long.merge(
        cal[
            [
                "d",
                "ds",
                "wm_yr_wk",
                "ev_cultural",
                "ev_national",
                "ev_religious",
                "ev_sporting",
                "ev_any",
                "ev_pre",
                "snap_CA",
                "snap_TX",
                "snap_WI",
            ]
        ],
        on="d",
    )
    long["snap"] = np.select([long["state_id"] == "CA", long["state_id"] == "TX"], [long["snap_CA"], long["snap_TX"]], long["snap_WI"])
    prices = pd.read_csv(f"{directory}/sell_prices.csv")
    prices = prices[prices["store_id"].isin(stores) & prices["item_id"].isin(pick)]
    long = long.merge(prices, on=["store_id", "item_id", "wm_yr_wk"], how="left")
    long["unique_id"] = long["store_id"] + "|" + long["item_id"]
    long = long.sort_values(["unique_id", "ds"])
    long = long[long.groupby("unique_id")["sell_price"].transform(lambda x: x.notna().cummax())]  # desde que el producto tiene precio
    long["sell_price"] = long.groupby("unique_id")["sell_price"].ffill()
    long["price_rel"] = long["sell_price"] / long.groupby("unique_id")["sell_price"].cummax()
    long["log_price"] = np.log(long["sell_price"])
    ref = long.groupby("unique_id")["sell_price"].shift(28)
    long["m_precio_cambio"] = ((long["sell_price"] / ref - 1).abs() > 0.02).fillna(False)
    long["m_rebaja"] = long["price_rel"] < 0.95
    long["m_snap"] = long["snap"] > 0
    long["m_evento"] = (long["ev_any"] + long["ev_pre"]) > 0
    closed = long.groupby("ds")["y"].sum()
    long["evaluable"] = ~long["ds"].isin(closed[closed < closed.median() * 0.05].index)
    for c, src in (("store_num", "store_id"), ("dept_num", "dept_id"), ("cat_num", "cat_id")):
        long[c] = long[src].astype("category").cat.codes
    long["y"] = long["y"].astype(float)
    price, events = ["price_rel", "log_price"], ["ev_cultural", "ev_national", "ev_religious", "ev_sporting", "ev_pre"]
    arms = [
        Arm("base", []),
        Arm("base (otra semilla)", [], seed=7),
        Arm("+ precio (futuro conocido)", price),
        Arm("+ precio (congelado al corte)", price, frozen=price),
        Arm("+ días de subsidio (SNAP)", ["snap"]),
        Arm("+ eventos y feriados", events),
        Arm("+ todo (precio conocido)", price + ["snap"] + events),
        Arm("+ todo (precio congelado)", price + ["snap"] + events, frozen=price),
    ]
    harness.MASK_ARMS = {
        "+ precio (futuro conocido)": ["m_precio_cambio", "m_rebaja"],
        "+ precio (congelado al corte)": ["m_precio_cambio", "m_rebaja"],
        "+ días de subsidio (SNAP)": ["m_snap"],
        "+ eventos y feriados": ["m_evento"],
        "+ todo (precio conocido)": ["m_precio_cambio", "m_rebaja", "m_snap", "m_evento"],
        "+ todo (precio congelado)": ["m_precio_cambio"],
    }
    masks = {
        "precio distinto al de hace 28 días": "m_precio_cambio",
        "en rebaja (≥ 5 % bajo su máximo)": "m_rebaja",
        "día de subsidio (SNAP)": "m_snap",
        "evento, feriado o víspera": "m_evento",
    }
    keep = ["unique_id", "ds", "y", "evaluable", "store_num", "dept_num", "cat_num", *price, "snap", *events, *masks.values()]
    return Dataset(
        "M5 (Walmart, EE. UU., producto × tienda, con precios)",
        long[keep],
        ["store_num", "dept_num", "cat_num"],
        arms,
        masks,
        _cutoffs("2016-04-24", 9),
        notes=f"{len(stores)} tiendas (California, Texas, Wisconsin), 200 productos estratificados por volumen; precio semanal real.",
    )


# ---------------------------------------------------------------- Rossmann (Alemania): venta diaria por tienda
def rossmann(directory: str, n_stores: int = 250) -> Dataset:
    t = pd.read_csv(f"{directory}/train.csv", dtype={"StateHoliday": str})
    t["ds"] = pd.to_datetime(t["Date"])
    full = t.groupby("Store")["ds"].nunique()
    stores = np.random.default_rng(42).choice(full[full == full.max()].index, n_stores, replace=False)
    t = t[t["Store"].isin(stores)].sort_values(["Store", "ds"])
    info = pd.read_csv(f"{directory}/store.csv")[["Store", "StoreType", "Assortment"]]
    t = t.merge(info, on="Store")
    t["unique_id"] = "T" + t["Store"].astype(str)
    t["y"] = t["Sales"].astype(float) / 1000.0  # miles de euros
    t["open"] = t["Open"].astype(int)
    t["evaluable"] = t["open"] == 1
    t["promo"] = t["Promo"].astype(int)
    t["school"] = pd.to_numeric(t["SchoolHoliday"], errors="coerce").fillna(0).astype(int)
    t["hol_public"] = (t["StateHoliday"] == "a").astype(int)
    t["hol_easter"] = (t["StateHoliday"] == "b").astype(int)
    t["hol_christmas"] = (t["StateHoliday"] == "c").astype(int)
    anyh = (t["StateHoliday"] != "0").astype(int)
    t["hol_pre"] = anyh.groupby(t["unique_id"]).shift(-1).fillna(0).astype(int)
    t["hol_post"] = anyh.groupby(t["unique_id"]).shift(1).fillna(0).astype(int)
    t["m_promo"] = t["promo"] > 0
    t["m_feriado"] = (anyh + t["hol_pre"] + t["hol_post"]) > 0
    t["m_escolar"] = t["school"] > 0
    t["type_num"] = t["StoreType"].astype("category").cat.codes
    t["assort_num"] = t["Assortment"].astype("category").cat.codes
    hol = ["hol_public", "hol_easter", "hol_christmas", "hol_pre", "hol_post"]
    arms = [
        Arm("base", ["open"]),
        Arm("base (otra semilla)", ["open"], seed=7),
        Arm("+ promociones", ["open", "promo"]),
        Arm("+ feriados", ["open", *hol]),
        Arm("+ vacaciones escolares", ["open", "school"]),
        Arm("+ todo", ["open", "promo", *hol, "school"]),
    ]
    harness.MASK_ARMS = {
        "+ promociones": ["m_promo"],
        "+ feriados": ["m_feriado"],
        "+ vacaciones escolares": ["m_escolar"],
        "+ todo": ["m_promo", "m_feriado", "m_escolar"],
    }
    masks = {"día en promoción": "m_promo", "feriado, víspera o día siguiente": "m_feriado", "vacaciones escolares": "m_escolar"}
    keep = ["unique_id", "ds", "y", "evaluable", "type_num", "assort_num", "open", "promo", "school", *hol, *masks.values()]
    return Dataset(
        "Rossmann (Alemania, farmacias, venta por tienda)",
        t[keep],
        ["type_num", "assort_num"],
        arms,
        masks,
        _cutoffs("2015-07-03", 9),
        notes=f"{n_stores} tiendas; la base (y todo brazo) sabe qué días abre la tienda; se evalúan solo días abiertos.",
    )
