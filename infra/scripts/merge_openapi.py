#!/usr/bin/env python3
"""Une docs/openapi/core.yaml y docs/openapi/analytics.yaml en docs/openapi/predictily-api.yaml.

Las dos partes se mantienen por separado (cada una junto a su módulo del backend);
este script genera la especificación única de la API pública. Falla si un mismo
nombre de ruta o de componente aparece en ambas con contenido distinto.

Uso: python infra/scripts/merge_openapi.py   (requiere PyYAML)
"""
from __future__ import annotations

import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "docs" / "openapi"


def main() -> int:
    core = yaml.safe_load((SRC / "core.yaml").read_text(encoding="utf-8"))
    analytics = yaml.safe_load((SRC / "analytics.yaml").read_text(encoding="utf-8"))
    conflicts: list[str] = []

    merged = dict(core)
    merged["info"] = {
        **core["info"],
        "title": "Predictily API pública v1",
        "description": core["info"].get("description", "").split("Las rutas analíticas")[0].rstrip()
        + "\n\nIncluye las rutas del núcleo y las analíticas (pronósticos, dashboard, precisión, "
        "inventario, alertas, escenarios, exportaciones). Archivo generado por "
        "`infra/scripts/merge_openapi.py`; editar `core.yaml` o `analytics.yaml`.\n",
    }
    tag_names = {t["name"] for t in merged.get("tags", [])}
    merged["tags"] = merged.get("tags", []) + [t for t in analytics.get("tags", []) if t["name"] not in tag_names]

    paths = dict(core.get("paths", {}))
    for path, item in analytics.get("paths", {}).items():
        if path in paths and paths[path] != item:
            conflicts.append(f"ruta {path}")
        paths[path] = item
    merged["paths"] = dict(sorted(paths.items()))

    components = {k: dict(v) for k, v in core.get("components", {}).items()}
    for section, entries in analytics.get("components", {}).items():
        target = components.setdefault(section, {})
        for name, value in entries.items():
            if name in target and target[name] != value:
                conflicts.append(f"components.{section}.{name}")
            target.setdefault(name, value)
    merged["components"] = components

    if conflicts:
        print("Conflictos entre core.yaml y analytics.yaml:\n  " + "\n  ".join(conflicts), file=sys.stderr)
        return 1

    out = SRC / "predictily-api.yaml"
    out.write_text(yaml.safe_dump(merged, allow_unicode=True, sort_keys=False, width=110), encoding="utf-8")
    print(f"{out.relative_to(ROOT)}: {len(merged['paths'])} rutas, "
          f"{sum(len(v) for v in components.values())} componentes")
    return 0


if __name__ == "__main__":
    sys.exit(main())
