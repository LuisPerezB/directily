"""Servidor de análisis: solo lectura, con los filtros de la API y sin herramientas por cliente salvo que se habiliten."""

from typing import Any

import pytest

from conftest import FakeApi, json_of, text_of

pytestmark = pytest.mark.anyio

CUSTOMER_TOOLS = {"predictily_radar_opportunities", "predictily_customer_predictions", "predictily_list_decisions", "predictily_explain_recommendation", "predictily_outcomes"}


def minimal_arguments(schema: dict[str, Any]) -> dict[str, Any]:
    """Argumentos mínimos válidos de una herramienta: solo los obligatorios."""
    arguments: dict[str, Any] = {}
    for name in schema.get("required", []):
        spec = schema["properties"][name]
        arguments[name] = "2026-01-01" if spec.get("type") == "string" else 1
    return arguments


async def test_customer_level_tools_are_hidden_unless_the_operator_enables_them(insights) -> None:
    async with insights() as client:
        default = {t.name for t in (await client.list_tools()).tools}
    async with insights(customer_tools=True) as client:
        enabled = {t.name for t in (await client.list_tools()).tools}

    assert not default & CUSTOMER_TOOLS
    assert enabled - default == CUSTOMER_TOOLS
    assert {"predictily_radar_summary", "predictily_decisions_summary", "predictily_roi_summary", "predictily_dashboard_summary"} <= default


async def test_a_hidden_customer_tool_cannot_be_called_by_name(api: FakeApi, insights) -> None:
    api.fallback = {"data": []}

    async with insights() as client:
        result = await client.call_tool("predictily_radar_opportunities", {})

    assert result.is_error and api.calls() == []


async def test_every_tool_is_declared_read_only_and_only_ever_reads(api: FakeApi, insights) -> None:
    api.fallback = {"data": []}

    async with insights(customer_tools=True) as client:
        tools = (await client.list_tools()).tools
        for tool in tools:
            result = await client.call_tool(tool.name, minimal_arguments(tool.input_schema))
            assert not result.is_error, f"{tool.name}: {text_of(result)}"

    assert len(tools) == 24
    assert all(t.annotations.read_only_hint is True and t.annotations.destructive_hint is False for t in tools)
    assert all(t.name.startswith("predictily_") and t.title and t.description for t in tools)
    assert {method for method, _ in api.calls()} == {"GET"}
    assert len(api.calls()) >= len(tools)


async def test_the_forecast_ranking_passes_the_documented_filters(api: FakeApi, insights) -> None:
    api.on("GET", "forecasts/products", {
        "data": [{"product": {"id": 45, "sku": "7861234567890", "name": "Cemento 50kg"}, "forecast_units": 1250.5, "change_pct": 0.12, "trend": "up", "lo_80": 1100.0, "hi_80": 1400.0}],
        "meta": {"current_page": 1, "last_page": 4, "per_page": 15, "total": 52},
        "links": {"next": "http://127.0.0.1:8000/api/v1/forecasts/products?page=2"},
        "horizon_support": {"horizon": 30, "status": "degraded", "reasons": [{"code": "no_holdout"}]},
    })  # fmt: skip

    async with insights() as client:
        result = json_of(await client.call_tool("predictily_forecast_products", {"horizon": 30, "search": "cemento", "sort": "-forecast_units", "category_id": 3}))

    assert dict(api.last("GET", "forecasts/products").url.params) == {"horizon": "30", "search": "cemento", "category_id": "3", "sort": "-forecast_units", "page": "1", "per_page": "15"}
    # Las cifras llegan tal cual (el EAN-13 del SKU no se confunde con una cédula) y la calidad declarada no se pierde.
    assert result["data"][0] == {"product": {"id": 45, "sku": "7861234567890", "name": "Cemento 50kg"}, "forecast_units": 1250.5, "change_pct": 0.12, "trend": "up", "lo_80": 1100.0, "hi_80": 1400.0}
    assert result["horizon_support"]["status"] == "degraded"
    assert result["pagination"] == {"page": 1, "per_page": 15, "total": 52, "last_page": 4, "has_more": True, "next_page": 2}
    assert "links" not in result


async def test_filters_are_translated_to_the_names_the_api_expects(api: FakeApi, insights) -> None:
    api.fallback = {"data": []}

    async with insights(customer_tools=True) as client:
        await client.call_tool("predictily_inventory_recommendations", {"risk_level": ["high", "critical"], "type": "reorder"})
        await client.call_tool("predictily_roi_summary", {"date_from": "2026-01-01", "date_to": "2026-03-31", "group_by": "kind"})
        await client.call_tool("predictily_outcomes", {"date_from": "2026-01-01", "recommendation_id": 8})
        await client.call_tool("predictily_forecast_series", {"product_id": 45, "branch_id": 2, "granularity": "month", "history_days": 365})
        await client.call_tool("predictily_list_products", {"search": "cemento", "is_active": True})
        await client.call_tool("predictily_radar_opportunities", {"horizon": 30, "kind": "recovery", "zone": "Costa", "order_by": "probability", "direction": "desc"})

    queries = [(path, dict(request.url.params)) for (_, path), request in zip(api.calls(), api.requests, strict=True)]
    assert queries == [
        ("inventory/recommendations", {"type": "reorder", "risk_level": "high,critical", "sort": "-stockout_probability", "page": "1", "per_page": "15"}),
        ("decisions/roi", {"from": "2026-01-01", "to": "2026-03-31", "group_by": "kind"}),
        ("decisions/outcomes", {"from": "2026-01-01", "recommendation_id": "8", "page": "1", "per_page": "10"}),
        ("forecasts/series", {"product_id": "45", "branch_id": "2", "granularity": "month", "history_days": "365"}),
        ("products", {"search": "cemento", "is_active": "1", "page": "1", "per_page": "15"}),
        ("radar/opportunities", {"horizon": "30", "kind": "recovery", "zone": "Costa", "order_by": "probability", "direction": "desc", "page": "1", "per_page": "10"}),
    ]


@pytest.mark.parametrize(
    ("section", "path", "params"),
    [
        ("summary", "supply/plans/12", {}),
        ("lines", "supply/plans/12/lines", {"page": "1", "per_page": "15"}),
        ("shortfalls", "supply/plans/12/shortfalls", {"page": "1", "per_page": "15"}),
    ],
)
async def test_a_supply_plan_is_read_by_section(api: FakeApi, insights, section: str, path: str, params: dict[str, str]) -> None:
    api.fallback = {"data": {}}

    async with insights() as client:
        await client.call_tool("predictily_supply_plan_detail", {"plan_id": 12, "section": section})

    assert api.calls() == [("GET", path)] and dict(api.requests[0].url.params) == params


async def test_an_out_of_catalog_horizon_is_refused_before_calling_the_api(api: FakeApi, insights) -> None:
    async with insights() as client:
        result = await client.call_tool("predictily_dashboard_summary", {"horizon": 45})

    assert result.is_error and api.calls() == []


@pytest.mark.parametrize(
    ("status", "body", "expected"),
    [
        (402, {"message": "El horizonte de 90 días no está en su plan.", "code": "plan_limit_exceeded", "limit": "horizon_days", "max": 30}, ["plan_limit_exceeded", "plan de la empresa", "'max': 30"]),
        (403, {"message": "Su rol comercial no incluye esta información.", "code": "forbidden"}, ["`decisions.view`", "Su rol comercial no incluye esta información."]),
    ],
)
async def test_plan_and_scope_limits_reach_the_model_as_they_are(api: FakeApi, insights, status: int, body: dict[str, Any], expected: list[str]) -> None:
    api.on("GET", "decisions/roi", body, status=status)

    async with insights() as client:
        result = await client.call_tool("predictily_roi_summary", {})

    assert result.is_error and all(fragment in text_of(result) for fragment in expected)


async def test_a_large_result_is_cut_down_for_the_model_and_says_how_to_get_the_rest(api: FakeApi, insights) -> None:
    api.on("GET", "forecasts/products", {"data": [{"product": {"id": i, "name": "Producto " + "x" * 200}, "forecast_units": i} for i in range(100)], "meta": {"current_page": 1, "last_page": 1, "per_page": 100, "total": 100}})

    async with insights(max_result_chars=4000) as client:
        result = await client.call_tool("predictily_forecast_products", {"per_page": 100})

    payload = json_of(result)
    assert len(text_of(result)) <= 4000
    assert 0 < len(payload["data"]) < 100 and payload["data"][0]["forecast_units"] == 0
    assert payload["_truncado"]["listas_recortadas"]["data"]["omitidos"] == 100 - len(payload["data"])


async def test_text_that_looks_like_pii_inside_results_is_masked(api: FakeApi, insights) -> None:
    api.on("GET", "alerts", {"data": [{"id": 1, "title": "Contactar a compras@proveedor.com (RUC 0990000000001)", "severity": "warning"}]})

    async with insights() as client:
        result = json_of(await client.call_tool("predictily_list_alerts", {}))

    assert result["data"][0]["title"] == "Contactar a c***@p***.com (RUC [ID])"
    assert dict(api.last("GET", "alerts").url.params) == {"status": "open"}
