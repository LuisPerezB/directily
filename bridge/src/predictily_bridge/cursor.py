"""Cursor opaco de reanudación (≤ 200 caracteres: el límite de ``bridge_checkpoints.checkpoint``).

``{"r": <id de la corrida>, "m": "f"|"i", "u": <inicio de la extracción, época>, "s": <desde, época|null>, "a": [<llaves del último registro>]}``
Los valores de las llaves llevan su tipo en el primer carácter para recuperarlos exactamente (``i``entero, ``s``texto, ``n``decimal,
``d``fecha, ``t``fecha-hora ISO, ``b``booleano, ``f``flotante): una llave de fecha-hora vuelve a ser fecha-hora al reanudar.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import date, datetime
from decimal import Decimal
from typing import Any

MAX_LENGTH = 200


class CursorError(Exception):
    pass


def encode_value(value: Any) -> str:
    if isinstance(value, bool):
        return "b1" if value else "b0"
    if isinstance(value, int):
        return f"i{value}"
    if isinstance(value, float):
        return f"f{value!r}"
    if isinstance(value, Decimal):
        return f"n{value}"
    if isinstance(value, datetime):
        return f"t{value.isoformat()}"
    if isinstance(value, date):
        return f"d{value.isoformat()}"
    if isinstance(value, str):
        return f"s{value}"
    raise CursorError(f"Tipo de llave no admitido en el cursor: {type(value).__name__}")


def decode_value(text: str) -> Any:
    tag, body = text[:1], text[1:]
    try:
        return {
            "b": lambda: body == "1",
            "i": lambda: int(body),
            "f": lambda: float(body),
            "n": lambda: Decimal(body),
            "t": lambda: datetime.fromisoformat(body),
            "d": lambda: date.fromisoformat(body),
            "s": lambda: body,
        }[tag]()
    except (KeyError, ValueError, ArithmeticError):
        raise CursorError("Cursor ilegible.") from None


@dataclass(frozen=True)
class Cursor:
    run_id: str
    mode: str  # "full" | "incremental"
    started_at: int  # época (s): inicio de la extracción = cota de la marca de agua
    since: int | None  # época (s) del «desde» de la corrida; None = carga completa
    after: tuple[Any, ...] | None  # llaves del último registro entregado (None = desde el principio)
    template_fp: str = ""  # 8 primeros hex de la huella de la plantilla: un cursor de otra plantilla nunca se reanuda
    batches: int = 0  # lotes confirmados de la corrida (posición del último): al reanudar tras perder el estado, el índice sigue donde el servidor lo dejó

    def encode(self) -> str:
        body = {
            "r": self.run_id,
            "m": "f" if self.mode == "full" else "i",
            "u": self.started_at,
            "s": self.since,
            "a": [encode_value(v) for v in self.after] if self.after is not None else None,
            "t": self.template_fp[:8],
            "n": self.batches,
        }
        text = json.dumps(body, separators=(",", ":"), ensure_ascii=False)
        if len(text) > MAX_LENGTH:
            raise CursorError(
                f"El cursor de reanudación ({len(text)} caracteres) supera el máximo de {MAX_LENGTH}: use llaves de orden más cortas en la plantilla."
            )
        return text

    @staticmethod
    def decode(text: str) -> "Cursor":
        try:
            body = json.loads(text)
            after = tuple(decode_value(v) for v in body["a"]) if body.get("a") is not None else None
            return Cursor(str(body["r"]), "full" if body["m"] == "f" else "incremental", int(body["u"]), int(body["s"]) if body.get("s") is not None else None, after, str(body.get("t", "")), int(body.get("n", 0)))
        except (ValueError, KeyError, TypeError):
            raise CursorError("Cursor ilegible.") from None
