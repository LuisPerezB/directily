"""Quiebres de stock inferidos de las ventas (`demand.stockout_inference`, `profiling/stockouts.py`).

Apagado (por defecto) nada cambia. Encendido, una racha de días en cero que la demanda habitual del producto no explica se marca quiebre
(`stockout_censored`, base `inferred`) con lo observado hasta cada día, se imputa para entrenar y queda fuera de las métricas.
"""

from __future__ import annotations

from dataclasses import replace

import numpy as np
import pandas as pd

from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.pipeline.data import TenantData, tenant_data_from_synthetic
from predictily_engine.platform.config import CORE_DEFAULTS, validate_overrides
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.profiling.stockouts import StockoutRules, infer_stockouts
from predictily_engine.synthetic import generate_company

START = pd.Timestamp("2026-01-05")   # lunes
RULES = StockoutRules()
ON = {"stockout_inference": True}


def frame(series: dict[str, np.ndarray], branch: int = 1) -> pd.DataFrame:
    """Panel mínimo: una fila por serie y día desde START."""
    parts = [pd.DataFrame({"unique_id": uid, "branch_id": branch, "ds": pd.date_range(START, periods=len(u), freq="D"),
                           "units": np.asarray(u, float), "stockout": False}) for uid, u in series.items()]
    return pd.concat(parts, ignore_index=True)


def infer(df: pd.DataFrame, rules: StockoutRules = RULES, excluded: np.ndarray | None = None, in_stock: np.ndarray | None = None):
    none = np.zeros(len(df), dtype=bool)
    return infer_stockouts(df, none if excluded is None else excluded, none if in_stock is None else in_stock, rules)


def regular(days: int = 160, level: float = 5.0, zero: tuple[tuple[int, int], ...] = ()) -> np.ndarray:
    """Producto que vende `level` todos los días, salvo las rachas (inicio, largo) de `zero`."""
    u = np.full(days, level)
    for start, length in zero:
        u[start:start + length] = 0.0
    return u


def flagged_offsets(result, df: pd.DataFrame, uid: str = "a") -> list[int]:
    rows = df[(df["unique_id"] == uid).to_numpy() & result.inferred]
    return sorted(((rows["ds"] - START).dt.days).tolist())


# ───────────────────────── configuración ─────────────────────────

def test_the_switch_is_off_by_default_and_its_parameters_are_validated():
    assert CORE_DEFAULTS["demand"]["stockout_inference"] is False
    assert DayRules.from_config(CORE_DEFAULTS["demand"]).stockouts is None and DayRules.from_config({}).stockouts is None
    on = DayRules.from_config({**ON, "stockout_inference_max_days": 30}).stockouts
    assert on == replace(RULES, max_days=30)
    ok = {"stockout_inference": True, "stockout_inference_alpha": 0.01, "stockout_inference_min_days": 2, "stockout_inference_max_days": 60,
          "stockout_inference_lookback_days": 56, "stockout_inference_min_branch_activity": 0.3, "stockout_inference_max_zero_share": 0.4}
    assert validate_overrides("demand", ok) == []
    bad = {"stockout_inference": "si", "stockout_inference_alpha": 0.5, "stockout_inference_min_days": 1, "stockout_inference_max_days": 3,
           "stockout_inference_lookback_days": 7, "stockout_inference_min_branch_activity": 2.0, "stockout_inference_max_zero_share": 1.0}
    assert sorted(e["key"] for e in validate_overrides("demand", bad)) == sorted(bad)


# ───────────────────────── la regla ─────────────────────────

def test_a_zero_run_of_a_regular_seller_is_flagged_from_the_day_it_becomes_improbable():
    df = frame({"a": regular(zero=((100, 10),))})
    res = infer(df)
    assert flagged_offsets(res, df) == list(range(102, 110)), "los dos primeros días en cero aún no son improbables: quedan como ceros"
    assert res.summary["series_days"] == 8 and res.summary["series"] == 1 and res.summary["open_at_cutoff_series"] == 0
    assert res.summary["rules"] == RULES.as_dict()


def test_isolated_zero_days_and_short_runs_are_never_flagged():
    df = frame({"a": regular(zero=((60, 1), (90, 2), (120, 1)))})
    assert not infer(df).inferred.any()


def test_a_sporadic_seller_is_never_flagged_however_long_the_run():
    u = np.where(np.arange(200) % 4 == 0, 3.0, 0.0)     # vende uno de cada cuatro días: 75 % de ceros
    u[120:160] = 0.0
    df = frame({"a": u})
    assert not infer(df).inferred.any()
    assert infer(df, replace(RULES, max_zero_share=0.9)).inferred.any(), "solo la condición de venta regular lo impide"


def test_a_weekday_the_product_never_sells_barely_counts_towards_the_run():
    u = regular()
    u[6::7] = 0.0                                        # nunca vende los domingos
    assert not infer(frame({"a": u})).inferred.any(), "un domingo en cero es lo habitual"
    u[117:121] = 0.0                                     # sábado, domingo, lunes y martes en cero (START es lunes)
    df = frame({"a": u})
    assert flagged_offsets(infer(df), df) == [120], "con el domingo dentro hacen falta tres días hábiles en cero"


def test_after_max_days_the_run_stops_being_a_stockout():
    df = frame({"a": regular(days=220, zero=((100, 80),))})
    res = infer(df, replace(RULES, max_days=30))
    assert flagged_offsets(res, df) == list(range(102, 130)), "días 3 a 30 de la racha; después se asume que dejó de venderse"
    assert res.summary["long_run_series_days"] == 50


def test_a_run_that_reaches_the_cutoff_is_reported_as_open():
    df = frame({"a": regular(zero=((150, 10),)), "b": regular()})
    res = infer(df)
    assert flagged_offsets(res, df) == list(range(152, 160)) and res.summary["open_at_cutoff_series"] == 1
    assert res.summary["series_total"] == 2 and res.summary["units_share_of_affected_series"] < 0.5


def test_the_flag_of_a_day_never_depends_on_what_happened_later():
    """Propiedad as-of: marcar con la historia cortada en cualquier día da las mismas marcas que la historia completa hasta ese día."""
    rng = np.random.default_rng(3)
    series = {}
    for k in range(6):
        u = rng.poisson(4.0, 260).astype(float)
        for start in rng.integers(90, 240, size=3):
            u[start:start + int(rng.integers(3, 25))] = 0.0
        series[f"s{k}"] = u
    df = frame(series)
    full = infer(df).inferred
    assert full.any()
    for cut in (120, 150, 181, 207, 233):
        keep = (df["ds"] <= START + pd.Timedelta(days=cut)).to_numpy()
        part = infer(df[keep].reset_index(drop=True)).inferred
        assert np.array_equal(part, full[keep]), f"corte en el día {cut}"


def test_days_without_source_neither_break_nor_count_and_are_never_flagged():
    df = frame({"a": regular(zero=((100, 7),))})
    off = (df["ds"] - START).dt.days
    excluded = off.isin([102, 103, 104]).to_numpy()      # tres días sin fuente dentro de la racha
    res = infer(df, excluded=excluded)
    assert flagged_offsets(res, df) == [105, 106], "ceros contados: 100, 101, 105 → improbable desde el tercero"


def test_confirmed_stock_breaks_the_run_and_is_never_flagged():
    df = frame({"a": regular(zero=((100, 8),))})
    off = (df["ds"] - START).dt.days
    res = infer(df, in_stock=(off == 103).to_numpy())    # el inventario confirma existencias ese día: fue un cero de demanda
    assert flagged_offsets(res, df) == [102, 106, 107]


def test_known_and_inferred_stockouts_do_not_count_as_zeros_of_the_history():
    # quiebre conocido por inventario (20 días) y, poco después, una racha nueva: la historia no «aprende» que el cero es normal
    df = frame({"a": regular(zero=((60, 20), (100, 6)))})
    off = (df["ds"] - START).dt.days
    df["stockout"] = off.between(60, 79).to_numpy()
    assert flagged_offsets(infer(df), df) == [102, 103, 104, 105]
    # dos quiebres inferidos seguidos: gracias a la segunda pasada el segundo se marca al cuarto día (sin ella, al sexto: los días
    # marcados del primero contarían como ceros habituales). Los dos días que abrieron el primero sí cuentan: por eso no es al tercero.
    df = frame({"a": regular(zero=((100, 21), (128, 6)))})
    assert flagged_offsets(infer(df), df) == [*range(102, 121), 131, 132, 133]


def test_a_new_product_without_enough_history_is_not_flagged():
    df = frame({"a": regular(days=40, zero=((20, 10),))})
    assert not infer(df).inferred.any()


def test_when_the_whole_branch_drops_no_product_is_flagged():
    quiet = {f"p{k}": regular(zero=((100, 10),)) * 1.0 for k in range(5)}      # toda la sucursal deja de vender
    assert not infer(frame(quiet)).inferred.any()
    assert infer(frame(quiet)).summary["branch_drop_series_days"] > 0
    one = {**{f"p{k}": regular() for k in range(5)}, "a": regular(zero=((100, 10),))}   # solo un producto: sí es quiebre
    df = frame(one)
    assert flagged_offsets(infer(df), df) == list(range(102, 110))
    two = pd.concat([frame(quiet, branch=1), frame({"a": regular(zero=((100, 10),)), "z": regular()}, branch=2).assign(
        unique_id=lambda d: "x" + d["unique_id"])], ignore_index=True)        # la salvaguarda es por sucursal
    res = infer(two)
    assert sorted(two.loc[res.inferred, "unique_id"].unique()) == ["xa"]


# ───────────────────────── el panel ─────────────────────────

def hand_data(units: dict[int, np.ndarray], inventory: pd.DataFrame | None = None) -> TenantData:
    days = pd.date_range(START, periods=len(next(iter(units.values()))), freq="D")
    rows = [{"branch_id": 1, "product_id": p, "ds": d, "units": float(u), "net_amount": float(u) * 10, "gross_amount": float(u) * 10,
             "discount_amount": 0.0, "cost_amount": float(u) * 4, "cost_units": float(u), "lines": 4}
            for p, series in units.items() for d, u in zip(days, series, strict=True) if u > 0]
    data = TenantData(
        tenant_id=1, settings={}, cutoff=days[-1].date(),
        branches=pd.DataFrame({"branch_id": [1], "region": "sierra", "canton": "quito"}),
        products=pd.DataFrame({"product_id": list(units), "category_id": 1, "is_active": True}),
        categories=pd.DataFrame(), sales=pd.DataFrame(rows), returns=pd.DataFrame(columns=["branch_id", "product_id", "ds", "units"]))
    return data if inventory is None else replace(data, inventory=inventory)


def test_off_the_panel_is_the_same_as_ever_and_on_the_flagged_days_get_the_stockout_treatment():
    data = hand_data({1: regular(zero=((100, 30),)), 2: regular(), 3: regular()})
    off = build_panel(data, DayRules.from_config({}))
    assert "stockout_inference" not in off.day_summary and not off.df["stockout"].any()
    assert (off.df.loc[off.df["product_id"] == 1, "y"].iloc[100:130] == 0).all(), "sin la inferencia el modelo aprende los ceros"
    on = build_panel(data, DayRules.from_config(ON))
    p1 = on.df[on.df["product_id"] == 1].reset_index(drop=True)
    hit = p1["stockout"].to_numpy(bool)
    assert np.flatnonzero(hit).tolist() == list(range(102, 130))
    assert set(p1.loc[hit, "day_status"].astype(str)) == {"stockout_censored"} and set(p1.loc[hit, "day_basis"].astype(str)) == {"inferred"}
    assert (p1.loc[hit, "units"] == 0).all() and (p1.loc[hit, "y"] > 3).all(), "imputados cerca del nivel previo, también pasado el día 21"
    assert set(p1.loc[[100, 101], "day_status"].astype(str)) == {"zero_confirmed"}
    others = on.df[on.df["product_id"] != 1]
    assert not others["stockout"].any() and others["y"].equals(off.df.loc[off.df["product_id"] != 1, "y"])
    block = on.day_summary["stockout_inference"]
    assert block["series_days"] == 28 and block["series"] == 1 and on.day_summary["series_days"]["stockout_censored"] == 28
    profile = on.profile.set_index("product_id")
    assert int(profile.loc[1, "stockout_days"]) == 28 and int(profile.loc[1, "censored_days"]) == 28


def test_with_inventory_the_confirmed_stock_prevails_over_the_inference():
    units = {1: regular(zero=((100, 10),)), 2: regular(zero=((100, 10),)), 3: regular(), 4: regular(), 5: regular()}
    days = pd.date_range(START, periods=160, freq="D")
    inv = pd.DataFrame({"branch_id": 1, "product_id": 1, "ds": days, "on_hand": 50.0})      # el producto 1 siempre tuvo existencias
    on = build_panel(hand_data(units, inventory=inv), DayRules.from_config(ON))
    by_product = on.df.groupby("product_id")["stockout"].sum()
    assert by_product[1] == 0, "ceros con existencias confirmadas: demanda, no quiebre"
    assert by_product[2] == 8, "el producto sin dato de inventario sí se infiere"


def test_on_the_demo_company_the_flags_match_the_real_stockouts_of_its_inventory():
    data = tenant_data_from_synthetic(generate_company("distribuidora_costa", seed=42))
    truth = build_panel(data, DayRules.from_config({})).df
    blind = build_panel(replace(data, inventory=data.inventory.iloc[0:0]), DayRules.from_config(ON))
    df = blind.df[["unique_id", "ds", "units", "stockout"]].merge(
        truth[["unique_id", "ds", "stockout"]].rename(columns={"stockout": "real"}), on=["unique_id", "ds"])
    flagged, real = df["stockout"].astype(bool), df["real"].astype(bool) & (df["units"] == 0)
    assert flagged.sum() > 300
    assert (flagged & real).sum() / flagged.sum() > 0.95, "precisión: casi todo lo marcado fue un quiebre real"
    busy = df.groupby("unique_id")["units"].transform("mean") >= 5
    assert (flagged & real & busy).sum() / (real & busy).sum() > 0.5, "cobertura en productos de venta diaria"


# ───────────────────────── el motor ─────────────────────────

def test_the_pipeline_reports_the_inferred_stockouts_only_when_the_switch_is_on():
    company = generate_company("distribuidora_costa", seed=42, n_products=8, start="2025-07-01")
    data = tenant_data_from_synthetic(company)
    data = replace(data, inventory=data.inventory.iloc[0:0])

    def run(demand: dict):
        return run_pipeline(replace(data, settings={**(data.settings or {}), "cores": {"demand": demand}}), horizon=28, seed=42, n_jobs=2)

    off, on = run({}), run(ON)
    assert "stockouts_inferred" not in {x["code"] for x in off.limitations} and "stockout_inference" not in off.summary["day_status"]
    block = on.summary["day_status"]["stockout_inference"]
    assert block["series_days"] > 0 and on.manifest["day_status"]["stockout_inference"] == block
    msg = next(x["message"] for x in on.limitations if x["code"] == "stockouts_inferred")
    assert str(block["series_days"]) in msg and "INFERIDO" in msg
    flagged = on.panel.df[on.panel.df["stockout"]]
    assert len(flagged) == block["series_days"] and (flagged["units"] == 0).all()
    held = on.backtest[on.backtest["is_holdout"]].merge(flagged[["unique_id", "ds"]], on=["unique_id", "ds"])
    assert held["stockout"].all(), "los días inferidos del período final no se evalúan"
