"""Compara dos volcados de `demo_parity.py` (antes/después). Salida 0 si TODO es idéntico (exacto, columna por columna), 1 si hay diferencias.

    engine/.venv/bin/python engine/tests/tools/compare_demo_parity.py /tmp/parity_base.pkl /tmp/parity_cur.pkl
"""

from __future__ import annotations

import json
import pickle
import sys

import pandas as pd


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    with open(sys.argv[1], "rb") as handle:
        before = pickle.load(handle)
    with open(sys.argv[2], "rb") as handle:
        after = pickle.load(handle)
    identical = True
    for name in before:
        x, y = before[name], after[name]
        if isinstance(x, pd.DataFrame):
            try:
                pd.testing.assert_frame_equal(x, y, check_exact=True)
                same = True
            except AssertionError as exc:
                same = False
                print(name, "DIFERENCIA", str(exc)[:300])
            print(f"{name:28s} filas={len(x):7d} columnas={x.shape[1]:3d} idéntico={same}")
        else:
            if name == "model_versions":      # la respuesta de la corrida trae su duración (tiempo de reloj): lo único que puede variar
                def strip(text: str) -> dict:
                    data = json.loads(text)
                    data.pop("duration_ms", None)
                    (data.get("summary") or {}).pop("duration_ms", None)
                    return data

                x, y = strip(x), strip(y)
            same = x == y
            print(f"{name:28s} idéntico={same}")
        identical = identical and bool(same)
    print("TODO IDÉNTICO" if identical else "HAY DIFERENCIAS")
    return 0 if identical else 1


if __name__ == "__main__":
    sys.exit(main())
