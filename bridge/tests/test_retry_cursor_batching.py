"""Retroceso exponencial con jitter, cursor tipado y lotes deterministas."""

from __future__ import annotations

import random
from datetime import UTC, date, datetime, timedelta, timezone
from decimal import Decimal

import pytest

from predictily_bridge import batching
from predictily_bridge.cursor import MAX_LENGTH, Cursor, CursorError
from predictily_bridge.retry import Backoff, RetryableError, RetryExhausted


# ── backoff ──
def test_full_jitter_stays_within_the_exponential_ceiling_and_cap() -> None:
    b = Backoff(base=1.0, cap=30.0, rng=random.Random(42))
    for attempt in range(10):
        ceiling = min(30.0, 2**attempt)
        samples = [b.delay(attempt) for _ in range(200)]
        assert all(0.0 <= s <= ceiling for s in samples)
        if attempt >= 3:
            assert max(samples) > ceiling * 0.8, "el jitter cubre todo el intervalo, no un valor fijo"
            assert min(samples) < ceiling * 0.2
    assert max(b.delay(20) for _ in range(100)) <= 30.0, "el tope se respeta"


def test_two_agents_do_not_retry_in_lockstep() -> None:
    a, c = Backoff(rng=random.Random(1)), Backoff(rng=random.Random(2))
    assert [a.delay(3) for _ in range(5)] != [c.delay(3) for _ in range(5)]


def test_retry_after_is_honoured_but_bounded() -> None:
    b = Backoff(base=1.0, cap=2.0, max_retry_after=60.0, rng=random.Random(0))
    assert b.delay(0, retry_after=30) >= 30
    assert b.delay(0, retry_after=10_000) == 60.0


def test_run_retries_until_success_then_gives_up_after_max_attempts() -> None:
    sleeps: list[float] = []
    calls = {"n": 0}

    def flaky() -> str:
        calls["n"] += 1
        if calls["n"] < 3:
            raise RetryableError("503")
        return "ok"

    b = Backoff(max_attempts=5, sleep=sleeps.append, rng=random.Random(3))
    assert b.run(flaky) == "ok" and calls["n"] == 3 and len(sleeps) == 2

    sleeps.clear()
    always = {"n": 0}

    def down() -> None:
        always["n"] += 1
        raise RetryableError("sin red")

    with pytest.raises(RetryExhausted):
        Backoff(max_attempts=4, sleep=sleeps.append, rng=random.Random(3)).run(down)
    assert always["n"] == 4 and len(sleeps) == 3, "no duerme tras el último intento"


def test_non_retryable_errors_are_not_retried() -> None:
    calls = {"n": 0}

    def bad() -> None:
        calls["n"] += 1
        raise ValueError("no es transitorio")

    with pytest.raises(ValueError):
        Backoff(sleep=lambda s: None).run(bad)
    assert calls["n"] == 1


# ── cursor ──
def test_cursor_roundtrip_keeps_value_types() -> None:
    values = (datetime(2026, 3, 4, 10, 11, 12, 123456, tzinfo=timezone(timedelta(hours=-5))), "001-001-000000042", 7, Decimal("12.5000"), date(2026, 3, 4), True)
    cursor = Cursor("run1", "incremental", 1_760_000_000, 1_759_990_000, values, "abcd1234")
    text = cursor.encode()
    assert len(text) <= MAX_LENGTH, len(text)
    back = Cursor.decode(text)
    assert back.after == values and isinstance(back.after[0], datetime) and isinstance(back.after[3], Decimal)
    assert (back.run_id, back.mode, back.started_at, back.since, back.template_fp) == ("run1", "incremental", 1_760_000_000, 1_759_990_000, "abcd1234")


def test_cursor_too_long_is_refused_with_actionable_message() -> None:
    with pytest.raises(CursorError, match="más cortas"):
        Cursor("r", "full", 1, None, ("x" * 300,), "").encode()


def test_cursor_garbage_and_unknown_types() -> None:
    for bad in ("", "no json", '{"r":1}', '{"r":"a","m":"f","u":1,"s":null,"a":["zX"]}'):
        with pytest.raises(CursorError):
            Cursor.decode(bad)
    with pytest.raises(CursorError):
        Cursor("r", "full", 1, None, (object(),), "").encode()


# ── lotes ──
ROWS = [
    {"a": "x", "n": Decimal("8.5000"), "d": datetime(2026, 1, 2, 3, 4, 5), "z": datetime(2026, 1, 2, 3, 4, 5, tzinfo=UTC), "t": date(2026, 1, 2), "f": float("nan"), "b": True, "bin": b"\x00\x01", "none": None},
    {"a": "ñandú €", "n": Decimal("1"), "d": datetime(2026, 1, 2, 3, 4, 5, 500000), "z": None, "t": None, "f": 1.5, "b": False, "bin": None, "none": None},
]


def test_batches_are_deterministic_and_roundtrip() -> None:
    one, two = batching.encode_batch(ROWS), batching.encode_batch([dict(r) for r in ROWS])
    assert one.content == two.content and one.sha256 == two.sha256, "gzip determinista (mtime 0)"
    assert one.content[:2] == b"\x1f\x8b" and one.content[4:8] == b"\x00\x00\x00\x00", "mtime del encabezado gzip = 0"
    back = batching.decode_batch(one.content)
    assert back[0] == {"a": "x", "n": "8.5000", "d": "2026-01-02 03:04:05", "z": "2026-01-02 03:04:05+00:00", "t": "2026-01-02", "f": None, "b": True, "bin": None, "none": None}
    assert back[1]["d"] == "2026-01-02 03:04:05.5" and back[1]["a"] == "ñandú €"
    assert one.row_count == 2 and one.columns["n"] == "decimal" and one.columns["none"] == "unknown" and one.columns["d"] == "datetime"
    with pytest.raises(ValueError):
        batching.encode_batch([])


def test_recorded_at_parsing() -> None:
    assert batching.to_utc("2026-03-04 10:00:00", "America/Guayaquil") == datetime(2026, 3, 4, 15, 0, tzinfo=UTC)
    assert batching.to_utc(datetime(2026, 3, 4, 10, 0), None) == datetime(2026, 3, 4, 10, 0, tzinfo=UTC)
    assert batching.to_utc("2026-03-04T10:00:00Z", "America/Guayaquil") == datetime(2026, 3, 4, 10, 0, tzinfo=UTC)
    assert batching.to_utc("basura", None) is None and batching.to_utc(None, None) is None
