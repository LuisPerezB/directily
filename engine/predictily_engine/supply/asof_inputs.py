"""Insumos AS-OF de la simulación cronológica de abastecimiento (V2.5 · W5-D; V25-SUP-038/050; docs/supply/SUPPLY_CORE.md §4).

En cada revisión `r` de la ventana la simulación decide con lo conocido ESE día: ventas (lectura as-of de W1), costo de compra, precio neto
reciente, plazos vigentes y existencias/lotes de la última foto ≤ el corte. Estas funciones puras construyen las matrices
(`SimInputs.unit_cost_t`, `price_t`, `lead_t`, `lead_std_t`) a partir de tablas completas leídas UNA vez.

Ningún dato posterior a la revisión entra en ella: un costo con `known_at` posterior, un plazo con `valid_from` posterior o un documento de
ventas recibido después no alteran las decisiones anteriores (prueba adversarial en tests/unit/test_supply_asof_simulation.py).
"""

from __future__ import annotations

from datetime import date
from typing import Callable

import numpy as np
import pandas as pd

from predictily_engine.inventory.recommendations import resolve_lead_time
from predictily_engine.supply.availability import known_by, to_date


def asof_costs(hist: pd.DataFrame | None, as_of: date, retroactive: bool = True) -> dict[int, dict]:
    """Último costo de compra conocido al corte por producto (misma regla que `SupplyRepository.last_purchase_costs`, SIN el costo del catálogo
    vigente: el catálogo no tiene historia y su valor de hoy es información posterior al corte)."""
    out: dict[int, dict] = {}
    if hist is None or hist.empty:
        return out
    df = hist.copy()
    df["_ok"] = [known_by(r.known_at, as_of, retroactive)[0] and (to_date(r.valid_from) is not None and to_date(r.valid_from) <= as_of)
                 for r in df.itertuples(index=False)]
    df = df[df["_ok"]].sort_values(["product_id", "valid_from", "cost_id"])
    for pid, g in df.groupby("product_id"):
        last = g.iloc[-1]
        out[int(pid)] = {"unit_cost": float(last["unit_cost"]), "source": f"purchase_cost_history:{last['valid_from']}"}
    return out


def review_indices(n_days: int, review_days: int) -> list[int]:
    """Índices de la ventana (0-based) en que la simulación revisa y decide (los mismos que `simulate_policy`)."""
    return [k for k in range(n_days) if k % review_days == 0 and k < n_days - 1]


def step_fill(rows_by_review: dict[int, np.ndarray], n_days: int) -> np.ndarray:
    """Matriz (n_days, N): cada día toma la fila de la ÚLTIMA revisión ≤ ese día (un valor conocido se mantiene hasta la próxima revisión)."""
    keys = sorted(rows_by_review)
    first = rows_by_review[keys[0]]
    out = np.empty((n_days, len(first)))
    for pos, k in enumerate(keys):
        end = keys[pos + 1] if pos + 1 < len(keys) else n_days
        out[k:end] = rows_by_review[k]
    if keys[0] > 0:
        out[: keys[0]] = first
    return out


def lead_vectors(keys: list[tuple[int, int]], lead_times: pd.DataFrame, suppliers: pd.DataFrame, default_lead: int) -> tuple[np.ndarray, np.ndarray]:
    lead = np.zeros(len(keys), dtype=int)
    std = np.zeros(len(keys))
    for i, (b, p) in enumerate(keys):
        d, s, _src = resolve_lead_time(p, b, lead_times, suppliers, default_lead)
        lead[i], std[i] = d, (s or 0.0)
    return lead, std


def asof_series_tables(*, keys: list[tuple[int, int]], dates: pd.DatetimeIndex, start: int, n_days: int, review_days: int,
                       cost_history: pd.DataFrame, price_at: Callable[[date], dict[int, float]], lead_times_at: Callable[[date], pd.DataFrame],
                       suppliers: pd.DataFrame, default_lead: int, fallback_cost: dict[tuple[int, int], float]) -> dict:
    """Matrices as-of (costo, precio, plazo, variabilidad) por día de la ventana + series excluidas por no tener costo conocido al inicio.

    `price_at(cut)` y `lead_times_at(cut)` leen lo conocido a `cut`; `fallback_cost`: costo del snapshot de existencias del inicio de la
    ventana (también as-of) para series sin historial de compras. Un precio ausente en una revisión conserva el de la anterior; si nunca hubo
    precio, el margen es 0 (precio = costo): se declara.
    """
    revs = review_indices(n_days, review_days)
    if 0 not in revs:
        revs = [0] + revs
    cost_rows: dict[int, np.ndarray] = {}
    price_rows: dict[int, np.ndarray] = {}
    lead_rows: dict[int, np.ndarray] = {}
    std_rows: dict[int, np.ndarray] = {}
    prev_price: dict[int, float] = {}
    prev_sig = None
    prev_lead = prev_std = None
    n = len(keys)
    excluded: list[dict] = []
    cost_known_at_start = np.ones(n, dtype=bool)
    price_declared_missing = 0
    for ridx, k in enumerate(revs):
        cut = dates[start + k].date()
        costs = asof_costs(cost_history, cut)
        crow = np.full(n, np.nan)
        for i, key in enumerate(keys):
            c = costs.get(key[1], {}).get("unit_cost")
            if c is None:
                c = fallback_cost.get(key)
            if c is not None:
                crow[i] = c
        if ridx == 0:
            cost_known_at_start = ~np.isnan(crow)
        else:
            crow = np.where(np.isnan(crow), cost_rows[revs[ridx - 1]], crow)     # un costo ya conocido no se «olvida»
        cost_rows[k] = crow
        prices = price_at(cut)
        for pid, v in prices.items():
            prev_price[pid] = v
        price_rows[k] = np.array([prev_price.get(key[1], np.nan) for key in keys])
        lt = lead_times_at(cut)
        sig = (len(lt), str(lt["valid_from"].max()) if len(lt) and "valid_from" in lt else None)
        if sig != prev_sig or prev_lead is None:
            prev_lead, prev_std = lead_vectors(keys, lt, suppliers, default_lead)
            prev_sig = sig
        lead_rows[k], std_rows[k] = prev_lead, prev_std
    for i, key in enumerate(keys):
        if not cost_known_at_start[i]:
            excluded.append({"branch_id": key[0], "product_id": key[1], "reason": "sin costo conocido al inicio de la ventana"})
    cost_t = step_fill(cost_rows, n_days)
    price_t = step_fill(price_rows, n_days)
    missing_price = np.isnan(price_t)
    price_declared_missing = int(np.isnan(price_t[0]).sum())
    price_t = np.where(missing_price, cost_t, price_t)       # sin precio observado: margen 0 (no se usa el precio del catálogo vigente)
    return {"cost_t": cost_t, "price_t": price_t, "lead_t": step_fill(lead_rows, n_days).astype(int), "lead_std_t": step_fill(std_rows, n_days),
            "excluded": excluded, "series_without_price": price_declared_missing, "keep": cost_known_at_start}
