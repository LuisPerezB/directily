"""Catálogo de familias de modelos y su nivel estratégico (A–E)."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class FamilyInfo:
    family: str
    level: str  # A estadístico, B global ML, C sectorial, D personalizado, E fundacional
    kind: str  # statistical | ml | foundation
    label: str
    hyperparameters: dict


FAMILIES: dict[str, FamilyInfo] = {
    "naive": FamilyInfo("naive", "A", "statistical", "Último valor observado", {}),
    "seasonal_naive": FamilyInfo("seasonal_naive", "A", "statistical", "Mismo día de la semana anterior",
                                 {"season_length": 7}),
    "window_average": FamilyInfo("window_average", "A", "statistical", "Promedio móvil de 28 días",
                                 {"window_size": 28}),
    "auto_ets": FamilyInfo("auto_ets", "A", "statistical", "Suavizamiento exponencial (ETS) con estacionalidad semanal",
                           {"season_length": 7, "model": "ZNZ"}),
    "croston_sba": FamilyInfo("croston_sba", "A", "statistical", "Croston SBA (demanda intermitente)", {}),
    "adida": FamilyInfo("adida", "A", "statistical", "ADIDA (agregación temporal para intermitencia)", {}),
    "tsb": FamilyInfo("tsb", "A", "statistical", "TSB (probabilidad y tamaño de demanda)",
                      {"alpha_d": 0.2, "alpha_p": 0.2}),
    "lightgbm_global": FamilyInfo("lightgbm_global", "B", "ml",
                                  "LightGBM global por empresa con calendario, precios y promociones", {}),
    "timesfm": FamilyInfo("timesfm", "E", "foundation", "TimesFM 2.5 (experimental, desactivado)", {}),
}

# Variantes con perfil semanal (`<base>_dow`): la familia base, que no ve el día de la semana, por los factores de día de la semana
# de cada serie (`statistical.weekday_profile`: últimos 56 días, encogidos hacia el perfil común con peso n / (n + 28)). Sin ellas, en
# un negocio que casi no vende un día (domingos de una distribuidora por rutas) los modelos planos reparten la demanda por igual
# entre los 7 días: sobrestiman ese día y subestiman los demás. Compiten en TODAS las clases (el backtest decide) cuando la empresa
# las activa con `weekday_profile_models` (configuración de Demanda, apagada por defecto: ver `pipeline/core.py`).
WEEKDAY_SUFFIX = "_dow"
WEEKDAY_PROFILE_DAYS = 56
WEEKDAY_PROFILE_SHRINK = 28.0
WEEKDAY_BASES = ("window_average", "croston_sba", "adida", "tsb")
for _base in WEEKDAY_BASES:
    _info = FAMILIES[_base]
    FAMILIES[_base + WEEKDAY_SUFFIX] = FamilyInfo(
        _base + WEEKDAY_SUFFIX, "A", "statistical", f"{_info.label} con perfil semanal",
        {**_info.hyperparameters, "weekday_profile_days": WEEKDAY_PROFILE_DAYS, "weekday_profile_shrink": WEEKDAY_PROFILE_SHRINK})
del _base, _info
WEEKDAY_FAMILIES = [b + WEEKDAY_SUFFIX for b in WEEKDAY_BASES]

STATISTICAL = [f for f, i in FAMILIES.items() if i.kind == "statistical"]
BASELINE_FAMILY = "seasonal_naive"
SIMPLE_FALLBACKS = ("seasonal_naive", "window_average")

# Candidatos por clase de demanda (el baseline siempre se evalúa y se reporta).
CANDIDATES_BY_CLASS = {
    "smooth": ["naive", "seasonal_naive", "window_average", "auto_ets", *WEEKDAY_FAMILIES, "lightgbm_global"],
    "erratic": ["naive", "seasonal_naive", "window_average", "auto_ets", *WEEKDAY_FAMILIES, "lightgbm_global"],
    "intermittent": ["seasonal_naive", "window_average", "croston_sba", "adida", "tsb", *WEEKDAY_FAMILIES, "lightgbm_global"],
    "lumpy": ["seasonal_naive", "window_average", "croston_sba", "adida", "tsb", *WEEKDAY_FAMILIES, "lightgbm_global"],
}
