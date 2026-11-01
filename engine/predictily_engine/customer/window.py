"""Ventana probable de recompra: distribución del tiempo hasta la siguiente compra (V2.5 §7 tarea C, §25.2; ADR-V25-11).

La ventana es un conjunto de CUANTILES P10/P50/P90 de los días hasta la siguiente compra contados desde el corte (`as_of`); nunca un día
exacto. Método (docs/ml/CUSTOMER_REPURCHASE.md §4):

1. Distribución empírica de los intervalos propios de la relación (los `gap_k` últimos, cada uno repartido en ±½ día por medirse en días enteros),
   suavizada con una exponencial a priori de
   `prior_weight` pseudo-observaciones cuya media es JERÁRQUICA: se encoge hacia los intervalos de las otras relaciones del mismo comprador
   en la misma categoría, luego hacia los de otros compradores de la categoría y por último hacia el promedio de la empresa (cada nivel pesa
   como máximo `prior_cap` observaciones). F(x) = [ #intervalos propios ≤ x + n0·(1 − e^{−x/μ₀}) ] / (m + n0).
2. Condicionada a que la próxima compra aún no ocurrió: con recencia r (días desde la última compra), el tiempo restante T cumple
   P(T ≤ t) = [F(r + t) − F(r)] / [1 − F(r)]. El cuantil de nivel q es el menor t entero con esa probabilidad ≥ q.
3. Recalibración de niveles (por cantidad de intervalos propios: 1–3, 4–7, 8+): con pocos intervalos el cuantil empírico es ruidoso y la
   cobertura real queda por debajo de la nominal. Con cortes de AJUSTE anteriores a los de evaluación (sus resultados ya eran conocibles en el
   primer corte de evaluación: separación ≥ `eval_days`) se elige, por grupo, el nivel de la distribución suavizada que alcanza el 10 / 50 / 90 %
   observado. Sin suficientes filas de ajuste, los niveles quedan nominales (0,1 / 0,5 / 0,9) y se declara.
4. Estado (`window_status`): `censored` (producto sin stock en la sucursal habitual al corte: la ausencia de compra no es información),
   `insufficient_data` (menos de `min_own_gaps` intervalos propios: sin cifras), `overdue` (la brecha actual supera el cuantil `overdue_quantile`
   de sus intervalos más una holgura: la ventana es una cola extrapolada, no un patrón; NO es abandono) y `estimated`.

Se valida con la cobertura de [P10, P90] y el error del P50 sobre cortes de EVALUACIÓN con seguimiento suficiente. Una relación que se
perdió (abandono) nunca vuelve a comprar: eso baja la cobertura observada y se reporta tal cual (la ventana es «cuándo, si vuelve»). Todo lo
que usa es conocido al corte (índices de eventos hasta el día del corte). Las constantes vienen de la configuración («a aprobar en el piloto»).
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, replace
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.customer.outputs import _row_quantile

QUANTILES = (0.1, 0.5, 0.9)
STATUS_ESTIMATED = "estimated"
STATUS_INSUFFICIENT = "insufficient_data"
STATUS_OVERDUE = "overdue"
STATUS_CENSORED = "censored"
WINDOW_STATUSES = (STATUS_ESTIMATED, STATUS_INSUFFICIENT, STATUS_OVERDUE, STATUS_CENSORED)
WINDOW_COLUMNS = ["window_p10_days", "window_p50_days", "window_p90_days", "window_status"]
BUCKETS: dict[str, tuple[int, int]] = {"1_3": (1, 3), "4_7": (4, 7), "8_plus": (8, 10 ** 6)}
# Rejillas de niveles de la recalibración (solo ensanchan hacia las colas; la mediana puede moverse a ambos lados).
LOWER_GRID = (0.10, 0.08, 0.06, 0.04, 0.02, 0.01)
MEDIAN_GRID = (0.30, 0.35, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80)
UPPER_GRID = (0.90, 0.92, 0.94, 0.96, 0.98, 0.99)
_CHUNK = 500        # filas por bloque del cálculo (memoria acotada: ~500 × gap_k × max_days valores por arreglo temporal)
_MU_FLOOR = 0.5


@dataclass(frozen=True)
class WindowParams:
    gap_k: int = 12
    min_own_gaps: int = 2
    prior_weight: float = 2.0
    prior_cap: int = 6
    max_days: int = 365
    overdue_quantile: float = 0.9
    tolerance_fraction: float = 0.2
    eval_days: int = 90
    eval_max_cutoffs: int = 12
    eval_min_rows: int = 100            # relaciones DISTINTAS evaluables mínimas (global)
    eval_min_customers: int = 30        # compradores DISTINTOS evaluables mínimos (global)
    eval_min_cutoffs: int = 4           # cortes de evaluación mínimos: una sola realización del calendario no valida nada
    group_min_relations: int = 50       # relaciones distintas mínimas por grupo (intervalos propios / overdue)
    eval_max_rows: int = 10000
    coverage_tolerance: float = 0.10

    @classmethod
    def from_cfg(cls, cfg: dict[str, Any]) -> WindowParams:
        return cls(gap_k=int(cfg["window_gap_k"]), min_own_gaps=int(cfg["window_min_own_gaps"]), prior_weight=float(cfg["window_prior_weight"]),
                   prior_cap=int(cfg["window_prior_cap"]), max_days=int(cfg["window_max_days"]),
                   overdue_quantile=float(cfg["window_overdue_quantile"]), tolerance_fraction=float(cfg["inactivity_tolerance_fraction"]),
                   eval_days=int(cfg["window_eval_days"]), eval_max_cutoffs=int(cfg["window_eval_max_cutoffs"]),
                   eval_min_rows=int(cfg.get("window_eval_min_rows", 100)), eval_min_customers=int(cfg.get("window_eval_min_customers", 30)),
                   eval_min_cutoffs=int(cfg.get("window_eval_min_cutoffs", 4)),
                   group_min_relations=int(cfg.get("window_group_min_relations", 50)), eval_max_rows=int(cfg.get("window_eval_max_rows", 10000)),
                   coverage_tolerance=float(cfg["window_coverage_tolerance"]))

    @classmethod
    def from_dict(cls, d: dict[str, Any] | None, cfg: dict[str, Any]) -> WindowParams:
        """Parámetros guardados en el artefacto (lo evaluado es lo servido); sin ellos, la configuración vigente."""
        if not d:
            return cls.from_cfg(cfg)
        names = set(cls.__dataclass_fields__)
        return cls(**{k: v for k, v in d.items() if k in names})

    def as_dict(self) -> dict[str, Any]:
        return asdict(self)


def bucket_of(m: np.ndarray) -> np.ndarray:
    """Grupo de cantidad de intervalos propios (`BUCKETS`) de cada fila."""
    m = np.asarray(m)
    out = np.full(len(m), "", dtype=object)
    for name, (lo, hi) in BUCKETS.items():
        out[(m >= lo) & (m <= hi)] = name
    return out


def _row_levels(m: np.ndarray, levels: dict[str, dict[str, float]] | None) -> np.ndarray:
    """Niveles (a, c, b) de cada fila según su grupo; sin niveles ajustados, los nominales."""
    out = np.tile(np.asarray(QUANTILES, dtype=np.float64), (len(m), 1))
    if levels:
        for name, lv in levels.items():
            sel = bucket_of(m) == name
            if sel.any():
                out[sel] = [lv["p10_level"], lv["p50_level"], lv["p90_level"]]
    return out


def _hierarchical_mean_interval(fb, R: np.ndarray, gn: np.ndarray, gs: np.ndarray, params: WindowParams) -> np.ndarray:
    """Media a priori del intervalo entre compras de cada relación: comprador-categoría → categoría → empresa (sin contar sus propios
    intervalos: cada nivel excluye lo ya contenido en el anterior)."""
    pid = fb.rel_product[R]
    cat = pd.Series(pid).map(fb.product_category).fillna(-1).to_numpy()
    cat = np.where(np.isfinite(cat), cat, -1).astype(np.int64)
    cat_key = np.where(cat >= 0, cat, -(pid.astype(np.int64) + 1))        # producto sin categoría: no se agrupa con otros
    ck = pd.MultiIndex.from_arrays([fb.rel_ccode[R], cat_key]).factorize()[0]
    ct = pd.factorize(cat_key)[0]
    ck_sum, ck_n = np.bincount(ck, weights=gs), np.bincount(ck, weights=gn)
    ct_sum, ct_n = np.bincount(ct, weights=gs), np.bincount(ct, weights=gn)
    ck_sum_o, ck_n_o = ck_sum[ck] - gs, ck_n[ck] - gn                       # otras relaciones del comprador en la categoría
    ct_sum_o, ct_n_o = ct_sum[ct] - ck_sum[ck], ct_n[ct] - ck_n[ck]         # otros compradores de la categoría
    total_n = float(gn.sum())
    glob = float(gs.sum() / total_n) if total_n > 0 else 30.0
    cap = float(params.prior_cap)
    w_ck, w_ct = np.minimum(np.maximum(ck_n_o, 0.0), cap), np.minimum(np.maximum(ct_n_o, 0.0), cap)
    mu_ck = np.divide(ck_sum_o, ck_n_o, out=np.zeros(len(R)), where=ck_n_o > 0)
    mu_ct = np.divide(ct_sum_o, ct_n_o, out=np.zeros(len(R)), where=ct_n_o > 0)
    mu = (w_ck * mu_ck + w_ct * mu_ct + glob) / (w_ck + w_ct + 1.0)
    return np.maximum(mu, _MU_FLOOR)


def _conditional_quantiles(G: np.ndarray, r: np.ndarray, mu: np.ndarray, m: np.ndarray, params: WindowParams, levels: np.ndarray) -> np.ndarray:
    """Cuantiles (días desde el corte) de T | T > 0 a los niveles `levels` (forma (n, L) o (L,)); NaN si el cuantil excede `max_days`."""
    n = len(r)
    levels = np.broadcast_to(np.asarray(levels, dtype=np.float64), (n, np.asarray(levels).shape[-1])) if n else np.zeros((0, 0))
    out = np.full((n, levels.shape[1] if n else 0), np.nan)
    if n == 0:
        return out
    t = np.arange(1, params.max_days + 1, dtype=np.float64)
    n0 = float(params.prior_weight)
    for s in range(0, n, _CHUNK):
        e = min(n, s + _CHUNK)
        g, rc, muc, mc = G[s:e], r[s:e], mu[s:e], m[s:e]
        den = np.maximum(mc + n0, 1e-9)
        # Corrección de continuidad: los intervalos se miden en días enteros, así que cada uno se reparte uniformemente en [g − ½, g + ½]
        # (una serie perfectamente regular da un rango de 1–2 días, no un punto).
        with np.errstate(invalid="ignore"):
            cnt_r = np.nan_to_num(np.clip(rc[:, None] - g + 0.5, 0.0, 1.0), nan=0.0).sum(axis=1)
            f_r = (cnt_r + n0 * (1.0 - np.exp(-rc / muc))) / den
            x = rc[:, None] + t[None, :]
            cnt = np.nan_to_num(np.clip(x[:, None, :] - g[:, :, None] + 0.5, 0.0, 1.0), nan=0.0).sum(axis=1)
        f = (cnt + n0 * (1.0 - np.exp(-x / muc[:, None]))) / den[:, None]
        cond = (f - f_r[:, None]) / np.maximum(1.0 - f_r, 1e-9)[:, None]
        rows = np.arange(e - s)
        for j in range(levels.shape[1]):
            ok = cond >= levels[s:e, j][:, None]
            idx = ok.argmax(axis=1)
            out[s:e, j] = np.where(ok[rows, idx], t[idx], np.nan)
    return out


def _window_inputs(fb, c: int, base: pd.DataFrame, params: WindowParams) -> dict[str, np.ndarray]:
    R = base["rel"].to_numpy(np.int64)
    ev = fb.events_at(c).ev
    hi = ev.pos(R, c)
    st = ev.start[R]
    G = ev.last_gaps(hi, R, params.gap_k)
    gn = (ev.cum_gapn[hi] - ev.cum_gapn[st]).astype(np.float64)
    gs = (ev.cum_gap[hi] - ev.cum_gap[st]).astype(np.float64)
    return {"G": G, "m": (~np.isnan(G)).sum(axis=1), "r": (c - base["last_day"].to_numpy(np.int64)).astype(np.float64),
            "mu": _hierarchical_mean_interval(fb, R, gn, gs, params)}


def window_from_base(fb, c: int, base: pd.DataFrame, params: WindowParams, with_reference: bool = False,
                     levels: dict[str, dict[str, float]] | None = None, candidate_levels: np.ndarray | None = None,
                     max_rows: int | None = None, inputs: dict[str, np.ndarray] | None = None) -> pd.DataFrame:
    """Ventana de cada relación de `base` (los candidatos del corte `c` tal como los devuelve `FeatureBuilder.compute`; deben ser TODOS los
    candidatos del corte para que el suavizado jerárquico use la población completa). Devuelve un DataFrame alineado por posición con
    `window_p10_days`, `window_p50_days`, `window_p90_days`, `window_status`, `window_own_gaps`, `window_prior_mean`, `rel` y `customer_id`.
    `levels`: niveles recalibrados por grupo (`fit_levels`); sin ellos, 0,1 / 0,5 / 0,9. Con `with_reference` agrega el intervalo ingenuo
    (cuantiles de los intervalos propios menos la recencia, sin suavizar ni condicionar). `candidate_levels` (L,): agrega las columnas
    `cand_0..cand_{L-1}` con el cuantil a cada nivel (para ajustar niveles). `max_rows` (evaluación): calcula los cuantiles solo de una muestra
    reproducible (semilla = corte) de las filas con cifras; el suavizado jerárquico siempre usa a TODOS los candidatos. La columna
    `window_sampled` es False en las filas que quedaron fuera de la muestra. `inputs`: entradas ya calculadas del corte (`_window_inputs`; el
    ajuste, la evaluación y la comparación nominal las reutilizan en vez de releer los eventos del corte)."""
    n = len(base)
    cols = [*WINDOW_COLUMNS, "window_own_gaps", "window_prior_mean", "window_sampled"]
    if n == 0:
        return pd.DataFrame({k: pd.Series(dtype=object if k == "window_status" else float) for k in cols})
    inp = inputs if inputs is not None else _window_inputs(fb, c, base, params)
    G, m, r, mu = inp["G"], inp["m"], inp["r"], inp["mu"]
    avail = base["avail_now"].to_numpy(dtype=float) if "avail_now" in base else np.full(n, np.nan)
    censored = avail == 0
    enough = m >= params.min_own_gaps
    status = np.full(n, STATUS_ESTIMATED, dtype=object)
    q_over = _row_quantile(G, params.overdue_quantile)
    med = _row_quantile(G, 0.5)
    with np.errstate(invalid="ignore"):
        overdue = enough & (r > q_over + params.tolerance_fraction * np.nan_to_num(med, nan=0.0))
    status[overdue] = STATUS_OVERDUE
    status[~enough] = STATUS_INSUFFICIENT
    status[censored] = STATUS_CENSORED
    compute = enough & ~censored
    sampled = np.ones(n, dtype=bool)
    if max_rows is not None and int(compute.sum()) > max_rows:
        keep = np.random.default_rng(int(c)).choice(np.nonzero(compute)[0], size=int(max_rows), replace=False)
        sampled = ~compute
        sampled[keep] = True
        compute = np.zeros(n, dtype=bool)
        compute[keep] = True
    q = np.full((n, len(QUANTILES)), np.nan)
    if compute.any():
        q[compute] = _conditional_quantiles(G[compute], r[compute], mu[compute], m[compute], params, _row_levels(m[compute], levels))
    out = pd.DataFrame({"window_p10_days": q[:, 0], "window_p50_days": q[:, 1], "window_p90_days": q[:, 2], "window_status": status,
                        "window_own_gaps": m.astype(int), "window_prior_mean": mu, "window_sampled": sampled,
                        "rel": base["rel"].to_numpy(np.int64), "customer_id": base["customer_id"].to_numpy(np.int64)})
    if with_reference:
        for qq in QUANTILES:
            with np.errstate(invalid="ignore"):
                out[f"ref_p{int(qq * 100)}"] = np.where(enough, np.maximum(_row_quantile(G, qq) - r, 1.0), np.nan)
    if candidate_levels is not None:
        cl = np.asarray(candidate_levels, dtype=np.float64)
        cand = np.full((n, len(cl)), np.nan)
        if compute.any():
            cand[compute] = _conditional_quantiles(G[compute], r[compute], mu[compute], m[compute], params, cl)
        for j in range(len(cl)):
            out[f"cand_{j}"] = cand[:, j]
    return out


# ───────────────────────────── validación: qué se puede servir ─────────────────────────────

def verdict(n_relations: int, n_customers: int, coverage: float | None, min_relations: int, min_customers: int, nominal: float, tolerance: float) -> tuple[str, str | None]:
    """Veredicto de un conjunto de filas evaluables: `insufficient_data` (pocas relaciones o compradores DISTINTOS), `validated` (cobertura ≥ nominal − tolerancia)
    o `not_validated`."""
    if n_relations < min_relations or n_customers < min_customers or coverage is None:
        return "insufficient_data", (f"{n_relations} relaciones y {n_customers} compradores distintos evaluables (mínimo {min_relations} y {min_customers})")
    if coverage >= nominal - tolerance:
        return "validated", None
    return "not_validated", f"cobertura {coverage:.3f} < {nominal - tolerance:.2f} (nominal {nominal} − tolerancia)"


def apply_validation(win: pd.DataFrame, payload: dict[str, Any] | None) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Retiene las cifras que la validación no respalda. `window_status` es la columna que consumen la API y la UI, así que refleja la validación:
    - validación global `insufficient_data` o `not_validated` → TODA fila con cifras pasa a `insufficient_data` y sus cuantiles quedan nulos;
    - con validación global, el grupo de la fila (cantidad de intervalos propios) y, si está `overdue`, el grupo `overdue` deben estar `validated`; si no,
      la fila pasa a `insufficient_data` con cuantiles nulos.
    Devuelve (ventana, {"withheld": {motivo: filas}, "beyond_validation": filas con P90 mayor que el seguimiento validado}); la columna `window_beyond_validation`
    marca las filas con cifras cuyo P90 excede el seguimiento con que se validó (su cola no se pudo evaluar)."""
    out = win.copy()
    out["window_beyond_validation"] = False
    info: dict[str, Any] = {"withheld": {}, "beyond_validation": 0}
    if payload is None or not len(out):
        return out, info
    has = out["window_status"].isin([STATUS_ESTIMATED, STATUS_OVERDUE]).to_numpy()
    status = payload.get("validation_status")
    groups = (payload.get("validation") or {}).get("groups") or {}
    reason = np.full(len(out), "", dtype=object)
    if status != "validated":
        reason[has] = f"global_{status}"
    else:
        bucket = bucket_of(out["window_own_gaps"].to_numpy())
        for name in BUCKETS:
            g = groups.get(f"gaps:{name}")
            if g is None or g.get("status") != "validated":
                reason[has & (bucket == name)] = f"gaps_{name}_{(g or {}).get('status', 'insufficient_data')}"
        og = groups.get("state:overdue")
        if og is None or og.get("status") != "validated":
            sel = has & (out["window_status"].to_numpy() == STATUS_OVERDUE) & (reason == "")
            reason[sel] = f"overdue_{(og or {}).get('status', 'insufficient_data')}"
    withheld = reason != ""
    for k, v in zip(*np.unique(reason[withheld], return_counts=True)):
        info["withheld"][str(k)] = int(v)
    out.loc[withheld, ["window_p10_days", "window_p50_days", "window_p90_days"]] = np.nan
    out.loc[withheld, "window_status"] = STATUS_INSUFFICIENT
    follow = (payload.get("validation") or {}).get("follow_up_days")
    if follow:
        kept = out["window_status"].isin([STATUS_ESTIMATED, STATUS_OVERDUE]).to_numpy()
        beyond = kept & (out["window_p90_days"].isna() | (out["window_p90_days"] > float(follow))).to_numpy()
        out["window_beyond_validation"] = beyond
        info["beyond_validation"] = int(beyond.sum())
    return out, info


# ───────────────────────────── ajuste de niveles y evaluación ─────────────────────────────

def _follow_up_table(bundle, c: int, params: WindowParams, levels: dict | None = None, with_reference: bool = False,
                     candidate_levels: np.ndarray | None = None, cache: dict[int, dict[str, Any]] | None = None) -> pd.DataFrame | None:
    """Ventana + resultado observado (T = días hasta la primera compra posterior al corte; inf = ninguna en el seguimiento) de los candidatos del
    corte `c`. `valid`: etiqueta madura y no censurada por disponibilidad (misma regla que el resto del Customer Core). `cache`: por corte guarda la base,
    las entradas de la ventana (intervalos, recencia, media jerárquica), T y la validez, que el ajuste, la evaluación y la comparación nominal reutilizan."""
    fb = bundle.builder
    hit = cache.get(c) if cache is not None else None
    if hit is None or hit.get("eval_days") != params.eval_days:
        base = bundle.base[bundle.base["cutoff"] == c].reset_index(drop=True)
        if base.empty:
            return None
        inputs = hit["inputs"] if hit is not None else _window_inputs(fb, c, base, params)
        lab = fb.labels(base, c, params.eval_days, int(bundle.last_complete))
        ev = fb.ev
        R = base["rel"].to_numpy(np.int64)
        hi = ev.pos(R, c)
        has_next = hi < ev.end[R]
        nxt = np.where(has_next, ev.day[np.clip(hi, 0, max(ev.n - 1, 0))] - c, np.inf) if ev.n else np.full(len(R), np.inf)
        hit = {"base": base, "inputs": inputs, "T": nxt.astype(float), "valid": lab["status"].to_numpy() == 1, "eval_days": params.eval_days}
        if cache is not None:
            cache[c] = hit
    tab = window_from_base(fb, c, hit["base"], params, with_reference=with_reference, levels=levels, candidate_levels=candidate_levels,
                           max_rows=params.eval_max_rows, inputs=hit["inputs"])
    tab["T"] = hit["T"]
    tab["valid"] = hit["valid"]
    tab["cutoff"] = c
    return tab[tab["window_sampled"]].reset_index(drop=True)


def split_cutoffs(bundle, params: WindowParams, holdout_starts: list[int] | None = None) -> tuple[list[int], list[int]]:
    """(cortes de ajuste, cortes de evaluación). Evaluación = los más recientes con seguimiento maduro de `eval_days` (hasta `eval_max_cutoffs`, a lo sumo el
    40 % de los maduros); ajuste = los anteriores cuyo seguimiento termina antes del primer corte de evaluación Y, si se dan `holdout_starts` (primer corte
    del bloque holdout de cada horizonte entrenado), antes del primero de ellos: un corte de ajuste ni su seguimiento tocan el holdout de ningún horizonte.
    Los cortes de evaluación solo se EVALÚAN (no ajustan nada) y pueden coincidir en el tiempo con el holdout."""
    last, w = int(bundle.last_complete), int(params.eval_days)
    mature = [int(c) for c in bundle.cutoffs if c + w <= last]
    if not mature:
        return [], []
    ne = max(1, min(params.eval_max_cutoffs, int(np.ceil(0.4 * len(mature)))))
    evals = mature[-ne:]
    limit = evals[0] if not holdout_starts else min(evals[0], min(int(x) for x in holdout_starts))
    fit = [c for c in mature if c + w <= limit][-24:]
    return fit, evals


def _distinct(g: pd.DataFrame) -> tuple[int, int]:
    return int(g["rel"].nunique()), int(g["customer_id"].nunique())


def fit_levels(parts: list[pd.DataFrame], cands: np.ndarray, params: WindowParams) -> dict[str, dict[str, Any]]:
    """Niveles recalibrados por grupo de cantidad de intervalos propios a partir de los cortes de ajuste (`cands` = niveles candidatos:
    LOWER_GRID + MEDIAN_GRID + UPPER_GRID, columnas `cand_j`). Solo filas evaluables con seguimiento (su cuantil ≤ `eval_days`):
    - cola superior b: el menor nivel cuya cobertura P(T ≤ Q_b) alcanza el 90 %;
    - cola inferior a: el mayor nivel cuyo P(T < Q_a) no supera el 10 %;
    - mediana c: el nivel cuyo P(T ≤ Q_c) se acerca más al 50 %.
    Un grupo con menos de `group_min_relations` relaciones DISTINTAS elegibles queda con niveles nominales (`fitted` = False)."""
    d = pd.concat(parts, ignore_index=True)
    d = d[d["valid"] & d["window_status"].isin([STATUS_ESTIMATED, STATUS_OVERDUE])]
    nl, nm = len(LOWER_GRID), len(MEDIAN_GRID)
    out: dict[str, dict[str, Any]] = {}
    w = float(params.eval_days)
    for name, (lo, hi) in BUCKETS.items():
        g = d[(d["window_own_gaps"] >= lo) & (d["window_own_gaps"] <= hi)]
        n_rel, _ = _distinct(g) if len(g) else (0, 0)
        item: dict[str, Any] = {"p10_level": 0.1, "p50_level": 0.5, "p90_level": 0.9, "fitted": False, "n_fit_rows": int(len(g)), "n_fit_relations": n_rel}
        if n_rel < params.group_min_relations:
            out[name] = item | {"reason": f"menos de {params.group_min_relations} relaciones distintas de ajuste: niveles nominales"}
            continue
        T = g["T"].to_numpy()

        def stat(j: int, side: str) -> tuple[float | None, int]:
            q = g[f"cand_{j}"].to_numpy()
            ok = np.isfinite(q) & (q <= w)
            if not ok.any() or g.loc[ok, "rel"].nunique() < params.group_min_relations:
                return None, int(ok.sum())
            return (float((T[ok] <= q[ok]).mean()) if side != "below" else float((T[ok] < q[ok]).mean())), int(ok.sum())

        up = [(UPPER_GRID[i], *stat(nl + nm + i, "upper")) for i in range(len(UPPER_GRID))]
        reached = [lv for lv, cov, _ in up if cov is not None and cov >= 0.9]
        usable = [lv for lv, cov, _ in up if cov is not None]
        item["p90_level"] = float(min(reached)) if reached else (float(max(usable)) if usable else 0.9)
        lowc = [(LOWER_GRID[i], *stat(i, "below")) for i in range(nl)]
        okl = [lv for lv, below, _ in lowc if below is not None and below <= 0.10]
        usable_l = [lv for lv, below, _ in lowc if below is not None]
        item["p10_level"] = float(max(okl)) if okl else (float(min(usable_l)) if usable_l else 0.1)
        medc = [(MEDIAN_GRID[i], *stat(nl + i, "upper")) for i in range(nm)]
        usable_m = [(abs(share - 0.5), lv) for lv, share, _ in medc if share is not None]
        item["p50_level"] = float(min(usable_m)[1]) if usable_m else 0.5
        out[name] = item | {"fitted": True}
    return out


def candidate_levels() -> np.ndarray:
    return np.asarray([*LOWER_GRID, *MEDIAN_GRID, *UPPER_GRID], dtype=np.float64)


def _coverage_block(d: pd.DataFrame, p10: str, p50: str, p90: str, horizon_days: int) -> dict[str, Any]:
    """Cobertura de [P10, P90], masas por fuera y error del P50 sobre filas con seguimiento suficiente. Una fila es evaluable para el
    intervalo si su P90 ≤ seguimiento (sabemos si la compra cayó dentro o fuera) y para el P50 si su P50 ≤ seguimiento. Se informan las relaciones y los
    compradores DISTINTOS evaluables (las filas de cortes consecutivos de una misma relación no son independientes)."""
    out: dict[str, Any] = {}
    elig = d[d[p90].notna() & (d[p90] <= horizon_days)]
    n_rel, n_cust = _distinct(elig) if len(elig) else (0, 0)
    out["interval"] = {"n": int(len(elig)), "n_relations": n_rel, "n_customers": n_cust, "nominal": 0.8}
    if len(elig):
        T = elig["T"].to_numpy()
        inside = (T >= elig[p10].to_numpy()) & (T <= elig[p90].to_numpy())
        out["interval"] |= {"coverage": float(inside.mean()), "below_p10": float((T < elig[p10].to_numpy()).mean()),
                            "above_p90": float((T > elig[p90].to_numpy()).mean()),
                            "mean_width_days": float((elig[p90] - elig[p10]).mean())}
    else:
        out["interval"] |= {"coverage": None, "below_p10": None, "above_p90": None, "mean_width_days": None}
    e50 = d[d[p50].notna() & (d[p50] <= horizon_days)]
    out["median"] = {"n": int(len(e50)), "nominal_share_at_or_below": 0.5}
    if len(e50):
        out["median"]["share_at_or_below_p50"] = float((e50["T"].to_numpy() <= e50[p50].to_numpy()).mean())
        obs = e50[np.isfinite(e50["T"].to_numpy()) & (e50["T"].to_numpy() <= horizon_days)]
        out["median"]["error_days"] = ({"n": int(len(obs)), "mae": float((obs[p50] - obs["T"]).abs().mean()),
                                        "median_abs_error": float((obs[p50] - obs["T"]).abs().median()),
                                        "bias": float((obs[p50] - obs["T"]).mean())} if len(obs) else None)
    else:
        out["median"] |= {"share_at_or_below_p50": None, "error_days": None}
    return out


def evaluate_window(bundle, params: WindowParams, holdout_starts: list[int] | None = None) -> dict[str, Any]:
    """Valida la ventana con seguimiento de `eval_days` días y, si no hay relaciones evaluables suficientes (intervalos largos: su P90 excede el
    seguimiento) o no hay cortes con ese seguimiento maduro, lo duplica (hasta 365 días) mientras el histórico lo permita. Se informa cada intento
    en `attempts`; el resultado es el primer intento concluyente o, si ninguno lo es, el último. `holdout_starts`: ver `split_cutoffs`."""
    attempts: list[dict[str, Any]] = []
    result: dict[str, Any] = {}
    seen: set[int] = set()
    cache: dict[int, dict[str, Any]] = {}
    for w in (params.eval_days, params.eval_days * 2, params.eval_days * 4):
        w = int(min(w, 365))
        if w in seen:
            continue
        seen.add(w)
        result = _evaluate_window_once(bundle, replace(params, eval_days=w), holdout_starts, cache)
        attempts.append({"eval_days": w, "status": result["status"], "n_evaluated": (result.get("interval") or {}).get("n"),
                         "n_relations": (result.get("interval") or {}).get("n_relations"), "n_cutoffs": result.get("n_cutoffs")})
        if result["status"] != "insufficient_data":
            break
    result["attempts"] = attempts
    return result


def _evaluate_window_once(bundle, params: WindowParams, holdout_starts: list[int] | None = None, cache: dict[int, dict[str, Any]] | None = None) -> dict[str, Any]:
    """Ajusta los niveles con los cortes de AJUSTE y mide cobertura y error temporal en los de EVALUACIÓN (`split_cutoffs`). Etiquetas con la
    misma madurez y censura por disponibilidad que el resto del Customer Core. Devuelve el informe y, en `levels`, lo que viaja en el artefacto."""
    W = int(params.eval_days)
    fit_c, eval_c = split_cutoffs(bundle, params, holdout_starts)
    base_info: dict[str, Any] = {
        "definition": ("T = días desde el corte hasta la primera compra posterior de la relación. Cobertura = fracción de relaciones cuyo T cae en "
                       "[P10, P90] (nominal ≥ 80 %), sobre filas con P90 ≤ seguimiento. Error de P50 en días sobre las que compraron dentro "
                       "del seguimiento (condicionado: subestima el error de las que tardan más). Un abandono cuenta como compra fuera de la ventana."),
        "follow_up_days": W, "params": params.as_dict(), "fit_cutoffs_before_holdout_of_every_horizon": bool(holdout_starts),
        "holdout_starts": [int(x) for x in holdout_starts] if holdout_starts else None,
        "reference": "intervalos propios sin suavizar ni condicionar a la recencia: cuantiles de los intervalos menos los días ya transcurridos"}
    if not eval_c:
        return base_info | {"status": "insufficient_data", "reason_code": "no_mature_cutoffs", "levels": None, "n_cutoffs": 0, "groups": {},
                            "reason": f"Ningún corte tiene {W} días de seguimiento maduro: la ventana no puede validarse."}
    # ── ajuste de niveles (cortes anteriores, resultados ya conocibles en el primer corte de evaluación y en ningún holdout) ──
    levels: dict[str, dict[str, Any]] | None = None
    fit_parts = [t for t in (_follow_up_table(bundle, c, params, candidate_levels=candidate_levels(), cache=cache) for c in fit_c) if t is not None]
    if fit_parts:
        levels = fit_levels(fit_parts, candidate_levels(), params)
        if not any(v["fitted"] for v in levels.values()):
            levels = None
    # ── evaluación (cortes posteriores) ──
    parts = [t for t in (_follow_up_table(bundle, c, params, levels=levels, with_reference=True, cache=cache) for c in eval_c) if t is not None]
    nominal_parts = [t for t in (_follow_up_table(bundle, c, params, levels=None, cache=cache) for c in eval_c) if t is not None] if levels else parts
    if not parts:
        return base_info | {"status": "insufficient_data", "reason_code": "no_rows", "reason": "Sin relaciones candidatas en los cortes maduros.",
                            "levels": None, "n_cutoffs": 0, "groups": {}}
    d = pd.concat(parts, ignore_index=True)

    def usable_of(frame: pd.DataFrame) -> pd.DataFrame:
        return frame[frame["valid"] & frame["window_status"].isin([STATUS_ESTIMATED, STATUS_OVERDUE])]

    usable = usable_of(d)
    cols = ("window_p10_days", "window_p50_days", "window_p90_days")
    block = _coverage_block(usable, *cols, W)
    nominal_block = _coverage_block(usable_of(pd.concat(nominal_parts, ignore_index=True)), *cols, W) if levels else block
    ref_block = _coverage_block(usable[usable["ref_p10"].notna()], "ref_p10", "ref_p50", "ref_p90", W)
    by_status = {s: _coverage_block(usable[usable["window_status"] == s], *cols, W)["interval"] for s in (STATUS_ESTIMATED, STATUS_OVERDUE)}
    by_gaps = {k: _coverage_block(usable[(usable["window_own_gaps"] >= lo) & (usable["window_own_gaps"] <= hi_)], *cols, W)["interval"]
               for k, (lo, hi_) in BUCKETS.items()}
    per_cutoff = []
    for c, g in usable.groupby("cutoff"):
        iv = _coverage_block(g, *cols, W)["interval"]
        per_cutoff.append({"cutoff": int(c), "n": iv["n"], "coverage": iv["coverage"]})
    covs = [x["coverage"] for x in per_cutoff if x["coverage"] is not None and x["n"] >= 30]
    nominal, tol = 0.8, params.coverage_tolerance
    iv = block["interval"]
    status, reason = verdict(iv["n_relations"], iv["n_customers"], iv["coverage"], params.eval_min_rows, params.eval_min_customers, nominal, tol)
    if len(parts) < params.eval_min_cutoffs and status != "insufficient_data":
        status, reason = "insufficient_data", f"solo {len(parts)} cortes de evaluación (mínimo {params.eval_min_cutoffs})"
    if status == "insufficient_data":
        reason = f"Evidencia insuficiente para validar la ventana: {reason} con P90 ≤ {W} días."
    elif status == "not_validated":
        reason = f"Cobertura {iv['coverage']:.3f} < {nominal - tol:.2f} (nominal {nominal} − tolerancia)."
    # veredicto POR GRUPO (cantidad de intervalos propios y estado overdue): lo que no alcanza la tolerancia no se sirve
    groups: dict[str, Any] = {}
    for k, g in by_gaps.items():
        st, why = verdict(g["n_relations"], g["n_customers"], g["coverage"], params.group_min_relations, 1, nominal, tol)
        groups[f"gaps:{k}"] = g | {"status": st, "reason": why}
    og = by_status[STATUS_OVERDUE]
    st, why = verdict(og["n_relations"], og["n_customers"], og["coverage"], params.group_min_relations, 1, nominal, tol)
    groups["state:overdue"] = og | {"status": st, "reason": why}
    counts = d["window_status"].value_counts().to_dict()
    n_not_assessable = int((usable["window_p90_days"].isna() | (usable["window_p90_days"] > W)).sum())
    return base_info | {
        "status": status, "reason": reason, "n_cutoffs": int(len(parts)), "fit_cutoff_days": fit_c, "eval_cutoff_days": eval_c,
        "levels": levels, "groups": groups, "recalibration": {
            "fitted": bool(levels), "fit_cutoffs": len(fit_parts), "grids": {"lower": list(LOWER_GRID), "median": list(MEDIAN_GRID), "upper": list(UPPER_GRID)},
            "separation": f"cada corte de ajuste termina su seguimiento de {W} días antes del primer corte de evaluación y antes del primer corte holdout de todo horizonte",
            "uncalibrated_nominal_levels": nominal_block["interval"] if levels else None},
        "rows": {"total": int(len(d)), "valid_labels": int(d["valid"].sum()), "by_status": {k: int(v) for k, v in counts.items()},
                 "not_assessable_p90_beyond_follow_up": n_not_assessable, "usable": int(len(usable)),
                 "not_assessable_share": (n_not_assessable / len(usable)) if len(usable) else None},
        "coverage_tolerance": params.coverage_tolerance, **block, "by_status": by_status, "by_own_gaps": by_gaps,
        "reference_metrics": ref_block, "stability": {"per_cutoff": per_cutoff,
                                                      "coverage_std": float(np.std(covs)) if len(covs) > 1 else None,
                                                      "coverage_min": float(min(covs)) if covs else None,
                                                      "coverage_max": float(max(covs)) if covs else None}}
