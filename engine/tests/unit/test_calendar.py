from datetime import date

from predictily_engine.calendar import EcuadorCalendar, region_for_province, rest_days


def test_ecuador_holiday_transfers():
    d24, d25, d26 = rest_days(2024), rest_days(2025), rest_days(2026)
    # 10 de agosto 2024 (sábado) se traslada al viernes 9; el sábado deja de ser feriado.
    assert date(2024, 8, 9) in d24 and date(2024, 8, 10) not in d24
    # 9 de octubre 2024 (miércoles) → viernes 11.
    assert date(2024, 10, 11) in d24 and date(2024, 10, 9) not in d24
    # 1 de mayo 2025 (jueves) → viernes 2; 24 de mayo 2025 (sábado) → viernes 23.
    assert date(2025, 5, 2) in d25 and date(2025, 5, 1) not in d25
    assert date(2025, 5, 23) in d25
    # 10 de agosto 2025 (domingo) → lunes 11.
    assert date(2025, 8, 11) in d25
    # 24 de mayo 2026 (domingo) → lunes 25.
    assert date(2026, 5, 25) in d26


def test_new_year_and_christmas_are_not_moved_midweek():
    assert date(2025, 1, 1) in rest_days(2025)  # miércoles: excepción legal
    assert date(2024, 12, 25) in rest_days(2024)  # miércoles


def test_carnival_and_good_friday():
    d = rest_days(2026)
    assert date(2026, 2, 16) in d and date(2026, 2, 17) in d
    assert date(2026, 4, 3) in d


def test_local_holidays_by_canton():
    assert date(2025, 12, 5) in rest_days(2025, "Quito")  # 6-dic sábado → viernes 5
    assert date(2025, 12, 5) not in rest_days(2025, "Guayaquil")
    assert date(2025, 7, 25) in rest_days(2025, "Guayaquil")


def test_regions():
    assert region_for_province("Pichincha") == "sierra"
    assert region_for_province("Manabí") == "costa"
    assert region_for_province("galapagos") == "costa"
    assert region_for_province("Napo") == "sierra"
    assert region_for_province("Desconocida") is None


def test_regional_commercial_events():
    sierra = EcuadorCalendar(region="sierra").frame(date(2025, 1, 1), date(2025, 12, 31))
    costa = EcuadorCalendar(region="costa").frame(date(2025, 1, 1), date(2025, 12, 31))
    assert sierra.loc["2025-08-10", "is_decimo_cuarto"] == 1 and sierra.loc["2025-03-10", "is_decimo_cuarto"] == 0
    assert costa.loc["2025-03-10", "is_decimo_cuarto"] == 1 and costa.loc["2025-08-10", "is_decimo_cuarto"] == 0
    assert sierra.loc["2025-09-01", "is_school_season"] == 1 and costa.loc["2025-04-15", "is_school_season"] == 1
    assert sierra.loc["2025-12-20", "is_decimo_tercero"] == 1
    assert sierra.loc["2025-11-15", "is_payday_window"] == 1
    assert sierra.loc["2025-11-28", "is_black_friday_window"] == 1
    assert sierra.loc["2025-05-10", "is_mothers_day_window"] == 1  # Día de la Madre: 11-may-2025
    unknown = EcuadorCalendar(region=None).frame(date(2025, 1, 1), date(2025, 12, 31))
    assert unknown["is_decimo_cuarto"].sum() == 0
