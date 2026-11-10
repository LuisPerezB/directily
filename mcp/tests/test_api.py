"""Cliente de la API: qué envía, cómo traduce los errores y qué NO hace nunca con el token."""

import httpx
import pytest

from conftest import API, TOKEN, FakeApi, settings
from predictily_mcp.api import ApiError, PredictilyApi, explain

pytestmark = pytest.mark.anyio


def client(api: FakeApi) -> PredictilyApi:
    return PredictilyApi(settings(), transport=httpx.MockTransport(api.handler))


async def test_every_request_carries_the_persons_token_and_asks_for_json(api: FakeApi) -> None:
    api.on("GET", "tenant", {"data": {"uuid": "u-1"}})

    payload = await client(api).request("GET", "/tenant", token=TOKEN, tenant_id="11111111-2222-3333-4444-555555555555")

    request = api.last("GET", "tenant")
    assert payload == {"data": {"uuid": "u-1"}}
    assert str(request.url) == f"{API}/tenant"
    assert request.headers["authorization"] == f"Bearer {TOKEN}"
    assert request.headers["x-tenant-id"] == "11111111-2222-3333-4444-555555555555"
    assert request.headers["accept"] == "application/json"
    assert request.headers["user-agent"].startswith("predictily-mcp/")


async def test_no_tenant_header_is_sent_when_the_token_is_bound_to_a_company(api: FakeApi) -> None:
    api.on("GET", "tenant", {"data": {}})

    await client(api).request("GET", "tenant", token=TOKEN)

    assert "x-tenant-id" not in api.last("GET", "tenant").headers


async def test_booleans_go_as_1_or_0_and_empty_filters_are_dropped(api: FakeApi) -> None:
    api.on("GET", "products", {"data": []})

    await client(api).request("GET", "products", token=TOKEN, params={"is_active": True, "sample": False, "search": None, "sort": "", "page": 2})

    assert dict(api.last("GET", "products").url.params) == {"is_active": "1", "sample": "0", "page": "2"}


async def test_a_204_is_an_empty_success(api: FakeApi) -> None:
    api.on("DELETE", "data-sources/3", None)

    assert await client(api).request("DELETE", "data-sources/3", token=TOKEN) is None


async def test_a_redirect_is_never_followed_with_the_token(api: FakeApi) -> None:
    api.on("GET", "tenant", lambda request: httpx.Response(302, headers={"Location": "https://otro-host.example/robo"}))

    with pytest.raises(ApiError) as error:
        await client(api).request("GET", "tenant", token=TOKEN)

    assert error.value.status == 302
    assert [str(r.url) for r in api.requests] == [f"{API}/tenant"]


async def test_api_errors_keep_their_code_and_field_errors(api: FakeApi) -> None:
    api.on("POST", "data-sources/3/mappings", {"message": "La consulta no es válida.", "code": "sql_query_rejected", "reason": "multiple_statements", "errors": {"query": ["Solo una sentencia SELECT."]}}, status=422)

    with pytest.raises(ApiError) as raised:
        await client(api).request("POST", "data-sources/3/mappings", token=TOKEN, json={})

    error = raised.value
    assert (error.status, error.code, error.errors) == (422, "sql_query_rejected", {"query": ["Solo una sentencia SELECT."]})
    text = explain(error)
    assert "Error 422 (sql_query_rejected): La consulta no es válida." in text
    assert "- query: Solo una sentencia SELECT." in text
    assert "multiple_statements" in text and "Corrija los argumentos" in text


@pytest.mark.parametrize(
    ("status", "expected"),
    [
        (401, "emitir uno nuevo"),
        (402, "plan de la empresa"),
        (403, "requiere la habilidad `datasources.manage`"),
        (404, "no pertenece a la empresa del token"),
        (409, "estado que permita"),
        (429, "Espere 30 s"),
        (500, "Fallo interno"),
    ],
)
async def test_each_status_tells_the_model_what_to_do_next(api: FakeApi, status: int, expected: str) -> None:
    api.on("GET", "x", lambda request: httpx.Response(status, json={"message": "m"}, headers={"Retry-After": "30"}))

    with pytest.raises(ApiError) as raised:
        await client(api).request("GET", "x", token=TOKEN)

    assert expected in explain(raised.value, ability="datasources.manage")


async def test_an_html_error_page_and_a_debug_trace_do_not_leak(api: FakeApi) -> None:
    api.on("GET", "html", lambda request: httpx.Response(500, text="<html>stack trace con /var/www/secret</html>"))
    api.on("GET", "debug", {"message": "Server Error", "exception": "PDOException", "file": "/var/www/app/X.php", "trace": [{"file": "/var/www"}]}, status=500)

    for path in ("html", "debug"):
        with pytest.raises(ApiError) as raised:
            await client(api).request("GET", path, token=TOKEN)
        text = explain(raised.value)
        assert "/var/www" not in text and "PDOException" not in text


async def test_a_network_failure_is_reported_without_the_url_or_the_token() -> None:
    def refuse(request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError(f"no route to {request.url} with {request.headers['authorization']}")

    def slow(request: httpx.Request) -> httpx.Response:
        raise httpx.ReadTimeout("timeout")

    for transport, code in ((refuse, "unreachable"), (slow, "timeout")):
        with pytest.raises(ApiError) as raised:
            await PredictilyApi(settings(), transport=httpx.MockTransport(transport)).request("GET", "tenant", token=TOKEN)
        text = explain(raised.value)
        assert raised.value.status == 0 and raised.value.code == code
        assert TOKEN not in text and "127.0.0.1" not in text and "PREDICTILY_API_URL" in text
