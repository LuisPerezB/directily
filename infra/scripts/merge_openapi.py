#!/usr/bin/env python3
"""Une docs/openapi/{core,analytics,v2}.yaml en docs/openapi/predictily-api.yaml, lo valida y lo contrasta con las rutas reales.

Las tres partes se mantienen por separado (cada una junto a su módulo del backend):
  - core.yaml       etapa 1 · núcleo (autenticación, empresas, catálogos, ventas, importaciones, fuentes, privacidad)
  - analytics.yaml  etapa 1 · pronósticos, dashboard, precisión, inventario, alertas, escenarios, exportaciones
  - v2.yaml         etapa 2 · Core de Datos, plataforma, Comportamiento de compra, Abastecimiento y asistente opcional

Este script genera la especificación única de la API pública. Falla (código 1) si:
  - la misma operación (ruta + método) o el mismo componente aparece en dos partes con contenido distinto;
  - dos rutas son equivalentes salvo el nombre de sus parámetros (p. ej. /x/{id} y /x/{export});
  - una referencia `$ref` interna no se resuelve;
  - la especificación fusionada no es OpenAPI válida (requiere openapi-spec-validator; con --strict su ausencia también falla);
  - con --routes, el conjunto de operaciones documentadas no coincide con las rutas reales de Laravel.

Uso:
  python infra/scripts/merge_openapi.py                       # genera predictily-api.yaml y valida
  python infra/scripts/merge_openapi.py --check               # no escribe: falla si predictily-api.yaml no está al día
  cd backend && php artisan route:list --path=api/v1 --json > /tmp/routes.json
  python infra/scripts/merge_openapi.py --routes /tmp/routes.json --check --strict   # contraste con las rutas reales (CI)

Requiere PyYAML; la validación usa openapi-spec-validator (pip install openapi-spec-validator).
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

import yaml

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "docs" / "openapi"
OUT = SRC / "predictily-api.yaml"
PARTS = ("core.yaml", "analytics.yaml", "v2.yaml")
METHODS = ("get", "put", "post", "delete", "patch", "options", "head", "trace")

# Rutas reales (MÉTODO, ruta con parámetros normalizados a {}) que se excluyen a propósito de la documentación, con el motivo.
# Hoy no hay ninguna: toda ruta bajo /api/v1 está documentada. Agregar aquí solo con una justificación escrita.
ROUTE_EXCLUSIONS: dict[tuple[str, str], str] = {}

INFO_SUFFIX = (
    "\n\nIncluye las rutas del núcleo, las analíticas (pronósticos, dashboard, precisión, inventario, alertas, escenarios, exportaciones) y las de la "
    "etapa 2 (Core de Datos, plataforma, Comportamiento de compra, Abastecimiento y asistente conversacional opcional). Archivo generado por "
    "`infra/scripts/merge_openapi.py`; editar `core.yaml`, `analytics.yaml` o `v2.yaml`.\n"
)


def load(name: str) -> dict[str, Any]:
    return yaml.safe_load((SRC / name).read_text(encoding="utf-8"))


def normalize(path: str) -> str:
    return re.sub(r"\{[^}]+\}", "{}", path)


def merge(parts: dict[str, dict[str, Any]]) -> tuple[dict[str, Any], list[str]]:
    conflicts: list[str] = []
    core = parts["core.yaml"]
    merged: dict[str, Any] = {k: v for k, v in core.items() if k in ("openapi", "servers", "security")}
    merged["info"] = {
        **core["info"],
        "title": "Predictily API pública v1",
        "description": core["info"].get("description", "").split("Las rutas analíticas")[0].rstrip() + INFO_SUFFIX,
    }

    # Etiquetas: unión conservando el orden de aparición.
    tags: list[dict[str, Any]] = []
    seen: set[str] = set()
    for name in PARTS:
        for tag in parts[name].get("tags", []):
            if tag["name"] not in seen:
                seen.add(tag["name"])
                tags.append(tag)
    merged["tags"] = tags

    # Rutas: se combinan por método; solo es conflicto la misma operación con contenido distinto.
    paths: dict[str, dict[str, Any]] = {}
    origin: dict[tuple[str, str], str] = {}
    for name in PARTS:
        for path, item in parts[name].get("paths", {}).items():
            target = paths.setdefault(path, {})
            for key, value in item.items():
                if key in target and target[key] != value:
                    conflicts.append(f"operación {key.upper()} {path} ({origin[(path, key)]} y {name})")
                    continue
                target[key] = value
                origin.setdefault((path, key), name)
    # Rutas equivalentes salvo el nombre del parámetro y con algún método repetido.
    by_shape: dict[str, list[str]] = {}
    for path in paths:
        by_shape.setdefault(normalize(path), []).append(path)
    for shape, group in by_shape.items():
        if len(group) > 1:
            methods = [m for p in group for m in paths[p] if m in METHODS]
            if len(methods) != len(set(methods)):
                conflicts.append(f"rutas equivalentes con métodos repetidos: {', '.join(group)}")
            else:
                conflicts.append(f"rutas equivalentes salvo el nombre del parámetro (unifíquelas): {', '.join(group)}")
    merged["paths"] = dict(sorted(paths.items()))

    # Componentes: por sección y nombre; solo es conflicto un mismo nombre con contenido distinto.
    components: dict[str, dict[str, Any]] = {}
    for name in PARTS:
        for section, entries in parts[name].get("components", {}).items():
            target = components.setdefault(section, {})
            for cname, value in entries.items():
                if cname in target and target[cname] != value:
                    conflicts.append(f"components.{section}.{cname} ({name})")
                    continue
                target.setdefault(cname, value)
    merged["components"] = components
    return merged, conflicts


def unresolved_refs(doc: dict[str, Any]) -> list[str]:
    missing: list[str] = []

    def walk(node: Any) -> None:
        if isinstance(node, dict):
            ref = node.get("$ref")
            if isinstance(ref, str) and ref.startswith("#/"):
                cur: Any = doc
                for part in ref[2:].split("/"):
                    if not isinstance(cur, dict) or part not in cur:
                        missing.append(ref)
                        break
                    cur = cur[part]
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    walk(doc)
    return sorted(set(missing))


def validate(doc: dict[str, Any], strict: bool) -> list[str]:
    try:
        from openapi_spec_validator import validate as osv_validate
    except ImportError:
        msg = "openapi-spec-validator no está instalado (pip install openapi-spec-validator)"
        if strict:
            return [msg]
        print(f"AVISO: {msg}; se omite la validación OpenAPI.", file=sys.stderr)
        return []
    try:
        osv_validate(doc)
    except Exception as exc:  # noqa: BLE001 - el validador lanza varias clases según el defecto
        return [f"{type(exc).__name__}: {str(exc)[:600]}"]
    return []


def documented_operations(doc: dict[str, Any]) -> set[tuple[str, str]]:
    return {(m.upper(), normalize(p)) for p, item in doc["paths"].items() for m in item if m in METHODS}


def real_operations(routes_file: str) -> set[tuple[str, str]]:
    raw = sys.stdin.read() if routes_file == "-" else Path(routes_file).read_text(encoding="utf-8")
    ops: set[tuple[str, str]] = set()
    for route in json.loads(raw):
        uri = route["uri"].strip("/")
        if not uri.startswith("api/v1"):
            continue
        path = "/" + uri[len("api/v1"):].lstrip("/")
        for method in str(route["method"]).split("|"):
            if method not in ("HEAD", "OPTIONS"):
                ops.add((method, normalize(path)))
    return ops


def compare_routes(doc: dict[str, Any], routes_file: str) -> tuple[list[str], str]:
    documented, real = documented_operations(doc), real_operations(routes_file)
    excluded = {op for op in real if op in ROUTE_EXCLUSIONS}
    missing = sorted(real - documented - excluded)
    extra = sorted(documented - real)
    summary = f"rutas reales: {len(real)} · documentadas: {len(documented)} · excluidas (justificadas): {len(excluded)}"
    problems = [f"ruta real sin documentar: {m} {p}" for m, p in missing] + [f"documentada pero no existe: {m} {p}" for m, p in extra]
    return problems, summary


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--check", action="store_true", help="no escribe: falla si predictily-api.yaml no coincide con lo generado")
    ap.add_argument("--strict", action="store_true", help="falla también si no está instalado openapi-spec-validator")
    ap.add_argument("--no-validate", action="store_true", help="omite la validación OpenAPI")
    ap.add_argument("--routes", metavar="ARCHIVO", help="salida de `php artisan route:list --path=api/v1 --json` ('-' = stdin) para contrastar rutas")
    args = ap.parse_args()

    parts = {name: load(name) for name in PARTS}
    merged, conflicts = merge(parts)
    errors = [f"conflicto: {c}" for c in conflicts]
    errors += [f"referencia sin resolver: {r}" for r in unresolved_refs(merged)]
    if not args.no_validate:
        errors += validate(merged, args.strict)
    summary = ""
    if args.routes:
        problems, summary = compare_routes(merged, args.routes)
        errors += problems

    text = yaml.safe_dump(merged, allow_unicode=True, sort_keys=False, width=110)
    if args.check:
        current = OUT.read_text(encoding="utf-8") if OUT.exists() else ""
        if current != text:
            errors.append(f"{OUT.relative_to(ROOT)} no está al día: ejecute `python infra/scripts/merge_openapi.py`")
    if errors:
        print("Errores:\n  " + "\n  ".join(errors), file=sys.stderr)
        return 1
    if not args.check:
        OUT.write_text(text, encoding="utf-8")
    n_comp = sum(len(v) for v in merged["components"].values())
    print(f"{OUT.relative_to(ROOT)}: {len(merged['paths'])} rutas, {len(documented_operations(merged))} operaciones, {n_comp} componentes"
          + (f" · {summary}" if summary else "") + (" · (verificado, sin escribir)" if args.check else ""))
    return 0


if __name__ == "__main__":
    sys.exit(main())
