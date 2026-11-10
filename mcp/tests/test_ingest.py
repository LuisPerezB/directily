"""Servidor de siembra, de punta a punta en memoria: cliente MCP → herramienta → API simulada."""

import json
from typing import Any

import httpx
import pytest
from mcp import types

from conftest import TOKEN, FakeApi, json_of, text_of
from predictily_mcp.sources import SourceProfiles

pytestmark = pytest.mark.anyio

PASSWORD = "S3creta-del-ERP!"
PROFILES = {
    "PREDICTILY_SOURCE_ERP_USERNAME": "lector_predictily",
    "PREDICTILY_SOURCE_ERP_PASSWORD": PASSWORD,
    "PREDICTILY_SOURCE_ERP_CONNECTOR": "sql_sqlserver",
    "PREDICTILY_SOURCE_ERP_HOST": "10.1.2.3",
    "PREDICTILY_SOURCE_ERP_TRUST_SERVER_CERTIFICATE": "true",
}
QUERY = "SELECT f.numero AS document_number, f.fecha AS issued_at FROM facturas f"
MAPPING = {"id": 9, "data_source_id": 7, "entity": "sales", "version": 2, "query": QUERY, "field_map": {"document_number": "document_number"}, "is_active": False, "approved_at": None}


def profiles() -> SourceProfiles:
    return SourceProfiles.load(None, PROFILES)


# ── identidad ────────────────────────────────────────────────────────────────────────────────────────────────────


async def test_whoami_identifies_the_person_and_the_company_without_pii(api: FakeApi, ingest) -> None:
    api.on("GET", "auth/me", {"data": {"user": {"id": 4, "name": "Ana", "email": "ana.lopez@demo.ec", "is_superadmin": False}, "tenants": []}})
    api.on("GET", "tenant", {"data": {"uuid": "u-1", "name": "Demo", "sector": "retail", "timezone": "America/Guayaquil", "currency": "USD", "role": "admin", "tax_id": "0990000000001", "plan": {"code": "trial", "name": "Prueba", "limits": {"users": 3}}, "onboarding": {"has_sales": False}, "settings": {"x": 1}}})

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_whoami", {}))

    assert result["user"] == {"id": 4, "name": "Ana", "email": "a***@d***.ec"}
    assert result["tenant"] == {"uuid": "u-1", "name": "Demo", "sector": "retail", "timezone": "America/Guayaquil", "currency": "USD", "role": "admin", "plan": {"code": "trial", "name": "Prueba"}, "onboarding": {"has_sales": False}}
    assert result["server_policy"] == {"purpose": "siembra de datos (ingesta)", "approvals": "elicit", "row_samples": "shapes", "customer_tools": False}
    assert api.last("GET", "tenant").headers["authorization"] == f"Bearer {TOKEN}"


async def test_whoami_explains_a_token_without_a_company(api: FakeApi, ingest) -> None:
    api.on("GET", "auth/me", {"data": {"user": {"id": 4, "name": "Ana", "email": "a@d.ec"}, "tenants": [{"uuid": "u-1", "name": "Demo", "role": "owner", "sector": "retail"}]}})
    api.on("GET", "tenant", {"message": "Indique la empresa.", "code": "tenant_required"}, status=400)

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_whoami", {}))

    assert "tenant_required" in result["tenant"]["error"]
    assert result["tenant"]["available"] == [{"uuid": "u-1", "name": "Demo", "role": "owner"}]


# ── fuentes: las credenciales del cliente no pasan por el modelo ─────────────────────────────────────────────────


async def test_a_sql_source_is_created_with_the_profiles_credentials(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources", {"data": {"id": 7, "connector_key": "sql_sqlserver", "name": "ERP", "status": "active", "config": {"host": "10.1.2.3", "database": "ventas"}, "has_credentials": True, "last_error": None}}, status=201)

    async with ingest(profiles()) as client:
        tool = next(t for t in (await client.list_tools()).tools if t.name == "predictily_create_sql_source")
        result = await client.call_tool("predictily_create_sql_source", {"name": "ERP", "credentials_profile": "erp", "database": "ventas", "statement_timeout_seconds": 300})

    assert api.json_of("POST", "data-sources") == {
        "type": "connector",
        "connector_key": "sql_sqlserver",
        "name": "ERP",
        "config": {"host": "10.1.2.3", "trust_server_certificate": True, "database": "ventas", "statement_timeout_seconds": 300},
        "credentials": {"username": "lector_predictily", "password": PASSWORD},
    }
    # El modelo no tiene dónde escribir una contraseña y tampoco la recibe de vuelta.
    assert not {"password", "username", "credentials"} & set(tool.input_schema["properties"])
    assert PASSWORD not in text_of(result) and "lector_predictily" not in text_of(result)
    assert "predictily_discover_schema" in json_of(result)["next_step"]


async def test_a_source_whose_connection_test_failed_says_how_to_recover(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources", {"data": {"id": 7, "status": "inactive", "last_error": "No se pudo abrir la conexión con la fuente."}}, status=201)

    async with ingest(profiles()) as client:
        result = json_of(await client.call_tool("predictily_create_sql_source", {"name": "ERP", "credentials_profile": "erp", "database": "ventas"}))

    assert "status=active" in result["next_step"] and "predictily_test_data_source" in result["next_step"]


@pytest.mark.parametrize(
    ("arguments", "expected"),
    [
        ({"name": "ERP", "credentials_profile": "erp"}, "Falta database"),
        ({"name": "ERP", "credentials_profile": "otro", "database": "ventas"}, "disponibles: erp"),
    ],
)
async def test_an_incomplete_source_is_refused_before_calling_the_api(api: FakeApi, ingest, arguments: dict[str, Any], expected: str) -> None:
    async with ingest(profiles()) as client:
        result = await client.call_tool("predictily_create_sql_source", arguments)

    assert result.is_error and expected in text_of(result)
    assert PASSWORD not in text_of(result) and api.calls() == []


async def test_the_profile_listing_shows_names_only(api: FakeApi, ingest) -> None:
    async with ingest(profiles()) as client:
        result = await client.call_tool("predictily_list_source_profiles", {})

    assert json_of(result)["profiles"] == [{"profile": "erp", "connector": "sql_sqlserver", "has_credentials": True, "provides": ["host", "trust_server_certificate"]}]
    assert PASSWORD not in text_of(result) and "10.1.2.3" not in text_of(result) and api.calls() == []


async def test_updating_a_source_merges_the_connection_settings(api: FakeApi, ingest) -> None:
    api.on("GET", "data-sources", {"data": [{"id": 6, "config": {"host": "otra"}}, {"id": 7, "config": {"host": "10.1.2.3", "port": 1433, "database": "ventas"}}]})
    api.on("PATCH", "data-sources/7", {"data": {"id": 7, "status": "active"}})

    async with ingest(profiles()) as client:
        await client.call_tool("predictily_update_data_source", {"data_source_id": 7, "statement_timeout_seconds": 300, "status": "active"})
        first = api.json_of("PATCH", "data-sources/7")
        await client.call_tool("predictily_update_data_source", {"data_source_id": 7, "credentials_profile": "erp"})
        second = api.json_of("PATCH", "data-sources/7")
        nothing = await client.call_tool("predictily_update_data_source", {"data_source_id": 7})

    assert first == {"status": "active", "config": {"host": "10.1.2.3", "port": 1433, "database": "ventas", "statement_timeout_seconds": 300}}
    assert second == {"credentials": {"username": "lector_predictily", "password": PASSWORD}}
    assert nothing.is_error and "al menos un cambio" in text_of(nothing)


# ── esquema y vista previa: metadatos sí, valores no ─────────────────────────────────────────────────────────────


async def test_schema_discovery_is_filtered_and_paged_for_the_model(api: FakeApi, ingest) -> None:
    tables = [{"schema": "dbo", "name": name, "kind": "table", "estimated_rows": rows, "column_count": 5} for name, rows in (("FacturaVenta", 900), ("DetalleVenta", 800), ("Producto", 50), ("Cliente", 40), ("VentaHistorico", 10))]
    api.on("POST", "data-sources/7/schema/discover", {"data": {"dialect": "sqlsrv", "schema_fingerprint": "f" * 64, "tables_total": 5, "columns_total": 25, "truncated": False, "tables": tables}})

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_discover_schema", {"data_source_id": 7, "table_filter": "VENTA", "limit": 2}))
        rest = json_of(await client.call_tool("predictily_discover_schema", {"data_source_id": 7, "table_filter": "VENTA", "limit": 2, "offset": 2, "include_columns": True}))

    assert [t["name"] for t in result["tables"]] == ["FacturaVenta", "DetalleVenta"]
    assert (result["matched"], result["count"], result["has_more"], result["next_offset"]) == (3, 2, True, 2)
    assert result["dialect"] == "sqlsrv" and result["schema_fingerprint"] == "f" * 64
    assert [t["name"] for t in rest["tables"]] == ["VentaHistorico"] and rest["has_more"] is False and rest["next_offset"] is None
    assert [dict(r.url.params) for r in api.requests] == [{"include_columns": "0"}, {"include_columns": "1"}]


PREVIEW = {"data": {"headers": ["document_number", "issued_at", "customer_code"], "rows": [["001-001-000012345", "2026-01-31 10:15:00", "CLI-0001"], ["001-001-000012346", "2026-02-01 09:00:00", None]], "rows_returned": 2, "note": "Vista previa de campos canónicos; no se importó nada."}}


async def test_a_mapping_preview_gives_shapes_not_the_clients_rows(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/mappings/9/preview", PREVIEW)

    async with ingest() as client:
        result = await client.call_tool("predictily_preview_mapping", {"data_source_id": 7, "mapping_id": 9})

    payload = json_of(result)
    assert "rows" not in payload and payload["rows_returned"] == 2
    assert payload["column_shapes"][0] == {"column": "document_number", "non_null": 2, "null_or_empty": 0, "shapes": [{"shape": "999-999-9{9}", "count": 2}]}
    assert payload["column_shapes"][2]["null_or_empty"] == 1
    assert "000012345" not in text_of(result) and "CLI-0001" not in text_of(result) and "2026" not in text_of(result)


async def test_the_owner_can_allow_real_rows(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/mappings/9/preview", PREVIEW)

    async with ingest(row_samples="full") as client:
        payload = json_of(await client.call_tool("predictily_preview_mapping", {"data_source_id": 7, "mapping_id": 9}))

    assert payload["rows"][0] == ["001-001-000012345", "2026-01-31 10:15:00", "CLI-0001"] and "column_shapes" not in payload


async def test_import_errors_show_the_shape_of_the_offending_value(api: FakeApi, ingest) -> None:
    api.on("GET", "imports/3/errors", {"data": [{"row_number": 12, "column": "issued_at", "error_code": "invalid_date", "severity": "error", "message": "Fecha no válida.", "raw_value": "31/02/2026", "dq_class": None}], "meta": {"current_page": 1, "last_page": 1, "per_page": 50, "total": 1}})

    async with ingest() as client:
        result = await client.call_tool("predictily_list_import_errors", {"import_id": 3, "severity": "error"})

    row = json_of(result)["data"][0]
    assert row["raw_value_shape"] == "99/99/9999" and "raw_value" not in row and row["error_code"] == "invalid_date"
    assert "31/02/2026" not in text_of(result)
    assert dict(api.last("GET", "imports/3/errors").url.params) == {"severity": "error", "page": "1"}


# ── aprobar es una decisión de la persona ────────────────────────────────────────────────────────────────────────


def answering(action: str, confirmar: bool | None = None):
    seen: list[str] = []

    async def callback(context: Any, params: types.ElicitRequestParams) -> types.ElicitResult:
        seen.append(params.message)
        return types.ElicitResult(action=action, content=None if confirmar is None else {"confirmar": confirmar})

    return callback, seen


async def test_approving_asks_the_person_and_shows_the_exact_query(api: FakeApi, ingest) -> None:
    api.on("GET", "data-sources/7/mappings/9", {"data": MAPPING})
    api.on("POST", "data-sources/7/mappings/9/approve", {"data": {**MAPPING, "is_active": True, "approved_at": "2026-11-10T15:00:00+00:00"}})
    callback, seen = answering("accept", True)

    async with ingest(client={"elicitation_callback": callback}) as client:
        result = json_of(await client.call_tool("predictily_approve_mapping", {"data_source_id": 7, "mapping_id": 9}))

    assert result["data"]["is_active"] is True
    assert len(seen) == 1 and QUERY in seen[0] and "mapeo #9" in seen[0] and "fuente #7" in seen[0]
    assert api.calls("POST") == [("POST", "data-sources/7/mappings/9/approve")]


@pytest.mark.parametrize(("action", "confirmar"), [("decline", None), ("cancel", None), ("accept", False)])
async def test_nothing_is_approved_unless_the_person_confirms(api: FakeApi, ingest, action: str, confirmar: bool | None) -> None:
    api.on("GET", "data-sources/7/mappings/9", {"data": MAPPING})
    callback, _ = answering(action, confirmar)

    async with ingest(client={"elicitation_callback": callback}) as client:
        result = await client.call_tool("predictily_approve_mapping", {"data_source_id": 7, "mapping_id": 9})

    assert result.is_error and "no confirmó" in text_of(result)
    assert api.calls("POST") == []


async def test_a_client_that_cannot_ask_the_person_cannot_approve(api: FakeApi, ingest) -> None:
    api.on("GET", "data-sources/7/mappings/9", {"data": MAPPING})

    async with ingest() as client:
        result = await client.call_tool("predictily_approve_mapping", {"data_source_id": 7, "mapping_id": 9})

    assert result.is_error and "elicitation" in text_of(result) and QUERY in text_of(result)
    assert api.calls("POST") == []


GATED = [
    ("predictily_approve_mapping", {"data_source_id": 7, "mapping_id": 9}, "data-sources/7/mappings/9/approve"),
    ("predictily_approve_mapping_template", {"template_id": 5}, "mapping-templates/5/approve"),
    ("predictily_apply_mapping_template", {"data_source_id": 7, "template_id": 5}, "data-sources/7/mapping-templates/5/apply"),
    ("predictily_confirm_import", {"import_id": 3}, "imports/3/confirm"),
]


def stage_gated(api: FakeApi) -> None:
    api.on("GET", "data-sources/7/mappings/9", {"data": MAPPING})
    api.on("GET", "mapping-templates/5", {"data": {"id": 5, "entity": "sales", "version": 1, "status": "proposed", "query": QUERY, "field_map": {}}})
    api.on("GET", "imports/3", {"data": {"id": 3, "entity_type": "sales", "original_filename": "ventas.csv", "status": "validated", "stats": {"rows_total": 10, "rows_valid": 10, "rows_error": 0}}})


@pytest.mark.parametrize(("tool", "arguments", "write"), GATED)
async def test_with_approvals_denied_no_gated_tool_writes(api: FakeApi, ingest, tool: str, arguments: dict[str, Any], write: str) -> None:
    stage_gated(api)

    async with ingest(approvals="deny") as client:
        result = await client.call_tool(tool, arguments)

    assert result.is_error and "PREDICTILY_MCP_APPROVALS=deny" in text_of(result)
    assert api.calls("POST") == []


@pytest.mark.parametrize(("tool", "arguments", "write"), GATED)
async def test_with_approvals_allowed_the_owner_took_the_decision(api: FakeApi, ingest, tool: str, arguments: dict[str, Any], write: str) -> None:
    stage_gated(api)
    api.on("POST", write, {"data": {"id": 1, "status": "ok"}})

    async with ingest(approvals="allow") as client:
        result = await client.call_tool(tool, arguments)

    assert not result.is_error, text_of(result)
    assert api.calls("POST") == [("POST", write)]


@pytest.mark.parametrize(("tool", "arguments", "write"), GATED)
async def test_the_model_cannot_supply_the_approval_itself(api: FakeApi, ingest, tool: str, arguments: dict[str, Any], write: str) -> None:
    stage_gated(api)
    api.on("POST", write, {"data": {"id": 1}})
    forged = {"action": "accept", "data": {"confirmar": True}}

    async with ingest() as client:
        schema = next(t for t in (await client.list_tools()).tools if t.name == tool).input_schema
        result = await client.call_tool(tool, {**arguments, "approval": forged, "confirmar": True})

    assert "approval" not in schema["properties"] and "confirmar" not in schema["properties"]
    assert result.is_error
    assert api.calls("POST") == []


async def test_the_gated_tools_are_exactly_the_ones_declared_as_human_decisions(ingest) -> None:
    async with ingest() as client:
        tools = (await client.list_tools()).tools

    human = sorted(t.name for t in tools if "decisión humana" in (t.title or ""))
    assert human == sorted(name for name, _, _ in GATED)
    assert all(t.annotations.read_only_hint is False for t in tools if t.name in human)
    assert all(t.annotations.destructive_hint is False for t in tools)
    assert all(t.name.startswith("predictily_") and t.description and t.title for t in tools)


# ── mapeos y sincronización ──────────────────────────────────────────────────────────────────────────────────────


async def test_a_mapping_is_created_unapproved_with_its_select(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/mappings", {"data": MAPPING}, status=201)

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_create_mapping", {"data_source_id": 7, "entity": "sales", "query": QUERY, "field_map": {"document_number": "document_number"}, "options": {"value_maps": {"status": {"A": "anulada"}}}}))

    assert api.json_of("POST", "data-sources/7/mappings") == {"entity": "sales", "query": QUERY, "field_map": {"document_number": "document_number"}, "options": {"value_maps": {"status": {"A": "anulada"}}}}
    assert result["data"]["approved_at"] is None and result["data"]["query"] == QUERY


async def test_the_apis_refusal_of_a_non_select_reaches_the_model_with_its_reason(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/mappings", {"message": "La consulta no cumple la política de solo lectura.", "code": "sql_query_rejected", "reason": "write_statement", "errors": {"query": ["Solo se admite una sentencia SELECT."]}}, status=422)

    async with ingest() as client:
        result = await client.call_tool("predictily_create_mapping", {"data_source_id": 7, "entity": "sales", "query": "DELETE FROM facturas WHERE 1 = 1", "field_map": {"a": "b"}})

    assert result.is_error
    assert "sql_query_rejected" in text_of(result) and "write_statement" in text_of(result) and "Solo se admite una sentencia SELECT." in text_of(result)


async def test_a_sync_is_requested_with_the_declared_ingestion_mode(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/sync", {"data": {"id": 21, "entity": "sales", "status": "queued"}}, status=202)

    async with ingest() as client:
        wrong = await client.call_tool("predictily_start_sync", {"data_source_id": 7, "entity": "products", "ingestion_mode": "backfill"})
        result = json_of(await client.call_tool("predictily_start_sync", {"data_source_id": 7, "entity": "sales", "mode": "full", "ingestion_mode": "backfill"}))
        await client.call_tool("predictily_start_sync", {"data_source_id": 7, "entity": "products"})

    assert wrong.is_error and "solo aplica a la entidad sales" in text_of(wrong)
    assert result["data"] == {"id": 21, "entity": "sales", "status": "queued"}
    assert [json.loads(r.content) for r in api.requests] == [{"entity": "sales", "mode": "full", "ingestion_mode": "backfill"}, {"entity": "products", "mode": "incremental"}]


async def test_a_missing_ability_names_the_ability_the_token_needs(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/sync", {"message": "No tiene permiso para esta acción.", "code": "forbidden"}, status=403)

    async with ingest() as client:
        result = await client.call_tool("predictily_start_sync", {"data_source_id": 7, "entity": "sales"})

    assert result.is_error and "`datasources.manage`" in text_of(result) and TOKEN not in text_of(result)


async def test_waiting_for_a_sync_polls_until_it_finishes(api: FakeApi, ingest) -> None:
    api.on("GET", "syncs/21", [{"data": {"id": 21, "status": "queued"}}, {"data": {"id": 21, "status": "running"}}, {"data": {"id": 21, "status": "needs_review", "import_job_id": 3, "rows_extracted": 120}}])

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_get_sync", {"sync_id": 21, "wait_seconds": 30}))

    assert result == {"data": {"id": 21, "status": "needs_review", "import_job_id": 3, "rows_extracted": 120}, "finished": True}
    assert len(api.calls("GET")) == 3


async def test_without_waiting_a_running_sync_is_reported_as_unfinished(api: FakeApi, ingest) -> None:
    api.on("GET", "syncs/21", {"data": {"id": 21, "status": "running"}})

    async with ingest() as client:
        result = json_of(await client.call_tool("predictily_get_sync", {"sync_id": 21}))

    assert result["finished"] is False and len(api.calls("GET")) == 1


# ── archivos ─────────────────────────────────────────────────────────────────────────────────────────────────────

JOB = {"id": 3, "entity_type": "sales", "status": "awaiting_mapping", "detected_columns": ["Nro Factura", "Fecha"], "suggested_mapping": {"document_number": "Nro Factura"}, "preview": {"headers": ["Nro Factura", "Fecha"], "rows": [["F-000991", "31/01/2026"]]}, "normalized_preview": [{"document_number": "F-000991", "issued_at": "2026-01-31"}]}


async def test_a_file_from_the_import_folder_is_uploaded_and_its_rows_stay_private(api: FakeApi, ingest, tmp_path) -> None:
    (tmp_path / "enero").mkdir()
    (tmp_path / "enero" / "ventas.csv").write_text("Nro Factura,Fecha\nF-000991,31/01/2026\n", encoding="utf-8")
    (tmp_path / "notas.txt").write_text("no es importable", encoding="utf-8")
    api.on("POST", "imports", {"data": JOB}, status=201)

    async with ingest(import_dir=tmp_path.resolve()) as client:
        listed = json_of(await client.call_tool("predictily_list_import_files", {}))
        result = await client.call_tool("predictily_upload_import_file", {"file_name": "enero/ventas.csv", "entity_type": "sales", "data_source_id": 7})

    assert [f["file_name"] for f in listed["files"]] == ["enero/ventas.csv"]
    request = api.last("POST", "imports")
    assert request.headers["content-type"].startswith("multipart/form-data")
    assert b'name="entity_type"\r\n\r\nsales' in request.content and b'name="data_source_id"\r\n\r\n7' in request.content
    assert b'filename="ventas.csv"' in request.content and b"F-000991,31/01/2026" in request.content
    job = json_of(result)["data"]
    assert job["suggested_mapping"] == {"document_number": "Nro Factura"} and job["detected_columns"] == ["Nro Factura", "Fecha"]
    assert job["preview"]["column_shapes"][0]["shapes"] == [{"shape": "A-999999", "count": 1}]
    assert "F-000991" not in text_of(result) and "31/01/2026" not in text_of(result)


@pytest.mark.parametrize(
    ("file_name", "expected"),
    [
        ("../secreto.csv", "dentro de la carpeta"),
        ("no-existe.csv", "No existe"),
        ("notas.txt", "Solo se cargan archivos .csv o .xlsx"),
    ],
)
async def test_only_csv_or_xlsx_inside_the_import_folder_can_be_uploaded(api: FakeApi, ingest, tmp_path, file_name: str, expected: str) -> None:
    folder = tmp_path / "importar"
    folder.mkdir()
    (folder / "notas.txt").write_text("x", encoding="utf-8")
    (tmp_path / "secreto.csv").write_text("clave,valor\n", encoding="utf-8")

    async with ingest(import_dir=folder.resolve()) as client:
        result = await client.call_tool("predictily_upload_import_file", {"file_name": file_name, "entity_type": "sales"})

    assert result.is_error and expected in text_of(result)
    assert api.calls() == []


async def test_file_uploads_are_off_until_the_operator_names_a_folder(api: FakeApi, ingest) -> None:
    async with ingest() as client:
        for tool, arguments in (("predictily_list_import_files", {}), ("predictily_upload_import_file", {"file_name": "ventas.csv", "entity_type": "sales"})):
            result = await client.call_tool(tool, arguments)
            assert result.is_error and "PREDICTILY_MCP_IMPORT_DIR" in text_of(result)

    assert api.calls() == []


async def test_an_import_is_mapped_validated_and_then_confirmed_by_the_person(api: FakeApi, ingest) -> None:
    validated = {**JOB, "status": "validated", "stats": {"rows_total": 1, "rows_valid": 1, "rows_error": 0, "documents_new": 1}}
    api.on("PUT", "imports/3/mapping", {"data": {**JOB, "status": "validating"}}, status=202)
    api.on("GET", "imports/3", [{"data": {**JOB, "status": "validating"}}, {"data": validated}])
    api.on("POST", "imports/3/confirm", {"data": {**JOB, "status": "importing"}}, status=202)
    callback, seen = answering("accept", True)

    async with ingest(client={"elicitation_callback": callback}) as client:
        await client.call_tool("predictily_set_import_mapping", {"import_id": 3, "mapping": {"document_number": "Nro Factura", "issued_at": "Fecha", "channel": None}, "options": {"date_format": "d/m/Y"}})
        waited = json_of(await client.call_tool("predictily_get_import", {"import_id": 3, "wait_seconds": 30}))
        confirmed = json_of(await client.call_tool("predictily_confirm_import", {"import_id": 3}))

    assert api.json_of("PUT", "imports/3/mapping") == {"mapping": {"document_number": "Nro Factura", "issued_at": "Fecha", "channel": None}, "options": {"date_format": "d/m/Y"}}
    assert waited["finished"] is True and waited["data"]["status"] == "validated" and "rows" not in waited["data"]["preview"]
    assert "importación #3" in seen[0] and "rows_total=1, rows_valid=1, rows_error=0, documents_new=1" in seen[0]
    assert "F-000991" not in seen[0]
    assert confirmed["data"]["status"] == "importing"


# ── verificación ─────────────────────────────────────────────────────────────────────────────────────────────────


async def test_the_reconciliation_tools_read_the_documented_routes(api: FakeApi, ingest) -> None:
    api.fallback = {"data": []}

    async with ingest() as client:
        await client.call_tool("predictily_sales_summary", {"date_from": "2026-01-01", "date_to": "2026-06-30"})
        await client.call_tool("predictily_list_reconciliation", {"entity": "sales", "date_from": "2026-01-01", "date_to": "2026-01-31"})
        await client.call_tool("predictily_get_data_coverage", {})
        await client.call_tool("predictily_get_data_quality", {})
        await client.call_tool("predictily_list_quarantine", {"import_job_id": 3})

    assert api.calls() == [("GET", "sales/summary"), ("GET", "lineage/reconciliation"), ("GET", "data-coverage"), ("GET", "data-coverage/gaps"), ("GET", "data-quality/extended"), ("GET", "data-quality/quarantine")]
    assert dict(api.requests[0].url.params) == {"date_from": "2026-01-01", "date_to": "2026-06-30", "granularity": "month"}
    assert dict(api.requests[1].url.params) == {"entity": "sales", "from": "2026-01-01", "to": "2026-01-31", "per_page": "20"}
    assert dict(api.requests[5].url.params) == {"import_job_id": "3", "per_page": "20"}


async def test_arguments_are_validated_before_any_request(api: FakeApi, ingest) -> None:
    async with ingest() as client:
        for tool, arguments in (
            ("predictily_start_sync", {"data_source_id": 7, "entity": "clientes"}),
            ("predictily_sales_summary", {"date_from": "01/01/2026", "date_to": "2026-06-30"}),
            ("predictily_get_sync", {"sync_id": 21, "wait_seconds": 9000}),
            ("predictily_test_data_source", {"data_source_id": 0}),
        ):
            assert (await client.call_tool(tool, arguments)).is_error

    assert api.calls() == []


async def test_a_failing_connector_message_never_carries_the_environment_token(api: FakeApi, ingest) -> None:
    api.on("POST", "data-sources/7/test", lambda request: httpx.Response(200, json={"data": {"ok": False, "message": f"Falló con Authorization: Bearer {TOKEN}", "details": {}}}))

    async with ingest() as client:
        result = await client.call_tool("predictily_test_data_source", {"data_source_id": 7})

    assert json_of(result)["data"]["ok"] is False and TOKEN not in text_of(result)
