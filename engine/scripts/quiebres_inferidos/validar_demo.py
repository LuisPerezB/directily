"""¿Acierta la inferencia de quiebres? Validación con las empresas demo (sintéticas), donde el inventario y la demanda real se conocen.

Se arma el panel dos veces: con el inventario (verdad: `stockout` del panel) y SIN inventario con la inferencia encendida. Se compara día
por día. Precisión = de los días marcados, cuántos eran quiebre real; cobertura = de los días de quiebre real en cero, cuántos se marcan.
Uso: python validar_demo.py <slug>... [clave=valor de `stockout_inference_*` ...]"""

import sys
import warnings
from dataclasses import replace

import numpy as np
import pandas as pd

from predictily_engine.pipeline.data import tenant_data_from_synthetic
from predictily_engine.profiling.days import DayRules
from predictily_engine.profiling.series import build_panel
from predictily_engine.synthetic import generate_company

warnings.filterwarnings("ignore")
BANDS = [(0, 1, "< 1"), (1, 5, "1–5"), (5, 10, "5–10"), (10, 50, "10–50"), (50, 1e9, "≥ 50")]


def main(slug: str, overrides: dict) -> None:
    company = generate_company(slug, seed=42)
    data = tenant_data_from_synthetic(company)
    truth = build_panel(data, DayRules.from_config({})).df
    cfg = {"stockout_inference": True, **overrides}
    test = build_panel(replace(data, inventory=data.inventory.iloc[0:0]), DayRules.from_config(cfg))
    df = test.df[["unique_id", "ds", "units", "stockout", "day_status", "y"]].merge(
        truth[["unique_id", "ds", "stockout", "y"]].rename(columns={"stockout": "real", "y": "y_truth"}), on=["unique_id", "ds"]
    )
    df["flag"] = df["stockout"].astype(bool)
    df["real_zero"] = df["real"].astype(bool) & (df["units"] == 0)
    mu = df.groupby("unique_id")["units"].transform("mean")
    df["band"] = pd.cut(mu, [b[0] for b in BANDS] + [1e9], labels=[b[2] for b in BANDS], right=False)
    s = test.day_summary["stockout_inference"]
    print(f"\n=== {slug}: {df['unique_id'].nunique()} series, {len(df):,} días-serie | reglas {s['rules']}")
    print(
        f"    marcados {s['series_days']:,} días en {s['series']} series; siguen en quiebre al corte {s['open_at_cutoff_series']}; "
        f"descartados por racha larga {s['long_run_series_days']:,}, por caída de la sucursal {s['branch_drop_series_days']:,}"
    )
    print("    tramo (venta/día)  series  quiebre real en cero  marcados  precisión  cobertura")
    for band in [b[2] for b in BANDS] + ["TODAS"]:
        g = df if band == "TODAS" else df[df["band"] == band]
        if g.empty:
            continue
        hit = int((g["flag"] & g["real_zero"]).sum())
        flagged, real = int(g["flag"].sum()), int(g["real_zero"].sum())
        precision = f"{hit / flagged:.0%}" if flagged else "—"
        recall = f"{hit / real:.0%}" if real else "—"
        print(f"    {band:17s} {g['unique_id'].nunique():6d} {real:21,d} {flagged:9,d} {precision:>10s} {recall:>10s}")
    wrong = df[df["flag"] & ~df["real_zero"]]
    if len(wrong):
        last_sale = df[df["units"] > 0].groupby("unique_id")["ds"].max()
        never_back = wrong["ds"] > wrong["unique_id"].map(last_sale)
        print(f"    marcados sin quiebre real: {len(wrong):,} ({int(never_back.sum()):,} son productos que no volvieron a venderse)")
    # nivel imputado frente al que da el inventario real, en los días de quiebre real en cero
    z = df[df["real_zero"]]
    if len(z):
        base = float(np.abs(z["units"] - z["y_truth"]).sum())
        print(
            f"    en los días de quiebre real en cero, el entrenamiento ve {float(z['y'].sum()):,.0f} unidades "
            f"(con inventario: {float(z['y_truth'].sum()):,.0f}; sin inferencia: 0) → recupera {1 - float(np.abs(z['y'] - z['y_truth']).sum()) / base:.0%} del ajuste"
        )


if __name__ == "__main__":
    args = sys.argv[1:]
    over = {k: (float(v) if "." in v else int(v)) for k, v in (a.split("=") for a in args if "=" in a)}
    for name in [a for a in args if "=" not in a]:
        main(name, over)
