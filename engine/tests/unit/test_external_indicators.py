import pandas as pd

from predictily_engine.features.external import as_of_series, as_of_value

OBS = pd.DataFrame({
    "indicator_id": [1, 1, 1, 1],
    "period_date": pd.to_datetime(["2026-08-01", "2026-09-01", "2026-09-01", "2026-10-01"]),
    "value": [100.0, 101.0, 101.5, 102.0],
    "published_at": pd.to_datetime(["2026-09-08", "2026-10-08", "2026-10-20", "2026-11-08"]),
    "revision": [0, 0, 1, 0],
})


def test_as_of_respects_publication_date():
    # El IPC de septiembre se publica el 8 de octubre: el 5 de octubre solo se conoce agosto.
    assert as_of_value(OBS, pd.Timestamp("2026-10-05")) == 100.0
    assert as_of_value(OBS, pd.Timestamp("2026-10-08")) == 101.0
    # La revisión se usa solo desde su publicación.
    assert as_of_value(OBS, pd.Timestamp("2026-10-19")) == 101.0
    assert as_of_value(OBS, pd.Timestamp("2026-10-21")) == 101.5
    assert as_of_value(OBS, pd.Timestamp("2026-09-01")) is None


def test_as_of_series_has_no_future_information():
    days = pd.date_range("2026-09-01", "2026-11-10")
    s = as_of_series(OBS, days)
    assert pd.isna(s.loc["2026-09-07"])
    assert s.loc["2026-09-08"] == 100.0
    assert s.loc["2026-10-07"] == 100.0
    assert s.loc["2026-10-25"] == 101.5
    assert s.loc["2026-11-09"] == 102.0
