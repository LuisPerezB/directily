"""Estado local de extracción (cifrado): por entidad, la corrida en curso, la marca de agua CONFIRMADA y el siguiente número de secuencia.

El estado local es una copia de trabajo: la VERDAD de lo confirmado es el servidor (``bridge_checkpoints``). Al iniciar cada ciclo el agente
reconcilia con ``GET /bridge/checkpoints`` y, si su estado local se perdió, reanuda desde el cursor del servidor.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any

from predictily_bridge.securestore import SecureStore


@dataclass
class EntityState:
    watermark: str | None = None  # ISO UTC confirmada por el servidor
    next_seq: int = 1
    run: dict[str, Any] | None = None
    files_done: dict[str, str] = field(default_factory=dict)
    blocked: dict[str, Any] | None = None
    last_error: str | None = None
    last_success_at: str | None = None
    rows_sent: int = 0
    template_fp: str | None = None
    abandoned: list[str] = field(default_factory=list)  # corridas descartadas: un cursor viejo del servidor con ese id nunca se reanuda

    def as_dict(self) -> dict[str, Any]:
        return dict(self.__dict__)

    @staticmethod
    def from_dict(data: dict[str, Any]) -> "EntityState":
        known = EntityState.__dataclass_fields__.keys()
        return EntityState(**{k: v for k, v in data.items() if k in known})


class AgentState:
    def __init__(self, store: SecureStore) -> None:
        self.store = store
        raw = store.load({"entities": {}})
        self.entities: dict[str, EntityState] = {k: EntityState.from_dict(v) for k, v in (raw.get("entities") or {}).items()}

    def entity(self, name: str) -> EntityState:
        return self.entities.setdefault(name, EntityState())

    def save(self) -> None:
        self.store.save({"entities": {k: v.as_dict() for k, v in self.entities.items()}})


def iso(dt: datetime) -> str:
    return dt.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%S+00:00")


def parse_iso(text: str) -> datetime:
    return datetime.fromisoformat(text.replace("Z", "+00:00")).astimezone(UTC)
