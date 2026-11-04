"""Lotes con vencimiento y salida FEFO (V2.5 · W5-D, §10; docs/supply/SUPPLY_CORE.md).

Funciones PURAS (sin base de datos) sobre los lotes de UNA serie sucursal × producto. Reglas:

- **As-of.** Los lotes de un corte son la ÚLTIMA foto (`snapshot_date`) anterior o igual al corte; una foto posterior no existe para ese
  corte (`lots_asof`). Una foto de lotes más antigua que el snapshot de existencias por más de `max_age_days` se ignora por obsoleta: sus
  cantidades ya no describen el stock y no se inventa un vencimiento para lo demás.
- **La existencia manda.** La cantidad física es `inventory_snapshots.on_hand`; los lotes aportan el VENCIMIENTO. Si los lotes suman más que
  la existencia se descartan por defecto los de mayor vencimiento (`latest_first`, conservador: queda lo que vence antes y por tanto la merma
  proyectada es MAYOR); `earliest_first` descarta los que vencen antes (coherente con un consumo FEFO desde la foto: lo vendido desde entonces salió de los
  primeros, pero la merma proyectada es MENOR). Si suman menos, el resto es existencia SIN vencimiento declarado (no se inventa) y sale después de los lotes con fecha.
- **Vencidos ya dados de baja.** Por defecto (`in_on_hand`) las unidades vencidas viven en `on_hand` y se restan de lo vendible. Si el ERP ya las dio de baja
  (`written_off`) no se restan otra vez. Se detecta la sospecha: un excedente de lotes igual a lo vencido se informa en las notas.
- **FEFO** (primero vence, primero sale): el vencimiento es el ÚLTIMO día vendible (inclusive); el lote se pierde al cierre de ese día. Lo
  reservado sale primero de los lotes que vencen antes. Un lote vencido al corte (`expiry ≤ corte`) está en el estante pero no es vendible.
- **Mermas proyectadas** con la demanda ya persistida del pronóstico (media `yhat`, o los cuantiles diarios `lo_80`/`hi_80`): nunca se
  recalcula el pronóstico ni se extrapola más allá de los días cubiertos. Sumar cuantiles diarios NO es el cuantil de la suma: el
  escenario `lo_80` es una cota conservadora de demanda baja, no un P10 del acumulado (se declara en el informe).

Los días se cuentan desde el corte: el día 1 es el día siguiente a `as_of`.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

import numpy as np
import pandas as pd

EPS = 1e-9
LOT_COLUMNS = ["branch_id", "product_id", "lot_code", "snapshot_date", "expiry_date", "quantity"]
NEVER = 10 ** 9   # «día de vencimiento» de la existencia sin vencimiento declarado


def empty_lots() -> pd.DataFrame:
    return pd.DataFrame({"branch_id": pd.Series(dtype="int64"), "product_id": pd.Series(dtype="int64"), "lot_code": pd.Series(dtype=object),
                         "snapshot_date": pd.Series(dtype="datetime64[ns]"), "expiry_date": pd.Series(dtype="datetime64[ns]"),
                         "quantity": pd.Series(dtype="float64")})


def lots_asof(lots: pd.DataFrame | None, as_of: date) -> pd.DataFrame:
    """Última foto de lotes ≤ `as_of` por sucursal × producto (las fotos posteriores al corte no existen para ese corte)."""
    if lots is None or lots.empty:
        return empty_lots()
    df = lots.copy()
    df["snapshot_date"] = pd.to_datetime(df["snapshot_date"])
    df["expiry_date"] = pd.to_datetime(df["expiry_date"])
    df["quantity"] = pd.to_numeric(df["quantity"], errors="coerce").astype(float)
    df = df[df["snapshot_date"] <= pd.Timestamp(as_of)]
    if df.empty:
        return empty_lots()
    latest = df.groupby(["branch_id", "product_id"])["snapshot_date"].transform("max")
    return df[df["snapshot_date"] == latest].reset_index(drop=True)


@dataclass
class LotPosition:
    """Lotes de una serie conciliados con su existencia física al corte."""

    status: str                      # not_configured | stale_ignored | configured
    reason: str | None = None
    snapshot_date: date | None = None
    lots: list[tuple[int, float, str]] = field(default_factory=list)   # (día de vencimiento, cantidad vendible, código), orden FEFO
    undated_qty: float = 0.0         # existencia vendible SIN vencimiento declarado (sale después de los lotes con fecha)
    expired_qty: float = 0.0         # en el estante pero vencida al corte: no es vendible
    expired_deducted: float = 0.0    # parte de `expired_qty` que se resta de `on_hand` (0 si el ERP ya la dio de baja: modo `written_off`)
    expired_lots: list[tuple[str, float]] = field(default_factory=list)
    dropped_qty: float = 0.0         # lotes que excedían la existencia física (se descartan primero los que vencen después)
    held_back_applied: float = 0.0   # reservado que salió de los lotes (FEFO)
    lots_total: float = 0.0
    notes: list[str] = field(default_factory=list)

    @property
    def dated_qty(self) -> float:
        return float(sum(q for _, q, _ in self.lots))

    @property
    def sellable_qty(self) -> float:
        return self.dated_qty + self.undated_qty

    def to_dict(self) -> dict[str, Any]:
        return {"status": self.status, "reason": self.reason, "snapshot_date": self.snapshot_date.isoformat() if self.snapshot_date else None,
                "lots_total": round(self.lots_total, 4), "expired_qty": round(self.expired_qty, 4),
                "expired_deducted": round(self.expired_deducted, 4), "expired_lots": [{"lot": c, "qty": round(q, 4)} for c, q in self.expired_lots],
                "dropped_qty": round(self.dropped_qty, 4), "dated_sellable_qty": round(self.dated_qty, 4),
                "undated_sellable_qty": round(self.undated_qty, 4), "held_back_applied": round(self.held_back_applied, 4),
                "lots": [{"lot": c, "expiry_day": d, "qty": round(q, 4)} for d, q, c in self.lots], "notes": list(self.notes)}


EXCESS_DROP = ("latest_first", "earliest_first")
EXPIRED_MODES = ("in_on_hand", "written_off")


def reconcile_lots(series_lots: pd.DataFrame | None, on_hand: float, held_back: float, as_of: date,
                   inventory_date: date | None = None, max_age_days: int = 7, excess_drop: str = "latest_first",
                   expired_mode: str = "in_on_hand") -> LotPosition:
    """Concilia los lotes de la serie con su existencia. `held_back` = unidades reservadas que no están dentro de la demanda planificada
    (salen primero de los lotes que vencen antes). Sin lotes con vencimiento ⇒ `not_configured` (nada se inventa)."""
    if series_lots is None or series_lots.empty:
        return LotPosition("not_configured", "Sin lotes para la serie al corte.")
    df = series_lots[series_lots["quantity"] > EPS]
    if df.empty:
        return LotPosition("not_configured", "Los lotes de la serie están agotados (cantidad 0).",
                           snapshot_date=pd.Timestamp(series_lots["snapshot_date"].max()).date())
    snap = pd.Timestamp(df["snapshot_date"].max()).date()
    total = float(df["quantity"].sum())
    pos = LotPosition("configured", snapshot_date=snap, lots_total=total)
    if inventory_date is not None and (inventory_date - snap).days > int(max_age_days):
        pos.status = "stale_ignored"
        pos.reason = (f"La foto de lotes ({snap}) es {(inventory_date - snap).days} días anterior al snapshot de existencias ({inventory_date}), "
                      f"más de {int(max_age_days)} días: sus cantidades ya no describen el stock y no se usan.")
        return pos
    if df["expiry_date"].isna().all():
        pos.status = "not_configured"
        pos.reason = "Los lotes de la serie no declaran vencimiento: no hay fecha que proyectar."
        return pos
    on_hand = max(float(on_hand), 0.0)
    rows = []
    for r in df.itertuples(index=False):
        exp = None if pd.isna(r.expiry_date) else (pd.Timestamp(r.expiry_date).normalize() - pd.Timestamp(as_of).normalize()).days
        rows.append([NEVER if exp is None else int(exp), float(r.quantity), str(r.lot_code)])
    if excess_drop not in EXCESS_DROP or expired_mode not in EXPIRED_MODES:
        raise ValueError("Política de conciliación de lotes no válida.")
    expired_total = float(sum(q for d, q, _ in rows if d <= 0))
    written_off = expired_mode == "written_off"
    if written_off:
        # El ERP ya dio de baja los vencidos: no están en `on_hand`, no se restan ni cuentan para el excedente.
        rows = [r for r in rows if r[0] > 0]
        total = float(sum(r[1] for r in rows))
    # 1. Excedente sobre la existencia física: se descarta lo que vence después (por defecto) o antes primero.
    if total > on_hand + EPS:
        excess = total - on_hand
        first = "después" if excess_drop == "latest_first" else "antes"
        pos.notes.append(f"Los lotes suman {total:g} u. y la existencia es {on_hand:g}: se descartan {excess:g} u. de los que vencen {first} "
                         + ("(la existencia manda; queda lo que vence antes: merma proyectada conservadora)." if excess_drop == "latest_first"
                            else "(consumo FEFO desde la foto: lo vendido salió de los primeros; merma proyectada menor)."))
        if (not written_off and expired_total > EPS and abs(excess - expired_total) <= max(1e-6, 1e-3 * max(on_hand, 1.0))):
            pos.notes.append(f"El excedente de los lotes ({excess:g} u.) coincide con lo vencido ({expired_total:g} u.): es probable que el ERP ya haya dado de baja "
                             "los vencidos de `on_hand`; configure `perishability_expired_stock_mode = written_off` para no restarlos dos veces.")
        pos.dropped_qty = excess
        order = sorted(rows, key=lambda x: (-x[0], x[2])) if excess_drop == "latest_first" else sorted(rows, key=lambda x: (x[0], x[2]))
        for row in order:
            take = min(row[1], excess)
            row[1] -= take
            excess -= take
            if excess <= EPS:
                break
        rows = [r for r in rows if r[1] > EPS]
    # 2. Vencidos al corte: en el estante pero no vendibles.
    live = []
    for d, q, c in rows:
        if d <= 0:
            pos.expired_qty += q
            pos.expired_lots.append((c, q))
        else:
            live.append([d, q, c])
    pos.expired_deducted = pos.expired_qty
    if written_off and expired_total > EPS:
        pos.notes.append(f"{expired_total:g} u. vencidas al corte ya dadas de baja por el ERP (modo `written_off`): no se restan de la existencia.")
    if pos.expired_qty > EPS:
        pos.notes.append(f"{pos.expired_qty:g} u. ya vencidas al corte ({len(pos.expired_lots)} lote(s)): en el estante pero no vendibles; "
                         "se excluyen de la existencia utilizable.")
    # 3. Reservado fuera de la demanda planificada: sale FEFO de los lotes vigentes.
    remaining_hold = max(float(held_back), 0.0)
    for row in sorted(live, key=lambda x: (x[0], x[2])):
        take = min(row[1], remaining_hold)
        row[1] -= take
        remaining_hold -= take
        pos.held_back_applied += take
        if remaining_hold <= EPS:
            break
    live = [r for r in live if r[1] > EPS]
    dated = [(d, q, c) for d, q, c in live if d != NEVER]
    undated_lot_qty = float(sum(q for d, q, _ in live if d == NEVER))
    sellable_total = max(on_hand - pos.expired_deducted - max(float(held_back), 0.0), 0.0)
    dated_total = float(sum(q for _, q, _ in dated))
    pos.lots = sorted(dated, key=lambda x: (x[0], x[2]))
    # Lo que no está en un lote con fecha (lotes sin fecha + diferencia con la existencia) es existencia SIN vencimiento declarado.
    pos.undated_qty = max(sellable_total - dated_total, 0.0)
    if undated_lot_qty > EPS or pos.undated_qty > EPS:
        pos.notes.append(f"{pos.undated_qty:g} u. vendibles sin vencimiento declarado (sin lote con fecha): salen después de los lotes con fecha "
                         "y no se proyecta ninguna merma sobre ellas.")
    return pos


@dataclass
class FefoRun:
    sold: np.ndarray
    unmet: np.ndarray
    expired_by_day: np.ndarray
    end_stock: float
    lost_lots: list[tuple[int, float, str]]      # (día, cantidad perdida, código) en orden cronológico
    unresolved_qty: float                         # vendible con vencimiento más allá de los días simulados (no se extrapola)

    @property
    def expired_qty(self) -> float:
        return float(self.expired_by_day.sum())


def fefo_run(lots: list[tuple[int, float, str]], undated_qty: float, demand: np.ndarray,
             arrivals: list[tuple[int, float, int | None, str]] | None = None) -> FefoRun:
    """Simulación diaria FEFO. `lots`: (día de vencimiento, cantidad, código); `undated_qty` sale al final; `arrivals`: (día de llegada,
    cantidad, día de vencimiento o None, código). Cada día entran las llegadas, se vende FEFO hasta cubrir la demanda (lo no cubierto se pierde) y al
    cierre se pierde el remanente de los lotes cuyo último día vendible es ése."""
    n = len(demand)
    queue: list[list] = [[int(d), float(q), c] for d, q, c in lots if q > EPS]
    if undated_qty > EPS:
        queue.append([NEVER, float(undated_qty), "sin_vencimiento"])
    by_day: dict[int, list[tuple[float, int | None, str]]] = {}
    for day, q, exp, code in (arrivals or []):
        by_day.setdefault(int(day), []).append((float(q), exp, code))
    sold = np.zeros(n)
    unmet = np.zeros(n)
    expired = np.zeros(n)
    lost: list[tuple[int, float, str]] = []
    for d in range(1, n + 1):
        for q, exp, code in by_day.get(d, []):
            if q > EPS:
                queue.append([NEVER if exp is None else int(exp), q, code])
        queue.sort(key=lambda r: (r[0], r[2]))
        need = float(demand[d - 1])
        for row in queue:
            if need <= EPS:
                break
            if row[0] < d:
                continue
            take = min(row[1], need)
            row[1] -= take
            need -= take
            sold[d - 1] += take
        unmet[d - 1] = max(need, 0.0)
        keep = []
        for row in queue:
            if row[0] == d and row[1] > EPS:
                expired[d - 1] += row[1]
                lost.append((d, row[1], row[2]))
            elif row[0] <= d and row[0] != NEVER:
                continue   # vencido y vacío
            else:
                keep.append(row)
        queue = [r for r in keep if r[1] > EPS]
    end_stock = float(sum(r[1] for r in queue))
    unresolved = float(sum(r[1] for r in queue if r[0] != NEVER))
    return FefoRun(sold, unmet, expired, end_stock, lost, unresolved)


def project_expiry(pos: LotPosition, demand: np.ndarray) -> dict[str, Any]:
    """Merma proyectada de los lotes EXISTENTES (sin compras nuevas: salen después de lo que vence antes, así que no la alteran) con una
    demanda diaria dada. Solo cubre los días de `demand`; un lote que vence más allá queda `unresolved` (no se extrapola)."""
    if pos.status != "configured" or len(demand) == 0:
        return {"status": pos.status if pos.status != "configured" else "no_demand", "expired_qty": None, "unresolved_qty": None,
                "lost_lots": [], "first_loss_day": None}
    run = fefo_run(pos.lots, pos.undated_qty, np.asarray(demand, dtype=float))
    return {"status": "configured", "expired_qty": float(run.expired_qty), "unresolved_qty": float(run.unresolved_qty),
            "lost_lots": [{"day": d, "qty": round(q, 4), "lot": c} for d, q, c in run.lost_lots],
            "first_loss_day": run.lost_lots[0][0] if run.lost_lots else None, "days_simulated": int(len(demand))}


def shelf_cap_by_period(daily_demand: np.ndarray, period_days: int, n_periods: int, shelf_life_days: int, life_fraction: float = 1.0) -> np.ndarray:
    """Tope del stock al cierre de cada período para que una compra nueva se venda DENTRO de su vida útil (FEFO).

    Una unidad disponible desde el inicio del período `a` vence el día `a·R + S` (S = vida útil efectiva); la que sigue en el estante al
    cierre de `a` (día (a+1)·R) debe venderse entre los días (a+1)·R + 1 y a·R + S. Si el stock al cierre de cada período `a` no supera la
    demanda de esa ventana, ninguna unidad nueva vence sin venderse (cola FEFO: cada unidad sale antes de que la demanda acumulada supere
    el stock que tenía delante). `NaN` = la ventana excede los días cubiertos del pronóstico: sin tope (no se extrapola). S ≤ R ⇒ tope 0
    (nada puede pasar de un período al siguiente).

    Este tope solo acota el ARRASTRE entre períodos. Con S < R no basta: lo que llega al inicio de un período también vence DENTRO de él si no se vende
    en sus primeros S días; ese límite lo da `shelf_avail_cap_by_period`.
    """
    S = int(np.floor(shelf_life_days * life_fraction + 1e-9))
    R = int(period_days)
    d = np.asarray(daily_demand, dtype=float)
    cap = np.full(n_periods, np.nan)
    for a in range(n_periods):
        lo, hi = (a + 1) * R, a * R + S      # días (lo, hi]
        if hi <= lo:
            cap[a] = 0.0
        elif hi <= len(d):
            cap[a] = float(d[lo:hi].sum())
    return cap


def shelf_avail_cap_by_period(daily_demand: np.ndarray, period_days: int, n_periods: int, shelf_life_days: int, life_fraction: float = 1.0) -> np.ndarray:
    """Tope de lo DISPONIBLE al inicio de cada período (arrastre + recepciones) cuando la vida útil es MENOR que el período (HIGH-1 de la revisión).

    Lo disponible desde el inicio del período `a` vence el día `a·R + S`; con cola FEFO ninguna unidad vence sin venderse si lo disponible no supera la
    demanda de los días `a·R + 1 … a·R + S`. Con S < R el resto del período queda sin cobertura posible: el plan por períodos no puede planificar más
    que esa demanda (no se compra lo que vencería) y la demanda de los días restantes se declara sin atender por vida útil. `NaN` = sin tope: S ≥ R
    (basta el tope de arrastre) o la ventana no está cubierta por el pronóstico (no se extrapola).
    """
    S = int(np.floor(shelf_life_days * life_fraction + 1e-9))
    R = int(period_days)
    d = np.asarray(daily_demand, dtype=float)
    cap = np.full(n_periods, np.nan)
    if S >= R:
        return cap
    for a in range(n_periods):
        lo, hi = a * R, a * R + max(S, 0)
        if hi <= len(d):
            cap[a] = float(d[lo:hi].sum())
    return cap
