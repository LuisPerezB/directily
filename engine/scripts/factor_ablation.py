"""Ablation de factores (W6-E · V2.5 §26 T12 · herramienta P2): backtest con y sin cada factor, diferencias por horizonte y segmento.

Corre el backtest rolling-origin as-of del LightGBM SIN factores y CON cada factor candidato (y con su combinación), solo con las ventanas de
selección, entrenado COMO SE SIRVE y con control PLACEBO (cada factor debe superar al mejor de K placebos con semilla; una combinación solo se promueve si cada
miembro pasó solo), y entrega las diferencias de métricas por horizonte y segmento con los denominadores de W3-A. SOLO LECTURA: no escribe ninguna tabla
(tampoco `factor_evaluations`; el informe trae `evaluation_rows` con esa forma para que un proceso autorizado las registre) y NUNCA toca el
período final reservado. Detalle y reglas: docstring de `predictily_engine/backtest/factor_ablation.py`.

Uso (desde engine/, con el venv):

    # 1) Datos FICTICIOS (sin base de datos): una empresa sintética con campañas anunciadas por anticipado (efecto sembrado) y un «clima» de ruido.
    .venv/bin/python scripts/factor_ablation.py --fictional --out /tmp/ablation.json --markdown /tmp/ablation.md

    # 2) Una BASE DE PRUEBAS (solo lectura; el nombre debe contener «test»): factores activados por la empresa o los indicados.
    ENGINE_TEST_DATABASE_URL=postgresql+psycopg://<usuario>:<clave>@127.0.0.1:5432/<base_de_pruebas> \\
        .venv/bin/python scripts/factor_ablation.py --tenant-id 12 --factors weather.observed,calendar_events.campana_ficticia

La URL NO se toma del código ni se imprime: se lee de `ENGINE_TEST_DATABASE_URL` (o `--database-url-env <variable>`). Una base de producción se rechaza
por nombre (URL analizada con `make_url`, «test» como palabra, sin `dbname`/`host`/`options` en la consulta, `current_database()` comprobado tras conectar) y, además, cada transacción abre con `SET TRANSACTION READ ONLY`. Para ensayar con datos de un piloto, restaure una COPIA en una base
cuyo nombre contenga «test» (documentado en docs/intelligence/FACTOR_PIPELINE.md).
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import warnings
from datetime import date
from pathlib import Path

warnings.filterwarnings("ignore")
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Ablation de factores (solo lectura; datos ficticios o una base de pruebas).")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--fictional", action="store_true", help="datos FICTICIOS en memoria (sin base de datos)")
    source.add_argument("--tenant-id", type=int, help="empresa de una base de PRUEBAS (solo lectura)")
    parser.add_argument("--database-url-env", default="ENGINE_TEST_DATABASE_URL", help="variable de entorno con la URL de la base de pruebas")
    parser.add_argument("--factors", help="claves de factor candidatas separadas por coma (por defecto, las que la empresa activó / las de la demo)")
    parser.add_argument("--cutoff", help="corte AAAA-MM-DD (por defecto, la última venta)")
    parser.add_argument("--max-windows", type=int, default=3, help="ventanas de selección máximas (3 por defecto)")
    parser.add_argument("--no-combination", action="store_true", help="no evaluar todos los factores juntos")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--n-jobs", type=int, default=2)
    parser.add_argument("--out", help="archivo JSON de salida (por defecto, la salida estándar)")
    parser.add_argument("--markdown", help="archivo Markdown con el resumen legible")
    args = parser.parse_args(argv)

    from predictily_engine.backtest import factor_ablation as ablation

    keys = [k.strip() for k in args.factors.split(",") if k.strip()] if args.factors else None
    if args.fictional:
        from predictily_engine.synthetic.factors import fictional_factor_demo

        demo = fictional_factor_demo(seed=args.seed)
        data, label = demo.data, {"source": "fictional", "note": demo.note, "campaigns": int(len(demo.campaigns))}
    else:
        url = os.environ.get(args.database_url_env, "")
        if not url:
            print(f"error: defina {args.database_url_env} con la URL de una base de PRUEBAS.", file=sys.stderr)
            return 2
        try:
            data = ablation.load_from_database(url, args.tenant_id, cutoff=date.fromisoformat(args.cutoff) if args.cutoff else None, factors=keys)
        except (PermissionError, ValueError) as exc:
            print(f"error: {exc}", file=sys.stderr)
            return 3
        label = {"source": "database", "database": ablation.database_name(url), "tenant_id": args.tenant_id}
    try:
        report = ablation.run_ablation(data, candidates=keys, combinations=not args.no_combination, max_windows=args.max_windows, seed=args.seed,
                                       n_jobs=args.n_jobs, label=label)
    except ValueError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 3
    text = json.dumps(report, indent=2, ensure_ascii=False, default=str)
    if args.out:
        Path(args.out).write_text(text + "\n", encoding="utf-8")
    else:
        print(text)
    if args.markdown:
        Path(args.markdown).write_text(ablation.render_markdown(report), encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
