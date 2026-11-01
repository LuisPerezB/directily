"""Métricas de Demand por horizonte y segmento (W3-A · V2.5 §6, §8 «Métricas Demand» y §26).

Filas de `model_evaluations` con `level = 'segment'` y `level_key = '<dimensión>:<valor>'`; `horizon_days = H` (7/14/15/30/60/90 del
catálogo, solo los H que el backtest alcanza) y métricas en el jsonb `metrics`. Cada fila lleva TODO lo que hace verificable la cifra:

- **Base de la métrica** (`horizon_days = H`): el error DIARIO por serie de los primeros H días del horizonte (pasos 1..H), de todas las
  ventanas del alcance (`backtest` = período final reservado; `selection_windows` = las ventanas que eligieron el modelo). Dentro de
  `metrics.horizon_total` va, además, el error del TOTAL de H días por serie y ventana (solo ventanas completas), que es el que importa
  para planear compras (V25-HOR-008).
- **WAPE** = Σ|error| / Σ reales con su denominador (`metrics.denominator.sum_actual`), su cobertura (`metrics.sample`: días evaluables,
  días excluidos por clase —quiebre, fuente ausente, cierre—, series y ventanas) y su estado (`wape_status` ok | unstable | undefined).
  Con denominador menor que el umbral (`wape_min_denominator`) o 0 el WAPE es `wape_unstable`: la columna `wape` queda NULA, el valor
  crudo va en `metrics.wape_raw` (si el denominador es positivo) y la métrica alternativa explícita en `metrics.alternative_metric`
  (MAE escalado = MASE si la escala existe; si no, el error absoluto agregado). Nunca un WAPE con denominador 0.
- **MAE, RMSE, MASE, RMSSE** (escala in-sample del naive estacional 7 con datos ≤ corte de CADA ventana, ya en las filas del backtest),
  **sesgo** (Σ(pred−real)/Σreal, con el mismo estado que el WAPE; `mean_error` es el sesgo absoluto en unidades por día evaluable),
  **pinball P10/P50/P90**, **cobertura 80/95** y **anchura** de intervalos (solo el modelo servido y el baseline, y solo con el período
  final reservado: son los que tienen intervalos calibrados con residuos observables en el origen).
- **Segmentos con pocas observaciones** (`insufficient_data`): menos de `segment_min_series` series o `segment_min_obs` series-día
  evaluables. La fila se persiste con sus tamaños de muestra y SIN cifras de error (columnas nulas): no se muestra lo que no se puede
  sostener.
- **Estabilidad entre ventanas** (`metrics.stability`): WAPE por ventana, su desvío y coeficiente de variación; con una sola ventana
  (período final) el desvío NO es estimable (`single_window`) y se adjunta la estabilidad de las ventanas de selección como referencia
  (`stability_selection_windows`).
- **Comparación** (solo en la fila del modelo servido): baseline (Seasonal Naive), mejor estadístico (elegido con las ventanas de
  selección, NUNCA con el período final), LightGBM y seleccionado, con diferencias y veredicto con tolerancia configurable.

Dimensiones: clase de demanda (la que tenía la serie en el corte de cada ventana), categoría, sucursal, tramo de volumen (top
`segment_volume_top_share` de las series por unidades imputadas de los últimos `segment_volume_lookback_days` días HASTA EL CORTE de
cada ventana) y vida de la serie (nueva = menos de `segment_new_series_days` días de historia al corte de la ventana). Volumen y vida se
derivan del panel tal como se conocía en cada corte (`AsOfWindows`): una venta posterior al corte no cambia el segmento de esa ventana.

Nada de esto cambia lo servido: son métricas adicionales calculadas sobre las mismas filas del backtest.
"""

from __future__ import annotations

import logging
import math
from dataclasses import asdict, dataclass
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.backtest.metrics import (
    WAPE_OK,
    alternative_metric,
    guarded_wape,
    window_stability,
)
from predictily_engine.demand.horizons import SUPPORTED_HORIZONS
from predictily_engine.models.registry import BASELINE_FAMILY, STATISTICAL

log = logging.getLogger(__name__)

SEGMENT_METRICS_VERSION = "demand-segment-metrics-v1"
LEVEL_SEGMENT = "segment"
SELECTION_FAMILY = "selection"
CATEGORY_NONE = "none"        # valor de `category` para las series cuyo producto no tiene categoría (nunca se pierden de la partición)
ML_FAMILY = "lightgbm_global"
DIMENSIONS = ("demand_class", "category", "branch", "volume_tier", "series_age")
SEG_COL = {d: f"seg_{d}" for d in DIMENSIONS}
HORIZON_BASIS = "daily_steps_1_to_H"
HORIZON_BASIS_TEXT = ("error diario por serie de los primeros H días del horizonte (pasos 1..H); el total de H días por serie y "
                      "ventana va en metrics.horizon_total")
TOTAL_BASIS_TEXT = ("suma de los H días por serie y ventana, solo ventanas completas; los días no evaluables restan de ambos lados")
STATUS_OK = "ok"
STATUS_INSUFFICIENT = "insufficient_data"
CHOSEN_WITH_SELECTION = "selection_windows"
CHOSEN_WITH_SAME_WINDOWS = "same_windows_optimistic"      # sin período final independiente: el mejor estadístico se elige con lo que se evalúa
INTERVAL_BASIS = "series_day_conformal_holdout"     # series-día; calibrados solo con residuos de las ventanas de selección, medidos en el período final
DEFINITIONS_REF = "forecast_runs.evaluation_manifest.segment_metrics"      # una sola copia de los textos estáticos por ejecución
SCALE_DEFINITION = "naive_estacional_7_con_datos_de_entrenamiento_previos_a_cada_origen"

SUM_COLS = ["n_rows", "n_ok", "sum_a", "sum_p", "sum_ae", "sum_se", "sum_e", "sum_sae", "sum_sse", "n_scaled",
            "x_stock", "x_missing", "x_closed", "x_invalid"]
PAIR_COLS = ["n_rows", "n_ok", "sum_a", "sum_p"]
ITV_COLS = ["n", "sum_a", "hit80", "hit95", "pb10", "pb50", "pb90", "pb50_mean", "n_p50", "w80", "w95", "le10", "le50", "le90"]


# ───────────────────────────────────────────── configuración ─────────────────────────────────────────────

@dataclass(frozen=True)
class SegmentConfig:
    """Umbrales de las métricas por segmento. Valores iniciales de diseño, «a aprobar en el piloto» (platform/config.py)."""

    enabled: bool = True
    wape_min_denominator: float = 30.0
    min_series: int = 3
    min_obs: int = 90
    volume_top_share: float = 0.2
    volume_lookback_days: int = 90
    new_series_days: int = 90
    max_values: int = 50
    similar_tolerance: float = 0.03
    stability_cv_warn: float = 0.5
    summary_max_cells: int = 20

    @classmethod
    def from_config(cls, cfg: dict[str, Any] | None) -> "SegmentConfig":
        """Lee la configuración efectiva del core de Demanda (ya validada); lo que falte toma el valor por defecto."""
        cfg = cfg or {}
        base = cls()
        return cls(
            enabled=bool(cfg.get("segment_metrics_enabled", base.enabled)),
            wape_min_denominator=float(cfg.get("wape_min_denominator", base.wape_min_denominator)),
            min_series=int(cfg.get("segment_min_series", base.min_series)),
            min_obs=int(cfg.get("segment_min_obs", base.min_obs)),
            volume_top_share=float(cfg.get("segment_volume_top_share", base.volume_top_share)),
            volume_lookback_days=int(cfg.get("segment_volume_lookback_days", base.volume_lookback_days)),
            new_series_days=int(cfg.get("segment_new_series_days", base.new_series_days)),
            max_values=int(cfg.get("segment_max_values", base.max_values)),
            similar_tolerance=float(cfg.get("segment_similar_tolerance", base.similar_tolerance)),
            stability_cv_warn=float(cfg.get("segment_stability_cv_warn", base.stability_cv_warn)),
            summary_max_cells=int(cfg.get("segment_summary_max_cells", base.summary_max_cells)),
        )

    @property
    def top_label(self) -> str:
        return f"top{int(round(self.volume_top_share * 100))}"

    def thresholds(self) -> dict[str, Any]:
        d = asdict(self)
        d.pop("enabled")
        d["justification"] = "valores iniciales de diseño, a aprobar en el piloto con datos reales"
        return d


# ───────────────────────────────────────────── contexto as-of (volumen y vida) ─────────────────────────────────────────────

@dataclass
class SegmentContext:
    """Atributos de volumen y vida de cada (corte, serie) del backtest, calculados con lo conocido en ese corte."""

    config: SegmentConfig
    attrs: pd.DataFrame  # índice (cutoff, unique_id); columnas volume_tier, series_age, trailing_units, age_days

    def covers(self, bt: pd.DataFrame) -> bool:
        """El contexto tiene atributos para TODOS los pares (corte, serie) de `bt` (se puede reutilizar sin reconstruirlo)."""
        if bt is None or bt.empty:
            return False
        pairs = pd.MultiIndex.from_frame(bt[["cutoff", "unique_id"]].drop_duplicates().assign(cutoff=lambda d: pd.to_datetime(d["cutoff"])))
        return bool(pairs.isin(self.attrs.index).all())


def safe_build_context(bt: pd.DataFrame, panel: Any, windows: Any | None, config: SegmentConfig,
                       reuse: SegmentContext | None = None) -> tuple[SegmentContext | None, str | None]:
    """`build_context` aislado: un fallo del cálculo de segmentos NO aborta la ejecución. Devuelve (contexto | None, error | None).
    Con `reuse` (un contexto ya construido) lo devuelve tal cual si cubre los pares de `bt`; si no, lo reconstruye."""
    try:
        if reuse is not None and reuse.covers(bt):
            return reuse, None
        return build_context(bt, panel, windows, config), None
    except Exception as exc:   # noqa: BLE001 (aislamiento deliberado: las métricas por segmento son aditivas)
        log.exception("Las métricas por segmento no pudieron prepararse; la ejecución continúa sin ellas")
        return None, f"{type(exc).__name__}: {exc}"[:300]


def build_context(bt: pd.DataFrame, panel: Any, windows: Any | None, config: SegmentConfig) -> SegmentContext | None:
    """Calcula tramo de volumen y vida de cada serie en el corte de cada ventana del backtest.

    Usa el panel TAL COMO SE CONOCÍA en el corte (`windows.at(corte)` cuando hay conocimiento as-of; si no, el panel final recortado al
    corte): nada posterior al corte interviene. El volumen son las unidades diarias (imputadas en los días no evaluables) de los últimos
    `volume_lookback_days` días; la vida son los días desde la primera venta. Las series sin unidades en la ventana nunca son «top»."""
    if bt is None or bt.empty or not config.enabled:
        return None
    pairs = bt[["cutoff", "unique_id"]].drop_duplicates()
    frames = []
    for cutoff, grp in pairs.groupby("cutoff"):
        cutoff = pd.Timestamp(cutoff)
        wp = windows.at(cutoff)[0] if windows is not None else panel
        ids = sorted(grp["unique_id"].unique())
        df = wp.df[(wp.df["ds"] <= cutoff) & wp.df["unique_id"].isin(ids)]
        first = df.groupby("unique_id")["ds"].min().reindex(ids)
        recent = df[df["ds"] > cutoff - pd.Timedelta(days=config.volume_lookback_days)]
        trailing = recent.groupby("unique_id")["y"].sum().reindex(ids).fillna(0.0)
        n_top = max(1, math.ceil(config.volume_top_share * len(ids)))
        ranked = trailing.reset_index().rename(columns={"y": "trailing_units"})
        ranked = ranked.sort_values(["trailing_units", "unique_id"], ascending=[False, True]).reset_index(drop=True)
        top = set(ranked.loc[:n_top - 1].query("trailing_units > 0")["unique_id"])
        age = ((cutoff - first).dt.days + 1)
        out = pd.DataFrame({"cutoff": cutoff, "unique_id": ids,
                            "trailing_units": trailing.to_numpy(float), "age_days": age.to_numpy(float)})
        out["volume_tier"] = np.where(out["unique_id"].isin(top), config.top_label, "rest")
        out["series_age"] = np.where(out["age_days"].isna(), None,
                                     np.where(out["age_days"] < config.new_series_days, "new", "mature"))
        frames.append(out)
    attrs = pd.concat(frames, ignore_index=True).set_index(["cutoff", "unique_id"])
    return SegmentContext(config, attrs)


def annotate(rows: pd.DataFrame, cat: pd.Series, seg: SegmentContext | None) -> pd.DataFrame:
    """Copia de `rows` con las columnas de segmento (`seg_<dimensión>`); conserva el índice y el orden."""
    out = rows.copy()
    uniq = pd.Series(out["unique_id"].unique())          # la extracción se hace sobre los identificadores únicos, no sobre cada fila
    parts = uniq.str.extract(r"b(\d+)-p(\d+)").astype(int)
    branch_label = dict(zip(uniq, "b" + parts[0].astype(str)))
    cid = parts[1].map(cat)
    category_label = dict(zip(uniq, np.where(cid.notna(), "c" + cid.astype("Int64").astype(str), CATEGORY_NONE)))
    out[SEG_COL["demand_class"]] = out["demand_class"] if "demand_class" in out else None
    out[SEG_COL["branch"]] = out["unique_id"].map(branch_label)
    out[SEG_COL["category"]] = out["unique_id"].map(category_label)
    if seg is not None:
        key = pd.MultiIndex.from_arrays([pd.to_datetime(out["cutoff"]), out["unique_id"]])
        looked = seg.attrs.reindex(key)
        out[SEG_COL["volume_tier"]] = looked["volume_tier"].to_numpy()
        out[SEG_COL["series_age"]] = looked["series_age"].to_numpy()
    else:
        out[SEG_COL["volume_tier"]] = None
        out[SEG_COL["series_age"]] = None
    return out


SEGMENT_ALIASES = {"series_life": "series_age"}   # nombre con que la política de promoción V2.5 (W3-B) llama a la vida de la serie


def provider_from_context(context: SegmentContext | None, cat: pd.Series):
    """Proveedor de segmentos para la política de promoción V2.5 (`selection.promotion.SegmentProvider`) a partir de un contexto ya construido:
    recibe filas del backtest de un alcance y devuelve un DataFrame con el MISMO índice y una columna por dimensión (`demand_class`,
    `category`, `branch`, `volume_tier`, `series_age`, con el alias `series_life`). Tramo de volumen y vida salen del panel tal como se
    conocía en el corte de cada ventana, los mismos segmentos que se miden en `model_evaluations`. None si no hay contexto."""
    if context is None:
        return None

    def provider(rows: pd.DataFrame) -> pd.DataFrame:
        annotated = annotate(rows, cat, context)
        out = pd.DataFrame({dim: annotated[SEG_COL[dim]] for dim in DIMENSIONS}, index=rows.index)
        for alias, dim in SEGMENT_ALIASES.items():
            out[alias] = out[dim]
        return out

    return provider


def make_provider(bt: pd.DataFrame, panel: Any, windows: Any | None, cat: pd.Series, config: SegmentConfig,
                  context: SegmentContext | None = None):
    """`provider_from_context` construyendo el contexto si no se entrega uno (None si el backtest está vacío o las métricas apagadas)."""
    return provider_from_context(context if context is not None else build_context(bt, panel, windows, config), cat)


# ───────────────────────────────────────────── agregados aditivos ─────────────────────────────────────────────

def _band_index(step: np.ndarray, horizons: tuple[int, ...]) -> np.ndarray:
    """Banda de cada paso: la del menor horizonte del catálogo que lo contiene (paso 15 → banda de H=15; paso 20 → la de H=30)."""
    return np.searchsorted(np.asarray(horizons), np.asarray(step), side="left")


def _prepare(rows: pd.DataFrame, horizons: tuple[int, ...]) -> pd.DataFrame:
    """Columnas aditivas por fila del backtest (los días no evaluables aportan 0 a las sumas y se cuentan por causa)."""
    n = len(rows)
    a = pd.to_numeric(rows["actual"], errors="coerce").to_numpy(float)
    p = pd.to_numeric(rows["yhat"], errors="coerce").to_numpy(float)
    censored = rows["stockout"].astype(bool).to_numpy()
    finite = np.isfinite(a) & np.isfinite(p)
    ok = ~censored & finite
    e = np.where(ok, p - a, 0.0)
    ae = np.abs(e)
    sm = pd.to_numeric(rows["scale_mae"], errors="coerce").to_numpy(float) if "scale_mae" in rows else np.full(n, np.nan)
    ss = pd.to_numeric(rows["scale_mse"], errors="coerce").to_numpy(float) if "scale_mse" in rows else np.full(n, np.nan)
    scaled = ok & np.isfinite(sm) & np.isfinite(ss) & (sm > 0) & (ss > 0)
    status = rows["day_status"].astype(str).to_numpy() if "day_status" in rows else np.full(n, "")
    x_missing = censored & (status == "missing_source")
    x_closed = censored & (status == "closed")
    out = pd.DataFrame({
        "family": rows["family"].to_numpy(), "unique_id": rows["unique_id"].to_numpy(), "window": rows["window"].to_numpy(),
        "band": _band_index(rows["step"].to_numpy(), horizons),
        "n_rows": 1.0, "n_ok": ok.astype(float), "sum_a": np.where(ok, a, 0.0), "sum_p": np.where(ok, p, 0.0),
        "sum_ae": ae, "sum_se": e ** 2, "sum_e": e,
        "sum_sae": np.where(scaled, ae / np.where(scaled, sm, 1.0), 0.0),
        "sum_sse": np.where(scaled, e ** 2 / np.where(scaled, ss, 1.0), 0.0), "n_scaled": scaled.astype(float),
        "x_stock": (censored & ~x_missing & ~x_closed).astype(float), "x_missing": x_missing.astype(float),
        "x_closed": x_closed.astype(float), "x_invalid": (~censored & ~finite).astype(float),
    }, index=rows.index)
    for col in SEG_COL.values():
        out[col] = rows[col].to_numpy()
    return out[out["band"] < len(horizons)]


def _cumulate(t: pd.DataFrame, keys: list[str], n_bands: int, cols: list[str]) -> pd.DataFrame:
    """Acumula las sumas por banda: la fila de la banda j contiene todo lo de los pasos ≤ H_j (se rellenan las bandas sin filas)."""
    if t.empty:
        return t.assign(band=pd.Series(dtype=int))
    grid = t[keys].drop_duplicates().merge(pd.DataFrame({"band": np.arange(n_bands)}), how="cross")
    m = grid.merge(t, on=keys + ["band"], how="left")
    m[cols] = m[cols].fillna(0.0)
    m = m.sort_values(keys + ["band"], kind="stable").reset_index(drop=True)
    m[cols] = m.groupby(keys, sort=False)[cols].cumsum()
    return m


def _daily_table(prep: pd.DataFrame, dims: tuple[str, ...], n_bands: int) -> pd.DataFrame:
    """Sumas acumuladas por (familia, dimensión, valor, ventana, banda)."""
    frames = []
    for dim in dims:
        col = SEG_COL[dim]
        g = prep.dropna(subset=[col]).groupby(["family", col, "window", "band"], observed=True, sort=False)[SUM_COLS].sum()
        g = g.reset_index().rename(columns={col: "value"})
        g.insert(1, "dim", dim)
        frames.append(g)
    t = pd.concat(frames, ignore_index=True) if frames else pd.DataFrame(columns=["family", "dim", "value", "window", "band", *SUM_COLS])
    return _cumulate(t, ["family", "dim", "value", "window"], n_bands, SUM_COLS)


def _pair_table(prep: pd.DataFrame, n_bands: int) -> pd.DataFrame:
    """Sumas acumuladas por (familia, serie, ventana, banda): base del error del TOTAL de H días."""
    g = prep.groupby(["family", "unique_id", "window", "band"], observed=True, sort=False)[PAIR_COLS].sum().reset_index()
    return _cumulate(g, ["family", "unique_id", "window"], n_bands, PAIR_COLS)


def _interval_table(scored: pd.DataFrame | None, family: str, horizons: tuple[int, ...], dims: tuple[str, ...], n_bands: int
                    ) -> pd.DataFrame | None:
    """Sumas acumuladas de pinball, cobertura y anchura de las filas con intervalo calibrado de `family`."""
    if scored is None or scored.empty:
        return None
    a = scored["actual"].to_numpy(float)
    lo80, hi80 = scored["lo_80"].to_numpy(float), scored["hi_80"].to_numpy(float)
    lo95, hi95 = scored["lo_95"].to_numpy(float), scored["hi_95"].to_numpy(float)
    p50 = scored["p50"].to_numpy(float) if "p50" in scored else np.full(len(scored), np.nan)
    y = scored["yhat"].to_numpy(float)

    def pin(q, f):
        e = a - f
        return np.maximum(q * e, (q - 1.0) * e)

    has50 = np.isfinite(p50)
    d = pd.DataFrame({
        "family": family, "window": scored["window"].to_numpy(), "band": _band_index(scored["step"].to_numpy(), horizons),
        "n": 1.0, "sum_a": a, "hit80": scored["hit_80"].astype(float).to_numpy(), "hit95": scored["hit_95"].astype(float).to_numpy(),
        "pb10": pin(0.1, lo80), "pb50": np.where(has50, pin(0.5, np.where(has50, p50, 0.0)), 0.0), "pb90": pin(0.9, hi80),
        "pb50_mean": pin(0.5, y), "n_p50": has50.astype(float), "w80": hi80 - lo80, "w95": hi95 - lo95,
        "le10": (a <= lo80 + 1e-9).astype(float), "le50": np.where(has50, (a <= p50 + 1e-9), False).astype(float),
        "le90": (a <= hi80 + 1e-9).astype(float),
    }, index=scored.index)
    for col in SEG_COL.values():
        d[col] = scored[col].to_numpy()
    d = d[d["band"] < len(horizons)]
    frames = []
    for dim in dims:
        col = SEG_COL[dim]
        g = d.dropna(subset=[col]).groupby(["family", col, "window", "band"], observed=True, sort=False)[ITV_COLS].sum()
        g = g.reset_index().rename(columns={col: "value"})
        g.insert(1, "dim", dim)
        frames.append(g)
    t = pd.concat(frames, ignore_index=True) if frames else None
    if t is None or t.empty:
        return None
    return _cumulate(t, ["family", "dim", "value", "window"], n_bands, ITV_COLS)


# ───────────────────────────────────────────── ensamblado de métricas ─────────────────────────────────────────────

def _f(v: Any) -> float | None:
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if np.isfinite(x) else None


def _daily(s: dict[str, float], cfg: SegmentConfig, n_series: int) -> dict[str, Any]:
    """Métricas diarias de un (familia, segmento, H) a partir de las sumas acumuladas. Devuelve las cifras CRUDAS y su estado."""
    g = (lambda k: float(s[k]))
    n_ok, n_scaled = int(g("n_ok")), int(g("n_scaled"))
    den = g("sum_a")
    w = guarded_wape(g("sum_ae"), den, cfg.wape_min_denominator)
    reasons = []
    if n_series < cfg.min_series:
        reasons.append({"code": "few_series", "n_series": int(n_series), "min": cfg.min_series})
    if n_ok < cfg.min_obs:
        reasons.append({"code": "few_observations", "evaluable_days": n_ok, "min": cfg.min_obs})
    out: dict[str, Any] = {
        "status": STATUS_INSUFFICIENT if reasons else STATUS_OK, "insufficient_reasons": reasons,
        "n_ok": n_ok, "n_series": int(n_series), "denominator": den, "sum_abs_error": g("sum_ae"),
        "wape": w, "mae": g("sum_ae") / n_ok if n_ok else None,
        "rmse": math.sqrt(g("sum_se") / n_ok) if n_ok else None,
        "mean_error": g("sum_e") / n_ok if n_ok else None,
        "bias_raw": g("sum_e") / den if den > 0 else None,
        "mase": g("sum_sae") / n_scaled if n_scaled else None,
        "rmsse": math.sqrt(g("sum_sse") / n_scaled) if n_scaled else None,
        "n_scaled": n_scaled,
        "excluded": {"stockout_days": int(g("x_stock")), "missing_source_days": int(g("x_missing")),
                     "closed_days": int(g("x_closed"))},
        "sum_scaled_abs": g("sum_sae"),
    }
    if g("x_invalid"):
        out["excluded"]["invalid_values_days"] = int(g("x_invalid"))
    out["n_rows"] = int(g("n_rows"))
    return out


def _interval_metrics(it: dict[str, float] | None) -> dict[str, Any] | None:
    if it is None or float(it["n"]) <= 0:
        return None
    n = float(it["n"])
    tot = float(it["sum_a"])
    n50 = float(it["n_p50"])
    return {
        "n_scored": int(n), "coverage_80": float(it["hit80"]) / n, "coverage_95": float(it["hit95"]) / n,
        "pinball_p10": float(it["pb10"]) / n, "pinball_p90": float(it["pb90"]) / n,
        "pinball_p50": float(it["pb50"]) / n50 if n50 > 0 and n50 == n else None,
        "pinball_p50_yhat_as_median": float(it["pb50_mean"]) / n,
        "interval_width_80": float(it["w80"]) / n, "interval_width_95": float(it["w95"]) / n,
        "interval_width_80_rel": float(it["w80"]) / tot if tot > 0 else None,
        "interval_width_95_rel": float(it["w95"]) / tot if tot > 0 else None,
        "share_actual_le_p10": float(it["le10"]) / n, "share_actual_le_p90": float(it["le90"]) / n,
        "share_actual_le_p50": float(it["le50"]) / n50 if n50 > 0 and n50 == n else None,
    }


def _total_metrics(rec: dict[str, Any] | None, cfg: SegmentConfig) -> dict[str, Any]:
    """Error del TOTAL de H días por serie y ventana (solo ventanas completas) de un (familia, segmento, H)."""
    base: dict[str, Any] = {}      # la definición («suma de los H días por serie y ventana…») vive en el manifiesto: TOTAL_BASIS_TEXT
    if rec is None or rec["n_pairs"] == 0:
        return {**base, "n_pairs": 0, "n_incomplete_pairs": 0 if rec is None else rec["n_incomplete"], "denominator": 0.0,
                "wape": None, "wape_status": "undefined", "wape_unstable": True, "wape_raw": None, "bias": None, "mae": None,
                "sum_abs_error": 0.0, "mean_error": None}
    w = guarded_wape(rec["abs_err"], rec["den"], cfg.wape_min_denominator)
    return {**base, "n_pairs": int(rec["n_pairs"]), "n_incomplete_pairs": int(rec["n_incomplete"]), "denominator": rec["den"],
            "wape": w["trusted"], "wape_status": w["status"], "wape_unstable": w["unstable"], "wape_raw": w["value"],
            "bias": rec["err"] / rec["den"] if w["status"] == WAPE_OK else None,
            "mae": rec["abs_err"] / rec["n_pairs"], "sum_abs_error": rec["abs_err"], "mean_error": rec["err"] / rec["n_pairs"]}


def _verdict(sel: float, ref: float, tol: float) -> tuple[str, float | None]:
    """Compara error de `sel` frente a `ref` (menor es mejor): (veredicto, mejora relativa = 1 − sel/ref)."""
    if ref <= 0:
        return ("similar" if sel <= 0 else "worse"), None
    rel = 1.0 - sel / ref
    return ("better" if rel > tol else "worse" if rel < -tol else "similar"), rel


def _compare_entry(a: dict[str, Any] | None, base: dict[str, Any] | None, cfg: SegmentConfig) -> dict[str, Any]:
    """Comparación de `a` con el baseline: WAPE si ambos son confiables; si no, error absoluto agregado (mismas observaciones)."""
    if a is None or base is None:
        return {"family": None if a is None else a["family"], "verdict": "not_available",
                "reason": "El modelo no tiene filas evaluadas en este segmento y horizonte."}
    if a["status"] != STATUS_OK or base["status"] != STATUS_OK:   # segmento con pocas observaciones: sin cifras, tampoco aquí
        return {"family": a["family"], "n_obs": a["n_ok"], "verdict": "not_comparable", "reason": "insufficient_data"}
    entry: dict[str, Any] = {"family": a["family"], "wape": a["cols"]["wape"], "mase": a["mase"],
                             "sum_abs_error": a["sum_abs_error"], "n_obs": a["n_ok"]}
    same = a["n_ok"] == base["n_ok"]
    entry["same_support"] = same
    if a["wape"]["status"] == WAPE_OK and base["wape"]["status"] == WAPE_OK:
        metric, x, y = "wape", a["wape"]["value"], base["wape"]["value"]
    elif same:
        metric, x, y = "aggregate_absolute_error", a["sum_abs_error"], base["sum_abs_error"]
    else:
        return {**entry, "verdict": "not_comparable", "reason": "wape_unstable_and_different_support"}
    verdict, rel = _verdict(x, y, cfg.similar_tolerance)
    return {**entry, "metric": metric, "value": x, "baseline_value": y, "delta": x - y, "relative_improvement": rel,
            "verdict": verdict}


def _persist_cols(d: dict[str, Any], itv: dict[str, Any] | None) -> dict[str, Any]:
    """Columnas escalares de `model_evaluations`: solo cifras confiables (WAPE y sesgo únicamente con denominador suficiente)."""
    ok = d["status"] == STATUS_OK
    trusted = d["wape"]["status"] == WAPE_OK
    return {
        "wape": d["wape"]["trusted"] if ok else None,
        "mae": d["mae"] if ok else None, "rmse": d["rmse"] if ok else None,
        "bias": d["bias_raw"] if ok and trusted else None,
        "coverage_80": itv["coverage_80"] if ok and itv else None, "coverage_95": itv["coverage_95"] if ok and itv else None,
    }


KEY = ["family", "dim", "value", "band"]


def _sums_index(table: pd.DataFrame | None, cols: list[str]) -> dict[tuple, dict[str, float]]:
    """Sumas de `cols` por (familia, dimensión, valor, banda) sobre todas las ventanas: una sola pasada y consultas O(1)."""
    if table is None or table.empty:
        return {}
    g = table.groupby(KEY, sort=False)[cols].sum()
    return dict(zip(g.index, g.to_dict("records")))


def _window_index(table: pd.DataFrame | None) -> dict[tuple, list[tuple]]:
    """Por (familia, dimensión, valor, banda): una tupla (ventana, n_ok, Σ|e|, Σ reales) por ventana."""
    out: dict[tuple, list[tuple]] = {}
    if table is None or table.empty:
        return out
    for f, d, v, b, w, n, ae, a in zip(table["family"], table["dim"], table["value"], table["band"], table["window"], table["n_ok"],
                                      table["sum_ae"], table["sum_a"]):
        out.setdefault((f, d, v, int(b)), []).append((int(w), float(n), float(ae), float(a)))
    return out


def _stability(index: dict, fam: str, dim: str, value: str, band: int, cfg: SegmentConfig,
               cutoffs: dict[Any, str]) -> dict[str, Any]:
    per = {w: ((ae / a) if a > 0 else None, a) for w, n, ae, a in index.get((fam, dim, value, band), ()) if n > 0}
    out = window_stability(per, cfg.stability_cv_warn, cfg.wape_min_denominator)
    for item in out["per_window"]:
        item["cutoff"] = cutoffs.get(item["window"])
    return out


def _best_statistical(decision: pd.DataFrame) -> dict[tuple[str, str], str]:
    """Mejor estadístico por segmento con la evidencia de DECISIÓN (ventanas de selección; nunca el período final): menor WAPE
    diario con todos los pasos disponibles. Empates: el orden alfabético de la familia (determinista)."""
    if decision.empty:
        return {}
    last = decision["band"].max()
    d = decision[(decision["band"] == last) & decision["family"].isin(STATISTICAL)]
    d = d.groupby(["family", "dim", "value"], as_index=False)[["sum_a", "sum_ae", "n_ok"]].sum()
    d = d[(d["sum_a"] > 0) & (d["n_ok"] > 0)]
    d = d.assign(w=d["sum_ae"] / d["sum_a"]).sort_values(["dim", "value", "w", "family"])
    return {(r.dim, r.value): r.family for r in d.drop_duplicates(["dim", "value"]).itertuples(index=False)}


# ───────────────────────────────────────────── filas de model_evaluations ─────────────────────────────────────────────

def segment_rows(test: pd.DataFrame, calib: pd.DataFrame | None, sel_rows: pd.DataFrame, ctx: dict[str, Any],
                 cfg: SegmentConfig, scored: dict[str, pd.DataFrame | None] | None = None,
                 calib_sel: pd.DataFrame | None = None, horizons: tuple[int, ...] = SUPPORTED_HORIZONS,
                 annotator: Any = None) -> list[dict[str, Any]]:
    """Filas `level='segment'` de un alcance de evaluación.

    `test`: filas del backtest del alcance (ya anotadas con `annotate`); `calib`: las ventanas de selección (anteriores) cuando el alcance
    es el período final reservado; `sel_rows`: las filas del modelo servido (composite) dentro de `test` y `calib_sel` las del modelo
    elegido SOLO con esas ventanas dentro de `calib`; `scored`: filas con intervalo calibrado por familia ('selection', baseline) para
    pinball y cobertura; `ctx`: evaluation_type, scope, cutoffs. `annotator`: función `filas → filas con columnas de segmento`
    (`annotate`); las filas llegan SIN anotar y el llamador conserva las suyas intactas. Sin ella se esperan filas ya anotadas."""
    if test.empty or sel_rows.empty:
        return []
    if annotator is not None:
        test, sel_rows = annotator(test), annotator(sel_rows)
        calib = annotator(calib) if calib is not None and not calib.empty else calib
        calib_sel = annotator(calib_sel) if calib_sel is not None and not calib_sel.empty else calib_sel
        scored = {fam: (annotator(sc) if sc is not None and not sc.empty else sc) for fam, sc in (scored or {}).items()}
    max_step = int(test["step"].max())
    emit = [h for h in horizons if h <= max_step]
    if not emit:
        return []
    n_bands = len(horizons)
    dims = tuple(d for d in DIMENSIONS if test[SEG_COL[d]].notna().any())
    rows_all = pd.concat([test, sel_rows.assign(family=SELECTION_FAMILY)], ignore_index=True)
    prep = _prepare(rows_all, horizons)
    daily = _daily_table(prep, dims, n_bands)
    pairs = _pair_table(prep, n_bands)
    attrs = prep.drop_duplicates(["unique_id", "window"])[["unique_id", "window", *SEG_COL.values()]]
    cutoffs = {int(w): pd.Timestamp(c).date().isoformat() for w, c in test.groupby("window")["cutoff"].first().items()}

    # Evidencia de DECISIÓN del mejor estadístico y estabilidad de referencia: las ventanas de selección (anteriores al período
    # final). Sin período final independiente son las mismas ventanas que se evalúan (optimista, así queda declarado).
    independent = calib is not None and not calib.empty
    if independent:
        decision_rows = calib if calib_sel is None or calib_sel.empty else pd.concat(
            [calib, calib_sel.assign(family=SELECTION_FAMILY)], ignore_index=True)
        decision = _daily_table(_prepare(decision_rows, horizons), dims, n_bands)
    else:
        decision = daily
    best_stat = _best_statistical(decision)
    cal_cutoffs = ({int(w): pd.Timestamp(c).date().isoformat() for w, c in calib.groupby("window")["cutoff"].first().items()}
                   if independent else cutoffs)

    # Valores por dimensión (tope en las de alta cardinalidad: se conservan los de mayor volumen del modelo servido).
    sel_last = daily[(daily["family"] == SELECTION_FAMILY) & (daily["band"] == n_bands - 1)]
    volume = sel_last.groupby(["dim", "value"])["sum_a"].sum()
    values: dict[str, list[str]] = {}
    omitted: dict[str, int] = {}
    for dim in dims:
        v = volume.loc[dim] if dim in volume.index.get_level_values(0) else pd.Series(dtype=float)
        if dim in ("category", "branch") and len(v) > cfg.max_values:
            keep = v.sort_values(ascending=False, kind="stable").head(cfg.max_values).index
            omitted[dim] = int(len(v) - len(keep))
            v = v.loc[keep]
        values[dim] = sorted(v.index.astype(str))
    present = {(d, x) for d in values for x in values[d]}

    # Series y ventanas por (familia, dimensión, valor, banda) y totales de H días por segmento.
    pair_cur = pairs.merge(attrs, on=["unique_id", "window"], how="left")
    seg_stats: dict[tuple, dict[str, Any]] = {}
    for j, h in enumerate(horizons):
        if h not in emit:
            continue
        cur = pair_cur[(pair_cur["band"] == j) & (pair_cur["n_rows"] > 0)]
        for dim in dims:
            col = SEG_COL[dim]
            c = cur.dropna(subset=[col])
            if c.empty:
                continue
            c = c.assign(complete=(c["n_rows"] == h) & (c["n_ok"] > 0), err=c["sum_p"] - c["sum_a"])
            c = c.assign(abs_err=c["err"].abs())
            for (fam, val), g in c.groupby(["family", col]):
                if (dim, val) not in present:
                    continue
                comp = g[g["complete"]]
                seg_stats[(fam, dim, val, j)] = {
                    "n_series": int(g.loc[g["n_ok"] > 0, "unique_id"].nunique()),     # series con al menos un día evaluable (una serie 100 % censurada no cuenta)
                    "n_series_with_rows": int(g["unique_id"].nunique()),
                    "total": {"n_pairs": int(len(comp)), "n_incomplete": int(len(g) - len(comp)), "den": float(comp["sum_a"].sum()),
                              "err": float(comp["err"].sum()), "abs_err": float(comp["abs_err"].sum())}}

    itv_tables = {fam: _interval_table(sc, fam, horizons, dims, n_bands) for fam, sc in (scored or {}).items()}
    families = sorted(daily["family"].unique())

    daily_sums, daily_win = _sums_index(daily, SUM_COLS), _window_index(daily)
    decision_win = daily_win if decision is daily else _window_index(decision)
    itv_sums = {fam: _sums_index(t, ITV_COLS) for fam, t in itv_tables.items()}

    rows: list[dict[str, Any]] = []
    for dim in dims:
        for val in values.get(dim, []):
            for j, h in enumerate(horizons):
                if h not in emit:
                    continue
                built: dict[str, dict[str, Any]] = {}
                for fam in families:
                    sums = daily_sums.get((fam, dim, val, j))
                    if sums is None or sums["n_rows"] <= 0:
                        continue
                    st = seg_stats.get((fam, dim, val, j), {"n_series": 0, "total": None})
                    d = _daily(sums, cfg, st["n_series"])
                    d["family"] = fam
                    d["n_windows"] = sum(1 for r in daily_win.get((fam, dim, val, j), ()) if r[1] > 0)
                    it_sums = itv_sums.get(fam, {}).get((fam, dim, val, j))
                    it = _interval_metrics(it_sums) if it_sums is not None else None
                    d["interval"] = it
                    d["cols"] = _persist_cols(d, it)
                    d["total"] = _total_metrics(st["total"], cfg)
                    built[fam] = d
                if SELECTION_FAMILY not in built:
                    continue
                base = built.get(BASELINE_FAMILY)
                best_family = best_stat.get((dim, val))
                best = built.get(best_family) if best_family else None
                ml = built.get(ML_FAMILY)
                roles: dict[str, list[str]] = {SELECTION_FAMILY: ["selected"]}
                if base is not None:
                    roles.setdefault(BASELINE_FAMILY, []).append("baseline")
                if best is not None:
                    roles.setdefault(best_family, []).append("best_statistical")
                if ml is not None:
                    roles.setdefault(ML_FAMILY, []).append("ml")
                comparison = None
                if base is not None:
                    comparison = {
                        "baseline_family": BASELINE_FAMILY, "best_statistical_family": best_family,
                        "best_statistical_chosen_with": CHOSEN_WITH_SELECTION if independent else CHOSEN_WITH_SAME_WINDOWS,
                        "similar_tolerance": cfg.similar_tolerance,
                        "selected_vs_baseline": _compare_entry(built[SELECTION_FAMILY], base, cfg),
                        "best_statistical_vs_baseline": _compare_entry(best, base, cfg),
                        "ml_vs_baseline": _compare_entry(ml, base, cfg),
                    }
                for fam, tags in roles.items():
                    d = built[fam]
                    stab = _stability(daily_win, fam, dim, val, j, cfg, cutoffs)
                    stab_ref = None
                    if independent:
                        stab_ref = _stability(decision_win, fam, dim, val, j, cfg, cal_cutoffs)
                        stab_ref["source"] = "selection_windows"
                    rows.append(_row(fam, tags, dim, val, h, d, stab, stab_ref, comparison if fam == SELECTION_FAMILY else None,
                                     ctx, cfg, independent))
    for r in rows:
        r["extra"]["omitted_values"] = omitted
    return rows


def _withheld_stability(stab: dict[str, Any]) -> dict[str, Any]:
    """Un segmento con pocas observaciones no publica el WAPE por ventana: solo cuántas ventanas tuvieron datos."""
    return {"status": "withheld_insufficient_data", "n_windows": stab.get("n_windows"), "per_window": [],
            "wape_mean": None, "wape_std": None, "wape_cv": None, "wape_min": None, "wape_max": None,
            "unstable_across_windows": None}


def _row(fam: str, tags: list[str], dim: str, val: str, h: int, d: dict[str, Any], stab: dict[str, Any],
         stab_ref: dict[str, Any] | None, comparison: dict[str, Any] | None, ctx: dict[str, Any], cfg: SegmentConfig,
         independent: bool) -> dict[str, Any]:
    insufficient = d["status"] == STATUS_INSUFFICIENT
    w = d["wape"]
    cols = d["cols"]
    it = d["interval"] if not insufficient else None
    alt = None
    if not insufficient and w["unstable"]:
        alt = {**alternative_metric(d["sum_abs_error"], d["n_ok"], d["sum_scaled_abs"], d["n_scaled"]),
               "used_because": "wape_undefined" if w["status"] == "undefined" else "wape_unstable"}
    metrics: dict[str, Any] = {
        "metrics_version": "demand-metrics-v1", "segment_metrics_version": SEGMENT_METRICS_VERSION,
        "definitions_ref": DEFINITIONS_REF,
        "segment": {"dimension": dim, "value": val, "horizon_days": h, "horizon_basis": HORIZON_BASIS, "roles": tags},
        "status": d["status"], "insufficient_reasons": d["insufficient_reasons"], "withheld": insufficient,
        "wape": cols["wape"], "wape_status": w["status"], "wape_unstable": w["unstable"],
        "wape_raw": None if insufficient else w["value"],
        "denominator": {"sum_actual": d["denominator"], "unit": "unidades", "min_required": cfg.wape_min_denominator},
        "sum_abs_error": None if insufficient else d["sum_abs_error"],
        "alternative_metric": alt,
        "mase": None if insufficient else d["mase"], "rmsse": None if insufficient else d["rmsse"], "scale": SCALE_DEFINITION,
        "bias": cols["bias"], "bias_raw": None if insufficient else d["bias_raw"],
        "mean_error": None if insufficient else d["mean_error"],
        "pinball_p10": None, "pinball_p50": None, "pinball_p90": None, "pinball_p50_yhat_as_median": None,
        "interval_width_80": None, "interval_width_95": None,
        "interval": None,
        "sample": {"n_obs": d["n_ok"], "n_series": d["n_series"], "n_windows": d["n_windows"],
                   "exclusions": {"scale_undefined": d["n_ok"] - d["n_scaled"], **d["excluded"]},
                   "evaluable_days": d["n_ok"], "excluded_days": sum(d["excluded"].values()), "total_days": d["n_rows"],
                   "evaluable_share": d["n_ok"] / d["n_rows"] if d["n_rows"] else None},
        "horizon_total": None if insufficient else d["total"],
        "stability": _withheld_stability(stab) if insufficient else stab,
        "stability_selection_windows": (_withheld_stability(stab_ref) if insufficient and stab_ref is not None else stab_ref),
        "comparison": comparison,
    }
    if it is not None:
        for k in ("pinball_p10", "pinball_p50", "pinball_p90", "pinball_p50_yhat_as_median", "interval_width_80", "interval_width_95"):
            metrics[k] = it[k]
        keep = ("n_scored", "coverage_80", "coverage_95", "interval_width_80", "interval_width_95", "interval_width_80_rel",
                "interval_width_95_rel")   # las cifras de P50 solo viajan en el nivel superior (sujetas a la decisión de publicar P50)
        metrics["interval"] = {**{k: it[k] for k in keep}, "basis": INTERVAL_BASIS}
        for k in ("share_actual_le_p10", "share_actual_le_p50", "share_actual_le_p90"):
            metrics[k] = it[k]
    elif not insufficient:
        metrics["interval"] = {"basis": None, "available": False,
                               "reason": "no_independent_holdout" if not independent else "intervals_only_for_served_and_baseline"}
    return {
        "model_family": fam, "evaluation_type": ctx["evaluation_type"], "level": LEVEL_SEGMENT, "level_key": f"{dim}:{val}",
        "horizon_days": h, "is_baseline": fam == BASELINE_FAMILY, "is_selected": fam == SELECTION_FAMILY,
        "wape": cols["wape"], "mae": cols["mae"], "rmse": cols["rmse"], "bias": cols["bias"],
        "coverage_80": cols["coverage_80"], "coverage_95": cols["coverage_95"], "n_obs": d["n_ok"], "n_windows": d["n_windows"],
        "cutoffs": ctx["cutoffs"], "metrics": metrics,
        "extra": {"evaluation_scope": ctx["scope"], "independent_holdout": ctx["scope"] == "holdout", "segment_dimension": dim,
                  "segment_value": val, "horizon_basis": HORIZON_BASIS, "roles": tags},
    }


# ───────────────────────────────────────────── manifiesto y resumen de la ejecución ─────────────────────────────────────────────

def manifest_block(config: SegmentConfig | None) -> dict[str, Any]:
    """Definiciones ESTÁTICAS de las filas `level='segment'`, una sola copia por ejecución (`forecast_runs.evaluation_manifest.segment_metrics`):
    las filas de `model_evaluations` solo traen cifras y códigos y remiten aquí (`metrics.definitions_ref`)."""
    if config is None:
        return {"version": SEGMENT_METRICS_VERSION, "enabled": False}
    return {
        "version": SEGMENT_METRICS_VERSION, "enabled": True, "dimensions": list(DIMENSIONS), "thresholds": config.thresholds(),
        "horizon_basis": {HORIZON_BASIS: HORIZON_BASIS_TEXT},
        "total_basis": TOTAL_BASIS_TEXT,
        "interval_basis": {INTERVAL_BASIS: "series-día; intervalos conformales calibrados solo con residuos de las ventanas de selección "
                                           "anteriores y medidos en el período final reservado; solo servido y baseline"},
        "comparison_basis": ("WAPE diario si ambos son confiables; si no, error absoluto agregado sobre las mismas observaciones; "
                             "«similar» = diferencia relativa ≤ comparison.similar_tolerance (a aprobar en el piloto)"),
        "best_statistical_chosen_with": {CHOSEN_WITH_SELECTION: "ventanas de selección (nunca el período final)",
                                         CHOSEN_WITH_SAME_WINDOWS: "las mismas ventanas evaluadas, sin período final independiente (optimista)"},
        "stability_basis": ("WAPE por ventana; solo cuentan las ventanas con denominador ≥ wape_min_denominator; el período final es una sola "
                            "ventana (desvío no estimable)"),
        "withheld": "status = insufficient_data: la fila trae tamaños de muestra y denominador, sin cifras de error",
        "detail": SUMMARY_DETAIL,
    }


SUMMARY_DETAIL = {"table": "model_evaluations", "filters": {"core": "demand", "level": "segment", "evaluation_type": "backtest",
                                                            "model_family": "selection"}}
REASON_RANK = {"worse": 0, "wape_undefined": 1, "wape_unstable": 2, "insufficient_data": 3}


def _cell_reason(m: dict[str, Any], verdict: str | None) -> str | None:
    """Por qué una celda merece atención (None = ok sin alertas): peor que el baseline, WAPE indefinido/inestable o sin datos suficientes."""
    if m["status"] == STATUS_INSUFFICIENT:
        return "insufficient_data"
    if verdict == "worse":
        return "worse"
    return {"undefined": "wape_undefined", "unstable": "wape_unstable"}.get(m["wape_status"])


def summary_block(evaluations: list[dict[str, Any]], config: SegmentConfig | None, horizons: tuple[int, ...] = SUPPORTED_HORIZONS,
                  error: str | None = None) -> dict[str, Any]:
    """Resumen COMPACTO de las métricas del modelo SERVIDO (`forecast_runs.summary.segment_metrics`). El tamaño no crece con el número de
    segmentos (el backend devuelve el resumen completo en cada listado de ejecuciones): trae conteos por dimensión y estado y, como
    máximo, `segment_summary_max_cells` celdas que merecen atención (peor que el baseline, WAPE indefinido o inestable, sin datos
    suficientes; en ese orden). El detalle por segmento y horizonte está en `model_evaluations` (`detail`) y las definiciones, en el
    manifiesto. No sustituye a `summary.metrics`."""
    rows = [e for e in evaluations if e["level"] == LEVEL_SEGMENT and e["evaluation_type"] == "backtest"]
    block: dict[str, Any] = {"version": SEGMENT_METRICS_VERSION, "enabled": config is not None, "available": True,
                             "dimensions": list(DIMENSIONS), "scope": None, "independent_holdout": None,
                             "horizons_evaluated": [], "horizons_not_evaluated": [],
                             "counts": {"segments": 0, "cells": 0, "by_status": {}, "by_reason": {}, "by_dimension": {}},
                             "attention": [], "attention_total": 0, "attention_truncated": False, "detail": SUMMARY_DETAIL,
                             "definitions_ref": DEFINITIONS_REF}
    if config is None:
        block["note"] = "Métricas por segmento desactivadas (segment_metrics_enabled = false)."
        return block
    if error is not None:
        block.update(available=False, error=error, note="Las métricas por segmento no están disponibles en esta ejecución "
                                                          "(limitación segment_metrics_unavailable); el pronóstico no cambió.")
        return block
    if not rows:
        block["note"] = "Sin backtesting con segmentos evaluables: no hay métricas por segmento."
        return block
    scope = rows[0]["extra"]["evaluation_scope"]
    block.update(scope=scope, independent_holdout=scope == "holdout")
    evaluated = sorted({int(e["horizon_days"]) for e in rows})
    block["horizons_evaluated"] = evaluated
    block["horizons_not_evaluated"] = [h for h in horizons if h not in evaluated]
    base_by = {(e["level_key"], int(e["horizon_days"])): e for e in rows if e["model_family"] == BASELINE_FAMILY}
    by_status: dict[str, int] = {}
    by_reason: dict[str, int] = {}
    by_dim: dict[str, dict[str, Any]] = {}
    attention: list[tuple] = []
    segments: set[str] = set()
    cells = 0
    for e in rows:
        if e["model_family"] != SELECTION_FAMILY:
            continue
        m = e["metrics"]
        dim = e["extra"]["segment_dimension"]
        cmp_ = (m.get("comparison") or {}).get("selected_vs_baseline") or {}
        verdict = cmp_.get("verdict")
        reason = _cell_reason(m, verdict)
        cells += 1
        segments.add(e["level_key"])
        by_status[m["status"]] = by_status.get(m["status"], 0) + 1
        d = by_dim.setdefault(dim, {"segments": set(), "cells": 0})
        d["segments"].add(e["level_key"])
        d["cells"] += 1
        for key in {m["status"], reason or "no_alert", verdict if m["status"] == STATUS_OK else None} - {None}:
            d[key] = d.get(key, 0) + 1
        if reason is not None:
            by_reason[reason] = by_reason.get(reason, 0) + 1
            b = base_by.get((e["level_key"], int(e["horizon_days"])))
            cell = {"segment": e["level_key"], "horizon": int(e["horizon_days"]), "reason": reason, "wape": m["wape"],
                    "wape_status": m["wape_status"], "denominator": m["denominator"]["sum_actual"],
                    "evaluable_days": m["sample"]["evaluable_days"], "baseline_wape": None if b is None else b["metrics"]["wape"]}
            rel = cmp_.get("relative_improvement")
            attention.append((REASON_RANK[reason], rel if rel is not None else 0.0, cell["horizon"], cell["segment"], cell))
    attention.sort(key=lambda t: t[:4])
    cap = max(0, config.summary_max_cells)
    block["attention"] = [t[4] for t in attention[:cap]]
    block["attention_total"] = len(attention)
    block["attention_truncated"] = len(attention) > cap
    block["counts"] = {"segments": len(segments), "cells": cells, "by_status": by_status, "by_reason": by_reason,
                       "by_dimension": {k: {**{kk: vv for kk, vv in v.items() if kk != "segments"}, "segments": len(v["segments"])}
                                        for k, v in sorted(by_dim.items())}}
    omitted = rows[0]["extra"].get("omitted_values") or {}
    if omitted:
        block["omitted_values"] = omitted
    return block
