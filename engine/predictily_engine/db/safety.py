"""Guardas para herramientas que solo pueden tocar una base de PRUEBAS (W6-E · revisión M4).

Una comprobación del tipo «el texto después de la última `/` contiene "test"» se evade con `postgresql://u@produccion/predictily?x=/test`, con
`?dbname=produccion` en la cadena de consulta (libpq la usa por encima de la ruta) o con un nombre como `latest_prod`. Aquí:

1. la URL se analiza con `sqlalchemy.engine.make_url` (nunca con cortes de texto) y SE EXIGE `url.database`;
2. el nombre debe contener `test` como PALABRA (`predictily_engine_test_w6e`, `test_copia`, `copia-test`; no `latest`, `contest` ni `attestation`);
3. la cadena de consulta solo admite parámetros benignos (nada de `dbname`, `host`, `hostaddr`, `service`, `options`, `user`, `port`, `passfile`…: pueden
   redirigir la conexión a otra base o anular la sesión de solo lectura);
4. tras conectar, `SELECT current_database()` debe seguir cumpliendo la regla (la conexión real, no la URL declarada);
5. las herramientas de lectura abren cada transacción con `SET TRANSACTION READ ONLY` (no solo `default_transaction_read_only` de la sesión, que una sentencia
   `SET` puede anular).

Las herramientas de `tests/tools/` (`factor_off_parity.py`, `demo_parity.py`) llevan una copia en línea de `parse_test_database_url` porque se ejecutan también contra
el árbol BASE de un `git archive`, que no trae este módulo; una prueba verifica que las tres copias rechazan lo mismo.
"""

from __future__ import annotations

import re
from typing import Any

from sqlalchemy import create_engine, event, text
from sqlalchemy.engine import URL, Engine, make_url

TEST_NAME = re.compile(r"(^|[_\-.])test([_\-.]|$)", re.IGNORECASE)
ALLOWED_QUERY = frozenset({"sslmode", "sslrootcert", "sslcert", "sslkey", "connect_timeout", "application_name", "keepalives", "keepalives_idle",
                           "keepalives_interval", "keepalives_count", "target_session_attrs", "gssencmode", "channel_binding"})
READ_ONLY_OPTIONS = "-c default_transaction_read_only=on"


def is_test_database_name(name: str | None) -> bool:
    return bool(name) and bool(TEST_NAME.search(name))


def parse_test_database_url(url: str | URL) -> URL:
    """URL analizada y validada; `PermissionError` si no es inequívocamente una base de pruebas (ver el docstring del módulo)."""
    try:
        parsed = make_url(url) if isinstance(url, str) else url
    except Exception as exc:          # noqa: BLE001
        raise PermissionError(f"URL de base de datos ilegible: {type(exc).__name__}") from exc
    if not parsed.drivername.startswith("postgresql"):
        raise PermissionError(f"Solo PostgreSQL: {parsed.drivername!r}.")
    if not parsed.database:
        raise PermissionError("La URL no declara la base de datos (url.database vacío): se rechaza.")
    if not is_test_database_name(parsed.database):
        raise PermissionError(f"La base «{parsed.database}» no parece de pruebas (su nombre debe contener «test» como palabra): estas herramientas solo corren "
                              "sobre una copia de pruebas o datos ficticios, nunca sobre una base de producción.")
    forbidden = sorted(k for k in parsed.query if k.lower() not in ALLOWED_QUERY)
    if forbidden:
        raise PermissionError(f"La cadena de consulta de la URL trae parámetros que pueden redirigir la conexión o anular la guarda: {forbidden}.")
    if "/" in (parsed.host or "") or "," in (parsed.host or ""):
        raise PermissionError("El host de la URL es ambiguo (varias máquinas o ruta de socket): se rechaza.")
    return parsed


def assert_connected_to_test_database(conn: Any) -> str:
    """Comprueba la base REAL de la conexión abierta (`current_database()`); devuelve su nombre."""
    name = conn.execute(text("SELECT current_database()")).scalar()
    if not is_test_database_name(name):
        raise PermissionError(f"La conexión abierta es a la base «{name}», que no parece de pruebas: se aborta.")
    return str(name)


def read_only_engine(url: str | URL, **kwargs: Any) -> Engine:
    """Engine de SOLO LECTURA sobre una base de PRUEBAS: URL validada, sesión con `default_transaction_read_only = on` y cada transacción con
    `SET TRANSACTION READ ONLY`. No se puede escribir ni siquiera si alguien cambiara el valor por defecto de la sesión."""
    parsed = parse_test_database_url(url)
    engine = create_engine(parsed, pool_pre_ping=True, connect_args={"options": READ_ONLY_OPTIONS}, **kwargs)

    @event.listens_for(engine, "begin")
    def _read_only(conn):         # noqa: ANN001  (primer comando de CADA transacción)
        conn.exec_driver_sql("SET TRANSACTION READ ONLY")

    return engine
