"""R-179 · el único interruptor por empresa de una familia de factores (`demand.exceptional_events_flag`) y su estado APAGADO.

Con la bandera en falso no se marca ni se usa ningún evento extraordinario y los pronósticos son idénticos a los de una empresa
sin eventos; con la bandera encendida los valores son los mismos (el evento solo etiqueta y degrada la calidad: no cambia la cifra).
"""

from __future__ import annotations

from dataclasses import replace

import pandas as pd
import pytest

from predictily_engine.pipeline.core import run_pipeline
from predictily_engine.platform.config import effective_config_record

COLUMNS = ["node_key", "granularity", "horizon_step", "yhat", "lo_95", "hi_95", "case_label", "quality_status"]


def _event(scope, ref, code, start, end, known_at):
    return {"id": abs(hash(code)) % 1000, "tenant_id": None, "scope": scope, "scope_ref": ref, "code": code, "name": code,
            "category": "exceptional", "starts_on": start, "ends_on": end, "status": "confirmed", "known_at": known_at,
            "source": "manual", "revision": 0}


@pytest.fixture(scope="module")
def runs(small_data):
    cutoff, day = pd.Timestamp(small_data.cutoff), pd.Timedelta(days=1)
    events = pd.DataFrame([_event("country", "EC", "PARO", cutoff + 6 * day, cutoff + 10 * day, cutoff - 5 * day),
                           _event("branch", "branch:1", "VIA", cutoff + 14 * day, cutoff + 17 * day, cutoff - day)])
    with_events = replace(small_data, exceptional_events=events)

    def run(data, flag):
        settings = {"cores": {"demand": {"exceptional_events_flag": flag}}}
        record = effective_config_record("demand", settings, data.sector)
        return run_pipeline(data, horizon=30, seed=42, n_jobs=1, config_record=record), record

    return {"on": run(with_events, True), "off": run(with_events, False), "none": run(replace(small_data, exceptional_events=None), False)}


def _frame(result):
    fv = result.forecast_values
    return fv[fv["granularity"] == "day"][COLUMNS].sort_values(["node_key", "horizon_step"]).reset_index(drop=True)


def test_the_flag_state_is_part_of_the_effective_configuration(runs):
    (_, on_record), (_, off_record) = runs["on"], runs["off"]
    assert on_record["effective_config"]["exceptional_events_flag"] is True and off_record["effective_config"]["exceptional_events_flag"] is False
    assert off_record["provenance"]["exceptional_events_flag"] == "tenant" and on_record["config_version"] != off_record["config_version"]


def test_with_the_flag_on_known_events_label_and_degrade_the_affected_days(runs):
    result, _ = runs["on"]
    info = result.summary["special_cases"]["exceptional_events"]
    assert info["flag_enabled"] is True and {e["code"] for e in info["used"]} == {"PARO", "VIA"}
    assert (_frame(result)["case_label"] == "exceptional_event").any()


def test_with_the_flag_off_no_event_is_flagged_or_used(runs):
    result, _ = runs["off"]
    info = result.summary["special_cases"]["exceptional_events"]
    assert info["flag_enabled"] is False and info["used"] == []
    frame = _frame(result)
    assert not (frame["case_label"] == "exceptional_event").any()


def test_with_the_flag_off_the_forecast_equals_the_one_of_a_company_without_events(runs):
    """Apagado = como si los eventos no existieran: mismas cifras, etiquetas y calidad por día."""
    pd.testing.assert_frame_equal(_frame(runs["off"][0]), _frame(runs["none"][0]))


def test_the_flag_never_changes_the_numbers_only_labels_and_quality(runs):
    on, off = _frame(runs["on"][0]), _frame(runs["off"][0])
    pd.testing.assert_frame_equal(on[["node_key", "horizon_step", "yhat", "lo_95", "hi_95"]], off[["node_key", "horizon_step", "yhat", "lo_95", "hi_95"]])
    assert not on["quality_status"].equals(off["quality_status"]), "el evento sí degrada la calidad cuando la bandera está encendida"
