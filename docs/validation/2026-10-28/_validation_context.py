"""Contexto portable para repetir la validación sin modificar sus resultados históricos."""
from __future__ import annotations

import argparse
import os
import sys
import tempfile
from pathlib import Path


def validation_context() -> tuple[Path, Path]:
    parser = argparse.ArgumentParser(
        description="Repite una comprobación histórica de Predictily; no conecta una BD.")
    parser.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[3])
    parser.add_argument("--output-dir", type=Path)
    args = parser.parse_args()
    source = args.repo_root.resolve() / "engine"
    if not (source / "predictily_engine" / "__init__.py").is_file():
        parser.error("No se encontró el motor; indique --repo-root con la raíz del repositorio.")
    output = args.output_dir.resolve() if args.output_dir else Path(
        tempfile.mkdtemp(prefix="predictily-validation-"))
    if output == Path(__file__).resolve().parent:
        parser.error("Use otra carpeta de salida para conservar la evidencia histórica.")
    for filename in ("unit-results.xml", "probe-results.json", "benchmark-results.json"):
        if (output / filename).exists():
            parser.error(f"Ya existe {filename} en la salida; elija una carpeta nueva.")
    output.mkdir(parents=True, exist_ok=True)
    (output / "tmp").mkdir(exist_ok=True)
    sys.dont_write_bytecode = True
    sys.path.insert(0, str(source))
    tempfile.tempdir = str(output / "tmp")
    os.environ["NUMBA_CACHE_DIR"] = str(output / "numba-cache")
    print(f"Motor: {source}\nSalida de esta ejecución: {output}", flush=True)
    return source, output
