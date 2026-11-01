"""Factores descriptivos de una relación comprador × producto al corte (V2.5 §7: «factores»; sensibilidad a precio ASOCIATIVA).

No es una explicación del modelo (no hay SHAP): son las variables conocidas al corte que más importan para leer la recompra, con su
valor y una lectura en español. La relación con el precio es asociativa: un cambio de precio observado junto a un cambio de cantidad o de
frecuencia NO prueba un efecto causal (no hay diseño que lo identifique). Se usa en los fixtures funcionales y en reportes; no se persiste
(`customer_predictions` no tiene columna de factores).
"""

from __future__ import annotations

from typing import Any

import numpy as np


def _num(v: Any) -> float | None:
    try:
        x = float(v)
    except (TypeError, ValueError):
        return None
    return x if np.isfinite(x) else None


def describe_factors(row: Any) -> list[dict[str, Any]]:
    """`row`: fila de `FeatureBuilder.compute(c).base` (Series o dict) con las variables de la relación al corte."""
    get = row.get if hasattr(row, "get") else (lambda k, d=None: row[k] if k in row else d)
    out: list[dict[str, Any]] = []

    def add(code: str, value: float | None, reading: str, direction: str) -> None:
        out.append({"factor": code, "value": value, "reading": reading, "direction": direction})

    rec, med, over = _num(get("rel_recency_days")), _num(get("gap_median")), _num(get("overdue_ratio"))
    if rec is not None and med is not None and over is not None:
        if over >= 1.0:
            add("recency_vs_interval", over, f"Han pasado {rec:.0f} días desde la última compra; su intervalo habitual es de {med:.0f} días (ya lo alcanzó).", "up")
        elif over >= 0.6:
            add("recency_vs_interval", over, f"Han pasado {rec:.0f} días; su intervalo habitual es de {med:.0f}: se acerca la recompra.", "neutral")
        else:
            add("recency_vs_interval", over, f"Compró hace {rec:.0f} días; su intervalo habitual es de {med:.0f}: es pronto.", "down")
    else:
        add("recency_vs_interval", None, "Sin intervalos propios suficientes para comparar la recencia.", "unknown")
    std = _num(get("gap_std"))
    if med is not None and std is not None:
        add("interval_dispersion", std, f"Sus intervalos varían ±{std:.0f} días alrededor de {med:.0f}: la ventana no es un día exacto.", "neutral")
    chg = _num(get("price_change_last"))
    if chg is not None:
        add("price_change_last", chg, f"El último precio pagado cambió {chg:+.0%} frente al anterior (asociación; no es un efecto causal).",
            "down" if chg > 0.05 else ("up" if chg < -0.05 else "neutral"))
    rel_price = _num(get("price_rel_to_product"))
    if rel_price is not None:
        add("price_vs_market", rel_price, f"Su último precio es {rel_price:.2f}× el precio medio reciente del producto (asociativo).", "neutral")
    qr = _num(get("qty_ratio_90_prior"))
    if qr is not None:
        add("quantity_trend", qr, f"Sus unidades de los últimos 90 días son {qr:.2f}× las 90 días previos.", "up" if qr > 1.2 else ("down" if qr < 0.8 else "neutral"))
    avail = _num(get("avail_now"))
    if avail is not None:
        add("availability_now", avail, "El producto está disponible en su sucursal habitual." if avail == 1 else
            "El producto NO está disponible en su sucursal habitual: la ausencia de compra no es información.", "up" if avail == 1 else "down")
    promo = _num(get("promo_now"))
    if promo:
        add("promotion_now", promo, f"Hay una promoción vigente conocida de {promo:.0%}.", "up")
    return out
