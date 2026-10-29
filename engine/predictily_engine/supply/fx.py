"""Tasas de cambio explícitas (tabla `exchange_rates`). Sin tasa ⇒ la oferta/presupuesto no es comparable."""

from __future__ import annotations

from datetime import date

import pandas as pd

from predictily_engine.supply.availability import known_by


class FxRates:
    def __init__(self, rates: pd.DataFrame | None, as_of: date, retroactive: bool = False,
                 max_age_days: int = 30) -> None:
        self.as_of = as_of
        self.max_age_days = max_age_days
        cols = ["from_currency", "to_currency", "rate", "valid_on", "known_at", "source"]
        if rates is None or rates.empty:
            self.df = pd.DataFrame(columns=cols)
            self.excluded_unknown_availability = 0
            return
        df = rates.copy()
        df["valid_on"] = pd.to_datetime(df["valid_on"]).dt.date
        df["rate"] = pd.to_numeric(df["rate"], errors="coerce")
        keep, excluded = [], 0
        for r in df.itertuples(index=False):
            ok, why = known_by(getattr(r, "known_at", None), as_of, retroactive)
            if ok and r.valid_on <= as_of and r.rate > 0:
                keep.append(True)
            else:
                keep.append(False)
                if why == "unknown_availability":
                    excluded += 1
        self.df = df[keep]
        self.excluded_unknown_availability = excluded

    def rate(self, from_currency: str, to_currency: str) -> tuple[float | None, dict]:
        """(tasa, metadatos). Directa; si no existe, la inversa de la tasa registrada (sin triangular)."""
        a, b = (from_currency or "").upper(), (to_currency or "").upper()
        if a == b and a:
            return 1.0, {"source": "identity"}
        for src, dst, invert in ((a, b, False), (b, a, True)):
            sel = self.df[(self.df["from_currency"].str.upper() == src) & (self.df["to_currency"].str.upper() == dst)]
            if sel.empty:
                continue
            row = sel.sort_values("valid_on").iloc[-1]
            age = (self.as_of - row["valid_on"]).days
            rate = float(row["rate"])
            return (1.0 / rate if invert else rate), {
                "source": row["source"], "valid_on": row["valid_on"].isoformat(), "age_days": age,
                "inverse": invert, "stale": age > self.max_age_days}
        return None, {"source": "missing"}
