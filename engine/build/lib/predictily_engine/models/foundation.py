"""Nivel E: adaptador para modelos fundacionales de series de tiempo (TimesFM 2.5).

Estado: DESACTIVADO. Antes de habilitarlo se debe:
1. Verificar la licencia comercial del código y de los pesos del checkpoint publicado
   (el repositorio de Google Research declara Apache-2.0; confirmar para la versión 2.5 y
   para los pesos descargados, y registrar la evidencia en docs/04-estrategia-modelado.md).
2. Verificar compatibilidad técnica en CPU (sin GPU) y el costo de inferencia en el VPS.
3. Evaluarlo con el mismo backtesting rolling-origin y la misma regla de selección: solo se
   usa si supera al mejor modelo estadístico por el margen mínimo.

La dependencia (torch + checkpoint) no se instala en el MVP.
"""

from __future__ import annotations

import pandas as pd


class FoundationModelUnavailable(RuntimeError):
    pass


class TimesFMAdapter:
    family = "timesfm"
    enabled = False
    reason = "Pendiente verificar licencia comercial de TimesFM 2.5 y compatibilidad en CPU; no instalado."

    @classmethod
    def available(cls) -> bool:
        return cls.enabled

    def fit(self, train: pd.DataFrame) -> "TimesFMAdapter":  # pragma: no cover - interfaz
        raise FoundationModelUnavailable(self.reason)

    def predict(self, h: int, future_exog: pd.DataFrame | None = None) -> pd.DataFrame:  # pragma: no cover
        raise FoundationModelUnavailable(self.reason)
