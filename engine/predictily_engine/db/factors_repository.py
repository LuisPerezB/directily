"""Lectura de factores externos para el motor (ADR-V25-25): indicadores, observaciones, eventos y precios confirmados.

Aislamiento multi-tenant: `FactorsRepository` se construye con un `tenant_id` y TODAS sus consultas lo incluyen (`_read` rechaza
cualquier SQL sin `:tenant_id`). Las filas públicas (`tenant_id` NULL) son catálogo compartido; las de otras empresas nunca se leen.

**Indicadores públicos y privados.** `external_indicators` admite un indicador público y otro de la empresa con el MISMO código
(`unique(tenant_id, code)` + índice único parcial para los públicos). La lectura anterior (`features.external.load_observations`)
los mezclaba en una sola serie (R-178). Aquí la resolución es por IDENTIFICADOR: dado un `indicator_id` se lee exactamente ese
indicador (público o de la empresa; el de otra empresa no existe para ella); dado un código, si hay un solo candidato se usa y si
hay dos (público y privado) hace falta una precedencia EXPLÍCITA (`tenant` o `public`); sin ella se falla con
`AmbiguousIndicatorError`. Nunca se combinan dos indicadores en una serie.
"""

from __future__ import annotations

from typing import Any, Mapping

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.db.repository import TenantScopeError

PRECEDENCES = ("tenant", "public")
ALL_SCOPES = "*"          # `scope_ref` comodín: todas las filas del indicador, sea cual sea su alcance
# Alcances de `calendar_events` que el pipeline de factores sabe aplicar a una serie de empresa. Son los mismos que acepta
# `demand.lifecycle.flag_events`; un evento de sucursal, producto, categoría o región NO se mapea a la serie de un factor global y se
# descarta con el motivo `scope_unsupported` (nunca se aplica en silencio a toda la empresa).
SUPPORTED_CALENDAR_SCOPES = ("country", "tenant", "sector")

OBSERVATION_COLUMNS = ["id", "indicator_id", "period_date", "value", "published_at", "ingested_at", "revision", "source_url",
                       "observed_at", "available_at", "valid_from", "valid_to", "issued_at", "forecast_horizon_days",
                       "source_version_id", "scope_ref", "quality_flags"]
INDICATOR_COLUMNS = ["id", "tenant_id", "code", "name", "source", "frequency", "unit", "country", "is_active"]
DEFINITION_COLUMNS = ["id", "tenant_id", "family", "key", "name", "availability_class", "storage", "external_indicator_id", "unit",
                      "frequency", "geo_scope", "source_key", "license", "missing_strategy", "max_staleness_days", "retention_days",
                      "schema", "is_active", "is_informative_alert"]
EVENT_COLUMNS = ["id", "tenant_id", "family", "event_type", "title", "source_url", "source_ref", "authority", "published_at",
                 "available_at", "effective_from", "effective_to", "jurisdiction", "corroboration", "status", "extracted_by",
                 "is_informative_alert", "source_version_id", "verified_by", "verified_at", "created_at"]
PRICE_COLUMNS = ["id", "product_id", "branch_id", "price", "currency", "valid_from", "valid_to", "source", "known_at"]


class AmbiguousIndicatorError(LookupError):
    """Existen un indicador público y otro de la empresa con el mismo código y no se declaró cuál usar."""

    def __init__(self, code: str, candidates: list[dict[str, Any]]) -> None:
        self.code = code
        self.candidates = candidates
        scopes = ", ".join(f"id={c['id']} ({'público' if c['tenant_id'] is None else 'de la empresa'})" for c in candidates)
        super().__init__(f"El código {code!r} corresponde a varios indicadores ({scopes}): declare la precedencia "
                         "('tenant' o 'public') o resuelva por identificador. Nunca se combinan en una serie.")


class AmbiguousCalendarError(LookupError):
    """Un código de evento de calendario existe como evento público y de la empresa (o con varios alcances) sin precedencia declarada."""


class ScopeUnsupportedError(LookupError):
    """Todas las filas del evento tienen un alcance que el pipeline de factores no soporta (`scope_unsupported`)."""


class AmbiguousDefinitionError(LookupError):
    """Existen una definición pública y otra de la empresa con la misma clave y no se declaró cuál usar."""


class FactorsRepository:
    def __init__(self, conn: Connection, tenant_id: int) -> None:
        if not isinstance(tenant_id, int) or isinstance(tenant_id, bool) or tenant_id <= 0:
            raise TenantScopeError("tenant_id inválido")
        self.conn = conn
        self.tenant_id = tenant_id

    # ── infraestructura ──
    def _read(self, sql: str, columns: list[str] | None = None, **params: Any) -> pd.DataFrame:
        if ":tenant_id" not in sql:
            raise TenantScopeError("Toda consulta del repositorio de factores debe filtrar por :tenant_id")
        result = self.conn.execute(text(sql), {"tenant_id": self.tenant_id, **params})
        return pd.DataFrame(result.fetchall(), columns=columns or list(result.keys()))

    # ── indicadores ──
    def indicator_candidates(self, code: str) -> list[dict[str, Any]]:
        """Indicadores activos con ese código visibles para la empresa: el público (si existe) y el propio (si existe)."""
        df = self._read(
            "SELECT id, tenant_id, code, name, source, frequency, unit, country, is_active FROM external_indicators "
            "WHERE code = :code AND (tenant_id IS NULL OR tenant_id = :tenant_id) AND is_active ORDER BY tenant_id NULLS FIRST, id",
            INDICATOR_COLUMNS, code=code)
        return _records(df)

    def resolve_indicator(self, *, indicator_id: int | None = None, code: str | None = None,
                          precedence: str | None = None) -> dict[str, Any]:
        """UN indicador, por identificador o por código con precedencia explícita. `LookupError` si no existe o no es visible;
        `AmbiguousIndicatorError` si el código es ambiguo y no se declaró precedencia."""
        if (indicator_id is None) == (code is None):
            raise ValueError("Indique indicator_id o code (exactamente uno).")
        if precedence is not None and precedence not in PRECEDENCES:
            raise ValueError(f"precedence inválida: {precedence!r} (válidas: {PRECEDENCES})")
        if indicator_id is not None:
            df = self._read(
                "SELECT id, tenant_id, code, name, source, frequency, unit, country, is_active FROM external_indicators "
                "WHERE id = :id AND (tenant_id IS NULL OR tenant_id = :tenant_id) AND is_active", INDICATOR_COLUMNS, id=indicator_id)
            if df.empty:
                raise LookupError(f"El indicador {indicator_id} no existe, está inactivo o no es visible para la empresa.")
            return _records(df)[0]
        candidates = self.indicator_candidates(str(code))
        if not candidates:
            raise LookupError(f"No hay un indicador activo con código {code!r} visible para la empresa.")
        if len(candidates) == 1:
            return candidates[0]
        if precedence is None:
            raise AmbiguousIndicatorError(str(code), candidates)
        wanted = [c for c in candidates if (c["tenant_id"] is not None) == (precedence == "tenant")]
        return wanted[0] if wanted else candidates[0]

    def indicator_observations(self, indicator_id: int, scope_ref: str | None = None) -> pd.DataFrame:
        """Observaciones de UN indicador visible para la empresa, con todas sus fechas (revisiones incluidas).

        `scope_ref`: None = solo las filas del alcance del indicador (scope_ref NULL); un valor = ese alcance; `ALL_SCOPES` = todas."""
        scope_sql = "" if scope_ref == ALL_SCOPES else (" AND o.scope_ref IS NULL" if scope_ref is None else " AND o.scope_ref = :scope_ref")
        df = self._read(
            "SELECT o.id, o.indicator_id, o.period_date, o.value::float8 AS value, o.published_at, o.ingested_at, o.revision, "
            "o.source_url, o.observed_at, o.available_at, o.valid_from, o.valid_to, o.issued_at, o.forecast_horizon_days, "
            "o.source_version_id, o.scope_ref, o.quality_flags "
            "FROM external_indicator_observations o JOIN external_indicators i ON i.id = o.indicator_id "
            "WHERE o.indicator_id = :indicator_id AND (i.tenant_id IS NULL OR i.tenant_id = :tenant_id)" + scope_sql +
            " ORDER BY o.period_date, o.revision, o.id", OBSERVATION_COLUMNS, indicator_id=indicator_id,
            **({} if scope_ref in (None, ALL_SCOPES) else {"scope_ref": scope_ref}))
        return df

    # ── catálogo de factores ──
    def factor_definitions(self, keys: list[str] | None = None) -> pd.DataFrame:
        """Definiciones visibles (públicas y de la empresa). Puede haber dos con la misma clave: `resolve_definition` las desambigua."""
        sql = ("SELECT id, tenant_id, family, key, name, availability_class, storage, external_indicator_id, unit, frequency, geo_scope, "
               'source_key, license, missing_strategy, max_staleness_days, retention_days, "schema", is_active, is_informative_alert '
               "FROM factor_definitions WHERE (tenant_id IS NULL OR tenant_id = :tenant_id)")
        if keys is not None:
            sql += " AND key = ANY(:keys)"
        return self._read(sql + " ORDER BY key, tenant_id NULLS FIRST", DEFINITION_COLUMNS, **({} if keys is None else {"keys": list(keys)}))

    def resolve_definition(self, key: str, precedence: str | None = None) -> dict[str, Any]:
        df = self.factor_definitions([key])
        rows = _records(df)
        if not rows:
            raise LookupError(f"No hay una definición de factor {key!r} visible para la empresa.")
        if len(rows) == 1:
            return rows[0]
        if precedence is None:
            raise AmbiguousDefinitionError(f"La clave de factor {key!r} existe como definición pública y de la empresa: declare la precedencia.")
        wanted = [r for r in rows if (r["tenant_id"] is not None) == (precedence == "tenant")]
        return wanted[0] if wanted else rows[0]

    def observations_for(self, definition: Mapping[str, Any], precedence: str | None = None) -> pd.DataFrame:
        """Observaciones de un factor según su almacenamiento. Soportados: `external_indicator_observations` (por el identificador
        de la definición o, sin él, por `schema.indicator_code` con la precedencia declarada) y `calendar_events` (eventos del código indicado en `schema.event_code`). Los demás almacenamientos
        nativos (ventas, precios, promociones, compromisos, inventario) tienen su propia lectura as-of y no pasan por aquí."""
        storage = definition.get("storage")
        if storage == "external_indicator_observations":
            indicator_id = definition.get("external_indicator_id")
            schema = definition.get("schema") or {}
            code = schema.get("indicator_code") if isinstance(schema, dict) else None
            if indicator_id is not None and not pd.isna(indicator_id):
                ref = self.resolve_indicator(indicator_id=int(indicator_id))
            elif code:
                ref = self.resolve_indicator(code=str(code), precedence=precedence)
            else:
                raise LookupError(f"El factor {definition.get('key')!r} no apunta a ningún indicador "
                                  "(external_indicator_id o schema.indicator_code).")
            return self.indicator_observations(int(ref["id"]))
        if storage == "calendar_events":
            schema = definition.get("schema") or {}
            code = schema.get("event_code") if isinstance(schema, dict) else None
            if not code:
                raise LookupError(f"El factor {definition.get('key')!r} (calendar_events) no declara schema.event_code.")
            return self.calendar_observations(str(code), precedence=precedence, scope=schema.get("scope"),
                                              scope_ref=schema.get("scope_ref"))
        raise NotImplementedError(f"El almacenamiento {storage!r} no se lee con el pipeline de factores.")

    # ── calendario (campañas) ──
    def calendar_observations(self, event_code: str, precedence: str | None = None, scope: str | None = None,
                              scope_ref: str | None = None) -> pd.DataFrame:
        """Las revisiones de UN evento de calendario como observaciones: el valor es 1 mientras el evento esté vigente y 0 si una
        revisión lo canceló; `valid_from…valid_to` es su ventana y `available_at` su `known_at` (NULL = desconocido: en una
        reconstrucción retroactiva no puede demostrarse que se conocía).

        - **Alcance.** Solo `country`, `tenant` y `sector` (`SUPPORTED_CALENDAR_SCOPES`). Las filas de sucursal, producto, categoría o región
          se DESCARTAN con el motivo `scope_unsupported` (quedan en `frame.attrs['discarded']`); si no queda ninguna se lanza
          `ScopeUnsupportedError`.
        - **Origen.** Un código puede existir como evento público (tenant NULL) y como evento de la empresa. Con ambos hace falta
          `precedence` ('tenant' o 'public'); sin ella `AmbiguousCalendarError`. Las revisiones de orígenes distintos NUNCA se mezclan:
          solo se devuelven las del origen elegido.
        - **Identidad.** Dentro del origen elegido debe quedar un único (scope, scope_ref); si hay varios, `AmbiguousCalendarError`, salvo
          que `scope` / `scope_ref` (de `factor_definitions.schema`) lo acoten."""
        if precedence is not None and precedence not in PRECEDENCES:
            raise ValueError(f"precedence inválida: {precedence!r} (válidas: {PRECEDENCES})")
        rows = self._read(
            "SELECT id, tenant_id, scope, scope_ref, starts_on AS period_date, CASE WHEN status = 'cancelled' THEN 0.0 ELSE 1.0 END AS value, "
            "revision, known_at AS available_at, starts_on AS valid_from, ends_on AS valid_to, status FROM calendar_events "
            "WHERE code = :code AND (tenant_id IS NULL OR tenant_id = :tenant_id) ORDER BY starts_on, revision, id", None, code=event_code)
        rows["origin"] = rows["tenant_id"].map(lambda t: "public" if t is None or pd.isna(t) else "tenant")
        unsupported = ~rows["scope"].isin(SUPPORTED_CALENDAR_SCOPES)
        discarded = [{"reason": "scope_unsupported", "scope": str(sc), "rows": int(n)}
                     for sc, n in rows.loc[unsupported, "scope"].value_counts().sort_index().items()]
        # L2 (W6-E): un evento de alcance `country` o `sector` solo aplica a la empresa de ESE país y ESE sector (un feriado de otro país, o la campaña
        # de otro rubro, no es una feature de esta empresa); el de alcance `tenant` es solo de la propia empresa.
        country, sector = self._tenant_country_and_sector()
        ref_text = rows["scope_ref"].astype("string").str.strip()
        applies = (((rows["scope"] == "country") & (ref_text.str.upper() == country))
                   | ((rows["scope"] == "sector") & (ref_text.str.lower() == sector))
                   | ((rows["scope"] == "tenant") & (rows["tenant_id"] == self.tenant_id))).fillna(False).astype(bool)
        inapplicable = ~unsupported & ~applies
        discarded += [{"reason": "scope_not_applicable", "scope": str(sc), "scope_ref": None if pd.isna(ref) else str(ref), "rows": int(n)}
                      for (sc, ref), n in rows.loc[inapplicable].groupby(["scope", rows["scope_ref"].fillna("")]).size().items()]
        kept = rows[~unsupported & applies]
        if kept.empty and discarded:
            raise ScopeUnsupportedError(
                f"Ninguna fila del evento {event_code!r} aplica a esta empresa: " + "; ".join(
                    f"{d['reason']} ({d['scope']}{'/' + d['scope_ref'] if d.get('scope_ref') else ''})" for d in discarded)
                + f" — solo {', '.join(SUPPORTED_CALENDAR_SCOPES)} y, para country/sector, el país y el rubro de la empresa.")
        if scope is not None:
            kept = kept[kept["scope"] == scope]
        if scope_ref is not None:
            kept = kept[kept["scope_ref"] == scope_ref]
        origins = sorted(set(kept["origin"]))
        if len(origins) > 1:
            if precedence is None:
                raise AmbiguousCalendarError(
                    f"El evento de calendario {event_code!r} existe como público y como de la empresa: declare la precedencia "
                    "('tenant' o 'public'). Nunca se combinan sus revisiones.")
            kept = kept[kept["origin"] == ("tenant" if precedence == "tenant" else "public")]
            if kept.empty:
                raise LookupError(f"No hay filas del evento {event_code!r} con el origen {precedence!r}.")
        identities = kept[["scope", "scope_ref"]].drop_duplicates()
        if len(identities) > 1:
            raise AmbiguousCalendarError(
                f"El evento de calendario {event_code!r} tiene varios alcances ({identities.to_dict('records')}): acote "
                "schema.scope / schema.scope_ref en la definición del factor.")
        out = kept.drop(columns=["tenant_id"]).reset_index(drop=True)
        out.attrs["discarded"] = discarded
        return out

    def _tenant_country_and_sector(self) -> tuple[str, str]:
        """País (código ISO en mayúsculas) y rubro (minúsculas) de la empresa, para decidir qué eventos de calendario le aplican."""
        df = self._read("SELECT country, sector FROM tenants WHERE id = :tenant_id")
        if df.empty:
            return "", ""
        row = df.iloc[0]
        return str(row["country"] or "").strip().upper(), str(row["sector"] or "").strip().lower()

    # ── eventos externos ──
    def events(self, families: list[str] | None = None) -> pd.DataFrame:
        """Eventos externos visibles (públicos y de la empresa) con su procedencia y estado."""
        sql = ("SELECT id, tenant_id, family, event_type, title, source_url, source_ref, authority, published_at, available_at, "
               "effective_from, effective_to, jurisdiction, corroboration, status, extracted_by, is_informative_alert, "
               "source_version_id, verified_by, verified_at, created_at FROM external_events "
               "WHERE (tenant_id IS NULL OR tenant_id = :tenant_id)")
        if families is not None:
            sql += " AND family = ANY(:families)"
        return self._read(sql + " ORDER BY id", EVENT_COLUMNS, **({} if families is None else {"families": list(families)}))

    # ── precios confirmados ──
    def price_history(self, product_ids: list[int] | None = None) -> pd.DataFrame:
        """`product_price_history` de la empresa con `known_at` (cuándo se conocía cada precio). Fuente de los precios futuros
        confirmados (KNOWN_IN_ADVANCE) del `FeatureBuilder`."""
        sql = ("SELECT id, product_id, branch_id, price::float8 AS price, currency, valid_from, valid_to, source, known_at "
               "FROM product_price_history WHERE tenant_id = :tenant_id")
        if product_ids is not None:
            sql += " AND product_id = ANY(:product_ids)"
        return self._read(sql + " ORDER BY product_id, branch_id NULLS FIRST, valid_from, id", PRICE_COLUMNS,
                          **({} if product_ids is None else {"product_ids": [int(p) for p in product_ids]}))


def _records(df: pd.DataFrame) -> list[dict[str, Any]]:
    """Filas como diccionarios con `None` en lugar de NaN/NaT."""
    out = []
    for row in df.to_dict("records"):
        out.append({k: (None if (v is pd.NaT or (isinstance(v, float) and pd.isna(v))) else v) for k, v in row.items()})
    return out


__all__ = ["ALL_SCOPES", "AmbiguousCalendarError", "AmbiguousDefinitionError", "AmbiguousIndicatorError", "FactorsRepository",
           "PRECEDENCES", "SUPPORTED_CALENDAR_SCOPES", "ScopeUnsupportedError"]
