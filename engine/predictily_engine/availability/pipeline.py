"""Pipeline de factores externos DESACTIVABLE por empresa (ADR-V25-25 · V2.5 §24.1).

**Qué factores están activos.** Un factor cuenta SOLO si se cumplen las dos condiciones:
1. el interruptor global del pipeline, bloque `factors` de `platform/config.py` (`factors.enabled = true`; APAGADO por defecto), y
2. la elección de la empresa para ese factor, `tenants.settings.factor_activation.<clave>.active = true` (la escribe el backend con
   `PUT /api/v1/intelligence/factors/{clave}/activation`; es la ÚNICA fuente de verdad de la activación: el bloque `factors` ya no
   tiene una lista propia de factores).
`factor_definitions.is_active` NO interviene: en el catálogo público es el valor por defecto «ninguna familia activa» y la elección de
la empresa vive en `factor_activation`. Con el pipeline APAGADO (o sin factores activos) no se consulta ninguna tabla de factores y no
entra ninguna columna al modelo: los marcos de entrenamiento y de predicción son idénticos a los de un motor sin factores.

Con el pipeline ENCENDIDO, por cada factor activo: se lee su almacenamiento (`FactorsRepository`), se valida su disponibilidad
con `FeatureAvailabilityValidator` para cada fecha y solo entra al modelo un factor cuyo valor sea utilizable en el corte y en
TODOS los días del horizonte (un día sin valor conocido, `no_value_for_date`, p. ej. sin campaña, queda en NaN y no cuenta como
falla de la fuente). Un factor `STALE` (API caída), `NOT_YET_AVAILABLE`, `INVALID`, escenario del usuario o sin historial de
emisiones queda FUERA: el respaldo es el modelo sin el factor (`FactorResolution.dropped` lo explica con motivo y
antigüedad; un factor `STALE` con estrategia `last_valid_with_age` se usa etiquetado con su antigüedad).

**Antigüedad × horizonte.** El valor visible al corte se ARRASTRA a los días del horizonte y su antigüedad se mide hasta cada día
(`corte + h`): un factor con antigüedad `a` al corte y límite `L` es `STALE` en los días con `a + h > L`. Con `disable_feature` basta
un día así en el horizonte para que el factor salga entero (consistencia entre entrenamiento y predicción); con
`last_valid_with_age` entra etiquetado. `dropped[].first_unusable_date` y `unusable_days` dicen desde cuándo. Es decir, el límite de un
factor debe cubrir `antigüedad al corte + horizonte máximo`, o su estrategia de ausencia debe ser `last_valid_with_age`.

**Ventanas del backtest.** El corte FINAL puede ser vigente; todo corte anterior se evalúa como RETROACTIVO (lo que no puede
demostrarse conocido no se usa), aunque la resolución del corte final fuera vigente.

W6-E: CONECTADO a `run_pipeline` (`backtest/factor_arm.py`). `load_tenant_data` lee los insumos de los factores activos
(`pipeline.data.load_factor_inputs`), `run_pipeline` los resuelve aquí con el horizonte real y SIEMPRE con la regla retroactiva
(`retroactive=True`: lo que no puede demostrarse conocido en el corte de cada ventana no entra, tampoco en el corte final de una
ejecución vigente) y el modelo con factores COMPITE con el LightGBM sin factores en las ventanas de selección (nunca en el período final
reservado); solo se sirve si gana. Apagado (por defecto) no se consulta nada. Los conectores externos y la ablation por familia sobre
datos reales son P2 (ADR-V25-25; `scripts/factor_ablation.py` es la herramienta).
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from typing import Any, Mapping

import pandas as pd

from predictily_engine.availability.contracts import FeatureContract, factor_contract
from predictily_engine.availability.model import (
    AvailabilityClass,
    AvailabilityResult,
    AvailabilityStatus,
    FactorSpec,
    ForecastBacktestForbidden,
    Reason,
)
from predictily_engine.availability.served import served_series, served_stats
from predictily_engine.availability.validator import FeatureAvailabilityValidator
from predictily_engine.availability.visibility import to_date, to_day
from predictily_engine.db.factors_repository import (
    AmbiguousCalendarError,
    AmbiguousDefinitionError,
    AmbiguousIndicatorError,
    FactorsRepository,
    ScopeUnsupportedError,
)

COLUMN_PREFIX = "factor__"
DEFINITION_VERSION_FIELDS = ("id", "tenant_id", "family", "key", "availability_class", "storage", "external_indicator_id", "frequency", "missing_strategy",
                             "max_staleness_days", "schema", "source_key", "license", "unit", "geo_scope")
ACTIVATION_SETTING = "factor_activation"        # tenants.settings.factor_activation.<clave>.active (backend, W2-F)
PIPELINE_CONNECTED_TO_RUN_PIPELINE = True        # W6-E: `run_pipeline` resuelve los factores activos (backtest/factor_arm.py)


@dataclass
class FactorResolution:
    """Resultado de resolver los factores de una ejecución: columnas por fecha y diagnóstico por factor.

    `columns` son los valores con el corte FINAL de la ejecución. Cada ventana del backtest necesita los valores tal como se
    conocían en SU corte: `columns_at(cutoff)` los recalcula con las mismas observaciones (si no, el futuro de una ventana vería
    publicaciones posteriores a su corte) y SIEMPRE como reconstrucción retroactiva cuando el corte es anterior al final. Un factor
    que entró con el corte final y no tiene aún valor utilizable en una ventana anterior queda en NaN en esa ventana (desconocido),
    de modo que el conjunto de columnas es el mismo en todas."""

    enabled: bool
    reason: str | None = None                                     # disabled | no_active_factors | None
    columns: pd.DataFrame = field(default_factory=lambda: pd.DataFrame({"ds": pd.Series(dtype="datetime64[ns]")}))
    contracts: dict[str, FeatureContract] = field(default_factory=dict)
    results: dict[str, AvailabilityResult] = field(default_factory=dict)    # estado de cada factor en el corte
    used: list[str] = field(default_factory=list)
    dropped: list[dict[str, Any]] = field(default_factory=list)
    scenario_only: list[str] = field(default_factory=list)
    discarded: list[dict[str, Any]] = field(default_factory=list)           # filas descartadas con motivo (p. ej. scope_unsupported)
    # Entradas para recalcular las columnas con otro corte (vacío en una resolución armada a mano: columnas fijas).
    _inputs: dict[str, tuple[FactorSpec, Any, Any]] = field(default_factory=dict, repr=False)   # clave → (spec, observaciones, preparadas)
    _dates: Any = field(default=None, repr=False)
    _validator: Any = field(default=None, repr=False)
    _final: Any = field(default=None, repr=False)
    _retro: Any = field(default=None, repr=False)
    _retro_prepared: dict[str, Any] = field(default_factory=dict, repr=False)
    _cache: dict[Any, pd.DataFrame] = field(default_factory=dict, repr=False)
    # W6-E (H1/M1) · modo «tal como se sirve»: con `served_block_days` las columnas de cada corte se arman con los valores que cada fila habría tenido
    # desde SU origen (bloques hacia atrás desde el corte; ver `availability/served.py`) y un valor vencido es faltante. None = comportamiento de W2-D.
    served_block_days: int | None = None
    stats: dict[str, dict[str, Any]] = field(default_factory=dict)          # cómo fue cada factor tal como se sirve (días vencidos, antigüedad máxima…)
    definitions: dict[str, dict[str, Any]] = field(default_factory=dict)    # metadatos de la definición con que se resolvió cada factor (para la huella de linaje)

    def definition_version(self, key: str) -> str | None:
        """Huella de la definición del factor tal como se resolvió (clase, almacenamiento, antigüedad, estrategia, esquema…): si cambia, el factor es otro."""
        definition = self.definitions.get(key)
        if definition is None:
            return None
        return hashlib.sha256(json.dumps(definition, sort_keys=True, default=str).encode()).hexdigest()

    @classmethod
    def disabled(cls, reason: str = "disabled") -> "FactorResolution":
        return cls(enabled=False, reason=reason)

    def columns_at(self, cutoff: Any) -> pd.DataFrame:
        """Columnas de los factores que entraron, con los valores visibles al corte `cutoff` (el corte final o el de una ventana).

        El corte final se evalúa con el validador de la resolución (vigente o retroactivo); cualquier corte ANTERIOR, con uno
        retroactivo (y observaciones preparadas para él): solo el corte final puede aceptar lo de disponibilidad desconocida."""
        if not self._inputs or self._validator is None:
            return self.columns
        day = to_date(cutoff)
        if day > self._final:
            raise ValueError(f"El corte {day} es posterior al corte final de la resolución ({self._final}).")
        if day not in self._cache:
            use_final = day == self._final or self._validator.retroactive
            if not use_final and self._retro is None:
                self._retro = FeatureAvailabilityValidator(self._validator.tz, retroactive=True)
            validator = self._validator if use_final else self._retro
            frame = pd.DataFrame({"ds": self._dates})
            for key in self.used:
                spec, observations, prepared = self._inputs[key]
                if not use_final:
                    if key not in self._retro_prepared:
                        self._retro_prepared[key] = validator.prepare(spec, observations)
                    prepared = self._retro_prepared[key]
                if self.served_block_days:
                    series = served_series(validator, spec, prepared, self._dates, day, self.served_block_days)
                else:
                    series = validator.evaluate_series(spec, prepared, self._dates, final_cutoff=day)
                frame[f"{COLUMN_PREFIX}{key}"] = series["value"].to_numpy(dtype=float)
            self._cache[day] = frame
        return self._cache[day]

    @property
    def feature_columns(self) -> list[str]:
        return [c for c in self.columns.columns if c != "ds"]

    def report(self) -> dict[str, Any]:
        """Forma serializable para el manifiesto y las limitaciones de la ejecución."""
        out = {"enabled": self.enabled, "reason": self.reason, "used": list(self.used), "dropped": list(self.dropped),
               "scenario_only": list(self.scenario_only), "discarded": list(self.discarded), "columns": self.feature_columns,
               "factors": {k: r.as_dict() for k, r in self.results.items()}}
        if self.served_block_days:
            out["served_block_days"] = self.served_block_days
            out["stats"] = {k: dict(v) for k, v in self.stats.items()}
        return out

    def select(self, keys: list[str]) -> "FactorResolution":
        """La MISMA resolución restringida a un subconjunto de factores (selección hacia delante del cableado de demanda): mismas observaciones, mismo
        validador y corte final; solo las columnas, contratos y entradas de `keys`."""
        keep = [k for k in self.used if k in set(keys)]
        columns = self.columns[["ds", *[f"{COLUMN_PREFIX}{k}" for k in keep]]].copy() if "ds" in self.columns else self.columns.copy()
        return FactorResolution(
            enabled=self.enabled, reason=self.reason, columns=columns,
            contracts={c: v for c, v in self.contracts.items() if c in set(columns.columns)}, results={k: v for k, v in self.results.items() if k in keep},
            used=keep, dropped=list(self.dropped), scenario_only=list(self.scenario_only), discarded=list(self.discarded),
            _inputs={k: v for k, v in self._inputs.items() if k in keep}, _dates=self._dates, _validator=self._validator, _final=self._final,
            _retro=self._retro, _retro_prepared={k: v for k, v in self._retro_prepared.items() if k in keep},
            served_block_days=self.served_block_days, stats={k: v for k, v in self.stats.items() if k in keep},
            definitions={k: v for k, v in self.definitions.items() if k in keep})

    def shifted(self, shifts: dict[str, int]) -> "FactorResolution":
        """PLACEBO (W6-E · revisión H1): la misma resolución con las observaciones de cada factor desplazadas `shifts[clave]` días en círculo dentro de su
        propio rango (período, publicación, disponibilidad, vigencia y emisión se mueven juntos: el rezago de publicación, la distribución, la
        proporción de faltantes y el patrón de disponibilidad relativo se conservan; solo se rompe la alineación con el objetivo). Una columna
        que nunca fue verdadera ayuda al modelo no debería superar a su placebo."""
        validator = self._validator
        inputs, columns = {}, self.columns[["ds"]].copy()
        for key in self.used:
            spec, observations, _ = self._inputs[key]
            moved = shift_observations(observations, int(shifts.get(key, 0)))
            prepared = validator.prepare(spec, moved)
            inputs[key] = (spec, moved, prepared)
            series = (served_series(validator, spec, prepared, self._dates, self._final, self.served_block_days) if self.served_block_days
                      else validator.evaluate_series(spec, prepared, self._dates, final_cutoff=self._final))
            columns[f"{COLUMN_PREFIX}{key}"] = series["value"].to_numpy(dtype=float)
        return FactorResolution(
            enabled=self.enabled, reason=self.reason, columns=columns, contracts=dict(self.contracts), results=dict(self.results), used=list(self.used),
            dropped=list(self.dropped), scenario_only=list(self.scenario_only), discarded=list(self.discarded), _inputs=inputs, _dates=self._dates,
            _validator=validator, _final=self._final, served_block_days=self.served_block_days, stats=dict(self.stats),
            definitions=dict(self.definitions))


SHIFTED_COLUMNS = ("period_date", "observed_at", "published_at", "ingested_at", "available_at", "valid_from", "valid_to", "issued_at")


def shift_observations(observations: Any, days: int) -> pd.DataFrame:
    """Copia de las observaciones con TODAS sus fechas desplazadas `days` días en círculo sobre el rango de sus períodos (`period_date`): lo que sobrepasa el
    último período vuelve al inicio con el mismo desfase entre período, publicación, disponibilidad y vigencia. `days = 0` devuelve las mismas fechas."""
    df = observations.copy() if isinstance(observations, pd.DataFrame) else pd.DataFrame(list(observations or []))
    if df.empty or "period_date" not in df or not days:
        return df
    period = pd.to_datetime(df["period_date"], errors="coerce")
    lo, hi = period.min(), period.max()
    span = int((hi - lo).days) + 1
    if span < 2:
        return df
    offset = int(days) % span
    moved = ((period - lo).dt.days + offset) % span
    delta = pd.to_timedelta(moved - (period - lo).dt.days, unit="D")          # desplazamiento efectivo de cada fila (con la vuelta)
    for col in SHIFTED_COLUMNS:
        if col in df:
            values = df[col] if pd.api.types.is_datetime64_dtype(df[col]) else pd.to_datetime(df[col], errors="coerce", utc=True)     # sin zona se conserva sin zona
            df[col] = values + delta
    return df


def factor_config_errors(config: Mapping[str, Any]) -> list[dict[str, str]]:
    """Errores de las partes anidadas de la configuración `factors` que `platform.config.validate_overrides` no puede validar solo
    con el tipo del valor por defecto (mapas de precedencia y de antigüedad). Solo revisa las claves presentes en `config`."""
    errors: list[dict[str, str]] = []
    for name in ("indicator_precedence", "definition_precedence", "calendar_precedence"):
        if name not in config:
            continue
        value = config[name]
        if not isinstance(value, dict) or any(not isinstance(k, str) or v not in ("tenant", "public") for k, v in value.items()):
            errors.append({"key": name, "code": "invalid_value", "message": "debe ser un objeto {clave: 'tenant' | 'public'}"})
    if "staleness_days_by_frequency" in config:
        by_frequency = config["staleness_days_by_frequency"]
        if not isinstance(by_frequency, dict) or any(
                not isinstance(k, str) or isinstance(v, bool) or not isinstance(v, int) or v < 1 for k, v in by_frequency.items()):
            errors.append({"key": "staleness_days_by_frequency", "code": "invalid_value",
                           "message": "debe ser un objeto {frecuencia: días enteros positivos}"})
    return errors


def validate_factor_config(config: Mapping[str, Any]) -> list[str]:
    """Mensajes de `factor_config_errors` (compatibilidad)."""
    return [f"{e['key']} {e['message']}" for e in factor_config_errors(config)]


def active_factor_keys(tenant_settings: Mapping[str, Any] | None) -> list[str]:
    """Claves con `tenants.settings.factor_activation.<clave>.active` verdadero (orden alfabético: resultado determinista). Una entrada
    mal formada (que no sea un objeto con `active` literalmente verdadero) cuenta como NO activa."""
    activation = (tenant_settings or {}).get(ACTIVATION_SETTING)
    if not isinstance(activation, Mapping):
        return []
    return sorted(str(k) for k, v in activation.items() if isinstance(v, Mapping) and v.get("active") is True)


class FactorPipeline:
    """Resuelve los factores activos de una empresa.

    `config`: configuración efectiva del core `factors` (interruptor global y parámetros). `activation`: `tenants.settings.factor_activation`
    (la elección de la empresa por factor). Un factor es activo solo con `config.enabled` Y su `active` verdadero."""

    def __init__(self, config: Mapping[str, Any], activation: Mapping[str, Any] | None = None) -> None:
        problems = validate_factor_config(config)
        if problems:
            raise ValueError("Configuración de factores inválida: " + "; ".join(problems))
        self.config = dict(config)
        self.activation = dict(activation or {})

    @classmethod
    def from_settings(cls, tenant_settings: Mapping[str, Any] | None, sector: str | None = None) -> "FactorPipeline":
        """Con la configuración efectiva (core → perfil → empresa) del core `factors` y la activación por factor de la empresa."""
        from predictily_engine.platform.config import resolve_config

        settings = dict(tenant_settings or {})
        config, _, _ = resolve_config("factors", settings, sector)
        return cls(config, settings.get(ACTIVATION_SETTING) if isinstance(settings.get(ACTIVATION_SETTING), Mapping) else None)

    @property
    def enabled(self) -> bool:
        return self.config.get("enabled") is True

    @property
    def active_factors(self) -> list[str]:
        """Factores activos: `enabled` Y `factor_activation.<clave>.active`. Apagado ⇒ ninguno."""
        return active_factor_keys({ACTIVATION_SETTING: self.activation}) if self.enabled else []

    def resolve(self, repo: FactorsRepository, dates: Any, cutoff: Any, *, timezone: str | None = None,
                retroactive: bool = True, served_block_days: int | None = None) -> FactorResolution:
        """Columnas de factor para `dates` (historia hasta el fin del horizonte) con el corte `cutoff`. APAGADO ⇒ no consulta nada.

        `retroactive`: ¿reconstrucción de un corte histórico? Solo el corte final puede ser vigente (`retroactive=False`); los cortes
        anteriores de las ventanas (`FactorResolution.columns_at`) siempre se evalúan como retroactivos.

        `served_block_days` (W6-E · H1/M1/L3): modo «tal como se sirve» (`availability/served.py`): cada fila de entrenamiento recibe el valor visible en
        SU origen y arrastrado como en la predicción (bloques de esa longitud: el horizonte que se sirve); un valor más viejo que `max_staleness_days` es
        faltante; un factor solo sale entero si su fuente está vencida EN EL ORIGEN (`disable_feature`/`unavailable`) o por un motivo que no sea la
        antigüedad del arrastre, la ausencia de valor para un día (`no_value_for_date`) ni un anuncio posterior al corte (`known_after_cutoff`: en una
        reconstrucción histórica lo que se anunció después del corte se ignora, no descarta el factor). None = el comportamiento de W2-D."""
        if not self.enabled:
            return FactorResolution.disabled("disabled")
        keys = self.active_factors
        if not keys:
            return FactorResolution(enabled=True, reason="no_active_factors")
        validator = FeatureAvailabilityValidator(timezone, retroactive=retroactive)
        index = pd.DatetimeIndex(pd.to_datetime(dates)).normalize()
        cutoff_day = to_date(cutoff)
        needed = index >= to_day(cutoff_day)
        out = FactorResolution(enabled=True, columns=pd.DataFrame({"ds": index}), _dates=index, _validator=validator, _final=cutoff_day,
                               served_block_days=served_block_days)
        indicator_precedence = (self.config.get("indicator_precedence") or {})
        definition_precedence = (self.config.get("definition_precedence") or {})
        calendar_precedence = (self.config.get("calendar_precedence") or {})
        for key in keys:
            try:
                definition = repo.resolve_definition(key, definition_precedence.get(key))
            except AmbiguousDefinitionError as exc:
                out.dropped.append(_drop(key, "INVALID", "ambiguous_definition", str(exc)))
                continue
            except LookupError as exc:
                out.dropped.append(_drop(key, "INVALID", "definition_not_found", str(exc)))
                continue
            try:
                spec = FactorSpec.from_definition(definition, self.config.get("default_max_staleness_days"),
                                                  self.config.get("staleness_days_by_frequency"))
            except (ValueError, KeyError, TypeError, AttributeError) as exc:       # L1: una definición malformada saca a ESE factor, no aborta la demanda
                out.dropped.append(_drop(key, "INVALID", "invalid_definition", f"La definición del factor es inválida: {type(exc).__name__}: {exc}"[:300]))
                continue
            if spec.availability_class is AvailabilityClass.USER_SCENARIO:
                out.scenario_only.append(key)
                out.results[key] = validator.evaluate(spec, pd.DataFrame(), cutoff_day)
                continue
            try:
                observations = repo.observations_for(definition, _precedence_for(definition, indicator_precedence, calendar_precedence,
                                                                                 definition_precedence))
                if retroactive:
                    validator.assert_backtest_allowed(spec, observations)
            except AmbiguousIndicatorError as exc:
                out.dropped.append(_drop(key, "INVALID", "ambiguous_indicator", str(exc)))
                continue
            except AmbiguousCalendarError as exc:
                out.dropped.append(_drop(key, "INVALID", "ambiguous_calendar", str(exc)))
                continue
            except ScopeUnsupportedError as exc:
                out.dropped.append(_drop(key, "INVALID", "scope_unsupported", str(exc), fallback=spec.missing_strategy))
                continue
            except ForecastBacktestForbidden as exc:
                out.dropped.append(_drop(key, AvailabilityStatus.INVALID.value, Reason.NO_ISSUANCE_HISTORY, str(exc),
                                         fallback=spec.missing_strategy))
                continue
            except (LookupError, NotImplementedError) as exc:
                out.dropped.append(_drop(key, "INVALID", "storage_unreadable", str(exc), fallback=spec.missing_strategy))
                continue
            for note in getattr(observations, "attrs", {}).get("discarded", []):
                out.discarded.append({"key": key, **note})
            try:
                prepared = validator.prepare(spec, observations)
                at_cutoff = validator.evaluate(spec, prepared, cutoff_day)
                series = (served_series(validator, spec, prepared, index, cutoff_day, served_block_days) if served_block_days
                          else validator.evaluate_series(spec, prepared, index, final_cutoff=cutoff_day))
            except (ValueError, KeyError, TypeError, AttributeError) as exc:       # L1: observaciones ilegibles sacan a ESE factor
                out.dropped.append(_drop(key, "INVALID", "invalid_observations", f"Las observaciones del factor son ilegibles: {type(exc).__name__}: {exc}"[:300],
                                         fallback=spec.missing_strategy))
                continue
            out.results[key] = at_cutoff
            if served_block_days:
                # Fuente vencida EN EL ORIGEN (cortada): con `disable_feature`/`unavailable` el factor sale entero; con `last_valid_with_age` sigue y sus
                # días vencidos son faltantes. El arrastre más allá del límite NO descarta un factor con la fuente al día (una serie diaria fresca).
                stale_row = (series["status"] == AvailabilityStatus.STALE.value).to_numpy(dtype=bool)
                drop_on_stale = at_cutoff.status is AvailabilityStatus.STALE and spec.missing_strategy != "last_valid_with_age"
                benign = series["reason"].isin([Reason.NO_VALUE_FOR_DATE, Reason.KNOWN_AFTER_CUTOFF]).to_numpy(dtype=bool) | (stale_row & (not drop_on_stale))
                # Solo los días FUTUROS se sirven desde el corte; las filas de entrenamiento (incluido el día del corte) leen desde su propio origen y, si ahí
                # nada era visible todavía, quedan faltantes: no es una falla de la fuente.
                served_days = (index > to_day(cutoff_day))
                bad = series[served_days & ~series["usable"].to_numpy(dtype=bool) & ~benign]
            else:
                # Un día sin valor conocido (`no_value_for_date`: p. ej. un día sin campaña) no es una falla de la fuente: queda en NaN.
                # Falla de la fuente = no utilizable por cualquier otro motivo (STALE, NOT_YET_AVAILABLE, INVALID…).
                bad = series[needed & ~series["usable"].to_numpy(dtype=bool) & (series["reason"] != Reason.NO_VALUE_FOR_DATE).to_numpy()]
            if not bad.empty:
                first = bad.iloc[0]
                out.dropped.append(_drop(key, str(first["status"]), str(first["reason"]), at_cutoff.message,
                                         fallback=spec.missing_strategy, age_days=at_cutoff.age_days,
                                         first_unusable_date=pd.Timestamp(first["ds"]).date().isoformat(),
                                         unusable_days=int(len(bad))))
                continue
            if series["value"].isna().all():
                # Una columna sin ningún valor no aporta nada al modelo: se descarta explícitamente, no entra como «usada».
                out.dropped.append(_drop(key, at_cutoff.status.value, "no_values", "El factor no tiene ningún valor utilizable en el rango.",
                                         fallback=spec.missing_strategy, age_days=at_cutoff.age_days))
                continue
            column = f"{COLUMN_PREFIX}{key}"
            out.columns[column] = series["value"].to_numpy(dtype=float)
            out._inputs[key] = (spec, observations, prepared)
            out.definitions[key] = {k: (None if v is None or (isinstance(v, float) and pd.isna(v)) else v) for k, v in dict(definition).items()
                                    if k in DEFINITION_VERSION_FIELDS}
            if served_block_days:
                out.stats[key] = {**served_stats(series, cutoff_day), "source_stale_at_origin": at_cutoff.status is AvailabilityStatus.STALE,
                                  "max_staleness_days": spec.max_staleness_days, "missing_strategy": spec.missing_strategy,
                                  "carry_cap": "los valores más viejos que max_staleness_days son faltantes (NaN)"}
            out.contracts[column] = factor_contract(
                key, spec.availability_class, source=f"{spec.storage}: {definition.get('name')}",
                description=f"Factor externo {definition.get('name')} ({spec.family})",
                assumption=(("Valor visible en el origen de cada bloque, arrastrado hasta la fecha con su antigüedad (≤ max_staleness_days; más viejo = faltante)."
                             if served_block_days else "Valor visible al corte arrastrado con su antigüedad.")
                            if spec.availability_class is AvailabilityClass.OBSERVED_HISTORICAL else None))
            out.used.append(key)
        if not out.used and not out.dropped and not out.scenario_only:
            out.reason = "no_active_factors"
        return out


def factor_pipeline_limitations(tenant_settings: Mapping[str, Any] | None, sector: str | None = None) -> list[dict[str, str]]:
    """Limitaciones de una ejecución de demanda respecto del pipeline de factores. Apagado (por defecto) ⇒ ninguna. Encendido y conectado
    (W6-E) ⇒ ninguna aquí: lo que ocurrió con cada factor (usado, descartado con motivo, sin insumos) lo registra `backtest/factor_arm.py`. Si
    el pipeline estuviera encendido pero sin cablear (`PIPELINE_CONNECTED_TO_RUN_PIPELINE` falsa) ⇒ `factor_pipeline_not_connected`. Una
    configuración de factores inválida no detiene la demanda: se registra `factors_config_invalid` y el pipeline se trata como apagado."""
    try:
        pipeline = FactorPipeline.from_settings(tenant_settings, sector)
    except ValueError as exc:                      # ConfigValidationError es un ValueError
        return [{"code": "factors_config_invalid", "message": f"La configuración del pipeline de factores es inválida y se ignora: {exc}"}]
    if pipeline.enabled and not PIPELINE_CONNECTED_TO_RUN_PIPELINE:
        return [{"code": "factor_pipeline_not_connected", "message": (
            "El pipeline de factores externos está encendido para la empresa (factors.enabled) pero aún no está conectado a la "
            "ejecución de demanda: ningún factor externo entró al modelo, con independencia de su activación por factor.")}]
    return []


def _precedence_for(definition: Mapping[str, Any], indicator_precedence: Mapping[str, str], calendar_precedence: Mapping[str, str],
                    definition_precedence: Mapping[str, str]) -> str | None:
    """Precedencia declarada que aplica al almacenamiento de la definición: por código de indicador, o por código de evento de calendario
    (con `definition_precedence[clave]` como respaldo)."""
    schema = definition.get("schema") or {}
    if not isinstance(schema, dict):
        return None
    if definition.get("storage") == "calendar_events":
        return calendar_precedence.get(schema.get("event_code")) or definition_precedence.get(definition.get("key"))
    return indicator_precedence.get(schema.get("indicator_code"))


def _drop(key: str, status: str, reason: str, message: str, **extra: Any) -> dict[str, Any]:
    return {"key": key, "status": status, "reason": reason, "message": message, **extra}
