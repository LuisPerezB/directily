"""Servidor Predictily simulado en memoria (misma semántica que el backend) y fixtures de origen para las pruebas del agente."""

from __future__ import annotations

import hashlib
import sqlite3
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Any

from predictily_bridge.retry import RetryExhausted
from predictily_bridge.state import iso, parse_iso
from predictily_bridge.transport import DeviceRevoked, ServerRejected

SALES_SQL = """
SELECT f.num_factura AS document_number, f.tipo AS document_type, f.estado AS status, f.fecha_emision AS issued_at, f.cod_sucursal AS branch_code,
       l.linea AS line_number, l.cod_item AS product_sku, l.cantidad AS quantity, l.precio_unit AS unit_price, f.modificado_en AS source_recorded_at
FROM erp_facturas f JOIN erp_factura_lineas l ON l.id_factura = f.id_factura
WHERE f.modificado_en >= :since
"""


class FakeServer:
    """Implementa ``ServerAPI``. ``bronze`` guarda los lotes por SHA-256 (idempotente, como Bronze real)."""

    def __init__(self) -> None:
        self.bronze: dict[str, bytes] = {}
        self.meta: dict[str, dict[str, Any]] = {}
        self.checkpoints_: dict[str, dict[str, Any]] = {}
        self.calls: list[tuple[str, Any]] = []
        self.order: list[str] = []  # SHA-256 en el orden en que se registraron por primera vez
        self.offline = False
        self.revoked = False
        self.fail: dict[str, list[Exception]] = {"declare": [], "put": [], "commit": []}
        self.reject_columns = False
        self.heartbeat_error: Exception | None = None
        self.checkpoints_error: Exception | None = None
        self.processing: dict[str, str] = {}  # sha -> estado de procesamiento del lote en el servidor (por defecto: completed)
        self.ledger: dict[tuple[str, str, int], str] = {}  # (entidad, corrida, posición) -> sha
        self.limits: dict[str, Any] = {"max_batch_bytes": 32 * 1024 * 1024}
        self.now = lambda: datetime.now(UTC)

    def _gate(self, op: str) -> None:
        if self.revoked:
            raise DeviceRevoked(401, "device_revoked", "revocado")
        if self.offline:
            raise RetryExhausted("sin conexión", Exception("offline"))
        if self.fail[op]:
            raise self.fail[op].pop(0)

    def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]:
        if self.revoked:
            raise DeviceRevoked(401, "device_revoked", "revocado")
        if self.offline:
            raise RetryExhausted("sin conexión", Exception("offline"))
        if self.heartbeat_error is not None:
            raise self.heartbeat_error
        self.calls.append(("heartbeat", payload))
        return {"status": "active", "limits": self.limits}

    def checkpoints(self) -> dict[str, dict[str, Any]]:
        if self.revoked:
            raise DeviceRevoked(401, "device_revoked", "revocado")
        if self.offline:
            raise RetryExhausted("sin conexión", Exception("offline"))
        if self.checkpoints_error is not None:
            raise self.checkpoints_error
        return {k: dict(v) for k, v in self.checkpoints_.items()}

    def declare_upload(self, body: dict[str, Any]) -> dict[str, Any]:
        self._gate("declare")
        self.calls.append(("declare", body))
        if self.reject_columns:
            raise ServerRejected(422, "unknown_columns", "columnas desconocidas")
        if body["sha256"] in self.bronze:
            return {"status": "duplicate", "bronze_object_id": 1, "processing": self.processing.get(body["sha256"], "completed")}
        self.meta[body["sha256"]] = body
        return {"status": "upload", "path": f"/api/v1/bridge/uploads/1/{body['sha256']}?e={body['entity']}&signature=x"}

    def put_batch(self, path: str, content: bytes) -> dict[str, Any]:
        self._gate("put")
        sha = path.split("?")[0].rsplit("/", 1)[1]
        self.calls.append(("put", sha))
        if hashlib.sha256(content).hexdigest() != sha:
            raise ServerRejected(422, "checksum_mismatch", "sha")
        if sha not in self.bronze:
            self.bronze[sha] = content
            self.order.append(sha)
        return {"status": "stored"}

    def commit(self, body: dict[str, Any]) -> dict[str, Any]:
        """Misma semántica que BridgeCheckpointService: lote en Bronze y no fallido, libro 1…N por corrida, marca de agua acotada y sin retroceso."""
        self._gate("commit")
        self.calls.append(("commit", body))
        entity, run_id, sha = body["entity"], body["run_id"], body.get("batch_sha256")
        if sha is not None:
            if sha not in self.bronze:
                raise ServerRejected(409, "batch_not_found", "no encontrado")
            if self.processing.get(sha, "completed") == "failed":
                raise ServerRejected(409, "batch_failed", "falló al procesarse")
        row = self.checkpoints_.setdefault(entity, {"entity": entity, "watermark": None, "checkpoint": None, "bronze_object_id": None})
        if sha is not None:
            key = (entity, run_id, int(body["run_batch_index"]))
            if self.ledger.get(key, sha) != sha:
                raise ServerRejected(409, "run_batch_conflict", "posición ocupada")
            self.ledger[key] = sha
        if body["run_complete"]:
            n = int(body["run_batches"])
            have = sorted(i for (e, r, i) in self.ledger if e == entity and r == run_id)
            if have != list(range(1, n + 1)):
                raise ServerRejected(409, "run_incomplete", "faltan lotes", {"missing": sorted(set(range(1, n + 1)) - set(have))})
            wm = parse_iso(body["watermark"])
            if wm > self.now() + timedelta(seconds=300):
                raise ServerRejected(422, "watermark_in_future", "futuro")
            if row["watermark"] is not None and wm < parse_iso(row["watermark"]):
                raise ServerRejected(409, "watermark_regression", "retrocede")
            row["watermark"], row["checkpoint"] = iso(wm), None
            for key in [k for k in self.ledger if k[0] == entity and k[1] != run_id]:
                del self.ledger[key]
        else:
            row["checkpoint"] = body["checkpoint"]
        if sha is not None:
            row["bronze_object_id"] = 1
        return dict(row)

    # ── ayudas de las pruebas ──
    def rows(self) -> list[dict[str, Any]]:
        from predictily_bridge.batching import decode_batch

        out: list[dict[str, Any]] = []
        for sha in self.order:
            out.extend(decode_batch(self.bronze[sha]))
        return out

    def commits(self) -> list[dict[str, Any]]:
        return [b for kind, b in self.calls if kind == "commit"]


def make_erp(path: Path, documents: int = 12, lines: int = 2, base: datetime | None = None) -> sqlite3.Connection:
    """Origen tipo ERP en SQLite: ``documents`` facturas de ``lines`` líneas; ``modificado_en`` crece con el número de factura."""
    base = base or datetime(2026, 1, 1, 8, 0, 0)
    con = sqlite3.connect(path)
    con.executescript(
        """
        CREATE TABLE erp_facturas (id_factura INTEGER PRIMARY KEY, num_factura TEXT UNIQUE NOT NULL, tipo TEXT NOT NULL, estado TEXT NOT NULL,
            fecha_emision TEXT NOT NULL, cod_sucursal TEXT NOT NULL, modificado_en TEXT NOT NULL);
        CREATE TABLE erp_factura_lineas (id_factura INTEGER NOT NULL, linea INTEGER NOT NULL, cod_item TEXT NOT NULL, cantidad REAL NOT NULL,
            precio_unit REAL NOT NULL, PRIMARY KEY (id_factura, linea));
        """
    )
    for n in range(1, documents + 1):
        stamp = (base + timedelta(hours=n)).strftime("%Y-%m-%d %H:%M:%S")
        con.execute("INSERT INTO erp_facturas VALUES (?,?,?,?,?,?,?)", (n, f"001-001-{n:09d}", "FACTURA", "EMITIDA", stamp, "001", stamp))
        for ln in range(1, lines + 1):
            con.execute("INSERT INTO erp_factura_lineas VALUES (?,?,?,?,?)", (n, ln, f"SKU-{(n + ln) % 5}", float(n + ln), 10.5))
    con.commit()
    return con
