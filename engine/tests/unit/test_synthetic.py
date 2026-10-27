import hashlib

from predictily_engine.synthetic import generate_company
from predictily_engine.synthetic.generator import SALES_COLUMNS


def test_generator_is_reproducible_and_realistic():
    a = generate_company("distribuidora_costa", seed=7, n_products=6, start="2026-01-01")
    b = generate_company("distribuidora_costa", seed=7, n_products=6, start="2026-01-01")
    h = lambda c: hashlib.sha256(c.sales.to_csv(index=False).encode()).hexdigest()  # noqa: E731
    assert h(a) == h(b)
    assert list(a.sales.columns) == SALES_COLUMNS
    assert set(a.sales["document_type"]) == {"factura", "nota de crédito"}
    assert (a.sales["status"] == "anulada").any()
    assert (a.true_demand["on_hand"] <= 0).any(), "debe haber quiebres de stock"
    assert (a.true_demand["demand"] >= a.true_demand["sold"]).all()
    assert a.sales["document_number"].str.match(r"^\d{3}-\d{3}-\d{9}$").all()
    issues = set(a.sales_with_errors["nota_calidad"]) - {""}
    assert {"invalid_date", "duplicate_in_file", "unknown_branch", "currency_mismatch"} <= issues


def test_different_seed_changes_data():
    a = generate_company("distribuidora_costa", seed=1, n_products=4, start="2026-06-01")
    b = generate_company("distribuidora_costa", seed=2, n_products=4, start="2026-06-01")
    assert not a.sales.equals(b.sales)
