"""Minimización de lo que sale hacia el modelo.

`mask_pii` replica el criterio de `App\\Support\\Privacy\\PiiMasker` del backend (correos, cédula/RUC, tokens) como
defensa en profundidad: la API ya no devuelve esos datos, pero lo que llega a un LLM elegido por la persona no debe
depender de eso. `shape_of` y `profile_rows` permiten validar un mapeo sin enviar NINGÚN valor del origen al modelo.
"""

import re
from collections import Counter
from collections.abc import Sequence
from typing import Any

REDACTED = "[REDACTED]"

# Claves cuyo valor nunca se entrega (mismas de PiiMasker::SENSITIVE_KEYS, más las de conexión).
SENSITIVE_KEYS = frozenset({
    "password", "password_confirmation", "current_password", "token", "access_token", "api_key", "secret", "credentials",
    "authorization", "cookie", "temporary_password", "tax_id", "cedula", "ruc", "name_encrypted", "credentials_encrypted",
    "remember_token", "otp", "totp", "totp_code", "mfa_code", "recovery_code", "recovery_codes", "mfa_secret",
    "mfa_secret_encrypted", "mfa_recovery_codes", "challenge", "otpauth_uri", "username",
})  # fmt: skip

# Identificadores de negocio que pueden tener 10 o 13 dígitos (un EAN-13 como SKU) sin ser una cédula ni un RUC.
_IDENTIFIER_KEYS = frozenset({
    "sku", "product_sku", "code", "branch_code", "supplier_code", "category_code", "document_number", "external_id",
    "barcode", "ean", "query", "sql",
})  # fmt: skip
_HASH_KEY = re.compile(r"sha|hash|fingerprint|snapshot|checksum", re.IGNORECASE)

_EMAIL = re.compile(r"([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9])[A-Za-z0-9\-]*((?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})")
_BEARER = re.compile(r"\bBearer\s+[A-Za-z0-9|._\-]+", re.IGNORECASE)
_SANCTUM = re.compile(r"\b\d+\|[A-Za-z0-9]{20,}\b")
_NATIONAL_ID = re.compile(r"(?<!\d)(\d{13}|\d{10})(?!\d)")
_LONG_HEX = re.compile(r"\b[A-Fa-f0-9]{40,}\b")


def mask_text(text: str, *, key: str | None = None) -> str:
    text = _EMAIL.sub(lambda m: f"{m.group(1)}***@{m.group(2)}***{m.group(3)}", text)
    text = _BEARER.sub("Bearer [TOKEN]", text)
    text = _SANCTUM.sub("[TOKEN]", text)
    lowered = (key or "").lower()
    if lowered not in _IDENTIFIER_KEYS:
        text = _NATIONAL_ID.sub("[ID]", text)
    if not _HASH_KEY.search(lowered):
        text = _LONG_HEX.sub("[TOKEN]", text)
    return text


def mask_pii(value: Any, *, key: str | None = None, depth: int = 0) -> Any:
    """Copia de `value` sin claves sensibles y con el texto enmascarado. Los números no se tocan."""
    if depth > 12:
        return "[…]"
    if isinstance(value, dict):
        return {
            k: REDACTED if isinstance(k, str) and k.lower() in SENSITIVE_KEYS and v is not None else mask_pii(v, key=str(k), depth=depth + 1)
            for k, v in value.items()
        }
    if isinstance(value, (list, tuple)):
        return [mask_pii(item, key=key, depth=depth + 1) for item in value]
    if isinstance(value, str):
        return mask_text(value, key=key)
    return value


def shape_of(value: Any) -> str:
    """Forma de un valor sin su contenido: letras → A, dígitos → 9, la puntuación se conserva («2026-01-31» → «9999-99-99»)."""
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "booleano"
    if isinstance(value, (int, float)):
        return "número"
    text = str(value)
    if text == "":
        return "vacío"
    shape = re.sub(r"[^\W\d_]", "A", text)
    shape = re.sub(r"\d", "9", shape)
    shape = re.sub(r"A{7,}|9{7,}", lambda m: f"{m.group(0)[0]}{{{len(m.group(0))}}}", shape)
    return shape[:40]


def profile_rows(headers: Sequence[str], rows: Sequence[Sequence[Any]], *, top: int = 3) -> list[dict[str, Any]]:
    """Perfil por columna de unas filas de muestra: cuántos valores hay y qué FORMAS tienen. Ningún valor sale."""
    profile = []
    for index, header in enumerate(headers):
        values = [row[index] if index < len(row) else None for row in rows]
        present = [v for v in values if v is not None and v != ""]
        shapes = Counter(shape_of(v) for v in present)
        profile.append({
            "column": header,
            "non_null": len(present),
            "null_or_empty": len(values) - len(present),
            "shapes": [{"shape": shape, "count": count} for shape, count in shapes.most_common(top)],
        })  # fmt: skip
    return profile


def profile_records(records: Sequence[dict[str, Any]], *, top: int = 3) -> list[dict[str, Any]]:
    """Igual que `profile_rows` para una lista de objetos (vista previa normalizada)."""
    headers: list[str] = []
    for record in records:
        for key in record:
            if key not in headers:
                headers.append(key)
    return profile_rows(headers, [[record.get(h) for h in headers] for record in records], top=top)
