"""Política de SOLO LECTURA de las consultas del agente: puerto de ``SqlQueryGuard`` del backend (ADR-V2-13, ADR-V25-10).

Acepta exactamente UNA sentencia ``SELECT`` (o ``WITH ... SELECT``), sin escritura, DDL, DCL, transacciones, procedimientos,
``SELECT ... INTO``, bloqueos de filas ni funciones/objetos de sistema, con un único parámetro nombrado ``:since`` (más los que el
llamador interno habilite explícitamente para la paginación por llave). Analiza léxicamente —cadenas, identificadores entrecomillados y
comentarios se aíslan antes de buscar palabras peligrosas— y devuelve el texto SIN comentarios: lo que se ejecuta es lo que se analizó.

PARIDAD: las reglas, los códigos de rechazo (``reason``) y el texto resultante son los de ``backend/app/Services/Connectors/Sql/
SqlQueryGuard.php``. El corpus compartido ``tests/fixtures/sql_guard_cases.json`` se genera con el guardia PHP y lo comprueban las pruebas de
ambos lados: si una regla cambia en un lado, la prueba del otro falla. Única extensión: el dialecto ``sqlite`` (que el guardia PHP no tiene)
usa las reglas de PostgreSQL (``[`` y comillas invertidas no son identificadores), comentarios de bloque sin anidar y vetos propios
(``pragma``, ``attach``, ``detach``, ``sqlite_*``, ``load_extension``, ``readfile``, ``writefile``).

Es una defensa en profundidad, NO sustituye a un usuario de base de datos de solo lectura ni a la sesión de solo lectura del controlador.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass

MAX_LENGTH = 20_000
SINCE_PARAM = "since"
DIALECTS = ("pgsql", "mysql", "sqlsrv", "sqlite")

FORBIDDEN_WORDS = frozenset(
    "insert update delete merge upsert drop alter create truncate rename grant revoke call exec execute copy do set reset begin commit "
    "rollback savepoint vacuum analyze reindex notify listen unlisten prepare deallocate declare lock unlock kill shutdown backup restore "
    "waitfor bulk dbcc into share load handler use outfile dumpfile".split()
)
SQLSERVER_EXTRA_WORDS = frozenset(
    "deny disable enable setuser revert checkpoint reconfigure print raiserror throw goto while readtext writetext updatetext "
    "openrowset openquery opendatasource openxml "
    # B-9: RECEIVE consume mensajes de una cola de Service Broker; las sugerencias de bloqueo toman bloqueos de filas/tablas.
    "receive updlock holdlock xlock tablockx".split()
)
SQLITE_EXTRA_WORDS = frozenset("pragma attach detach".split())
FORBIDDEN_FUNCTIONS = frozenset(
    "sleep benchmark get_lock release_lock release_all_locks is_free_lock load_file sys_exec sys_eval set_config nextval setval dblink "
    "dblink_exec dblink_connect query_to_xml table_to_xml schema_to_xml database_to_xml cursor_to_xml query_to_xml_and_xmlschema "
    "database_to_xml_and_xmlschema table_to_xml_and_xmlschema schema_to_xml_and_xmlschema lo_import "
    "lo_export lo_create lo_unlink lo_put lo_from_bytea lo_open lo_creat lowrite lo_truncate lo_truncate64".split()
)
SQLITE_EXTRA_FUNCTIONS = frozenset("load_extension readfile writefile edit fts3_tokenizer".split())
FORBIDDEN_PREFIXES = ("pg_", "xp_", "sp_", "fn_")
SQLITE_EXTRA_PREFIXES = ("sqlite_",)
SYSTEM_NAMES = frozenset(
    "information_schema performance_schema sysobjects syscolumns sysusers syslogins sql_logins sysdatabases sysprocesses syscomments".split()
)
SYSTEM_QUALIFIERS = frozenset("sys mysql master msdb tempdb model".split())
_SYMBOLS = "(),.;*+-/%=<>!|&^~"
_WS = " \t\n\r\x0b\x0c"  # ctype_space de PHP
_PHP_TRIM = " \t\n\r\0\x0b"  # trim() de PHP


class SqlQueryRejected(Exception):
    """La consulta no cumple la política de solo lectura. ``reason`` es el código estable (igual que en PHP)."""

    def __init__(self, message: str, reason: str = "not_read_only") -> None:
        super().__init__(message)
        self.reason = reason


@dataclass(frozen=True)
class Token:
    t: str  # ws | comment | word | ident | string | number | param | symbol
    v: str
    name: str | None = None


@dataclass(frozen=True)
class Param:
    """Posición de un parámetro nombrado (``:since``…) en la consulta validada."""

    name: str


@dataclass(frozen=True)
class ValidatedQuery:
    """Consulta que superó el guardia. Solo ``validate`` la construye; los controladores solo ejecutan objetos de este tipo."""

    parts: tuple[str | Param, ...]
    uses_since: bool

    @property
    def param_names(self) -> tuple[str, ...]:
        return tuple(p.name for p in self.parts if isinstance(p, Param))

    def render(self, since_literal: str | None = None) -> str:
        """Texto con ``:since`` sustituido por un literal (solo para las pruebas de paridad con PHP)."""
        out: list[str] = []
        for part in self.parts:
            if isinstance(part, Param):
                if part.name != SINCE_PARAM or since_literal is None:
                    raise SqlQueryRejected("La consulta usa :since pero no se proporcionó valor.", "since_missing")
                out.append(since_literal)
            else:
                out.append(part)
        return "".join(out).strip(_PHP_TRIM)

    def bind(self, style: str, values: dict[str, object]) -> tuple[str, list[object] | dict[str, object]]:
        """Texto SQL con marcadores del controlador y los valores de los parámetros (nunca interpolados en el texto).

        ``style``: ``pyformat`` (``%(n)s``; psycopg), ``format`` (``%s``; PyMySQL), ``qmark`` (``?``; sqlite3, pyodbc) o ``named`` (``:n``).
        En los estilos con ``%`` el ``%`` literal de la consulta se duplica.
        """
        sql: list[str] = []
        positional: list[object] = []
        named: dict[str, object] = {}
        for part in self.parts:
            if isinstance(part, Param):
                if part.name not in values:
                    raise SqlQueryRejected(f"Falta el valor del parámetro :{part.name}.", "since_missing")
                if style == "pyformat":
                    sql.append(f"%({part.name})s")
                    named[part.name] = values[part.name]
                elif style == "format":
                    sql.append("%s")
                    positional.append(values[part.name])
                elif style == "qmark":
                    sql.append("?")
                    positional.append(values[part.name])
                elif style == "named":
                    sql.append(f":{part.name}")
                    named[part.name] = values[part.name]
                else:
                    raise ValueError(f"Estilo de parámetros desconocido: {style}")
            else:
                sql.append(part.replace("%", "%%") if style in ("pyformat", "format") else part)
        return "".join(sql).strip(_PHP_TRIM), (named if style in ("pyformat", "named") else positional)


def validate(sql: str, dialect: str, allowed_params: frozenset[str] = frozenset({SINCE_PARAM})) -> ValidatedQuery:
    """Valida ``sql`` para el dialecto (``pgsql`` | ``mysql`` | ``sqlsrv`` | ``sqlite``). Lanza ``SqlQueryRejected``."""
    if dialect not in DIALECTS:
        raise ValueError(f"Dialecto desconocido: {dialect}")
    if sql.strip(_PHP_TRIM) == "":
        raise SqlQueryRejected("La consulta está vacía.", "empty")
    try:
        encoded = sql.encode("utf-8")
    except UnicodeEncodeError:
        raise SqlQueryRejected("La consulta debe estar en UTF-8.", "encoding") from None
    if len(encoded) > MAX_LENGTH:
        raise SqlQueryRejected(f"La consulta supera los {MAX_LENGTH} caracteres permitidos.", "too_long")
    if "\0" in sql:
        raise SqlQueryRejected("La consulta contiene caracteres no permitidos.", "invalid_character")
    if "\\" in sql:
        raise SqlQueryRejected("No se permite la barra invertida en la consulta (los escapes difieren entre motores de base de datos).", "backslash")

    tokens = _tokenize(sql, dialect)

    end = len(tokens)
    while end > 0:
        last = tokens[end - 1]
        if last.t in ("ws", "comment") or (last.t == "symbol" and last.v == ";"):
            end -= 1
            continue
        break
    tokens = tokens[:end]

    significant = [t for t in tokens if t.t not in ("ws", "comment")]
    if not significant:
        raise SqlQueryRejected("La consulta está vacía.", "empty")
    for token in significant:
        if token.t == "symbol" and token.v == ";":
            raise SqlQueryRejected('Solo se permite una sentencia: se detectó más de una (";").', "multi_statement")
    first = significant[0]
    if first.t != "word" or first.v.lower() not in ("select", "with"):
        raise SqlQueryRejected("Solo se permiten consultas SELECT (o WITH ... SELECT).", "not_select")

    forbidden = set(FORBIDDEN_WORDS)
    functions = set(FORBIDDEN_FUNCTIONS)
    prefixes = FORBIDDEN_PREFIXES
    if dialect == "sqlsrv":
        forbidden |= SQLSERVER_EXTRA_WORDS
    if dialect == "sqlite":
        forbidden |= SQLITE_EXTRA_WORDS
        functions |= SQLITE_EXTRA_FUNCTIONS
        prefixes = FORBIDDEN_PREFIXES + SQLITE_EXTRA_PREFIXES

    depth = 0
    uses_since = False
    for i, token in enumerate(significant):
        nxt = significant[i + 1] if i + 1 < len(significant) else None
        if token.t == "symbol":
            if token.v == "(":
                depth += 1
            elif token.v == ")":
                depth -= 1
                if depth < 0:
                    raise SqlQueryRejected("Paréntesis desbalanceados en la consulta.", "unbalanced")
            continue
        if token.t == "param":
            if token.name not in allowed_params:
                raise SqlQueryRejected(f"Parámetro no permitido :{token.name}. El único parámetro disponible es :since.", "parameter")
            if token.name == SINCE_PARAM:
                uses_since = True
            continue
        if token.t == "ident":
            _assert_not_system_object((token.name or "").lower(), nxt, prefixes)
            continue
        if token.t != "word":
            continue
        word = token.v.lower()
        if word in forbidden:
            raise SqlQueryRejected(f"La consulta contiene la palabra «{word.upper()}», no permitida en consultas de solo lectura.", "forbidden_keyword")
        # NEXT VALUE FOR <secuencia> avanza la secuencia (SQL Server, MariaDB): escritura. FETCH NEXT … ROWS y una columna «next» siguen permitidas.
        after = significant[i + 2] if i + 2 < len(significant) else None
        if word == "next" and nxt is not None and nxt.t == "word" and nxt.v.lower() == "value" and after is not None and after.t == "word" and after.v.lower() == "for":
            raise SqlQueryRejected("«NEXT VALUE FOR» avanza una secuencia: no está permitido en consultas de solo lectura.", "forbidden_keyword")
        is_call = nxt is not None and nxt.t == "symbol" and nxt.v == "("
        if word == "replace" and not is_call:
            raise SqlQueryRejected("«REPLACE» solo se permite como función REPLACE(...).", "forbidden_keyword")
        if is_call and (word in functions or "_to_xml" in word):  # también por patrón: cualquier función *_to_xml*
            raise SqlQueryRejected(f"La función {word}() no está permitida en consultas de solo lectura.", "forbidden_function")
        _assert_not_system_object(word, nxt, prefixes)
    if depth != 0:
        raise SqlQueryRejected("Paréntesis desbalanceados en la consulta.", "unbalanced")

    parts: list[str | Param] = []
    for token in tokens:
        if token.t == "comment":
            piece: str | Param = " "
        elif token.t == "param":
            piece = Param(token.name or "")
        else:
            piece = token.v
        if isinstance(piece, str) and parts and isinstance(parts[-1], str):
            parts[-1] += piece
        else:
            parts.append(piece)
    return ValidatedQuery(tuple(parts), uses_since)


def _assert_not_system_object(name: str, nxt: Token | None, prefixes: tuple[str, ...]) -> None:
    for prefix in prefixes:
        if name.startswith(prefix):
            raise SqlQueryRejected(f"Se rechaza el objeto de sistema «{name}».", "system_object")
    if name in SYSTEM_NAMES:
        raise SqlQueryRejected(f"Se rechaza el catálogo de sistema «{name}».", "system_object")
    if name in SYSTEM_QUALIFIERS and nxt is not None and nxt.t == "symbol" and nxt.v == ".":
        raise SqlQueryRejected(f"Se rechaza el esquema de sistema «{name}».", "system_object")


def _is_letter(ch: str) -> bool:
    return ch == "_" or unicodedata.category(ch).startswith("L")


def _is_word_char(ch: str) -> bool:
    return ch in "_$" or unicodedata.category(ch)[0] in ("L", "N")


_NUMBER = re.compile(r"(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?")
_PARAM = re.compile(r":([A-Za-z_][A-Za-z0-9_]*)")


def _tokenize(sql: str, dialect: str) -> list[Token]:
    mysqlish = dialect in ("mysql",)
    tokens: list[Token] = []
    n = len(sql)
    i = 0
    while i < n:
        c = sql[i]
        nxt = sql[i + 1] if i + 1 < n else ""
        if c in _WS:
            j = i
            while j < n and sql[j] in _WS:
                j += 1
            tokens.append(Token("ws", sql[i:j]))
            i = j
        elif c == "-" and nxt == "-":
            j = i
            while j < n and sql[j] not in "\n\r":
                j += 1
            tokens.append(Token("comment", sql[i:j]))
            i = j
        elif c == "#" and mysqlish:
            j = i
            while j < n and sql[j] not in "\n\r":
                j += 1
            tokens.append(Token("comment", sql[i:j]))
            i = j
        elif c == "/" and nxt == "*":
            if (sql[i + 2 : i + 3]) == "!" or sql[i + 2 : i + 4] == "M!":
                raise SqlQueryRejected("No se permiten comentarios ejecutables (/*! ... */).", "executable_comment")
            j = i + 2
            depth = 1
            nested = dialect in ("pgsql", "sqlsrv")  # PostgreSQL y SQL Server anidan comentarios de bloque; MySQL y SQLite no
            while j < n and depth > 0:
                if sql[j] == "*" and sql[j + 1 : j + 2] == "/":
                    depth -= 1
                    j += 2
                elif nested and sql[j] == "/" and sql[j + 1 : j + 2] == "*":
                    depth += 1
                    j += 2
                else:
                    j += 1
            if depth > 0:
                raise SqlQueryRejected("Comentario de bloque sin cerrar.", "unterminated")
            tokens.append(Token("comment", sql[i:j]))
            i = j
        elif c == "'":
            j = _skip_quoted(sql, i, "'")
            tokens.append(Token("string", sql[i:j]))
            i = j
        elif c == '"':
            j = _skip_quoted(sql, i, '"')
            tokens.append(Token("ident", sql[i:j], sql[i + 1 : j - 1]))
            i = j
        elif c == "`" and mysqlish:
            j = _skip_quoted(sql, i, "`")
            tokens.append(Token("ident", sql[i:j], sql[i + 1 : j - 1]))
            i = j
        elif c == "[" and dialect == "sqlsrv":
            j = i + 1
            while j < n:
                if sql[j] == "]":
                    if sql[j + 1 : j + 2] == "]":
                        j += 2
                        continue
                    break
                j += 1
            if j >= n:
                raise SqlQueryRejected("Identificador entre corchetes sin cerrar.", "unterminated")
            tokens.append(Token("ident", sql[i : j + 1], sql[i + 1 : j]))
            i = j + 1
        elif c in "0123456789" or (c == "." and nxt != "" and nxt in "0123456789"):
            m = _NUMBER.match(sql, i)
            assert m is not None
            tokens.append(Token("number", m.group(0)))
            i += len(m.group(0))
        elif _is_letter(c):
            j = i + 1
            while j < n and _is_word_char(sql[j]):
                j += 1
            tokens.append(Token("word", sql[i:j]))
            i = j
        elif c == ":":
            if nxt == ":":
                tokens.append(Token("symbol", "::"))
                i += 2
            else:
                m = _PARAM.match(sql, i)
                if m is None:
                    raise SqlQueryRejected('Uso no permitido de ":" en la consulta.', "invalid_character")
                tokens.append(Token("param", m.group(0), m.group(1)))
                i += len(m.group(0))
        elif c in _SYMBOLS:
            tokens.append(Token("symbol", c))
            i += 1
        else:
            raise SqlQueryRejected(f"Carácter no permitido en la consulta: «{c}».", "invalid_character")
    return tokens


def _skip_quoted(sql: str, start: int, quote: str) -> int:
    n = len(sql)
    j = start + 1
    while j < n:
        if sql[j] == quote:
            if sql[j + 1 : j + 2] == quote:
                j += 2
                continue
            return j + 1
        j += 1
    raise SqlQueryRejected("Comillas sin cerrar en la consulta.", "unterminated")
