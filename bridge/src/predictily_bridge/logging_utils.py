"""Registro sin secretos: un filtro enmascara tokens del dispositivo, contraseñas de cadenas de conexión y cualquier secreto registrado."""

from __future__ import annotations

import logging
import re
import threading

_TOKEN = re.compile(r"brt_[A-Za-z0-9_-]{20,}")
_KV = re.compile(r"(?i)\b(password|pwd|passwd|secret|token|authorization|api[_-]?key|x-bridge-signature)\b(\s*[=:]\s*)(?:Bearer\s+)?([^\s;,&'\"]+)")
_CODE = re.compile(r"\bBRG(?:-[0-9A-Za-z]{4}){5}\b")
_BEARER = re.compile(r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{8,}")

_lock = threading.Lock()
_secrets: set[str] = set()


def register_secret(value: str | None) -> None:
    """Todo texto igual a un secreto conocido se enmascara en cualquier mensaje (contraseña de la fuente, token…)."""
    if value and len(value) >= 4:
        with _lock:
            _secrets.add(value)


def clear_secrets() -> None:
    with _lock:
        _secrets.clear()


def redact(text: str) -> str:
    with _lock:
        known = sorted(_secrets, key=len, reverse=True)
    for secret in known:
        text = text.replace(secret, "***")
    text = _TOKEN.sub("brt_***", text)
    text = _CODE.sub("BRG-****", text)
    text = _BEARER.sub("Bearer ***", text)
    return _KV.sub(lambda m: f"{m.group(1)}{m.group(2)}***", text)


class RedactingFilter(logging.Filter):
    def filter(self, record: logging.LogRecord) -> bool:
        try:
            message = record.getMessage()
        except Exception:  # pragma: no cover - formato defectuoso del llamador
            message = str(record.msg)
        record.msg = redact(message)
        record.args = ()
        if record.exc_info:
            # El texto de la excepción también pasa por el filtro (puede traer cadenas de conexión).
            record.exc_text = redact(logging.Formatter().formatException(record.exc_info))
            record.exc_info = None
        return True


def setup_logging(level: str = "INFO", logfile: str | None = None) -> logging.Logger:
    logger = logging.getLogger("predictily_bridge")
    logger.setLevel(getattr(logging, level.upper(), logging.INFO))
    logger.handlers.clear()
    handlers: list[logging.Handler] = [logging.StreamHandler()]
    if logfile:
        handlers.append(logging.FileHandler(logfile, encoding="utf-8"))
    for handler in handlers:
        handler.addFilter(RedactingFilter())
        handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(name)s %(message)s"))
        logger.addHandler(handler)
    logger.propagate = False
    return logger
