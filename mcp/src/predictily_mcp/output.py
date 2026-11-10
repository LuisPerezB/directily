"""Forma de las respuestas hacia el modelo: JSON compacto, paginación uniforme y tamaño acotado."""

import copy
import json
from typing import Any

_PAGE_NOISE = ("links", "path", "from", "to", "current_page", "per_page", "total", "last_page")


def normalize(payload: Any) -> Any:
    """Convierte la paginación de Laravel (`meta.current_page`…) en un bloque `pagination` y quita los enlaces."""
    if not isinstance(payload, dict):
        return payload
    out = {k: v for k, v in payload.items() if k != "links"}
    meta = out.get("meta")
    if isinstance(meta, dict) and isinstance(meta.get("current_page"), int):
        page = meta["current_page"]
        last = meta.get("last_page") if isinstance(meta.get("last_page"), int) else page
        out["pagination"] = {
            "page": page,
            "per_page": meta.get("per_page"),
            "total": meta.get("total"),
            "last_page": last,
            "has_more": page < last,
            "next_page": page + 1 if page < last else None,
        }
        rest = {k: v for k, v in meta.items() if k not in _PAGE_NOISE}
        if rest:
            out["meta"] = rest
        else:
            out.pop("meta")
    return out


def _dumps(payload: Any) -> str:
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":"), default=str)


def _largest_list(node: Any, path: str = "") -> tuple[int, str, list[Any]] | None:
    """La lista (con más de un elemento) que más ocupa serializada, con su ruta."""
    best: tuple[int, str, list[Any]] | None = None
    if isinstance(node, list):
        if len(node) > 1:
            best = (len(_dumps(node)), path or "(raíz)", node)
        children = enumerate(node)
    elif isinstance(node, dict):
        children = node.items()
    else:
        return None
    for key, child in children:
        found = _largest_list(child, f"{path}.{key}" if path else str(key))
        # Entre una lista y otra contenida en ella gana la interior solo si es casi todo el tamaño (recortar la exterior quita más).
        if found and (best is None or found[0] > best[0]):
            best = found
    return best


def render(payload: Any, max_chars: int) -> str:
    """JSON de `payload`. Si excede `max_chars` recorta las listas más grandes y lo dice, con cómo pedir el resto."""
    text = _dumps(payload)
    if len(text) <= max_chars:
        return text

    work = copy.deepcopy(payload)
    if not isinstance(work, dict):
        work = {"data": work}
    trimmed: dict[str, dict[str, int]] = {}
    note = {
        "listas_recortadas": trimmed,
        "sugerencia": "El resultado excedía el tamaño máximo. Use filtros más específicos, un per_page menor o la página siguiente.",
    }
    for _ in range(60):
        work["_truncado"] = note
        text = _dumps(work)
        if len(text) <= max_chars:
            return text
        work.pop("_truncado")
        target = _largest_list(work)
        if target is None:
            break
        _, path, items = target
        keep = max(1, len(items) // 2)
        entry = trimmed.setdefault(path, {"mostrados": len(items), "omitidos": 0})
        entry["omitidos"] += len(items) - keep
        entry["mostrados"] = keep
        del items[keep:]

    # Sin listas que recortar (un objeto enorme): vista parcial declarada, no un JSON cortado a ciegas.
    return _dumps({"_truncado": note, "vista_parcial": _dumps(work)[: max(0, max_chars - 600)]})
