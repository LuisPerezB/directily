"""Lectura de datos de UNA empresa para el Customer Core (etapa 2, P2).

Misma garantía de aislamiento que `TenantRepository`: toda consulta es parametrizada e incluye
`:tenant_id`; `_read` rechaza SQL que no filtre por empresa. Las compras válidas excluyen anuladas,
notas de crédito/devoluciones, ventas anónimas y compradores genéricos; las devoluciones se leen
aparte (nunca restan de la cantidad de compra).

Compras as-of (ADR-V25-23): `purchases(through)` y `returns(through)` devuelven lo que se CONOCÍA al corte (la versión del documento
vigente ese día), no el estado actual: un documento cargado después o una anulación posterior no alteran un corte pasado. Ambas se
derivan de UNA lectura de la vista `sales_line_versions` (`purchase_versions`), con la que el Customer Core arma además las
variables de cada corte con su propio conocimiento y las etiquetas con el más reciente (la verdad al corte final). Con `knowledge`
(un día posterior al corte) rige lo conocido hasta ese día: así lee su período final una ejecución vigente (ver `data.asof`).
"""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pandas as pd
from sqlalchemy import Connection, text

from predictily_engine.data.asof import PurchaseVersions, to_day, with_knowledge
from predictily_engine.db.repository import TenantRepository, TenantScopeError
from predictily_engine.extensions.data_core import effective_coverage

VALID_DOCS = "('invoice', 'sales_note', 'other')"


class CustomerRepository:
    def __init__(self, conn: Connection, tenant_id: int) -> None:
        self.base = TenantRepository(conn, tenant_id)  # valida tenant_id
        self.conn = conn
        self.tenant_id = tenant_id
        # lecturas de versiones ya hechas (por tipo y filtros): (corte final, conocimiento, líneas)
        self._lines: dict[tuple, tuple[pd.Timestamp, pd.Timestamp, pd.DataFrame]] = {}

    # ── infraestructura ──
    def _read(self, sql: str, **params: Any) -> pd.DataFrame:
        if ":tenant_id" not in sql:
            raise TenantScopeError("Toda consulta del repositorio debe filtrar por :tenant_id")
        result = self.conn.execute(text(sql), {"tenant_id": self.tenant_id, **params})
        return pd.DataFrame(result.fetchall(), columns=list(result.keys()))

    def _scalar(self, sql: str, **params: Any) -> Any:
        if ":tenant_id" not in sql:
            raise TenantScopeError("Toda consulta del repositorio debe filtrar por :tenant_id")
        return self.conn.execute(text(sql), {"tenant_id": self.tenant_id, **params}).scalar()

    # ── empresa y ejecuciones ──
    def tenant(self) -> dict[str, Any]:
        return self.base.tenant()

    def core_run(self, run_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT * FROM core_runs WHERE id = :run_id AND tenant_id = :tenant_id AND core = 'customer'",
            run_id=run_id)
        if df.empty:
            return None
        row = df.iloc[0].to_dict()
        for k in ("options", "horizons", "summary", "assumptions", "limitations", "effective_config",
                  "evaluation_manifest", "model_versions", "target_definition"):
            v = row.get(k)
            if isinstance(v, str):
                row[k] = json.loads(v)
        return row

    def training_job(self, job_id: int) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, tenant_id, core_run_id, job_type, status FROM training_jobs "
            "WHERE id = :job_id AND tenant_id = :tenant_id", job_id=job_id)
        return None if df.empty else df.iloc[0].to_dict()

    # ── catálogos ──
    def products(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS product_id, category_id, unit, is_active, deleted_at IS NOT NULL AS deleted "
            "FROM products WHERE tenant_id = :tenant_id ORDER BY id")

    def branches(self) -> pd.DataFrame:
        return self._read(
            "SELECT id AS branch_id, province, canton FROM branches WHERE tenant_id = :tenant_id ORDER BY id")

    # ── ventas ──
    def sales_range(self) -> tuple[date | None, date | None]:
        """Primera y última fecha con ventas válidas (cualquier comprador, incluidos anónimos)."""
        df = self._read(
            f"SELECT MIN(s.sale_date) AS first_day, MAX(s.sale_date) AS last_day FROM sales s "
            f"WHERE s.tenant_id = :tenant_id AND s.status = 'completed' AND s.document_type IN {VALID_DOCS}")
        if df.empty:
            return None, None
        r = df.iloc[0]
        return (None if pd.isna(r["first_day"]) else r["first_day"], None if pd.isna(r["last_day"]) else r["last_day"])

    def _version_lines(self, kind: str, through: date, exclude_generic: bool = True, generic_codes: tuple[str, ...] = (),
                       knowledge: date | None = None) -> tuple[pd.DataFrame, str]:
        """Compras válidas (`kind = 'purchases'`) o devoluciones (`'returns'`) con fecha ≤ `through` conocidas hasta el día `knowledge`
        (por defecto el propio corte), AGREGADAS por comprador × producto × sucursal × día × moneda y ventana de visibilidad (`c_from`,
        `c_to`: primer y último corte en que la versión del documento es visible; ver `data.asof`). Una lectura por tipo y filtros; un corte o
        un conocimiento mayores la reemplazan por otra que cubre también lo ya leído."""
        final = to_day(through)
        know = final if knowledge is None else max(final, to_day(knowledge))
        key = (kind, bool(exclude_generic), tuple(generic_codes))
        tz = self.base.timezone()
        cached = self._lines.get(key)
        if cached is not None and cached[0] >= final and cached[1] >= know:
            return cached[2], tz
        if cached is not None:
            final, know = max(final, cached[0]), max(know, cached[1])
        self.conn.execute(text("SET LOCAL jit = off"))  # escaneo con agregación: JIT solo suma tiempo de compilación
        window = ("v.sale_date AS ds, s.currency, (v.ingestion_mode = 'incremental') AS incremental, "
                  "GREATEST(v.sale_date, CAST(v.known_from AT TIME ZONE CAST(:tz AS text) AS date)) AS c_from, "
                  "CAST(v.known_until AT TIME ZONE CAST(:tz AS text) AS date) - 1 AS c_to, ")
        source = "FROM sales_line_versions v JOIN sales s ON s.id = v.sale_id AND s.tenant_id = v.tenant_id "
        if kind == "purchases":
            generic_sql = ("AND c.is_generic = false AND c.external_code <> ALL(CAST(:codes AS text[])) "
                           if exclude_generic else "")
            sql = f"""
                WITH v AS (
                    SELECT v.customer_id, v.product_id, v.branch_id, {window}
                           v.quantity, v.net_amount, v.unit_price, v.discount_amount
                    {source}
                    JOIN customers c ON c.id = v.customer_id AND c.tenant_id = v.tenant_id
                    WHERE v.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                      AND v.status = 'completed' AND v.document_type IN {VALID_DOCS}
                      AND v.is_return = false AND v.quantity > 0 AND c.deleted_at IS NULL
                      {generic_sql}
                      AND v.sale_date <= :through
                )
                SELECT customer_id, product_id, branch_id, ds, currency, false AS is_ret, incremental, c_from, c_to,
                       SUM(quantity)::float8 AS qty, SUM(net_amount)::float8 AS net_amount,
                       SUM(quantity * unit_price)::float8 AS gross_amount, SUM(discount_amount)::float8 AS discount_amount,
                       COUNT(*) AS lines
                FROM v WHERE c_from <= :knowledge AND (c_to IS NULL OR c_to >= c_from)
                GROUP BY customer_id, product_id, branch_id, ds, currency, incremental, c_from, c_to
                """
        else:
            sql = f"""
                WITH v AS (
                    SELECT v.customer_id, v.product_id, v.branch_id, {window} v.quantity
                    {source}
                    WHERE v.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND v.status = 'completed'
                      AND v.customer_id IS NOT NULL AND (v.is_return = true OR v.document_type = 'credit_note')
                      AND v.sale_date <= :through
                )
                SELECT customer_id, product_id, branch_id, ds, currency, true AS is_ret, incremental, c_from, c_to,
                       SUM(quantity)::float8 AS qty, 0.0::float8 AS net_amount, 0.0::float8 AS gross_amount,
                       0.0::float8 AS discount_amount, COUNT(*) AS lines
                FROM v WHERE c_from <= :knowledge AND (c_to IS NULL OR c_to >= c_from)
                GROUP BY customer_id, product_id, branch_id, ds, currency, incremental, c_from, c_to
                """
        df = self._read(sql, through=final.date(), knowledge=know.date(), tz=tz, codes=list(generic_codes))
        self._lines[key] = (final, know, df)
        return df, tz

    def purchase_versions(self, through: date, exclude_generic: bool, generic_codes: list[str],
                          knowledge: date | None = None) -> PurchaseVersions:
        """Versiones de las líneas de compra y devolución de compradores identificados hasta `through`, con su ventana de
        conocimiento: ÚNICA lectura con la que el Customer Core deriva cada vista as-of (ver `data.asof.PurchaseVersions`).
        `knowledge`: hasta qué día llega lo conocido para la verdad del corte final (por defecto el propio corte)."""
        codes = tuple(generic_codes)
        p, tz = self._version_lines("purchases", through, exclude_generic, codes, knowledge)
        r, _ = self._version_lines("returns", through, knowledge=knowledge)
        return PurchaseVersions(pd.concat([p, r], ignore_index=True), tz, to_day(through), knowledge)

    def purchases(self, through: date, exclude_generic: bool, generic_codes: list[str],
                  knowledge: date | None = None) -> pd.DataFrame:
        """Compras válidas por comprador identificado × producto × sucursal × día (cantidades de todas las líneas) tal como se
        conocían al corte `through`: un documento cargado después no aparece y uno anulado o corregido después aparece con la
        versión que tenía ese día. Con `knowledge`, lo conocido hasta ese día."""
        versions = self.purchase_versions(through, exclude_generic, generic_codes, knowledge)
        return with_knowledge(versions.purchase_cells(through, knowledge), versions)

    def returns(self, through: date, knowledge: date | None = None) -> pd.DataFrame:
        """Devoluciones (notas de crédito o líneas marcadas) de compradores identificados conocidas al corte: se informan aparte."""
        lines, tz = self._version_lines("returns", through, knowledge=knowledge)
        return PurchaseVersions(lines, tz, to_day(through), knowledge).return_cells(through, knowledge)

    def excluded_sales_stats(self, through: date, generic_codes: list[str], knowledge: date | None = None) -> dict[str, Any]:
        """Líneas y unidades de ventas válidas que NO se atribuyen a un comprador modelable (anónimas/genéricas), tal como se
        conocían al corte `through` (o a `knowledge`; misma regla de visibilidad que las compras).

        EXCEPCIÓN (documentada, W2-C): la visibilidad as-of se aplica a las VENTAS; los atributos del comprador (`customers.deleted_at`,
        `is_generic`, `external_code`) se leen en su estado ACTUAL porque el Customer Core no los versiona, así que en una reconstrucción
        histórica un cliente dado de baja DESPUÉS del corte figura aquí como `deleted_customer`. Los gates de calidad (`quality.signals.identity`)
        calculan la misma proporción con la baja AS-OF y la prefieren; esta cuenta es su respaldo (`identity.basis` lo declara)."""
        tz = self.base.timezone()
        know = to_day(through) if knowledge is None else max(to_day(through), to_day(knowledge))
        df = self._read(
            f"""
            SELECT CASE WHEN v.customer_id IS NULL THEN 'anonymous'
                        WHEN c.is_generic OR c.external_code = ANY(CAST(:codes AS text[])) THEN 'generic'
                        WHEN c.deleted_at IS NOT NULL THEN 'deleted_customer'
                        ELSE 'modeled' END AS kind,
                   COUNT(*) AS lines, SUM(v.quantity)::float8 AS units
            FROM sales_line_versions v
            LEFT JOIN customers c ON c.id = v.customer_id AND c.tenant_id = v.tenant_id
            WHERE v.tenant_id = :tenant_id AND v.status = 'completed'
              AND v.document_type IN {VALID_DOCS} AND v.is_return = false AND v.quantity > 0
              AND v.sale_date <= :through AND v.sale_date > :through - 180
              AND GREATEST(v.sale_date, CAST(v.known_from AT TIME ZONE CAST(:tz AS text) AS date)) <= :knowledge
              AND (v.known_until IS NULL OR CAST(v.known_until AT TIME ZONE CAST(:tz AS text) AS date) - 1 >= :knowledge)
            GROUP BY 1
            """,
            through=through, knowledge=know.date(), tz=tz, codes=list(generic_codes))
        out = {k: {"lines": 0, "units": 0.0} for k in ("anonymous", "generic", "deleted_customer", "modeled")}
        for r in df.itertuples(index=False):
            out[r.kind] = {"lines": int(r.lines), "units": float(r.units or 0.0)}
        total = sum(v["units"] for v in out.values())
        out["modeled_share_units_180d"] = (out["modeled"]["units"] / total) if total > 0 else None
        out["window_days"] = 180
        return out

    def customer_codes(self, customer_ids: list[int]) -> pd.DataFrame:
        if not customer_ids:
            return pd.DataFrame(columns=["customer_id", "external_code"])
        return self._read(
            "SELECT id AS customer_id, external_code FROM customers WHERE tenant_id = :tenant_id AND id = ANY(:ids)",
            ids=[int(i) for i in customer_ids])

    # ── inventario y cobertura ──
    def availability(self, product_ids: list[int], date_from: date, date_to: date) -> pd.DataFrame:
        """Días con inventario conocido: disponible = existencia − reservas > 0 (sin snapshot = desconocido)."""
        if not product_ids:
            return pd.DataFrame(columns=["branch_id", "product_id", "ds", "available"])
        df = self._read(
            """
            SELECT branch_id, product_id, snapshot_date AS ds,
                   ((COALESCE(on_hand, 0) - COALESCE(reserved, 0)) > 0) AS available
            FROM inventory_snapshots
            WHERE tenant_id = :tenant_id AND product_id = ANY(:pids) AND snapshot_date BETWEEN :d1 AND :d2
            """,
            pids=[int(p) for p in product_ids], d1=date_from, d2=date_to)
        return df

    def coverage(self, entity: str = "sales") -> pd.DataFrame:
        """Cobertura EFECTIVA de la fuente (regla de P3: declaración del usuario > mínimo entre fuentes > ninguna), en una fila.

        Sin cobertura (`rule = none`) devuelve un DataFrame vacío: la maduración de etiquetas usa entonces la última venta.
        """
        eff = effective_coverage(self.conn, self.tenant_id, entity, None)
        if eff["complete_through"] is None:
            return pd.DataFrame(columns=["entity", "branch_id", "complete_from", "complete_through", "rule", "basis"])
        return pd.DataFrame([{"entity": entity, "branch_id": None, "complete_from": eff["complete_from"],
                              "complete_through": eff["complete_through"], "rule": eff["rule"], "basis": eff["basis"]}])

    # ── promociones (mismo contrato as-of que la demanda) ──
    def promotions(self) -> pd.DataFrame:
        return self.base.promotions()

    def promotion_revisions(self) -> pd.DataFrame:
        return self.base.promotion_revisions()

    # ── compromisos comerciales (los escribe P3; aquí solo se leen) ──
    def commitment_revisions(self) -> pd.DataFrame:
        """Todas las revisiones de compromisos con `known_at`, `recorded_at` (ingestión) y `cancelled_at` en hora local de la empresa
        (la regla de visibilidad a un corte —P3 `CommitmentAsOf`: `COALESCE(known_at, recorded_at) ≤ corte`— se aplica en
        `customer.commitments.state_as_of`)."""
        tz = TenantRepository._TENANT_TZ
        df = self._read(
            f"""
            SELECT r.commitment_id, r.revision, r.status, (r.known_at AT TIME ZONE tz.tz) AS known_local,
                   (r.recorded_at AT TIME ZONE tz.tz) AS recorded_local,
                   (r.cancelled_at AT TIME ZONE tz.tz) AS cancelled_local, r.snapshot,
                   c.commitment_type, c.customer_id, c.currency
            FROM commercial_commitment_revisions r
            {tz.format(alias='r')}
            JOIN commercial_commitments c ON c.tenant_id = r.tenant_id AND c.id = r.commitment_id
            WHERE r.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
            ORDER BY r.commitment_id, r.revision
            """)
        if len(df):
            df["snapshot"] = df["snapshot"].map(lambda v: json.loads(v) if isinstance(v, str) else v)
        return df

    def product_skus(self) -> dict[str, int]:
        df = self._read("SELECT id, upper(sku) AS sku FROM products WHERE tenant_id = :tenant_id")
        return {r.sku: int(r.id) for r in df.itertuples(index=False)}

    # ── demanda agregada vigente (para la reconciliación) ──
    def current_forecast_run(self) -> dict[str, Any] | None:
        df = self._read(
            "SELECT id, version, cutoff_date, status FROM forecast_runs "
            "WHERE tenant_id = :tenant_id AND is_current AND status = 'completed' ORDER BY id DESC LIMIT 1")
        return None if df.empty else df.iloc[0].to_dict()

    def aggregate_demand(self, forecast_run_id: int, product_ids: list[int], as_of: date, horizon_days: int) -> pd.DataFrame:
        """Suma de la demanda diaria pronosticada por producto (todas las sucursales) en (as_of, as_of + H]."""
        if not product_ids:
            return pd.DataFrame(columns=["product_id", "days", "yhat"])
        return self._read(
            """
            SELECT fv.product_id, COUNT(*) AS days, SUM(fv.yhat)::float8 AS yhat
            FROM forecast_values fv
            WHERE fv.tenant_id = :tenant_id AND fv.forecast_run_id = :run_id AND fv.level = 'product'
              AND fv.granularity = 'day' AND fv.product_id = ANY(:pids)
              AND fv.target_date > :as_of AND fv.target_date <= CAST(:as_of AS date) + CAST(:h AS integer)
            GROUP BY fv.product_id
            """,
            run_id=forecast_run_id, pids=[int(p) for p in product_ids], as_of=as_of, h=int(horizon_days))

    # ── registro de modelos ──
    # W4-B · las filas traen lo necesario para decidir si pueden servirse (`mlops.policy`, espejo de `ModelServingPolicy::basis()`):
    # aprobación, marcas de publicación y enlaces de corrida, más el uuid de la empresa (clave `tenants/<uuid>/models/…` de lo publicado).
    SERVING_COLUMNS = ("m.id, m.tenant_id, m.version, m.model_family, m.role, m.status, m.hyperparameters, m.artifact_path, m.artifact_sha256, "
                       "m.artifact_compat, m.approved_at, m.approved_by, m.published_at, m.published_by, m.published_object_key, "
                       "m.published_env, m.forecast_run_id, m.core_run_id, m.training_job_id, t.uuid::text AS tenant_uuid")

    def validated_references(self) -> pd.DataFrame:
        """Referencias validadas (más reciente primero): respaldo si falla el artefacto en producción."""
        return self._read(
            f"SELECT {self.SERVING_COLUMNS} FROM model_registry m JOIN tenants t ON t.id = m.tenant_id "
            "WHERE m.tenant_id = :tenant_id AND m.core = 'customer' AND m.status IN ('validated', 'retired', 'production') "
            "AND m.hyperparameters->>'kind' = 'reference' AND (m.artifact_path IS NOT NULL OR m.published_object_key IS NOT NULL) ORDER BY m.id DESC")

    def production_models(self) -> pd.DataFrame:
        return self._read(
            f"SELECT {self.SERVING_COLUMNS}, m.training_data_to FROM model_registry m JOIN tenants t ON t.id = m.tenant_id "
            "WHERE m.tenant_id = :tenant_id AND m.core = 'customer' AND m.status = 'production' ORDER BY m.id")
