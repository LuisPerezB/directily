"""Evidencia observable para la verificación causal (lectura de una sola empresa)."""

from __future__ import annotations

from datetime import date
from typing import Any

import pandas as pd

from predictily_engine.causal.contract import CausalEvidence, check_requirements
from predictily_engine.db.supply_repository import SupplyRepository
from predictily_engine.platform.envelope import envelope

TARGET = {"metric": "causal_effect_of_price_on_demand", "status": "no_estimator_enabled"}


def weekly_evidence(repo: SupplyRepository, as_of: date, product_id: int | None, branch_id: int | None) -> CausalEvidence:
    df = repo._read(
        """
        SELECT date_trunc('week', si.sale_date)::date AS week,
               SUM(si.quantity)::float8 AS units,
               SUM(si.net_amount)::float8 / NULLIF(SUM(si.quantity), 0) AS price
        FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.tenant_id = si.tenant_id
        WHERE si.tenant_id = :tenant_id AND s.tenant_id = :tenant_id AND s.status = 'completed' AND si.is_return = false
          AND si.quantity > 0 AND si.sale_date <= :as_of
          AND (CAST(:pid AS bigint) IS NULL OR si.product_id = :pid) AND (CAST(:bid AS bigint) IS NULL OR si.branch_id = :bid)
        GROUP BY date_trunc('week', si.sale_date) ORDER BY 1
        """, as_of=as_of, pid=product_id, bid=branch_id)
    promos = repo.promotions()
    stock = repo._read(
        """
        SELECT date_trunc('week', snapshot_date)::date AS week, AVG((on_hand <= 0)::int)::float8 AS stockout_share
        FROM inventory_snapshots WHERE tenant_id = :tenant_id AND snapshot_date <= :as_of
          AND (CAST(:pid AS bigint) IS NULL OR product_id = :pid) AND (CAST(:bid AS bigint) IS NULL OR branch_id = :bid)
        GROUP BY 1
        """, as_of=as_of, pid=product_id, bid=branch_id)
    if not df.empty:
        df["week"] = pd.to_datetime(df["week"])
        df["promo"] = 0
        if not promos.empty:
            for p in promos.itertuples(index=False):
                if product_id is not None and pd.notna(p.product_id) and int(p.product_id) != product_id:
                    continue
                m = (df["week"] <= pd.Timestamp(p.ends_on)) & (df["week"] + pd.Timedelta(days=6) >= pd.Timestamp(p.starts_on))
                df.loc[m, "promo"] = 1
        stock["week"] = pd.to_datetime(stock["week"])
        df = df.merge(stock, on="week", how="left")
        df["stockout_share"] = df["stockout_share"].fillna(0.0)
    price_hist = repo._read("SELECT COUNT(*) AS n, COUNT(known_at) AS k FROM product_price_history WHERE tenant_id = :tenant_id")
    n, k = int(price_hist.iloc[0]["n"]), int(price_hist.iloc[0]["k"])
    return CausalEvidence(weekly=df, price_known_at_available=n > 0 and k == n,
                          promotions_known_at_available=bool(not promos.empty and promos["known_at"].notna().all()))


def causal_check(engine, tenant_id: int, request: dict[str, Any]) -> dict[str, Any]:
    with engine.connect() as conn:
        repo = SupplyRepository(conn, tenant_id)
        repo.tenant()
        as_of = date.fromisoformat(str(request["as_of"])[:10]) if request.get("as_of") else (repo.last_sale_date() or date.today())
        ev = weekly_evidence(repo, as_of, request.get("product_id"), request.get("branch_id"))
    result = check_requirements(request, ev, request.get("thresholds"))
    return envelope(
        tenant_id=tenant_id, run_id=None, core="scenario", as_of=as_of, target_definition={**TARGET, "request": request},
        entity_keys=["product_id", "branch_id"], unit="product_unit", currency=None, result_type="causal_estimate",
        model_version={"contract": result["contract_version"], "estimator": None}, assumptions=[
            "No hay estimador causal habilitado; la respuesta verifica requisitos de identificación.",
            "Los supuestos de identificación no son verificables con los datos."],
        limitations=result["reason_codes"], quality_status="insufficient_evidence", uncertainty_source="none", data=result)
