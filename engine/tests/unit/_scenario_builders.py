"""Constructores de datos SINTÉTICOS para los escenarios de forma de serie (W3-A · V2.5 §26 T03).

Todo lo que producen son ventas FICTICIAS generadas en la prueba (no son pronósticos ni datos reales): sirven para afirmar propiedades
del pipeline (qué modelo se sirve, qué métricas quedan definidas o indefinidas, qué días se excluyen), no para medir precisión real.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from predictily_engine.pipeline.data import TenantData

START = "2025-01-01"
N_DAYS = 420


def days(n: int = N_DAYS, start: str = START) -> pd.DatetimeIndex:
    return pd.date_range(start, periods=n, freq="D")


def scenario_data(patterns: dict[tuple[int, int], np.ndarray], *, start: str = START, inventory: pd.DataFrame | None = None,
                  promotions: pd.DataFrame | None = None, price: dict[tuple[int, int], np.ndarray] | None = None,
                  categories: int = 2) -> TenantData:
    """`TenantData` de una empresa ficticia. `patterns[(sucursal, producto)]` = unidades por día desde `start` (0 = sin venta).
    `price[(b, p)]` = precio unitario neto por día (por defecto 10). `inventory`: filas (branch_id, product_id, ds, on_hand, reserved,
    in_transit, unit_cost); `promotions`: columnas de `TenantData.promotions`."""
    n = len(next(iter(patterns.values())))
    index = days(n, start)
    rows = []
    for (b, p), arr in patterns.items():
        unit_price = price[(b, p)] if price and (b, p) in price else np.full(n, 10.0)
        for d, u, pr in zip(index, np.asarray(arr, float), unit_price):
            if u > 0:
                rows.append({"branch_id": b, "product_id": p, "ds": d, "units": float(u), "net_amount": float(u * pr),
                             "gross_amount": float(u * pr), "discount_amount": 0.0, "cost_amount": float(u * 4.0),
                             "cost_units": float(u), "lines": 3})
    pids = sorted({p for _, p in patterns})
    bids = sorted({b for b, _ in patterns})
    inv = inventory if inventory is not None else pd.DataFrame(columns=["branch_id", "product_id", "ds", "on_hand", "reserved",
                                                                        "in_transit", "unit_cost"])
    latest = (inv.sort_values("ds").groupby(["branch_id", "product_id"], as_index=False).tail(1).reset_index(drop=True)
              if not inv.empty else inv.copy())
    promo = promotions if promotions is not None else pd.DataFrame(columns=[
        "promotion_id", "discount_pct", "starts_on", "ends_on", "branch_id", "product_id", "category_id", "known_at"])
    data = TenantData(
        tenant_id=1, settings={}, cutoff=index[-1].date(),
        branches=pd.DataFrame({"branch_id": bids, "code": [f"B{b}" for b in bids], "name": [f"Sucursal {b}" for b in bids],
                               "province": "Pichincha", "canton": "Quito", "region": "sierra"}),
        products=pd.DataFrame({"product_id": pids, "sku": [f"P{p}" for p in pids], "name": [f"Producto {p}" for p in pids],
                               "category_id": [1 + (p % categories) for p in pids], "unit": "u", "current_price": 10.0,
                               "current_cost": 4.0, "is_active": True}),
        categories=pd.DataFrame({"category_id": list(range(1, categories + 1)),
                                 "name": [f"Categoría {c}" for c in range(1, categories + 1)]}),
        sales=pd.DataFrame(rows), inventory=inv, latest_inventory=latest, promotions=promo)
    return data


def weekly(level: float, amplitude: float = 0.0, n: int = N_DAYS, phase: int = 0) -> np.ndarray:
    """Nivel con patrón semanal determinista (sábado-domingo altos si amplitude > 0)."""
    dow = (np.arange(n) + phase) % 7
    return level + amplitude * np.where(dow >= 5, 1.0, -0.4)


def noisy(level: float, seed: int, n: int = N_DAYS, amplitude: float = 1.5) -> np.ndarray:
    """Demanda irregular: Poisson alrededor de un nivel con patrón semanal."""
    rng = np.random.default_rng(seed)
    return rng.poisson(np.clip(weekly(level, amplitude, n), 0.1, None)).astype(float)
