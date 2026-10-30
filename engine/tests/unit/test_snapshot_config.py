"""Huella de datos completa (ADR-V2-04) y configuración efectiva (ADR-V2-06) de la demanda."""

from dataclasses import replace

import pandas as pd
import pytest

from predictily_engine.demand.snapshot import build_snapshot, eligibility_rules
from predictily_engine.platform.config import config_hash, resolve_config
from predictily_engine.profiling.series import build_panel

EXPECTED = {"sales", "returns", "inventory", "prices", "costs", "catalog", "promotions", "calendar",
            "external_indicators", "supply_inputs", "config", "eligibility", "coverage"}


@pytest.fixture(scope="module")
def base(small_data):
    panel = build_panel(small_data)
    cfg = {"core": "demand", "config": resolve_config("demand", {}, None)[0], "run": {"seed": 42}}
    snap = build_snapshot(small_data, panel, cfg, pd.Timestamp(small_data.cutoff), None, {})
    return panel, cfg, snap


def _changed(a, b):
    return {k for k in a.components if a.components[k] != b.components[k]}


def test_snapshot_covers_every_input_that_can_change_a_cutoff(base, pipeline_result):
    _, _, snap = base
    assert set(snap.components) == EXPECTED and snap.core == "demand"
    assert pipeline_result.snapshot.hash == snap.hash or set(pipeline_result.snapshot.components) == EXPECTED
    assert snap.row_counts["sales"] > 0 and snap.row_counts["inventory"] > 0


def test_each_change_alters_only_its_own_component(small_data, base):
    panel, cfg, snap = base
    kn = pd.Timestamp(small_data.cutoff)

    def rebuild(data=small_data, pnl=panel, config=cfg, events=None):
        return build_snapshot(data, pnl, config, kn, events, {})

    assert rebuild().hash == snap.hash, "determinista"

    sales = small_data.sales.copy()
    sales.loc[sales.index[0], "units"] += 1
    assert "sales" in _changed(snap, rebuild(replace(small_data, sales=sales)))

    inv = small_data.inventory.copy()
    inv.loc[inv.index[0], "on_hand"] += 5
    assert _changed(snap, rebuild(replace(small_data, inventory=inv))) == {"inventory"}

    promos = small_data.promotions.copy()
    promos.loc[promos.index[0], "known_at"] = pd.Timestamp("2020-01-01")
    assert _changed(snap, rebuild(replace(small_data, promotions=promos))) == {"promotions"}, \
        "una promoción conocida antes o después cambia la huella aunque sus ventas no cambien"

    costs = small_data.sales.copy()
    costs.loc[costs.index[0], "cost_amount"] += 3
    assert _changed(snap, rebuild(replace(small_data, sales=costs))) == {"costs"}

    products = small_data.products.copy()
    products.loc[products.index[0], "is_active"] = False
    assert _changed(snap, rebuild(replace(small_data, products=products))) == {"catalog"}

    events = pd.DataFrame([{"code": "PARO", "scope": "country", "scope_ref": "EC", "starts_on": pd.Timestamp("2026-11-01"),
                            "ends_on": pd.Timestamp("2026-11-02"), "known_at": pd.Timestamp("2026-10-20"), "revision": 0}])
    assert _changed(snap, rebuild(events=events)) == {"calendar"}

    other_cfg = {**cfg, "run": {"seed": 43}}
    assert _changed(snap, rebuild(config=other_cfg)) == {"config"}

    price_panel = type(panel)(panel.df.assign(price=panel.df["price"] * 1.01), panel.profile, panel.cutoff)
    assert _changed(snap, rebuild(pnl=price_panel)) == {"prices"}

    leads = small_data.lead_times.copy()
    leads.loc[leads.index[0], "lead_time_days"] += 1
    assert _changed(snap, rebuild(replace(small_data, lead_times=leads))) == {"supply_inputs"}


def test_eligibility_rules_are_part_of_the_snapshot(small_data, base, monkeypatch):
    panel, cfg, snap = base
    rules = eligibility_rules()
    assert {"adi_threshold", "cv2_threshold", "min_history_days", "inactive_after_days", "backtest_tiers"} <= set(rules)
    import predictily_engine.demand.snapshot as mod

    monkeypatch.setattr(mod, "MIN_HISTORY_DAYS", 35)
    changed = build_snapshot(small_data, panel, cfg, pd.Timestamp(small_data.cutoff), None, {})
    assert _changed(snap, changed) == {"eligibility"}


def test_reproducibility_same_snapshot_config_and_seed_gives_same_values(small_data, pipeline_result):
    """El mismo resultado se verifica con la corrida repetida de test_pipeline.test_reproducible_with_same_seed;
    aquí, que la huella es estable ante el orden de filas y no depende del momento de ejecución."""
    shuffled = replace(small_data, sales=small_data.sales.sample(frac=1.0, random_state=1).reset_index(drop=True))
    panel = build_panel(small_data)
    cfg = pipeline_result.effective_config
    a = build_snapshot(small_data, panel, cfg, pd.Timestamp(small_data.cutoff), None, {})
    b = build_snapshot(shuffled, panel, cfg, pd.Timestamp(small_data.cutoff), None, {})
    assert a.components["sales"] == b.components["sales"]


def test_effective_config_follows_core_profile_tenant_precedence_and_has_a_hash():
    core, v0, src0 = resolve_config("demand", {}, None)
    assert core["horizons"] == [7, 14, 30, 60, 90] and core["min_improvement"] == 0.03
    assert set(src0.values()) == {"core"}
    # Configuración de la empresa: claves de la etapa 1 y sección settings.cores.demand.
    tuned, v1, src1 = resolve_config("demand", {"service_level": 0.9, "cores": {"demand": {"min_improvement": 0.05}}}, None)
    assert tuned["service_level"] == 0.9 and tuned["min_improvement"] == 0.05
    assert src1["min_improvement"] == "tenant" and src1["service_level"] == "tenant" and src1["horizons"] == "core"
    assert v0 != v1 and v0 == resolve_config("demand", {}, None)[1], "la versión es el hash de la configuración efectiva"
    with pytest.raises(ValueError):
        resolve_config("demand", {"cores": {"demand": {"inventa_algo": 1}}}, None)
    assert config_hash({"a": 1}) == config_hash({"a": 1}) != config_hash({"a": 2})


def test_pipeline_records_run_options_inside_the_effective_config(pipeline_result):
    eff = pipeline_result.effective_config
    assert eff["config"]["horizons"] == [7, 14, 30, 60, 90]
    run = eff["run"]
    assert run["seed"] == 42 and run["min_improvement"] == 0.03 and run["horizon_days"] == 90
    assert run["horizons"] == [7, 14, 30, 60, 90] and run["feature_set_version"].startswith("fs-")
    assert pipeline_result.config_version == config_hash(eff)
    assert pipeline_result.summary["config_version"] == pipeline_result.config_version
    assert pipeline_result.summary["data_snapshot_hash"] == pipeline_result.snapshot.hash
