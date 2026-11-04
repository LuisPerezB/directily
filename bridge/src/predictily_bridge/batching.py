"""Lotes: filas → NDJSON UTF-8 → gzip DETERMINISTA (mtime 0) con SHA-256. El mismo contenido produce los mismos bytes, así que un lote reenviado
tras una caída es idempotente por SHA-256 en el servidor (nunca se registra ni se importa dos veces).

Cada línea es un objeto JSON con los campos canónicos de la plantilla (``null`` = sin valor). Fechas-hora sin zona van como ``AAAA-MM-DD HH:MM:SS``
(hora local de la fuente: el servidor las interpreta con su ``source_timezone``); con zona, con su desplazamiento. Decimales como texto exacto.
"""

from __future__ import annotations

import gzip
import io
import json
import math
from dataclasses import dataclass
from datetime import UTC, date, datetime, time
from decimal import Decimal
from typing import Any
from zoneinfo import ZoneInfo

from predictily_bridge.crypto import sha256_hex


def json_safe(value: Any) -> Any:
    if value is None or isinstance(value, (bool, int, str)):
        return value
    if isinstance(value, float):
        return value if math.isfinite(value) else None
    if isinstance(value, Decimal):
        return format(value, "f") if value.is_finite() else None
    if isinstance(value, datetime):
        if value.tzinfo is None:
            base = value.strftime("%Y-%m-%d %H:%M:%S")
            return f"{base}.{value.microsecond:06d}".rstrip("0") if value.microsecond else base
        return value.isoformat(sep=" ")
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, time):
        return value.isoformat()
    if isinstance(value, (bytes, bytearray, memoryview)):
        return None  # los binarios no son datos de negocio del importador
    return str(value)


def type_name(value: Any) -> str | None:
    if value is None:
        return None
    if isinstance(value, bool):
        return "bool"
    if isinstance(value, int):
        return "int"
    if isinstance(value, float):
        return "float"
    if isinstance(value, Decimal):
        return "decimal"
    if isinstance(value, datetime):
        return "datetime"
    if isinstance(value, date):
        return "date"
    return "string"


@dataclass(frozen=True)
class EncodedBatch:
    content: bytes
    sha256: str
    row_count: int
    columns: dict[str, str]  # columna -> tipo observado ("unknown" si solo se vio NULL)
    max_recorded_at: datetime | None = None

    @property
    def size_bytes(self) -> int:
        return len(self.content)


def encode_batch(rows: list[dict[str, Any]]) -> EncodedBatch:
    if not rows:
        raise ValueError("Un lote no puede estar vacío.")
    columns: dict[str, str | None] = {}
    lines = io.StringIO()
    for row in rows:
        for key, value in row.items():
            if columns.get(key) is None:
                columns[key] = type_name(value)
        lines.write(json.dumps({k: json_safe(v) for k, v in row.items()}, ensure_ascii=False, separators=(",", ":"), allow_nan=False))
        lines.write("\n")
    raw = io.BytesIO()
    with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0, compresslevel=6) as gz:
        gz.write(lines.getvalue().encode("utf-8"))
    content = raw.getvalue()
    return EncodedBatch(content, sha256_hex(content), len(rows), {k: v or "unknown" for k, v in columns.items()})


def decode_batch(content: bytes) -> list[dict[str, Any]]:
    return [json.loads(line) for line in gzip.decompress(content).decode("utf-8").splitlines() if line]


def to_utc(value: Any, source_tz: str | None) -> datetime | None:
    """Instante UTC de una fecha de registro de la fuente (ingenua → en la zona de la fuente); None si es ilegible."""
    if value is None or value == "":
        return None
    if isinstance(value, str):
        try:
            value = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
        except ValueError:
            return None
    if isinstance(value, date) and not isinstance(value, datetime):
        value = datetime(value.year, value.month, value.day)
    if not isinstance(value, datetime):
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=ZoneInfo(source_tz) if source_tz else UTC)
    return value.astimezone(UTC)
