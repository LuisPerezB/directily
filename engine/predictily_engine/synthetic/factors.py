"""Demo FICTICIA de factores externos (W6-E): una empresa sintética con un efecto sembrado, para probar el cableado y la ablation.

TODO es ficticio. No hay ninguna fuente real: el efecto lo siembra este módulo y por eso se conoce la respuesta correcta.

- **`calendar_events.campana_ficticia`** (KNOWN_IN_ADVANCE, almacenamiento `calendar_events`): campañas comerciales de `campaign_days` días cada
  `campaign_every` días, anunciadas `lead_days` días antes de empezar (`available_at`). Las ventas de TODAS las series se multiplican por
  `uplift` durante la campaña: un factor que el modelo SIN factores no puede conocer y el modelo CON el factor sí (si se conocía al corte).
- **`weather.observed`** (OBSERVED_HISTORICAL, `external_indicator_observations`): un «clima» ficticio que es RUIDO puro, publicado cada día
  a las 11:00 UTC (06:00 en Ecuador). No tiene relación con las ventas: la ablation debe NO promoverlo.

`fictional_factor_demo` devuelve el `TenantData` (ventas ya modificadas por la campaña), los `FactorInputs` (lo que `load_factor_inputs` leería
de la base) y los ajustes de empresa que encienden el pipeline y activan los dos factores.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import numpy as np
import pandas as pd

from predictily_engine.pipeline.data import FactorInputs, TenantData, tenant_data_from_synthetic
from predictily_engine.synthetic.generator import generate_company

CAMPAIGN_KEY = "calendar_events.campana_ficticia"
NOISE_KEY = "weather.observed"
FICTIONAL_NOTE = "FICTICIO: efecto sembrado por predictily_engine.synthetic.factors; no representa ninguna empresa ni fuente real."


def _definition(key: str, family: str, name: str, availability_class: str, storage: str, frequency: str, missing: str,
                max_staleness_days: int | None, schema: dict[str, Any]) -> dict[str, Any]:
    return {"id": None, "tenant_id": None, "family": family, "key": key, "name": name, "availability_class": availability_class,
            "storage": storage, "external_indicator_id": None, "unit": None, "frequency": frequency, "geo_scope": "country",
            "source_key": None, "license": None, "missing_strategy": missing, "max_staleness_days": max_staleness_days,
            "retention_days": None, "schema": schema, "is_active": False, "is_informative_alert": False}


@dataclass
class FictionalFactorDemo:
    data: TenantData
    inputs: FactorInputs
    settings: dict[str, Any]                 # ajustes de empresa: pipeline encendido y los dos factores activados
    campaigns: pd.DataFrame = field(default_factory=pd.DataFrame)     # starts_on, ends_on, announced_on (la verdad sembrada)
    note: str = FICTIONAL_NOTE

    def with_settings(self, settings: dict[str, Any]) -> TenantData:
        """El `TenantData` de la demo con otros ajustes de empresa (p. ej. pipeline apagado) y los MISMOS datos de factores cargados."""
        from dataclasses import replace

        return replace(self.data, settings={**self.data.settings, **settings}, factor_inputs=self.inputs)


def fictional_factor_demo(seed: int = 42, n_products: int = 12, start: str = "2025-04-01", *, uplift: float = 1.8, campaign_days: int = 9,
                          campaign_every: int = 55, lead_days: int = 80, first_campaign_day: int = 40, future_campaigns: int = 2,
                          with_noise_factor: bool = True) -> FictionalFactorDemo:
    """Empresa ficticia con campañas anunciadas por anticipado (efecto sembrado) y, opcionalmente, un factor de ruido."""
    company = generate_company("ferreteria_andina", seed=seed, n_products=n_products, start=start)
    data = tenant_data_from_synthetic(company)
    cutoff = pd.Timestamp(data.cutoff).normalize()
    first = pd.Timestamp(data.sales["ds"].min()).normalize()

    # ── campañas (la verdad sembrada) ──
    rows = []
    day = first + pd.Timedelta(days=first_campaign_day)
    while day + pd.Timedelta(days=campaign_days - 1) <= cutoff:
        rows.append((day, day + pd.Timedelta(days=campaign_days - 1), day - pd.Timedelta(days=lead_days)))
        day += pd.Timedelta(days=campaign_every)
    for k in range(future_campaigns):                    # campañas FUTURAS ya anunciadas al corte final (KNOWN_IN_ADVANCE)
        starts = cutoff + pd.Timedelta(days=15 + 50 * k)
        rows.append((starts, starts + pd.Timedelta(days=campaign_days - 1), cutoff - pd.Timedelta(days=3 + k)))
    campaigns = pd.DataFrame(rows, columns=["starts_on", "ends_on", "announced_on"])

    # ── efecto sembrado en las ventas: unidades y montos × uplift durante la campaña ──
    sales = data.sales.copy()
    in_campaign = np.zeros(len(sales), dtype=bool)
    ds = pd.to_datetime(sales["ds"]).to_numpy()
    for c in campaigns.itertuples(index=False):
        in_campaign |= (ds >= np.datetime64(c.starts_on)) & (ds <= np.datetime64(c.ends_on))
    units = sales["units"].to_numpy(float)
    new_units = np.where(in_campaign, np.round(units * uplift), units)
    ratio = np.where(units > 0, new_units / np.where(units > 0, units, 1.0), 1.0)
    sales["units"] = new_units
    for col in ("net_amount", "gross_amount", "discount_amount", "cost_amount", "cost_units"):
        if col in sales:
            sales[col] = sales[col].to_numpy(float) * ratio
    data.sales = sales

    # ── factor 1: campañas (KNOWN_IN_ADVANCE sobre calendar_events) ──
    definitions = {CAMPAIGN_KEY: _definition(CAMPAIGN_KEY, "calendar_events", "Campaña comercial ficticia", "KNOWN_IN_ADVANCE", "calendar_events",
                                             "event", "disable_feature", None, {"event_code": "CAMPANA_FICTICIA", "fictional": True})}
    observations = {CAMPAIGN_KEY: pd.DataFrame({
        "id": np.arange(1, len(campaigns) + 1), "period_date": campaigns["starts_on"], "value": 1.0, "revision": 0,
        "available_at": campaigns["announced_on"] + pd.Timedelta(hours=12), "valid_from": campaigns["starts_on"], "valid_to": campaigns["ends_on"]})}

    # ── factor 2: «clima» ficticio, ruido puro (OBSERVED_HISTORICAL) ──
    if with_noise_factor:
        rng = np.random.default_rng([seed, 7919])
        days = pd.date_range(first, cutoff, freq="D")
        definitions[NOISE_KEY] = _definition(NOISE_KEY, "weather", "Clima observado ficticio (ruido)", "OBSERVED_HISTORICAL",
                                             "external_indicator_observations", "daily", "last_valid_with_age", 3,
                                             {"indicator_code": "CLIMA_FICTICIO", "fictional": True})
        observations[NOISE_KEY] = pd.DataFrame({
            "id": np.arange(1, len(days) + 1), "period_date": days, "value": rng.normal(0.0, 1.0, len(days)), "revision": 0,
            "observed_at": days + pd.Timedelta(hours=3), "published_at": days + pd.Timedelta(days=1, hours=11),
            "ingested_at": days + pd.Timedelta(days=1, hours=11)})
    keys = sorted(definitions)
    inputs = FactorInputs(keys=keys, definitions=definitions, observations=observations)
    settings = {"cores": {"factors": {"enabled": True}}, "factor_activation": {k: {"active": True} for k in keys}}
    data.settings = {**data.settings, **settings}
    data.factor_inputs = inputs
    return FictionalFactorDemo(data=data, inputs=inputs, settings=settings, campaigns=campaigns)


__all__ = ["CAMPAIGN_KEY", "FICTIONAL_NOTE", "NOISE_KEY", "FictionalFactorDemo", "fictional_factor_demo"]
