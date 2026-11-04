"""Reintentos con retroceso exponencial y jitter completo («full jitter»): ``espera = U(0, min(tope, base · 2^intento))``.

El jitter evita que muchos agentes reconectados a la vez golpeen al servidor sincronizados. Respeta ``Retry-After`` (acotado). Sin ``sleep``
real en las pruebas: ``sleep`` y ``rng`` se inyectan.
"""

from __future__ import annotations

import random
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import TypeVar

T = TypeVar("T")


class RetryableError(Exception):
    """Fallo transitorio (red, 408, 429, 5xx). ``retry_after`` (segundos) lo sugiere el servidor."""

    def __init__(self, message: str, retry_after: float | None = None) -> None:
        super().__init__(message)
        self.retry_after = retry_after


class RetryExhausted(Exception):
    def __init__(self, message: str, last: Exception) -> None:
        super().__init__(message)
        self.last = last


@dataclass
class Backoff:
    base: float = 1.0
    cap: float = 60.0
    max_attempts: int = 6
    max_retry_after: float = 300.0
    sleep: Callable[[float], None] = time.sleep
    rng: random.Random = field(default_factory=random.Random)

    def delay(self, attempt: int, retry_after: float | None = None) -> float:
        """Espera antes del reintento número ``attempt`` (0 = el primero)."""
        ceiling = min(self.cap, self.base * (2**attempt))
        delay = self.rng.uniform(0.0, ceiling)
        if retry_after is not None:
            delay = max(delay, min(retry_after, self.max_retry_after))
        return delay

    def run(self, operation: Callable[[], T], on_retry: Callable[[int, float, Exception], None] | None = None) -> T:
        last: Exception | None = None
        for attempt in range(self.max_attempts):
            try:
                return operation()
            except RetryableError as e:
                last = e
                if attempt == self.max_attempts - 1:
                    break
                wait = self.delay(attempt, e.retry_after)
                if on_retry:
                    on_retry(attempt + 1, wait, e)
                self.sleep(wait)
        assert last is not None
        raise RetryExhausted(f"Sin éxito tras {self.max_attempts} intentos: {last}", last)
