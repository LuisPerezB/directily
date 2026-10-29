"""Conversión de unidades explícita (tabla `unit_conversions`). Nunca se convierte en silencio.

`cantidad_to = cantidad_from × factor`. Orden de búsqueda: conversión específica del producto (directa o
inversa) y luego la general (directa o inversa). No se encadenan conversiones: sin fila ⇒ no hay conversión
y el llamador debe tratar el dato como NO COMPARABLE.
"""

from __future__ import annotations

from datetime import date

import pandas as pd


def norm_unit(unit: str | None) -> str | None:
    if unit is None or (isinstance(unit, float) and pd.isna(unit)):
        return None
    u = str(unit).strip().lower()
    return u or None


class UnitConversions:
    def __init__(self, conversions: pd.DataFrame | None) -> None:
        self.df = pd.DataFrame(columns=["product_id", "from_unit", "to_unit", "factor", "valid_from"]) \
            if conversions is None or conversions.empty else conversions.copy()
        if not self.df.empty:
            self.df["from_unit"] = self.df["from_unit"].map(norm_unit)
            self.df["to_unit"] = self.df["to_unit"].map(norm_unit)
            self.df["factor"] = pd.to_numeric(self.df["factor"], errors="coerce")
            self.df["valid_from"] = pd.to_datetime(self.df["valid_from"], errors="coerce")

    def _lookup(self, product_id: int | None, a: str, b: str, as_of: date) -> float | None:
        d = self.df
        if d.empty:
            return None
        sel = d[(d["from_unit"] == a) & (d["to_unit"] == b)]
        if product_id is None:
            sel = sel[sel["product_id"].isna()]
        else:
            sel = sel[sel["product_id"] == product_id]
        sel = sel[sel["valid_from"].isna() | (sel["valid_from"] <= pd.Timestamp(as_of))]
        sel = sel[sel["factor"] > 0]
        if sel.empty:
            return None
        sel = sel.sort_values("valid_from", na_position="first")
        return float(sel.iloc[-1]["factor"])

    def factor(self, product_id: int | None, from_unit: str | None, to_unit: str | None,
               as_of: date) -> tuple[float | None, str]:
        """(factor, fuente). Fuente: identity | product | product_inverse | general | general_inverse | missing."""
        a, b = norm_unit(from_unit), norm_unit(to_unit)
        if a is None or b is None:
            return None, "missing"
        if a == b:
            return 1.0, "identity"
        for pid, label in ((product_id, "product"), (None, "general")):
            if pid is None and label == "product":
                continue
            f = self._lookup(pid, a, b, as_of)
            if f is not None:
                return f, label
            inv = self._lookup(pid, b, a, as_of)
            if inv is not None:
                return 1.0 / inv, f"{label}_inverse"
        return None, "missing"
