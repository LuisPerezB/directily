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

# «Mismo día de la semana»: media de las últimas k observaciones del MISMO día de la semana de cada serie. Tiene el reparto semanal por
# construcción y reacciona rápido a un cambio de nivel (es ruidosa: por eso compite y no se impone). Entra con el mismo interruptor que las
# variantes `*_dow` (`weekday_profile_models`); no es una variante de una base plana, así que no forma parte de `WEEKDAY_FAMILIES`.
WEEKDAY_AVERAGE = "weekday_average"
WEEKDAY_AVERAGE_K = 4
FAMILIES[WEEKDAY_AVERAGE] = FamilyInfo(WEEKDAY_AVERAGE, "A", "statistical", "Promedio de los últimos 4 mismos días de la semana",
                                       {"k": WEEKDAY_AVERAGE_K})
# Todo lo que `weekday_profile_models` enciende (y lo que el pipeline retira cuando está apagado).
WEEKDAY_SWITCH_FAMILIES = [*WEEKDAY_FAMILIES, WEEKDAY_AVERAGE]

# Combinación (`models/ensemble.py`): promedio simple de AutoETS y LightGBM global. Es una familia compleja más: compite con la regla de
# LightGBM (margen y ventanas frente al mejor estadístico) cuando la empresa activa `ensemble_models` (apagado por defecto).
ENSEMBLE_FAMILY = "ets_lightgbm_mean"
ENSEMBLE_MEMBERS = ("auto_ets", "lightgbm_global")
FAMILIES[ENSEMBLE_FAMILY] = FamilyInfo(ENSEMBLE_FAMILY, "B", "ml", "Combinación AutoETS + LightGBM (promedio)",
                                       {"members": list(ENSEMBLE_MEMBERS), "weights": "equal"})
# Familias que solo reemplazan al mejor estadístico con margen y estabilidad (regla de `selection/selector.py`).
COMPLEX_FAMILIES = ("lightgbm_global", ENSEMBLE_FAMILY)

STATISTICAL = [f for f, i in FAMILIES.items() if i.kind == "statistical"]
BASELINE_FAMILY = "seasonal_naive"
SIMPLE_FALLBACKS = ("seasonal_naive", "window_average")

# Candidatos por clase de demanda (el baseline siempre se evalúa y se reporta).
CANDIDATES_BY_CLASS = {
    "smooth": ["naive", "seasonal_naive", "window_average", "auto_ets", *WEEKDAY_SWITCH_FAMILIES, ENSEMBLE_FAMILY, "lightgbm_global"],
    "erratic": ["naive", "seasonal_naive", "window_average", "auto_ets", *WEEKDAY_SWITCH_FAMILIES, ENSEMBLE_FAMILY, "lightgbm_global"],
    "intermittent": ["seasonal_naive", "window_average", "croston_sba", "adida", "tsb", *WEEKDAY_SWITCH_FAMILIES, ENSEMBLE_FAMILY, "lightgbm_global"],
    "lumpy": ["seasonal_naive", "window_average", "croston_sba", "adida", "tsb", *WEEKDAY_SWITCH_FAMILIES, ENSEMBLE_FAMILY, "lightgbm_global"],
}
