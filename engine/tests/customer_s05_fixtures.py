"""Fixtures FUNCIONALES del Customer Core S05 (W3-C): el caso de las cubetas de huevo de V2.5 §7 y un cliente con cambios temporales de precio.

NO son pronósticos reales ni validación de ninguna empresa: son procesos de compra ficticios con intervalos, cantidades y precios que se
conocen por construcción, para afirmar PROPIEDADES del pipeline (la ventana contiene el intervalo típico, la cantidad condicionada vuelve al
patrón tras el episodio, no hay mirada al futuro…), nunca números mágicos.

Cada caso se inserta como un comprador más dentro de un mundo sintético de verdad conocida (`customer.synthetic.generate_world`): los modelos
se entrenan con ese mundo + el caso, SOLO con datos hasta el corte (`data_until`); el histórico posterior se guarda aparte (`later`) y solo lo
usan las aserciones.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta

import numpy as np
import pandas as pd

from predictily_engine.customer.data import CustomerData
from predictily_engine.customer.events import to_day
from predictily_engine.customer.s05 import finalize_s05
from predictily_engine.customer.s05_persist import attach_window
from predictily_engine.customer.synthetic import SyntheticWorld, generate_world
from predictily_engine.customer.train import train_horizon
from predictily_engine.customer.window import WindowParams, evaluate_window

START = date(2023, 1, 2)
N_DAYS = 1100                      # ≈ 3 años
EGG_CUSTOMER, EGG_PRODUCT = 9001, 9001
PRICE_CUSTOMER, PRICE_PRODUCT = 9002, 9002


@dataclass
class CaseSeries:
    """Compras del caso (día relativo a START, cantidad, precio neto unitario) y los cortes de interés."""

    customer_id: int
    product_id: int
    sales: list[tuple[int, float, float]]
    episode: tuple[int, int]
    cutoffs: dict[str, int] = field(default_factory=dict)
    regimes: dict[str, dict] = field(default_factory=dict)

    def purchase_days(self) -> list[int]:
        return [d for d, _, _ in self.sales]

    def frame(self, through_day: int | None = None) -> pd.DataFrame:
        rows = [{"customer_id": self.customer_id, "product_id": self.product_id, "branch_id": 1, "ds": pd.Timestamp(START + timedelta(days=d)),
                 "currency": "USD", "qty": q, "net_amount": q * p, "gross_amount": q * p, "discount_amount": 0.0}
                for d, q, p in self.sales if through_day is None or d <= through_day]
        return pd.DataFrame(rows)

    def later_purchase_days(self, cutoff_day: int) -> list[int]:
        """Histórico POSTERIOR al corte (separado: nunca entra en el entrenamiento ni en las variables)."""
        return [d for d in self.purchase_days() if d > cutoff_day]


def egg_buckets(seed: int = 11) -> CaseSeries:
    """V2.5 §7: 3 años; normalmente 5–7 cubetas cada 9–15 días; un episodio de ~2 meses (subió el precio) con 3–5 cubetas cada 6–10 días; vuelta al
    patrón. Se eligen dos cortes con la MISMA recencia (3 días): uno dentro del episodio y otro bien entrado el patrón restablecido; y el corte del
    caso del texto: la última compra fue 13 días antes y la siguiente cae 1–2 días después (intervalo de 14–15 días)."""
    rng = np.random.default_rng(seed)
    ep0, ep1 = 700, 762
    price_a, price_b = 3.20, 4.40
    sales: list[tuple[int, float, float]] = []
    day = 5
    while day < N_DAYS - 1:
        episode = ep0 <= day < ep1
        qty = float(rng.integers(3, 6) if episode else rng.integers(5, 8))
        sales.append((day, qty, price_b if episode else price_a))
        day += int(rng.integers(6, 11) if episode else rng.integers(9, 16))
    days = [d for d, _, _ in sales]
    case = CaseSeries(EGG_CUSTOMER, EGG_PRODUCT, sales, (ep0, ep1))
    # corte DENTRO del episodio: 3 días después de la 5.ª compra del episodio
    in_ep = [d for d in days if ep0 <= d < ep1]
    case.cutoffs["episode"] = in_ep[4] + 3
    # corte con el patrón restablecido: 3 días después de la 6.ª compra posterior al episodio
    after = [d for d in days if d >= ep1 + 15]
    case.cutoffs["restored"] = after[5] + 3
    # corte del texto: última compra 13 días antes y la siguiente a 14–15 días de ella (cae 1–2 días después del corte)
    gaps = {days[i]: days[i + 1] - days[i] for i in range(len(days) - 1)}
    cand = [d for d in days if d >= ep1 + 120 and gaps.get(d, 0) in (14, 15) and d + 13 < N_DAYS - 40]
    case.cutoffs["text_case"] = cand[0] + 13
    case.regimes = {"normal": {"gap": (9, 15), "qty": (5, 7), "price": price_a}, "episode": {"gap": (6, 10), "qty": (3, 5), "price": price_b}}
    return case


def price_changer(seed: int = 21) -> CaseSeries:
    """Cliente quincenal (14–16 días) de 9–11 unidades a 1,00; dos meses con el precio en 1,60 y 6–7 unidades; luego vuelve al patrón."""
    rng = np.random.default_rng(seed)
    ep0, ep1 = 500, 560
    sales: list[tuple[int, float, float]] = []
    day = 3
    while day < N_DAYS - 1:
        episode = ep0 <= day < ep1
        qty = float(rng.integers(6, 8) if episode else rng.integers(9, 12))
        sales.append((day, qty, 1.60 if episode else 1.00))
        day += int(rng.integers(14, 17))
    days = [d for d, _, _ in sales]
    case = CaseSeries(PRICE_CUSTOMER, PRICE_PRODUCT, sales, (ep0, ep1))
    case.cutoffs["before_episode"] = [d for d in days if d < ep0][-2] + 4
    case.cutoffs["episode"] = [d for d in days if ep0 <= d < ep1][-1] + 4
    case.cutoffs["restored"] = [d for d in days if d >= ep1][5] + 4
    return case


def population(n_customers: int = 110, seed: int = 5) -> SyntheticWorld:
    """Mundo sintético de verdad conocida (los demás compradores) sobre el que se entrena; mismo calendario que los casos."""
    return generate_world(seed=seed, n_customers=n_customers, n_products=6, n_days=N_DAYS, mean_interval_range=(10, 90), start=START)


def data_until(world: SyntheticWorld, cases: list[CaseSeries], cutoff_day: int) -> CustomerData:
    """Población + casos con SOLO datos hasta `cutoff_day` (inclusive): lo que el pipeline conocería ese día (as-of)."""
    data = world.customer_data(through=START + timedelta(days=cutoff_day))
    extra = pd.concat([c.frame(cutoff_day) for c in cases if len(c.frame(cutoff_day))], ignore_index=True)
    data.purchases = pd.concat([data.purchases, extra], ignore_index=True)
    data.products = pd.concat([data.products, pd.DataFrame({"product_id": [c.product_id for c in cases], "category_id": 1, "unit": "unidad",
                                                            "is_active": True})], ignore_index=True)
    data.sales_last = max(data.sales_last, extra["ds"].max().date()) if data.sales_last else extra["ds"].max().date()
    data.through = START + timedelta(days=cutoff_day)
    return data


def train_with_s05(bundle, cfg: dict, horizons: tuple[int, ...], seed: int = 42):
    """La misma secuencia que `service._run_train`: entrena cada horizonte, finaliza E(N) entre horizontes, valida la ventana con cortes de ajuste anteriores a
    todo holdout y adjunta la ventana a los artefactos. Devuelve (resultados, finalización, evaluación de la ventana)."""
    results = {h: train_horizon(bundle, h, cfg, seed, 1) for h in horizons}
    finalization = window_eval = None
    if cfg.get("s05_enabled", True):
        finalization = finalize_s05(bundle, results, cfg)
        starts = [int(to_day(r.manifest["blocks"]["holdout"]["cutoff_from"])) for r in results.values()
                  if r.status in ("trained", "reference_only") and (r.manifest.get("blocks") or {}).get("holdout")]
        window_eval = evaluate_window(bundle, WindowParams.from_cfg(cfg), holdout_starts=starts or None)
        attach_window(results, window_eval, WindowParams.from_dict(window_eval.get("params"), cfg))
    return results, finalization, window_eval
