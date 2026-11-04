"""`FeatureAvailabilityValidator` (ADR-V25-06 · V2.5 §24.5): el valor VISIBLE de un factor en un corte y su estado.

Dado un factor (`FactorSpec`: su clase de disponibilidad, antigüedad máxima y estrategia de ausencia), sus observaciones con
las fechas de V2.5 §24.5 y un corte `C` (más, opcionalmente, la fecha para la que se necesita el valor, p. ej. un día del
horizonte), devuelve un `AvailabilityResult` con el valor visible y uno de cinco estados:

| Estado | Significado |
|---|---|
| `AVAILABLE` | hay un valor visible y vigente |
| `NOT_YET_AVAILABLE` | el valor existe o existirá, pero NO era conocido al corte (o no hay ninguno aún) |
| `STALE` | hay un último valor visible, pero su antigüedad supera `max_staleness_days` (o su vigencia terminó); se etiqueta con su antigüedad |
| `INVALID` | el dato no puede usarse como feature (sin fecha, inconsistente, sin historial de emisiones) |
| `SCENARIO_ONLY` | supuesto del usuario: solo para simulación, nunca para medir precisión |

**Visibilidad.** Una sola regla (módulo `visibility`): `disponibilidad < medianoche local de (C + 1)` (W1, ADR-V25-23) y, sin
fecha, `supply.availability.known_by` (vigente sí; retroactivo no). La disponibilidad de una observación es
`COALESCE(available_at, GREATEST(published_at, ingested_at))`; un pronóstico de tercero nunca es visible antes de su emisión
(`issued_at`), aunque se haya registrado antes.

**Reglas por clase.**

- `OBSERVED_HISTORICAL`: visible si su disponibilidad es anterior a la frontera; entre las visibles, el período más reciente y, dentro
  del período, la última revisión visible al corte (una revisión posterior no altera el pasado). Para una fecha de uso posterior al
  corte el valor se arrastra (`carried_forward`) y su antigüedad crece hasta volverse `STALE`.
- `STATIC`: igual, sin caducidad.
- `KNOWN_IN_ADVANCE`: el valor de una fecha puede ser futuro si se conocía antes del corte (feriados, campañas, promociones y precios
  futuros confirmados); se elige por la fecha de vigencia que cubre el día pedido y la última revisión conocida al corte.
- `THIRD_PARTY_FORECAST`: solo cuentan los pronósticos emitidos (`issued_at`) y disponibles antes del corte; el más reciente es el
  visible. En una reconstrucción retroactiva, una emisión sin `issued_at` no puede demostrarse: si la fuente no tiene historial
  archivado de emisiones el factor es `INVALID` y el backtest se prohíbe (`assert_backtest_allowed`).
- `USER_SCENARIO`: siempre `SCENARIO_ONLY`; `eligible_for_accuracy` es falso.
- `UNAVAILABLE_FOR_HORIZON`: `NOT_YET_AVAILABLE` con motivo `unavailable_for_horizon`.

Las observaciones son un `DataFrame` con las columnas de `external_indicator_observations` (`period_date`, `value`, `revision`,
`observed_at`, `published_at`, `ingested_at`, `available_at`, `valid_from`, `valid_to`, `issued_at`, `forecast_horizon_days`,
`id`); las que falten se tratan como nulas.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, timedelta
from typing import Any, Mapping

import numpy as np
import pandas as pd

from predictily_engine.availability.model import (
    AvailabilityClass,
    AvailabilityResult,
    AvailabilityStatus,
    FactorSpec,
    ForecastBacktestForbidden,
    Reason,
)
from predictily_engine.availability.visibility import (
    boundary,
    local_date,
    resolve_timezone,
    to_date,
    to_utc_naive,
    visible_mask,
)
from predictily_engine.data.asof import local_day

_AC = AvailabilityClass
_ST = AvailabilityStatus


@dataclass
class PreparedObservations:
    """Observaciones normalizadas (instantes en UTC sin zona, fechas como días) listas para evaluar muchos cortes."""

    frame: pd.DataFrame
    availability_class: AvailabilityClass


def _col(df: pd.DataFrame, name: str, default: Any = None) -> pd.Series:
    if name in df.columns:
        return df[name].reset_index(drop=True)
    return pd.Series([default] * len(df), dtype=object)


def _day(value: Any) -> date | None:
    return None if value is None or value is pd.NaT or pd.isna(value) else pd.Timestamp(value).date()


class FeatureAvailabilityValidator:
    """Validador de disponibilidad temporal. `timezone`: zona de la empresa. `retroactive`: ¿reconstrucción de un corte
    histórico (backtest, simulación)? Por defecto sí: lo que no puede demostrarse conocido NO se usa."""

    def __init__(self, timezone: str | None = None, *, retroactive: bool = True) -> None:
        self.tz = resolve_timezone(timezone)
        self.retroactive = bool(retroactive)

    # ───────────────────────── preparación ─────────────────────────

    def prepare(self, spec: FactorSpec, observations: Any) -> PreparedObservations:
        """Normaliza las observaciones de un factor y marca las inválidas por sí mismas (independiente del corte)."""
        if isinstance(observations, PreparedObservations):
            if observations.availability_class is not spec.availability_class:
                raise ValueError("Observaciones preparadas para otra clase de disponibilidad.")
            return observations
        df = observations if isinstance(observations, pd.DataFrame) else pd.DataFrame(list(observations or []))
        n = len(df)
        cls = spec.availability_class
        period = pd.to_datetime(_col(df, "period_date"), errors="coerce").dt.normalize()
        observed = to_utc_naive(_col(df, "observed_at"))
        published = to_utc_naive(_col(df, "published_at"))
        ingested = to_utc_naive(_col(df, "ingested_at"))
        declared = to_utc_naive(_col(df, "available_at"))
        issued = to_utc_naive(_col(df, "issued_at"))
        fallback = pd.concat([published, ingested], axis=1).max(axis=1) if n else published
        avail = declared.where(declared.notna(), fallback).astype("datetime64[ns]")
        # Un pronóstico nunca está disponible antes de su emisión, aunque se haya registrado antes.
        avail_eff = pd.concat([avail, issued], axis=1).max(axis=1).astype("datetime64[ns]") if n and cls is _AC.THIRD_PARTY_FORECAST else avail
        frame = pd.DataFrame({
            "rid": _col(df, "id") if "id" in df.columns else pd.Series(range(n), dtype=object),
            "period": period, "value": pd.to_numeric(_col(df, "value"), errors="coerce"),
            "revision": pd.to_numeric(_col(df, "revision", 0), errors="coerce").fillna(0).astype(int),
            "observed": observed, "published": published, "avail": avail, "avail_eff": avail_eff, "issued": issued,
            "vfrom": pd.to_datetime(_col(df, "valid_from"), errors="coerce").dt.normalize(),
            "vto": pd.to_datetime(_col(df, "valid_to"), errors="coerce").dt.normalize(),
        })
        avail_day = pd.Series(local_day(frame["avail"], self.tz), index=frame.index) if n else pd.Series(dtype="datetime64[ns]")
        frame["avail_day"] = avail_day
        invalid = pd.Series([None] * n, dtype=object)

        def mark(condition: pd.Series, code: str) -> None:
            nonlocal invalid
            invalid = invalid.where(invalid.notna() | ~condition.fillna(False).astype(bool), code)

        if n:
            mark(frame["value"].isna(), Reason.MISSING_VALUE)
            mark(frame["period"].isna(), Reason.MISSING_PERIOD)
            mark(frame["observed"].notna() & frame["avail"].notna() & (frame["observed"] > frame["avail"]), Reason.AVAILABLE_BEFORE_OBSERVED)
            if cls is _AC.OBSERVED_HISTORICAL:
                # Un período observado no puede empezar después del día en que el dato ya estaba disponible.
                mark(frame["avail_day"].notna() & (frame["period"] > frame["avail_day"]), Reason.PERIOD_AFTER_AVAILABILITY)
            if cls is _AC.THIRD_PARTY_FORECAST and self.retroactive:
                mark(frame["issued"].isna(), Reason.NO_ISSUANCE_HISTORY)
        frame["invalid"] = invalid
        return PreparedObservations(frame, cls)

    # ───────────────────────── evaluación puntual ─────────────────────────

    def evaluate(self, spec: FactorSpec, observations: Any, cutoff: Any, *, target_date: Any = None) -> AvailabilityResult:
        """Estado y valor visible del factor al corte `cutoff` (fecha local de la empresa), para usarlo en `target_date`
        (por defecto el propio corte)."""
        cls = spec.availability_class
        c = to_date(cutoff)
        t = to_date(target_date) if target_date is not None else c
        if c is None or t is None:
            raise ValueError("El corte (y la fecha de uso, si se da) deben ser fechas válidas.")

        def result(status: AvailabilityStatus, reason: str, **kw: Any) -> AvailabilityResult:
            fallback = spec.missing_strategy if status is not _ST.AVAILABLE and status is not _ST.SCENARIO_ONLY else None
            return AvailabilityResult(status=status, reason=reason, availability_class=cls, cutoff=c, factor_key=spec.key,
                                      target_date=t, max_staleness_days=spec.max_staleness_days, fallback=fallback, **kw)

        if cls is _AC.UNAVAILABLE_FOR_HORIZON:
            return result(_ST.NOT_YET_AVAILABLE, Reason.UNAVAILABLE_FOR_HORIZON)
        prep = self.prepare(spec, observations)
        frame = prep.frame
        if cls is _AC.USER_SCENARIO:
            return self._scenario(spec, frame, c, t)
        if frame.empty:
            return result(_ST.NOT_YET_AVAILABLE, Reason.NO_OBSERVATIONS)
        valid = frame[frame["invalid"].isna()]
        if valid.empty:
            reasons = frame["invalid"].value_counts()
            return result(_ST.INVALID, str(reasons.index[0]), detail={"invalid_rows": int(len(frame)), "reasons": reasons.to_dict()})
        if cls in (_AC.OBSERVED_HISTORICAL, _AC.STATIC):
            return self._evaluate_observed(spec, valid, c, t, result)
        if cls is _AC.KNOWN_IN_ADVANCE:
            return self._evaluate_known_in_advance(spec, valid, c, t, result)
        return self._evaluate_third_party(spec, valid, c, t, result)

    def _scenario(self, spec: FactorSpec, frame: pd.DataFrame, c: date, t: date) -> AvailabilityResult:
        value, record, rev = None, None, None
        if not frame.empty:
            usable = frame[frame["value"].notna()]
            if not usable.empty:
                last = usable.sort_values(["revision"], kind="mergesort").iloc[-1]
                value, record, rev = float(last["value"]), last["rid"], int(last["revision"])
        return AvailabilityResult(status=_ST.SCENARIO_ONLY, reason=Reason.USER_SCENARIO, availability_class=spec.availability_class,
                                  cutoff=c, factor_key=spec.key, target_date=t, value=value, record_id=record, revision=rev,
                                  flags=("scenario",), detail={"accuracy": "excluded"})

    @staticmethod
    def _describe(row: pd.Series) -> dict[str, Any]:
        def inst(v: Any) -> pd.Timestamp | None:
            return None if pd.isna(v) else pd.Timestamp(v)
        return {"value": float(row["value"]), "record_id": row["rid"], "revision": int(row["revision"]),
                "period_date": _day(row["period"]), "observed_at": inst(row["observed"]), "published_at": inst(row["published"]),
                "available_at": inst(row["avail_eff"]), "issued_at": inst(row["issued"])}

    def _hidden_reason(self, rows: pd.DataFrame, default: str) -> tuple[AvailabilityStatus, str]:
        """Por qué no hay filas visibles: sin fechas en una reconstrucción retroactiva (INVALID) o disponibles después (NOT_YET)."""
        if self.retroactive and rows["avail_eff"].isna().all():
            return _ST.INVALID, Reason.UNKNOWN_AVAILABILITY
        return _ST.NOT_YET_AVAILABLE, default

    def _visible(self, rows: pd.DataFrame, c: date) -> pd.DataFrame:
        return rows[visible_mask(rows["avail_eff"], c, self.tz, self.retroactive)]

    def _evaluate_observed(self, spec: FactorSpec, valid: pd.DataFrame, c: date, t: date, result: Any) -> AvailabilityResult:
        visible = self._visible(valid, c)
        hidden = int(len(valid) - len(visible))
        if visible.empty:
            status, reason = self._hidden_reason(valid, Reason.AFTER_CUTOFF)
            return result(status, reason, detail={"hidden_after_cutoff": hidden})
        # Período más reciente conocido y, dentro de él, la última revisión visible al corte.
        row = visible.sort_values(["period", "revision", "avail_eff"], kind="mergesort", na_position="first").iloc[-1]
        ref = local_date(row["observed"], self.tz) if pd.notna(row["observed"]) else _day(row["period"])
        age = max((t - ref).days, 0) if ref is not None else None
        flags = ("carried_forward",) if t > c else ()
        info = self._describe(row)
        detail = {"hidden_after_cutoff": hidden, "availability_age_days": self._availability_age(row, t)}
        if spec.availability_class is _AC.STATIC:
            return result(_ST.AVAILABLE, Reason.OK, age_days=age, flags=flags, detail=detail, **info)
        valid_to = _day(row["vto"])
        if valid_to is not None and valid_to < t:
            return result(_ST.STALE, Reason.VALIDITY_EXPIRED, age_days=(t - valid_to).days, flags=flags, detail=detail, **info)
        if spec.max_staleness_days is not None and age is not None and age > spec.max_staleness_days:
            return result(_ST.STALE, Reason.STALE, age_days=age, flags=flags, detail=detail, **info)
        return result(_ST.AVAILABLE, Reason.OK, age_days=age, flags=flags, detail=detail, **info)

    def _availability_age(self, row: pd.Series, t: date) -> int | None:
        day = local_date(row["avail_eff"], self.tz) if pd.notna(row["avail_eff"]) else None
        return None if day is None else max((t - day).days, 0)

    @staticmethod
    def _covers(frame: pd.DataFrame, t: date) -> pd.Series:
        """Filas cuya vigencia cubre el día `t` (ventana valid_from…valid_to) o, sin ventana, cuyo período es ese día."""
        ts = pd.Timestamp(t)
        windowed = frame["vfrom"].notna()
        in_window = windowed & (frame["vfrom"] <= ts) & (frame["vto"].isna() | (frame["vto"] >= ts))
        return in_window | (~windowed & (frame["period"] == ts))

    def _evaluate_known_in_advance(self, spec: FactorSpec, valid: pd.DataFrame, c: date, t: date, result: Any) -> AvailabilityResult:
        covering = valid[self._covers(valid, t)]
        if covering.empty:
            return result(_ST.NOT_YET_AVAILABLE, Reason.NO_VALUE_FOR_DATE)
        # Primero la ÚLTIMA revisión visible al corte de cada evento (su identidad: el período de su primera fecha); recién después se prueba la
        # cobertura de la fecha: una revisión posterior que ACORTA o cancela el evento reemplaza a la anterior en TODAS sus fechas (W6-E · M3).
        visible_all = self._visible(valid, c)
        latest = (visible_all.sort_values(["period", "revision", "avail_eff"], kind="mergesort", na_position="first")
                  .groupby("period", sort=False).tail(1)) if len(visible_all) else visible_all
        chosen = latest[self._covers(latest, t)] if len(latest) else latest
        visible_covering = self._visible(covering, c)
        hidden = int(len(covering) - len(visible_covering))
        if chosen.empty:
            if visible_covering.empty:
                status, reason = self._hidden_reason(covering, Reason.KNOWN_AFTER_CUTOFF)
                return result(status, reason, detail={"hidden_after_cutoff": hidden})
            # La fecha la cubría una revisión que una posterior (visible al corte) acortó o canceló: ya no hay valor conocido para ese día.
            return result(_ST.NOT_YET_AVAILABLE, Reason.NO_VALUE_FOR_DATE, detail={"hidden_after_cutoff": hidden, "superseded_revision": True})
        row = chosen.sort_values(["revision", "avail_eff"], kind="mergesort", na_position="first").iloc[-1]
        detail = {"hidden_after_cutoff": hidden, "lead_days": (t - c).days, "availability_age_days": self._availability_age(row, c)}
        return result(_ST.AVAILABLE, Reason.OK, flags=("known_in_advance",) if t > c else (), detail=detail, **self._describe(row))

    def _evaluate_third_party(self, spec: FactorSpec, valid: pd.DataFrame, c: date, t: date, result: Any) -> AvailabilityResult:
        cand = valid[self._covers(valid, t)]
        if cand.empty:
            return result(_ST.NOT_YET_AVAILABLE, Reason.NO_VALUE_FOR_DATE)
        flags: tuple[str, ...] = ()
        if (cand["avail"].notna() & cand["issued"].notna() & (cand["avail"] < cand["issued"])).any():
            flags = ("registered_before_issue",)       # registrado antes de emitirse: anomalía de datos, no se confía en el registro
        visible = self._visible(cand, c)
        hidden = int(len(cand) - len(visible))
        if visible.empty:
            limit = boundary(c, self.tz)
            issued_later = cand["issued"].notna() & (cand["issued"].to_numpy("datetime64[ns]") >= limit)
            if issued_later.any():
                return result(_ST.NOT_YET_AVAILABLE, Reason.ISSUED_AFTER_CUTOFF, flags=flags, detail={"hidden_after_cutoff": hidden})
            status, reason = self._hidden_reason(cand, Reason.AFTER_CUTOFF)
            return result(status, reason, flags=flags, detail={"hidden_after_cutoff": hidden})
        row = visible.sort_values(["issued", "revision"], kind="mergesort", na_position="first").iloc[-1]
        ref = local_date(row["issued"], self.tz) if pd.notna(row["issued"]) else local_date(row["avail_eff"], self.tz)
        age = max((c - ref).days, 0) if ref is not None else None
        detail = {"hidden_after_cutoff": hidden, "lead_days": (t - c).days}
        status, reason = (_ST.STALE, Reason.STALE) if (spec.max_staleness_days is not None and age is not None
                                                       and age > spec.max_staleness_days) else (_ST.AVAILABLE, Reason.OK)
        return result(status, reason, age_days=age, flags=flags, detail=detail, **self._describe(row))

    # ───────────────────────── eventos externos ─────────────────────────

    def evaluate_event(self, event: Mapping[str, Any] | pd.Series, cutoff: Any, *, target_date: Any = None,
                       factor_key: str | None = None, as_feature: bool = True,
                       availability_class: AvailabilityClass = _AC.OBSERVED_HISTORICAL) -> AvailabilityResult:
        """Estado de un `external_events` al corte. Sin `published_at` es INVALID (no usable como feature); solo un evento
        VERIFIED (o EXPIRED con `verified_at` y `verified_by` registrados: verificado y vencido después), ya publicado, disponible y
        verificado antes del corte es AVAILABLE. La vigencia (`effective_to`) vencida antes
        de la fecha de uso lo marca STALE. Una alerta informativa se evalúa en el tiempo igual, pero NO es una feature
        predictiva (`usable_as_feature` falso)."""
        c = to_date(cutoff)
        t = to_date(target_date) if target_date is not None else c

        def get(name: str) -> Any:
            return event.get(name) if hasattr(event, "get") else None

        def instant(name: str) -> pd.Timestamp | None:
            v = to_utc_naive([get(name)]).iloc[0]
            return None if pd.isna(v) else pd.Timestamp(v)

        record = get("id")
        key = factor_key or (f"event:{record}" if record is not None else "event")
        published, created, available, verified = (instant("published_at"), instant("created_at"), instant("available_at"),
                                                   instant("verified_at"))
        status_text = str(get("status") or "PROPOSED").upper()
        flags = ("informative_alert",) if as_feature and bool(get("is_informative_alert")) else ()

        def out(status: AvailabilityStatus, reason: str, **kw: Any) -> AvailabilityResult:
            fallback = None if status is _ST.AVAILABLE else "disable_feature"
            return AvailabilityResult(status=status, reason=reason, availability_class=availability_class, cutoff=c, factor_key=key,
                                      target_date=t, record_id=record, published_at=published, fallback=fallback, flags=flags, **kw)

        if published is None:
            return out(_ST.INVALID, Reason.MISSING_PUBLISHED_AT)
        if status_text == "REJECTED":
            return out(_ST.INVALID, Reason.EVENT_REJECTED)
        if status_text == "PROPOSED":
            return out(_ST.INVALID, Reason.EVENT_NOT_VERIFIED)
        if status_text == "EXPIRED":
            # EXPIRED se alcanza también desde PROPOSED sin pasar por VERIFIED: solo vale si consta quién y cuándo lo verificó,
            # en cualquier modo (una ejecución vigente tampoco puede suponer una verificación que no está registrada).
            verified_by = get("verified_by")
            if verified is None or verified_by is None or pd.isna(verified_by):
                return out(_ST.INVALID, Reason.EVENT_NOT_VERIFIED)
        # Disponibilidad real: la declarada o la mayor entre publicación e ingestión (`created_at`).
        usable = available if available is not None else max(x for x in (published, created) if x is not None)
        if verified is None and self.retroactive:
            return out(_ST.INVALID, Reason.VERIFICATION_DATE_UNKNOWN, available_at=usable)
        for label, moment, reason in (("published", published, Reason.PUBLISHED_AFTER_CUTOFF),
                                      ("available", usable, Reason.AFTER_CUTOFF),
                                      ("verified", verified, Reason.VERIFIED_AFTER_CUTOFF)):
            if moment is not None and not visible_mask(pd.Series([moment], dtype="datetime64[ns]"), c, self.tz, False)[0]:
                return out(_ST.NOT_YET_AVAILABLE, reason, available_at=usable, detail={"stage": label})
        effective_to, effective_from = _day(get("effective_to")), _day(get("effective_from"))
        detail = {"applies_on_target": (effective_from is None or effective_from <= t) and (effective_to is None or effective_to >= t),
                  "corroboration": get("corroboration")}
        if effective_to is not None and effective_to < t:
            return out(_ST.STALE, Reason.VALIDITY_EXPIRED, available_at=usable, age_days=(t - effective_to).days, detail=detail)
        return out(_ST.AVAILABLE, Reason.OK, available_at=usable, detail=detail)

    # ───────────────────────── series y guardas ─────────────────────────

    def evaluate_series(self, spec: FactorSpec, observations: Any, dates: Any, *, final_cutoff: Any) -> pd.DataFrame:
        """Valor de un factor para cada fecha de `dates` con el corte final `final_cutoff`.

        Corte de cada fecha `d`: observado/estático → `min(d, corte final)` (el entrenamiento ve lo que se conocía ese día; el
        futuro arrastra lo visible al corte final); previsto de antemano → el corte final (es un hecho conocido al corte);
        pronóstico de tercero → `min(corte final, d − 1 día)` (el vigente la víspera). Columnas: `ds`, `value` (NaN si no es
        usable), `status`, `reason`, `age_days`, `usable`."""
        prep = self.prepare(spec, observations)
        final = to_date(final_cutoff)
        cls = spec.availability_class
        rows = []
        for ts in pd.DatetimeIndex(dates):
            day = ts.date()
            if cls is _AC.KNOWN_IN_ADVANCE:
                cut = final
            elif cls is _AC.THIRD_PARTY_FORECAST:
                cut = min(final, day - timedelta(days=1))
            else:
                cut = min(final, day)
            r = self.evaluate(spec, prep, cut, target_date=day)
            rows.append((ts, r.value if r.usable_as_feature and r.value is not None else np.nan, r.status.value, r.reason,
                         r.age_days, r.usable_as_feature))
        return pd.DataFrame(rows, columns=["ds", "value", "status", "reason", "age_days", "usable"])

    def issuance_archive(self, spec: FactorSpec, observations: Any) -> dict[str, Any]:
        """Estado del historial archivado de emisiones de un pronóstico de tercero: `complete`, `partial`, `missing`, `empty`
        o `not_applicable` (otra clase)."""
        if spec.availability_class is not _AC.THIRD_PARTY_FORECAST:
            return {"status": "not_applicable", "rows": 0, "with_issued_at": 0}
        df = observations if isinstance(observations, pd.DataFrame) else pd.DataFrame(list(observations or []))
        n = len(df)
        with_issue = int(df["issued_at"].notna().sum()) if n and "issued_at" in df.columns else 0
        status = "empty" if n == 0 else "missing" if with_issue == 0 else "complete" if with_issue == n else "partial"
        return {"status": status, "rows": int(n), "with_issued_at": with_issue}

    def assert_backtest_allowed(self, spec: FactorSpec, observations: Any) -> None:
        """Prohíbe el backtest con un pronóstico de tercero cuya fuente no tiene historial archivado de emisiones: usar su
        pronóstico publicado después como si hubiera existido sería fuga temporal. Alternativas: histórico archivado real,
        desactivar el factor o limitar la conclusión."""
        archive = self.issuance_archive(spec, observations)
        if archive["status"] == "missing":
            raise ForecastBacktestForbidden(
                f"El factor {spec.key!r} (THIRD_PARTY_FORECAST) no tiene historial archivado de emisiones ({archive['rows']} filas sin "
                "issued_at): el backtest con este factor está prohibido.")
