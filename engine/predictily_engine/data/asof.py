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

El motor lee las versiones UNA vez hasta el corte final (vista `sales_line_versions`, contrato de la migración
`2026_03_01_000100`) y deriva cada vista as-of en pandas con filtros vectorizados:

- `SalesVersions`: Demanda. Celdas diarias sucursal × producto, devoluciones, estadísticas y última venta de cada corte.
- `PurchaseVersions`: Customer Core. Compras y devoluciones diarias comprador × producto × sucursal.

Todas las marcas de tiempo se manejan en UTC sin zona (`datetime64[ns]`); la medianoche local de cada día se calcula una
sola vez con `local_midnight_after`. Las filas se ordenan de forma determinista (documento, línea, conocimiento) para que las
sumas en coma flotante y las huellas no dependan del orden en que la base devuelve las filas.
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


def _i8(values: np.ndarray) -> np.ndarray:
    return np.asarray(values, dtype="datetime64[ns]").view("int64")


# ───────────────────────────── versiones de líneas ─────────────────────────────

class VersionedLines:
    """Líneas con ventana de conocimiento `[known_from, known_until)` y su regla de visibilidad por corte.

    `lines` debe traer `ds` (fecha del evento), `known_from` y `known_until` (UTC sin zona; NaT = vigente). Se descartan
    las versiones cuya ventana es vacía (cargas `backfill` que colapsan versiones), las conocidas después del corte final
    y las posteriores a él. Una versión reemplazada DESPUÉS del corte final se trata como vigente: nada ocurrido tras el
    corte final puede cambiar lo que se ve en los cortes anteriores (reproducibilidad)."""

    SORT_KEYS: tuple[str, ...] = ("sale_id", "line_number", "known_from")
    CONTENT_COLUMNS: tuple[str, ...] = ()

    def __init__(self, lines: pd.DataFrame, tz: str | None, final: Any, _copy: bool = True) -> None:
        self.tz = resolve_timezone(tz)
        self.final = to_day(final)
        self.boundary_final = knowledge_boundary(self.final, self.tz)
        df = lines.copy() if _copy else lines
        for col in ("ds", "known_from", "known_until"):
            df[col] = pd.to_datetime(df[col])
        ds = df["ds"].to_numpy("datetime64[ns]")
        kf = df["known_from"].to_numpy("datetime64[ns]")
        ku = df["known_until"].to_numpy("datetime64[ns]")
        open_end = np.isnat(ku) | (ku >= self.boundary_final)
        df["known_until"] = df["known_until"].mask(open_end)
        ku = df["known_until"].to_numpy("datetime64[ns]")
        empty_window = ~np.isnat(ku) & (ku <= kf)
        never_visible = np.isnat(kf) | (kf >= self.boundary_final) | (ds > np.datetime64(self.final, "ns"))
        df = df.loc[~(empty_window | never_visible)]
        keys = [k for k in self.SORT_KEYS if k in df.columns]
        self.lines = df.sort_values(keys, kind="mergesort").reset_index(drop=True)
        self._ds = _i8(self.lines["ds"].to_numpy("datetime64[ns]"))
        self._kf = _i8(self.lines["known_from"].to_numpy("datetime64[ns]"))
        ku = self.lines["known_until"].to_numpy("datetime64[ns]")
        self._ku = np.where(np.isnat(ku), _I64_MAX, _i8(ku))
        self._truth: np.ndarray | None = None
        self._late: np.ndarray | None = None
        self._dynamic: np.ndarray | None = None
        self._dyn_idx: np.ndarray | None = None
        self._digest: str | None = None

    def __len__(self) -> int:
        return len(self.lines)

    # ── visibilidad ──
    def visible(self, cutoff: Any) -> np.ndarray:
        """Máscara de las versiones visibles al corte `cutoff` (con fecha de evento ≤ corte)."""
        day = to_day(cutoff)
        b = int(_i8(np.array([knowledge_boundary(day, self.tz)]))[0])
        c = int(_i8(np.array([np.datetime64(day, "ns")]))[0])
        return (self._ds <= c) & (self._kf < b) & (self._ku >= b)

    @property
    def truth(self) -> np.ndarray:
        """Versiones visibles al corte final: la «verdad disponible» con que se etiquetan y evalúan los cortes anteriores."""
        if self._truth is None:
            self._truth = self.visible(self.final)
        return self._truth

    @property
    def late(self) -> np.ndarray:
        """Versiones que NO se conocían al terminar su propio día de negocio (llegaron o cambiaron después)."""
        if self._late is None:
            uniq, inv = np.unique(self._ds, return_inverse=True)
            bounds = _i8(local_midnight_after(uniq.view("datetime64[ns]"), self.tz))
            self._late = self._kf >= bounds[inv]
        return self._late

    @property
    def dynamic(self) -> np.ndarray:
        """Versiones cuya visibilidad depende del corte: no son «vigentes y conocidas desde su día». El resto (la gran
        mayoría en una carga histórica) es visible en todo corte ≥ su fecha y coincide con la verdad."""
        if self._dynamic is None:
            self._dynamic = ~(self.truth & ~self.late)
            self._dyn_idx = np.flatnonzero(self._dynamic)
        return self._dynamic

    @property
    def has_dynamic(self) -> bool:
        return bool(self.dynamic.any())

    def difference(self, cutoff: Any) -> dict[str, int]:
        """Cuánto difiere la vista as-of del corte de la verdad restringida a ese corte (solo líneas dinámicas):
        `late` = vigentes en la verdad pero aún no conocidas al corte; `superseded` = visibles al corte pero ya
        reemplazadas o anuladas en la verdad."""
        if not self.has_dynamic:
            return {"late": 0, "superseded": 0}
        day = to_day(cutoff)
        idx = self._dyn_idx
        assert idx is not None
        b = int(_i8(np.array([knowledge_boundary(day, self.tz)]))[0])
        c = int(_i8(np.array([np.datetime64(day, "ns")]))[0])
        vis = (self._ds[idx] <= c) & (self._kf[idx] < b) & (self._ku[idx] >= b)
        truth_c = self.truth[idx] & (self._ds[idx] <= c)
        return {"late": int((truth_c & ~vis).sum()), "superseded": int((vis & ~truth_c).sum())}

    def differs_from_truth(self, cutoff: Any) -> bool:
        d = self.difference(cutoff)
        return bool(d["late"] or d["superseded"])

    # ── huella y resumen ──
    def digest(self) -> str:
        """Huella de las versiones (contenido y ventanas de conocimiento) que pueden ser visibles en algún corte ≤ final."""
        if self._digest is None:
            cols = [c for c in (*self.CONTENT_COLUMNS, "ds", "known_from", "known_until") if c in self.lines.columns]
            if self.lines.empty:
                payload = b"empty"
            else:
                payload = pd.util.hash_pandas_object(self.lines[cols], index=False).to_numpy().tobytes()
            self._digest = hashlib.sha256(f"{self.tz}|".encode() + payload).hexdigest()
        return self._digest

    def summary(self) -> dict[str, Any]:
        truth = self.truth
        out: dict[str, Any] = {
            "timezone": self.tz, "final_cutoff": self.final.date().isoformat(), "lines": int(len(self.lines)),
            "dynamic_lines": int(self.dynamic.sum()), "late_lines": int((truth & self.late).sum()),
            "superseded_lines": int((~truth).sum()), "digest": self.digest(),
        }
        if "ingestion_mode" in self.lines.columns and len(self.lines):
            modes = self.lines.loc[truth, "ingestion_mode"].value_counts()
            out["ingestion_modes"] = {str(k): int(v) for k, v in modes.items()}
        return out

    def restrict(self, keep: np.ndarray):
        """Nueva instancia solo con las líneas `keep` (p. ej. al descartar ventas anteriores a la cobertura completa)."""
        return type(self)(self.lines.loc[np.asarray(keep, dtype=bool)], self.tz, self.final)


def with_knowledge(df: pd.DataFrame, versions: VersionedLines | None) -> pd.DataFrame:
    """Marca `df` con la huella de las ventanas de conocimiento de las que se derivó (la usa `platform.snapshot`)."""
    if versions is not None:
        df.attrs[KNOWLEDGE_ATTR] = versions.digest()
    return df


# ───────────────────────────── Demanda ─────────────────────────────

class SalesVersions(VersionedLines):
    """Versiones de líneas de venta de UNA empresa (solo documentos `completed`, el único estado que cuenta)."""

    SORT_KEYS = ("sale_id", "line_number", "known_from")
    CONTENT_COLUMNS = ("sale_id", "line_number", "branch_id", "product_id", "quantity", "unit_price", "discount_amount",
                       "net_amount", "unit_cost", "is_return", "document_type")

    def __init__(self, lines: pd.DataFrame, tz: str | None, final: Any) -> None:
        df = lines.copy()
        for col in ("quantity", "unit_price", "discount_amount", "net_amount", "unit_cost"):
            df[col] = pd.to_numeric(df[col], errors="coerce").astype(float)
        df["is_return"] = df["is_return"].astype(bool)
        super().__init__(df, tz, final, _copy=False)
        self._valid_line: np.ndarray | None = None
        self._return_line: np.ndarray | None = None

    @staticmethod
    def empty(tz: str | None, final: Any) -> "SalesVersions":
        cols = ["sale_id", "line_number", "branch_id", "product_id", "ds", "quantity", "unit_price", "discount_amount",
                "net_amount", "unit_cost", "is_return", "document_type", "known_from", "known_until"]
        return SalesVersions(pd.DataFrame({c: pd.Series(dtype="object") for c in cols}), tz, final)

    @property
    def valid_line(self) -> np.ndarray:
        """Líneas de venta válidas por su tipo: documento de venta y no devolución."""
        if self._valid_line is None:
            self._valid_line = (~self.lines["is_return"].to_numpy()) & self.lines["document_type"].isin(VALID_SALE_DOCS).to_numpy()
        return self._valid_line

    @property
    def return_line(self) -> np.ndarray:
        """Devoluciones: líneas marcadas como tales o pertenecientes a notas de crédito."""
        if self._return_line is None:
            self._return_line = self.lines["is_return"].to_numpy() | (self.lines["document_type"] == "credit_note").to_numpy()
        return self._return_line

    def _valid_sales(self, mask: np.ndarray) -> pd.DataFrame:
        return self.lines.loc[mask & self.valid_line]

    def sales_cells(self, cutoff: Any) -> pd.DataFrame:
        """Ventas válidas diarias por sucursal-producto tal como se conocían al corte (excluye anuladas y devoluciones)."""
        v = self._valid_sales(self.visible(cutoff))
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in SALES_CELL_COLUMNS})
        q = v["quantity"].to_numpy(float)
        cost = v["unit_cost"].to_numpy(float)
        has_cost = ~np.isnan(cost)
        g = pd.DataFrame({
            "branch_id": v["branch_id"].to_numpy(np.int64), "product_id": v["product_id"].to_numpy(np.int64),
            "ds": v["ds"].to_numpy("datetime64[ns]"), "units": q, "net_amount": v["net_amount"].to_numpy(float),
            "gross_amount": q * v["unit_price"].to_numpy(float), "discount_amount": v["discount_amount"].to_numpy(float),
            "cost_amount": np.where(has_cost, q * cost, np.nan), "cost_units": np.where(has_cost, q, np.nan),
        })
        gb = g.groupby(["branch_id", "product_id", "ds"], sort=True)
        out = gb[["units", "net_amount", "gross_amount", "discount_amount"]].sum()
        out[["cost_amount", "cost_units"]] = gb[["cost_amount", "cost_units"]].sum(min_count=1)
        out["lines"] = gb.size()
        return out.reset_index()[SALES_CELL_COLUMNS]

    def return_cells(self, cutoff: Any) -> pd.DataFrame:
        """Devoluciones diarias por sucursal-producto (líneas marcadas o notas de crédito) conocidas al corte."""
        v = self.lines.loc[self.visible(cutoff) & self.return_line]
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in RETURN_CELL_COLUMNS})
        g = pd.DataFrame({"branch_id": v["branch_id"].to_numpy(np.int64), "product_id": v["product_id"].to_numpy(np.int64),
                          "ds": v["ds"].to_numpy("datetime64[ns]"), "units": v["quantity"].to_numpy(float)})
        return g.groupby(["branch_id", "product_id", "ds"], sort=True, as_index=False)["units"].sum()

    def stats(self, cutoff: Any) -> dict[str, Any]:
        """Líneas válidas conocidas al corte, cuántas traen costo y rango de fechas (controles de calidad)."""
        mask = self.visible(cutoff) & ~self.lines["is_return"].to_numpy()
        v = self.lines.loc[mask]
        if v.empty:
            return {"lines": 0, "lines_with_cost": 0, "date_from": None, "date_to": None}
        return {"lines": int(len(v)), "lines_with_cost": int(v["unit_cost"].notna().sum()),
                "date_from": v["ds"].min().date(), "date_to": v["ds"].max().date()}

    def last_sale_date(self, cutoff: Any) -> date | None:
        v = self._valid_sales(self.visible(cutoff))
        return None if v.empty else v["ds"].max().date()


# ───────────────────────────── Customer Core ─────────────────────────────

class PurchaseVersions(VersionedLines):
    """Versiones de líneas de compra (`is_return = false`) y de devolución de compradores identificados.

    Columnas: `sale_id`, `line_number`, `is_ret` (devolución), `customer_id`, `product_id`, `branch_id`, `ds`, `currency`,
    `qty`, `net_amount`, `gross_amount`, `discount_amount`, `known_from`, `known_until`."""

    SORT_KEYS = ("sale_id", "line_number", "known_from")
    CONTENT_COLUMNS = ("sale_id", "line_number", "is_ret", "customer_id", "product_id", "branch_id", "currency", "qty",
                       "net_amount", "gross_amount", "discount_amount")

    def __init__(self, lines: pd.DataFrame, tz: str | None, final: Any) -> None:
        df = lines.copy()
        for col in ("qty", "net_amount", "gross_amount", "discount_amount"):
            df[col] = pd.to_numeric(df[col], errors="coerce").astype(float)
        df["is_ret"] = df["is_ret"].astype(bool)
        df["currency"] = df["currency"].astype(object)
        super().__init__(df, tz, final, _copy=False)

    @staticmethod
    def empty(tz: str | None, final: Any) -> "PurchaseVersions":
        cols = ["sale_id", "line_number", "is_ret", "customer_id", "product_id", "branch_id", "ds", "currency", "qty",
                "net_amount", "gross_amount", "discount_amount", "known_from", "known_until"]
        return PurchaseVersions(pd.DataFrame({c: pd.Series(dtype="object") for c in cols}), tz, final)

    def purchase_cells(self, cutoff: Any) -> pd.DataFrame:
        """Compras válidas por comprador × producto × sucursal × día × moneda tal como se conocían al corte."""
        v = self.lines.loc[self.visible(cutoff) & ~self.lines["is_ret"].to_numpy()]
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else ("object" if c == "currency" else "float64"))
                                 for c in PURCHASE_CELL_COLUMNS})
        g = v[["customer_id", "product_id", "branch_id", "ds", "currency", "qty", "net_amount", "gross_amount",
               "discount_amount"]]
        out = g.groupby(["customer_id", "product_id", "branch_id", "ds", "currency"], sort=True, as_index=False)[
            ["qty", "net_amount", "gross_amount", "discount_amount"]].sum()
        # Orden estable por comprador, producto y día: el desempate entre sucursales del mismo día es por sucursal.
        return out.sort_values(["customer_id", "product_id", "ds", "branch_id", "currency"], kind="mergesort") \
            .reset_index(drop=True)[PURCHASE_CELL_COLUMNS]

    def return_cells(self, cutoff: Any) -> pd.DataFrame:
        v = self.lines.loc[self.visible(cutoff) & self.lines["is_ret"].to_numpy()]
        if v.empty:
            return pd.DataFrame({c: pd.Series(dtype="datetime64[ns]" if c == "ds" else "float64") for c in CUSTOMER_RETURN_COLUMNS})
        return v.groupby(["customer_id", "product_id", "ds"], sort=True, as_index=False)["qty"].sum()
