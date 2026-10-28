"""Régimen regional de Ecuador (afecta décimo cuarto sueldo y calendario escolar)."""

from __future__ import annotations

import unicodedata

_COSTA = {
    "el oro", "esmeraldas", "guayas", "los rios", "manabi", "santa elena",
    "santo domingo de los tsachilas", "santo domingo", "galapagos",
}
_SIERRA = {
    "azuay", "bolivar", "canar", "carchi", "chimborazo", "cotopaxi", "imbabura",
    "loja", "pichincha", "tungurahua",
    # Amazonía comparte régimen con la Sierra (décimo cuarto en agosto, clases en septiembre).
    "morona santiago", "napo", "orellana", "pastaza", "sucumbios", "zamora chinchipe",
}


def _normalize(text: str) -> str:
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    return " ".join(text.lower().replace("-", " ").split())


def region_for_province(province: str | None) -> str | None:
    """Devuelve 'costa', 'sierra' o None si la provincia es desconocida.

    Galápagos se agrupa con la Costa y la Amazonía con la Sierra, según los regímenes
    laboral (décimo cuarto) y educativo vigentes.
    """
    if not province:
        return None
    key = _normalize(province)
    if key in _COSTA:
        return "costa"
    if key in _SIERRA:
        return "sierra"
    return None
