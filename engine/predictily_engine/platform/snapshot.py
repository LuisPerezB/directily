"""Huellas reproducibles de datos (ADR-V2-04).

Cada componente (ventas, precios, promociones as-of, costos, externos, calendario, configuración,
reglas de elegibilidad...) se reduce a un hash estable; el snapshot es el hash ordenado de todos.

Ventas as-of (ADR-V25-23): el hash del componente de ventas se calcula sobre los datos as-of EFECTIVAMENTE usados y cubre
además sus ventanas de conocimiento. Los marcos derivados de `data.asof` (`daily_sales`, compras del Customer Core) llevan en
`DataFrame.attrs['knowledge_digest']` la huella de las versiones de las que salieron (contenido, `known_from` y `known_until`);
`frame_hash` la mezcla con el contenido. Así dos cortes con las mismas cifras pero distinto conocimiento (p. ej. un documento
cargado de forma histórica frente a uno recibido tarde, que altera lo que entrenaron las ventanas anteriores del backtest) NO
comparten huella, y lo ocurrido después del corte final no la altera. Un marco sin esa marca se hashea como siempre.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import pandas as pd

from predictily_engine.data.asof import KNOWLEDGE_ATTR


def frame_hash(df: pd.DataFrame | None, columns: list[str] | None = None, sort_by: list[str] | None = None,
               knowledge: str | None = None) -> str:
    """Hash estable de un DataFrame (independiente del orden de filas si se indica sort_by).

    `knowledge`: huella de las ventanas de conocimiento de los datos (por defecto la de `df.attrs`, si la trae)."""
    if knowledge is None and df is not None:
        knowledge = (df.attrs or {}).get(KNOWLEDGE_ATTR)
    suffix = b"" if knowledge is None else b"|knowledge:" + str(knowledge).encode()
    if df is None or len(df) == 0:
        return hashlib.sha256(b"empty" + suffix).hexdigest()
    d = df[columns] if columns else df
    d = d.sort_values(sort_by or list(d.columns), kind="mergesort").reset_index(drop=True)
    hashed = pd.util.hash_pandas_object(d, index=False).to_numpy()
    return hashlib.sha256(hashed.tobytes() + suffix).hexdigest()


def value_hash(value: Any) -> str:
    return hashlib.sha256(json.dumps(value, sort_keys=True, default=str).encode()).hexdigest()


@dataclass
class DataSnapshot:
    core: str
    as_of: date
    components: dict[str, str] = field(default_factory=dict)
    row_counts: dict[str, int] = field(default_factory=dict)
    coverage: dict[str, Any] = field(default_factory=dict)

    def add_frame(self, name: str, df: pd.DataFrame | None, columns: list[str] | None = None,
                  sort_by: list[str] | None = None, knowledge: str | None = None) -> None:
        self.components[name] = frame_hash(df, columns, sort_by, knowledge)
        self.row_counts[name] = 0 if df is None else int(len(df))

    def add_value(self, name: str, value: Any) -> None:
        self.components[name] = value_hash(value)

    @property
    def hash(self) -> str:
        return value_hash({"core": self.core, "as_of": self.as_of.isoformat(), "components": self.components})


def persist_snapshot(conn, tenant_id: int, snap: DataSnapshot) -> int:
    """Inserta (o reutiliza) el snapshot de la empresa; devuelve su id. Requiere una conexión SQLAlchemy."""
    from sqlalchemy import text

    row = conn.execute(text(
        "INSERT INTO data_snapshots (tenant_id, core, as_of, hash, components, row_counts, coverage) "
        "VALUES (:t, :core, :as_of, :hash, CAST(:components AS jsonb), CAST(:rows AS jsonb), CAST(:coverage AS jsonb)) "
        "ON CONFLICT (tenant_id, core, hash) DO UPDATE SET core = EXCLUDED.core RETURNING id"
    ), {"t": tenant_id, "core": snap.core, "as_of": snap.as_of, "hash": snap.hash,
        "components": json.dumps(snap.components), "rows": json.dumps(snap.row_counts),
        "coverage": json.dumps(snap.coverage, default=str)}).scalar_one()
    return int(row)
