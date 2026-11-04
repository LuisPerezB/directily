from __future__ import annotations

from pathlib import Path
from typing import Any

from predictily_bridge import config as cfgmod
from predictily_bridge.adapters import create_adapter
from predictily_bridge.device import DeviceHome
from predictily_bridge.runner import Agent
from predictily_bridge.spool import Spool
from predictily_bridge.state import AgentState

from fakes import SALES_SQL, FakeServer


def sqlite_config(db_path: Path, *, batch_rows: int = 5, spool_mb: int = 64, sql: str = SALES_SQL, extra_agent: dict[str, Any] | None = None, order_by: list[str] | None = None) -> cfgmod.Config:
    agent = {"batch_rows": batch_rows, "spool_max_mb": spool_mb, "overlap_minutes": 0, "source_timezone": "America/Guayaquil", **(extra_agent or {})}
    return cfgmod.parse({
        "server": {"url": "http://127.0.0.1:9", "allow_insecure_localhost": True},
        "agent": agent,
        "source": {"kind": "sqlite", "path": str(db_path)},
        "templates": [{"id": "erp.demo.sales", "entity": "sales", "version": 1, "sql": sql, "recorded_at_column": "source_recorded_at",
                       "order_by": order_by or ["document_number", "line_number"]}],
    })


class Clock:
    """Reloj controlable: ``sleep`` lo adelanta (sin esperas reales)."""

    def __init__(self, start: float = 1_767_225_600.0) -> None:  # 2026-01-01T00:00:00Z
        self.t = start

    def __call__(self) -> float:
        return self.t

    def sleep(self, seconds: float) -> None:
        self.t += seconds


def make_agent(config: cfgmod.Config, home_dir: Path, server: FakeServer, clock: Clock | None = None, password: str | None = None, adapter: Any = None,
               key_backend: str | None = None) -> tuple[Agent, DeviceHome]:
    home = DeviceHome(home_dir, use_keyring=False)
    clock = clock or Clock()
    adapter = adapter or create_adapter(config, password)
    spool = Spool(home.spool_dir, home.keystore, config.agent.spool_max_mb * 1024 * 1024)
    agent = Agent(config, server, adapter, AgentState(home.state_store), spool, clock=clock, sleep=clock.sleep, on_revoked=home.mark_revoked, key_backend=key_backend)
    return agent, home
