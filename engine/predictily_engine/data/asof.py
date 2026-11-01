"""Lectura as-of de las ventas (ADR-V25-23 · tiempo de conocimiento y versiones de documentos).

Una venta tiene tres fechas distintas que antes se confundían en `sale_date`:

1. **Fecha de evento** (`sale_date`): cuándo ocurrió la venta para el negocio (fecha local de la empresa).
2. **Conocimiento** (`known_from` … `known_until`): desde cuándo Predictily CONOCE cada versión del documento y hasta
   cuándo fue la vigente. Una carga histórica declarada (`backfill`) se asume conocida desde el inicio de su día de
   negocio; una carga rutinaria (`incremental`) lo es desde que se recibió; una anulación o corrección posterior cierra
   la ventana de la versión anterior y abre la nueva.
3. **Ingestión** (`first_seen_at` / `created_at`): cuándo llegó el documento por primera vez (auditoría, no entra aquí).

Regla de visibilidad al corte C (fecha local de la empresa): una versión es visible si
`known_from < medianoche local de (C + 1)` y (`known_until` es NULL o `known_until >= medianoche local de (C + 1)`).

**Equivalencia por días.** Los cortes son fechas, así que la regla solo depende del DÍA local de `known_from` y de `known_until`:
`known_from < medianoche(C+1)` ⇔ `día_local(known_from) ≤ C` y `known_until ≥ medianoche(C+1)` ⇔ `día_local(known_until) ≥ C+1`. Una
versión es entonces visible exactamente en los cortes `C ∈ [max(sale_date, día_local(known_from)), día_local(known_until) − 1]` (sin
extremo superior si `known_until` es NULL). La lectura aprovecha esto: la base AGREGA las líneas que comparten celda y ventana de
visibilidad (`c_from`, `c_to`), de modo que se transfieren unas pocas filas por celda en lugar de una por línea (con una carga
histórica es la misma cantidad de filas que la consulta agregada anterior). `test_asof_visibility.py` comprueba la equivalencia
contra la regla literal en varias zonas horarias, incluidos los instantes justo en la frontera.

El motor lee las versiones UNA vez hasta el corte final (vista `sales_line_versions`, contrato de la migración
`2026_03_01_000100`) y deriva cada vista as-of en pandas con filtros vectorizados:

- `SalesVersions`: Demanda. Celdas diarias sucursal × producto, devoluciones, estadísticas y última venta de cada corte.
- `PurchaseVersions`: Customer Core. Compras y devoluciones diarias comprador × producto × sucursal.

**Conocimiento del corte final.** Cada lectura lleva dos límites: el corte final `F` (última fecha de EVENTO incluida) y el día `K ≥ F`
hasta el que llega lo CONOCIDO (`knowledge`). En una reconstrucción HISTÓRICA `K = F`: lo ocurrido después del corte final no puede cambiar
ninguna vista ≤ F (una versión conocida después se descarta y una reemplazada después se trata como vigente: reproducibilidad). En una
ejecución VIGENTE (corte = último dato) `K` es HOY: lo recibido después de la medianoche del último día de datos —p. ej. la carga nocturna de
ayer— sí cuenta para el período final y para los reales con que se evalúan las ventanas, igual que las promociones (`promotions_known_on`); sin
esto, con carga incremental el último día aparecería vacío. Las vistas de los cortes ANTERIORES (ventanas del backtest, observaciones del
Customer Core) usan siempre solo lo conocido en su propio corte.

Las sumas en coma flotante son deterministas: la base suma en `numeric` (exacto) y pandas ordena las celdas antes de sumar ventanas
distintas de una misma celda.
"""

from __future__ import annotations

import hashlib
from datetime import date
from typing import Any
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import numpy as np
import pandas as pd

DEFAULT_TIMEZONE = "America/Guayaquil"
VALID_SALE_DOCS = ("invoice", "sales_note", "other")
KNOWLEDGE_ATTR = "knowledge_digest"  # `DataFrame.attrs`: huella de las ventanas de conocimiento de los datos de un marco
_I64_MAX = np.iinfo(np.int64).max
_EPOCH = np.datetime64("1970-01-01", "D")

SALES_CELL_COLUMNS = ["branch_id", "product_id", "ds", "units", "net_amount", "gross_amount", "discount_amount",
                      "cost_amount", "cost_units", "lines"]
RETURN_CELL_COLUMNS = ["branch_id", "product_id", "ds", "units"]
PURCHASE_CELL_COLUMNS = ["customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount", "gross_amount",
                         "discount_amount"]
CUSTOMER_RETURN_COLUMNS = ["customer_id", "product_id", "ds", "qty"]


# ───────────────────────────── tiempo ─────────────────────────────

def resolve_timezone(name: str | None) -> str:
    """Zona de la empresa; si falta o no es válida, la de Ecuador (la misma que usa el repositorio)."""
    try:
        ZoneInfo(name or DEFAULT_TIMEZONE)
        return name or DEFAULT_TIMEZONE
    except (ZoneInfoNotFoundError, ValueError):
        return DEFAULT_TIMEZONE


def to_day(value: Any) -> pd.Timestamp:
    """Fecha → medianoche sin zona (`Timestamp`)."""
    return pd.Timestamp(value).normalize()


def local_today(tz: str | None) -> date:
    """Hoy en la zona horaria de la empresa (el día hasta el que llega lo conocido en una ejecución vigente)."""
    return pd.Timestamp.now(tz=resolve_timezone(tz)).date()


def local_midnight_after(days: Any, tz: str | None) -> np.ndarray:
    """Medianoche LOCAL del día siguiente a cada fecha de `days`, expresada en UTC sin zona (`datetime64[ns]`).

    Es el instante `M(C + 1)` de la regla de visibilidad: lo conocido ANTES de ese instante se conocía durante el día C.
    Una medianoche inexistente (salto de horario) se adelanta; una ambigua usa la primera ocurrencia."""
    values = np.atleast_1d(np.asarray(days, dtype="datetime64[ns]"))
    if values.size == 0:
        return np.array([], dtype="datetime64[ns]")
    idx = pd.DatetimeIndex(values).normalize() + pd.Timedelta(days=1)
    local = idx.tz_localize(resolve_timezone(tz), ambiguous=np.ones(len(idx), dtype=bool), nonexistent="shift_forward")
    return local.tz_convert("UTC").tz_localize(None).to_numpy(dtype="datetime64[ns]")


def knowledge_boundary(cutoff: Any, tz: str | None) -> np.datetime64:
    """`M(C + 1)` del corte `cutoff` (UTC sin zona)."""
    return local_midnight_after([np.datetime64(to_day(cutoff), "ns")], tz)[0]


def local_day(instants: Any, tz: str | None) -> np.ndarray:
    """Día LOCAL (medianoche sin zona, `datetime64[ns]`) de instantes UTC sin zona; NaT se conserva."""
    idx = pd.DatetimeIndex(pd.to_datetime(instants))
    return idx.tz_localize("UTC").tz_convert(resolve_timezone(tz)).tz_localize(None).normalize().to_numpy("datetime64[ns]")


def _days(values: Any) -> np.ndarray:
    """datetime64 → días enteros desde 1970-01-01 (NaT = sentinela máxima, «sin extremo»)."""
    arr = np.asarray(values, dtype="datetime64[ns]")
    days = arr.astype("datetime64[D]").astype(np.int64)
    return np.where(np.isnat(arr), _I64_MAX, days)


def _day(value: Any) -> int:
    return int((np.datetime64(to_day(value), "D") - _EPOCH).astype(np.int64))


# ───────────────────────────── celdas con ventana de visibilidad ─────────────────────────────

class VersionedCells:
    """Celdas (claves + ventana de visibilidad en días) con sus sumas: cada fila agrega las líneas que comparten celda y ventana.

    Columnas: `KEYS` (celda), `FLAGS` (booleanas que forman parte de la clave), `ds` (fecha del evento), `c_from` / `c_to` (primer y
    último corte en que la versión es visible; `c_to` NaT = vigente), `SUMS` (se suman) y `NANSUMS` (se suman y quedan nulas si todas lo
    son: costos). Se descartan las versiones de ventana vacía (cargas `backfill` que colapsan versiones), las conocidas después de `knowledge`
    o con fecha de evento posterior al corte final, y una versión reemplazada DESPUÉS de `knowledge` se trata como vigente."""

    KEYS: tuple[str, ...] = ()
    FLAGS: tuple[str, ...] = ()
    SUMS: tuple[str, ...] = ("lines",)
    NANSUMS: tuple[str, ...] = ()

    def __init__(self, cells: pd.DataFrame, tz: str | None, final: Any, knowledge: Any = None) -> None:
        self.tz = resolve_timezone(tz)
        self.final = to_day(final)
        # Hasta qué día llega lo CONOCIDO: el propio corte final (reconstrucción histórica) o uno posterior (ejecución vigente: hoy).
        self.knowledge = self.final if knowledge is None else max(self.final, to_day(knowledge))
        df = cells.copy()
        for col in ("ds", "c_from", "c_to"):
            df[col] = pd.to_datetime(df[col])
        final64, know64 = np.datetime64(self.final, "ns"), np.datetime64(self.knowledge, "ns")
        df["c_to"] = df["c_to"].mask(df["c_to"].isna() | (df["c_to"] >= know64))   # vigente (o reemplazada después de lo conocido)
        keep = (df["ds"] <= final64) & (df["c_from"] <= know64) & (df["c_to"].isna() | (df["c_to"] >= df["c_from"]))
        df = df.loc[keep]
        group = [*self.KEYS, *self.FLAGS, "ds", "c_from", "c_to"]
        if df.empty:
            self.cells = df.reset_index(drop=True)
        else:
            gb = df.groupby(group, sort=True, dropna=False)
            out = gb[list(self.SUMS)].sum()
            if self.NANSUMS:
                out[list(self.NANSUMS)] = gb[list(self.NANSUMS)].sum(min_count=1)
            self.cells = out.reset_index()
        self._ds = _days(self.cells["ds"].to_numpy("datetime64[ns]"))
        self._cf = _days(self.cells["c_from"].to_numpy("datetime64[ns]"))
        self._ct = _days(self.cells["c_to"].to_numpy("datetime64[ns]"))
        self._lines = self.cells["lines"].to_numpy(np.int64)
        self._k = _day(self.knowledge)
        self._truth: np.ndarray | None = None
        self._dynamic: np.ndarray | None = None
        self._dyn_idx: np.ndarray | None = None
        self._digest: str | None = None

    def __len__(self) -> int:
        """Número de líneas (versiones) que resume, no de filas."""
        return int(self._lines.sum())

    @property
    def n_cells(self) -> int:
        return len(self.cells)

    # ── visibilidad ──
    def visible(self, cutoff: Any, knowledge: Any = None) -> np.ndarray:
        """Máscara de las filas con fecha de evento ≤ `cutoff` que eran visibles al terminar el día `knowledge`. Por defecto el propio
        corte (la vista as-of: lo que se conocía ese día); un día posterior da lo conocido hasta entonces (ejecución vigente)."""
        d = _day(cutoff)
        k = d if knowledge is None else max(d, _day(knowledge))
        if k > self._k:
            raise ValueError("El corte o el conocimiento pedidos exceden los de la lectura de versiones.")
        mask = (self._cf <= k) & (k <= self._ct)
        return mask if k == d else mask & (self._ds <= d)

    @property
    def truth(self) -> np.ndarray:
        """Filas conocidas al terminar `knowledge` con fecha de evento ≤ corte final: la «verdad disponible» con que se etiquetan y evalúan
        los cortes anteriores (en una ejecución vigente incluye lo recibido hasta hoy)."""
        if self._truth is None:
            self._truth = self.visible(self.final, self.knowledge)
        return self._truth

    @property
    def late(self) -> np.ndarray:
        """Versiones que NO se conocían al terminar su propio día de negocio (llegaron después)."""
        return self._cf > self._ds

    @property
    def dynamic(self) -> np.ndarray:
        """Filas cuya visibilidad depende del corte: tardías o reemplazadas. El resto (la gran mayoría en una carga histórica) es
        visible en todo corte ≥ su fecha y coincide con la verdad."""
        if self._dynamic is None:
            self._dynamic = self.late | ~self.truth
            self._dyn_idx = np.flatnonzero(self._dynamic)
        return self._dynamic

    @property
    def has_dynamic(self) -> bool:
        return bool(self.dynamic.any())

    def difference(self, cutoff: Any) -> dict[str, int]:
        """Cuánto difiere la vista as-of del corte de la verdad restringida a ese corte, en líneas: `late` = vigentes en la verdad pero
        aún no conocidas al corte; `superseded` = visibles al corte pero ya reemplazadas o anuladas en la verdad."""
        if not self.has_dynamic:
            return {"late": 0, "superseded": 0}
        d = _day(cutoff)
        idx = self._dyn_idx
        assert idx is not None
        vis = (self._cf[idx] <= d) & (d <= self._ct[idx])
        truth_c = self.truth[idx] & (self._ds[idx] <= d)
        lines = self._lines[idx]
        return {"late": int(lines[truth_c & ~vis].sum()), "superseded": int(lines[vis & ~truth_c].sum())}

    def differs_from_truth(self, cutoff: Any) -> bool:
        d = self.difference(cutoff)
        return bool(d["late"] or d["superseded"])

    # ── huella y resumen ──
    def digest(self) -> str:
        """Huella del contenido y de las ventanas de visibilidad de las versiones que pueden ser visibles en algún corte ≤ final."""
        if self._digest is None:
            payload = b"empty" if self.cells.empty else pd.util.hash_pandas_object(self.cells, index=False).to_numpy().tobytes()
            self._digest = hashlib.sha256(f"{self.tz}|".encode() + payload).hexdigest()
        return self._digest

    def summary(self) -> dict[str, Any]:
        truth = self.truth
        lines = self._lines
        out: dict[str, Any] = {
            "timezone": self.tz, "final_cutoff": self.final.date().isoformat(), "knowledge_date": self.knowledge.date().isoformat(),
            "lines": int(lines.sum()),
            "window_cells": int(len(self.cells)), "dynamic_lines": int(lines[self.dynamic].sum()),
            "late_lines": int(lines[truth & self.late].sum()), "superseded_lines": int(lines[~truth].sum()), "digest": self.digest(),
        }
        if "incremental" in self.cells.columns and len(self.cells):
            inc = self.cells["incremental"].to_numpy(bool)
            modes = (("backfill", lines[truth & ~inc].sum()), ("incremental", lines[truth & inc].sum()))
            out["ingestion_modes"] = {k: int(v) for k, v in modes if v}
        return out

    def at_final(self, final: Any, knowledge: Any = None):
        """Las mismas versiones con otro corte final y otro límite de conocimiento menores o iguales (su «verdad» es lo conocido a ese
        límite; por defecto el propio corte: una reconstrucción histórica). Una lectura hecha hasta un corte mayor sirve para uno menor
        sin volver a leer la base."""
        day = to_day(final)
        k = day if knowledge is None else max(day, to_day(knowledge))
        if day == self.final and k == self.knowledge:
            return self
        if day > self.final or k > self.knowledge:
            raise ValueError("El corte final o el conocimiento pedidos exceden los de la lectura de versiones.")
        cache = self.__dict__.setdefault("_at_final", {})
        if (day, k) not in cache:
            cache[(day, k)] = type(self)(self.cells, self.tz, day, k)
        return cache[(day, k)]

    def restrict(self, keep: np.ndarray):
        """Nueva instancia solo con las filas `keep` (p. ej. al descartar ventas anteriores a la cobertura completa)."""
        return type(self)(self.cells.loc[np.asarray(keep, dtype=bool)], self.tz, self.final, self.knowledge)

    # ── de líneas con instantes a ventanas en días ──
    @staticmethod
    def window(ds: Any, known_from: Any, known_until: Any, tz: str | None) -> tuple[np.ndarray, np.ndarray]:
        """(`c_from`, `c_to`) de versiones con `known_from` / `known_until` (instantes UTC sin zona; NaT = vigente)."""
        ds64 = np.asarray(pd.to_datetime(ds), dtype="datetime64[ns]")
        c_from = np.maximum(ds64, local_day(known_from, tz))
        until = local_day(known_until, tz)
        c_to = np.where(np.isnat(until), until, until - np.timedelta64(1, "D"))
        return c_from, c_to


def with_knowledge(df: pd.DataFrame, versions: VersionedCells | None) -> pd.DataFrame:
    """Marca `df` con la huella de las ventanas de conocimiento de las que se derivó (la usa `platform.snapshot`)."""
    if versions is not None:
        df.attrs[KNOWLEDGE_ATTR] = versions.digest()
    return df


def _empty_cells(cls: type, object_cols: tuple[str, ...] = ()) -> pd.DataFrame:
    cols = [*cls.KEYS, *cls.FLAGS, "ds", "c_from", "c_to", *cls.SUMS, *cls.NANSUMS]

    def dtype(c: str) -> str:
        return "datetime64[ns]" if c in ("ds", "c_from", "c_to") else ("object" if c in object_cols else "float64")

    return pd.DataFrame({c: pd.Series(dtype=dtype(c)) for c in cols})


# ───────────────────────────── Demanda ─────────────────────────────

class SalesVersions(VersionedCells):
    """Versiones de líneas de venta de UNA empresa (solo documentos `completed`, el único estado que cuenta).

    Claves: sucursal × producto. Banderas: `is_sale` (documento de venta y no devolución), `is_ret` (línea marcada como devolución) e
    `incremental` (modo de ingestión). Una línea que no es `is_sale` es una devolución (línea marcada o nota de crédito)."""

    KEYS = ("branch_id", "product_id")
    FLAGS = ("is_sale", "is_ret", "incremental")
    SUMS = ("units", "net_amount", "gross_amount", "discount_amount", "lines", "lines_cost")
    NANSUMS = ("cost_amount", "cost_units")

    @classmethod
    def from_lines(cls, lines: pd.DataFrame, tz: str | None, final: Any, knowledge: Any = None) -> "SalesVersions":
        """Desde líneas con `known_from` / `known_until` (instantes UTC sin zona): sale_id, line_number, branch_id, product_id, ds,
        quantity, unit_price, discount_amount, net_amount, unit_cost, is_return, document_type, [ingestion_mode]."""
        d = lines
        if d.empty:
            return cls.empty(tz, final, knowledge)
        q = pd.to_numeric(d["quantity"], errors="coerce").astype(float).to_numpy()
        cost = pd.to_numeric(d["unit_cost"], errors="coerce").astype(float).to_numpy()
        has_cost = ~np.isnan(cost)
        is_ret = d["is_return"].astype(bool).to_numpy()
        c_from, c_to = cls.window(d["ds"], d["known_from"], d["known_until"], tz)
        mode = d["ingestion_mode"].to_numpy() if "ingestion_mode" in d else np.full(len(d), "backfill")
        cells = pd.DataFrame({
            "branch_id": d["branch_id"].to_numpy(np.int64), "product_id": d["product_id"].to_numpy(np.int64),
            "is_sale": (~is_ret) & d["document_type"].isin(VALID_SALE_DOCS).to_numpy(), "is_ret": is_ret,
            "incremental": mode == "incremental", "ds": pd.to_datetime(d["ds"]).to_numpy("datetime64[ns]"),
            "c_from": c_from, "c_to": c_to, "units": q,
            "net_amount": pd.to_numeric(d["net_amount"], errors="coerce").astype(float).to_numpy(),
            "gross_amount": q * pd.to_numeric(d["unit_price"], errors="coerce").astype(float).to_numpy(),
            "discount_amount": pd.to_numeric(d["discount_amount"], errors="coerce").astype(float).to_numpy(),
            "cost_amount": np.where(has_cost, q * cost, np.nan), "cost_units": np.where(has_cost, q, np.nan),
            "lines": np.ones(len(d), dtype=np.int64), "lines_cost": has_cost.astype(np.int64),
        })
        return cls(cells, tz, final, knowledge)

    @classmethod
    def empty(cls, tz: str | None, final: Any, knowledge: Any = None) -> "SalesVersions":
        return cls(_empty_cells(cls), tz, final, knowledge)

    def _rows(self, mask: np.ndarray) -> pd.DataFrame:
        return self.cells.loc[mask] if len(self.cells) else self.cells

    def sales_cells(self, cutoff: Any, knowledge: Any = None) -> pd.DataFrame:
        """Ventas válidas diarias por sucursal-producto con fecha ≤ `cutoff` tal como se conocían al terminar `knowledge` (por defecto
        el propio corte; excluye anuladas y devoluciones)."""
        v = self._rows(self.visible(cutoff, knowledge) & self.cells["is_sale"].to_numpy(bool))
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in SALES_CELL_COLUMNS})
        gb = v.groupby(["branch_id", "product_id", "ds"], sort=True)
        out = gb[["units", "net_amount", "gross_amount", "discount_amount", "lines"]].sum()
        out[["cost_amount", "cost_units"]] = gb[["cost_amount", "cost_units"]].sum(min_count=1)
        out = out.reset_index()
        out["lines"] = out["lines"].astype(np.int64)
        return out[SALES_CELL_COLUMNS]

    def return_cells(self, cutoff: Any, knowledge: Any = None) -> pd.DataFrame:
        """Devoluciones diarias por sucursal-producto (líneas marcadas o notas de crédito) conocidas al corte (o a `knowledge`)."""
        v = self._rows(self.visible(cutoff, knowledge) & ~self.cells["is_sale"].to_numpy(bool))
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in RETURN_CELL_COLUMNS})
        return v.groupby(["branch_id", "product_id", "ds"], sort=True, as_index=False)["units"].sum()

    def stats(self, cutoff: Any, knowledge: Any = None) -> dict[str, Any]:
        """Líneas válidas conocidas al corte (o a `knowledge`), cuántas traen costo y rango de fechas (controles de calidad)."""
        v = self._rows(self.visible(cutoff, knowledge) & ~self.cells["is_ret"].to_numpy(bool))
        if v.empty:
            return {"lines": 0, "lines_with_cost": 0, "date_from": None, "date_to": None}
        return {"lines": int(v["lines"].sum()), "lines_with_cost": int(v["lines_cost"].sum()),
                "date_from": v["ds"].min().date(), "date_to": v["ds"].max().date()}

    def last_sale_date(self, cutoff: Any, knowledge: Any = None) -> date | None:
        v = self._rows(self.visible(cutoff, knowledge) & self.cells["is_sale"].to_numpy(bool))
        return None if v.empty else v["ds"].max().date()


# ───────────────────────────── Customer Core ─────────────────────────────

class PurchaseVersions(VersionedCells):
    """Versiones de líneas de compra (`is_ret = false`) y de devolución de compradores identificados.

    Claves: comprador × producto × sucursal × moneda. Banderas: `is_ret` (devolución) e `incremental`."""

    KEYS = ("customer_id", "product_id", "branch_id", "currency")
    FLAGS = ("is_ret", "incremental")
    SUMS = ("qty", "net_amount", "gross_amount", "discount_amount", "lines")

    @classmethod
    def from_lines(cls, lines: pd.DataFrame, tz: str | None, final: Any, knowledge: Any = None) -> "PurchaseVersions":
        """Desde líneas con `known_from` / `known_until`: sale_id, line_number, is_ret, customer_id, product_id, branch_id, ds,
        currency, qty, net_amount, gross_amount, discount_amount, [ingestion_mode]."""
        d = lines
        if d.empty:
            return cls.empty(tz, final, knowledge)
        c_from, c_to = cls.window(d["ds"], d["known_from"], d["known_until"], tz)
        mode = d["ingestion_mode"].to_numpy() if "ingestion_mode" in d else np.full(len(d), "backfill")
        cells = pd.DataFrame({
            "customer_id": d["customer_id"].to_numpy(np.int64), "product_id": d["product_id"].to_numpy(np.int64),
            "branch_id": d["branch_id"].to_numpy(np.int64), "currency": d["currency"].astype(object).to_numpy(),
            "is_ret": d["is_ret"].astype(bool).to_numpy(), "incremental": mode == "incremental",
            "ds": pd.to_datetime(d["ds"]).to_numpy("datetime64[ns]"), "c_from": c_from, "c_to": c_to,
            "qty": pd.to_numeric(d["qty"], errors="coerce").astype(float).to_numpy(),
            "net_amount": pd.to_numeric(d["net_amount"], errors="coerce").astype(float).to_numpy(),
            "gross_amount": pd.to_numeric(d["gross_amount"], errors="coerce").astype(float).to_numpy(),
            "discount_amount": pd.to_numeric(d["discount_amount"], errors="coerce").astype(float).to_numpy(),
            "lines": np.ones(len(d), dtype=np.int64),
        })
        return cls(cells, tz, final, knowledge)

    @classmethod
    def empty(cls, tz: str | None, final: Any, knowledge: Any = None) -> "PurchaseVersions":
        return cls(_empty_cells(cls, ("currency",)), tz, final, knowledge)

    def _rows(self, mask: np.ndarray) -> pd.DataFrame:
        return self.cells.loc[mask] if len(self.cells) else self.cells

    def purchase_cells(self, cutoff: Any, knowledge: Any = None) -> pd.DataFrame:
        """Compras válidas por comprador × producto × sucursal × día × moneda con fecha ≤ `cutoff` tal como se conocían al terminar
        `knowledge` (por defecto el propio corte)."""
        v = self._rows(self.visible(cutoff, knowledge) & ~self.cells["is_ret"].to_numpy(bool))
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else ("object" if c == "currency" else "float64"))
                                 for c in PURCHASE_CELL_COLUMNS})
        return self._purchases(v)

    @staticmethod
    def _purchases(v: pd.DataFrame) -> pd.DataFrame:
        out = v.groupby(["customer_id", "product_id", "branch_id", "ds", "currency"], sort=True, as_index=False)[
            ["qty", "net_amount", "gross_amount", "discount_amount"]].sum()
        # Orden estable por comprador, producto y día: el desempate entre sucursales del mismo día es por sucursal.
        return out.sort_values(["customer_id", "product_id", "ds", "branch_id", "currency"], kind="mergesort") \
            .reset_index(drop=True)[PURCHASE_CELL_COLUMNS]

    def all_purchase_cells(self) -> pd.DataFrame:
        """Compras de TODAS las versiones, visibles o no en algún corte: define el universo de relaciones, compradores y
        productos común a la vista de cada corte y a la verdad (no es una vista as-of)."""
        v = self._rows(~self.cells["is_ret"].to_numpy(bool)) if len(self.cells) else self.cells
        return self.purchase_cells(self.final, self.knowledge) if v.empty else self._purchases(v)

    def return_cells(self, cutoff: Any, knowledge: Any = None) -> pd.DataFrame:
        v = self._rows(self.visible(cutoff, knowledge) & self.cells["is_ret"].to_numpy(bool))
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in CUSTOMER_RETURN_COLUMNS})
        return v.groupby(["customer_id", "product_id", "ds"], sort=True, as_index=False)["qty"].sum()
