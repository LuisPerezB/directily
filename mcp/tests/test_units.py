"""Configuración, privacidad, forma de la salida y perfiles de conexión (sin servidor MCP)."""

import json

import pytest

from predictily_mcp.config import ConfigError, Settings
from predictily_mcp.output import normalize, render
from predictily_mcp.privacy import REDACTED, mask_pii, profile_records, profile_rows, shape_of
from predictily_mcp.sources import ProfileError, SourceProfiles

BASE = {"PREDICTILY_API_URL": "http://127.0.0.1:8000/api/v1", "PREDICTILY_API_TOKEN": "5|secretoDePrueba000000000000"}


# ── configuración ────────────────────────────────────────────────────────────────────────────────────────────────


def test_defaults_are_the_private_ones() -> None:
    settings = Settings.from_env(BASE)

    assert settings.approvals == "elicit"
    assert settings.row_samples == "shapes"
    assert settings.customer_tools is False
    assert settings.import_dir is None


def test_the_token_never_appears_in_repr() -> None:
    assert "secretoDePrueba" not in repr(Settings.from_env(BASE))


def test_stdio_needs_a_token_and_http_ignores_the_one_in_the_environment() -> None:
    with pytest.raises(ConfigError, match="PREDICTILY_API_TOKEN"):
        Settings.from_env({"PREDICTILY_API_URL": BASE["PREDICTILY_API_URL"]})

    http = Settings.from_env(BASE, http_mode=True)
    assert http.api_token is None and http.http_mode is True


@pytest.mark.parametrize(
    "url",
    ["", "ftp://127.0.0.1/api/v1", "http://usuario:clave@127.0.0.1/api/v1", "http://127.0.0.1/api/v1?x=1"],
)
def test_a_malformed_api_url_is_rejected(url: str) -> None:
    with pytest.raises(ConfigError, match="PREDICTILY_API_URL"):
        Settings.from_env({**BASE, "PREDICTILY_API_URL": url})


def test_plain_http_to_a_remote_host_is_refused_unless_declared() -> None:
    remote = {**BASE, "PREDICTILY_API_URL": "http://10.20.30.40:8000/api/v1"}
    with pytest.raises(ConfigError, match="sin cifrar"):
        Settings.from_env(remote)

    assert Settings.from_env({**remote, "PREDICTILY_API_ALLOW_INSECURE_HTTP": "true"}).api_url == "http://10.20.30.40:8000/api/v1"
    assert Settings.from_env({**BASE, "PREDICTILY_API_URL": "https://app.example.com/api/v1/"}).api_url == "https://app.example.com/api/v1"


@pytest.mark.parametrize(
    ("name", "value"),
    [("PREDICTILY_MCP_APPROVALS", "siempre"), ("PREDICTILY_MCP_ROW_SAMPLES", "todo"), ("PREDICTILY_MCP_CUSTOMER_TOOLS", "quizá"), ("PREDICTILY_API_TIMEOUT", "0")],
)
def test_an_invalid_policy_value_stops_the_server(name: str, value: str) -> None:
    with pytest.raises(ConfigError, match=name):
        Settings.from_env({**BASE, name: value})


# ── privacidad ───────────────────────────────────────────────────────────────────────────────────────────────────


def test_pii_is_masked_like_the_backend_does() -> None:
    masked = mask_pii({
        "email": "juan.perez@empresa.com.ec",
        "note": "cédula 0912345678 y RUC 0912345678001",
        "header": "Authorization: Bearer 12|abcdefghijklmnopqrstuvwxyz",
        "password": "no-debe-salir",
        "credentials": {"username": "sa", "password": "x"},
        "nested": [{"tax_id": "0990000000001", "name": "Ferretería"}],
    })  # fmt: skip

    assert masked["email"] == "j***@e***.com.ec"
    assert masked["note"] == "cédula [ID] y RUC [ID]"
    assert "abcdefghij" not in masked["header"] and "[TOKEN]" in masked["header"]
    assert masked["password"] == REDACTED and masked["credentials"] == REDACTED
    assert masked["nested"] == [{"tax_id": REDACTED, "name": "Ferretería"}]


def test_business_identifiers_and_numbers_survive_the_mask() -> None:
    payload = {"sku": "7861234567890", "document_number": "0010010000", "units": 1234567890, "sha256": "a" * 64, "query": "SELECT 1 WHERE id = 1234567890"}

    assert mask_pii(payload) == payload


def test_a_shape_never_contains_the_value() -> None:
    assert shape_of("2026-01-31") == "9999-99-99"
    assert shape_of("FAC-001-0000123") == "AAA-999-9{7}"
    assert shape_of("María José") == "AAAAA AAAA"
    assert shape_of(12.5) == "número" and shape_of(None) == "null" and shape_of("") == "vacío"


def test_a_row_profile_reports_shapes_and_counts_only() -> None:
    rows = [["2026-01-31", "CLI-0001", 10], ["2026-02-01", None, 3], ["31/01/2026", "CLI-0002", 1]]

    profile = profile_rows(["issued_at", "customer_code", "quantity"], rows)

    assert profile[0] == {"column": "issued_at", "non_null": 3, "null_or_empty": 0, "shapes": [{"shape": "9999-99-99", "count": 2}, {"shape": "99/99/9999", "count": 1}]}
    assert profile[1]["null_or_empty"] == 1 and profile[1]["shapes"] == [{"shape": "AAA-9999", "count": 2}]
    assert "CLI-0001" not in json.dumps(profile) and "2026" not in json.dumps(profile)
    assert profile_records([{"a": "x1"}, {"a": "y2", "b": 1}])[1]["column"] == "b"


# ── salida ───────────────────────────────────────────────────────────────────────────────────────────────────────


def test_laravel_pagination_becomes_a_uniform_block() -> None:
    out = normalize({"data": [1, 2], "links": {"next": "http://…"}, "meta": {"current_page": 2, "last_page": 5, "per_page": 2, "total": 9, "path": "http://…", "extra": "x"}})

    assert out == {"data": [1, 2], "meta": {"extra": "x"}, "pagination": {"page": 2, "per_page": 2, "total": 9, "last_page": 5, "has_more": True, "next_page": 3}}
    assert normalize({"data": [], "meta": {"current_page": 1, "last_page": 1, "per_page": 10, "total": 0}})["pagination"]["next_page"] is None


def test_an_oversized_result_is_trimmed_to_valid_json_and_says_so() -> None:
    payload = {"data": [{"id": i, "name": "producto " + "x" * 80} for i in range(200)], "pagination": {"page": 1, "has_more": True}}

    text = render(payload, 3000)
    parsed = json.loads(text)

    assert len(text) <= 3000
    assert 1 <= len(parsed["data"]) < 200 and parsed["data"][0]["id"] == 0
    trimmed = parsed["_truncado"]["listas_recortadas"]["data"]
    assert trimmed["mostrados"] == len(parsed["data"]) and trimmed["mostrados"] + trimmed["omitidos"] == 200
    assert parsed["pagination"] == {"page": 1, "has_more": True}


def test_a_small_result_is_returned_untouched() -> None:
    assert json.loads(render({"data": {"á": 1}}, 2000)) == {"data": {"á": 1}}
    assert "á" in render({"data": {"á": 1}}, 2000)


# ── perfiles de conexión ─────────────────────────────────────────────────────────────────────────────────────────


def test_profiles_come_from_the_environment_with_typed_config() -> None:
    profiles = SourceProfiles.load(None, {
        "PREDICTILY_SOURCE_ERP_CENTRAL_USERNAME": "lector",
        "PREDICTILY_SOURCE_ERP_CENTRAL_PASSWORD": "S3creta!",
        "PREDICTILY_SOURCE_ERP_CENTRAL_CONNECTOR": "sql_sqlserver",
        "PREDICTILY_SOURCE_ERP_CENTRAL_HOST": "10.1.2.3",
        "PREDICTILY_SOURCE_ERP_CENTRAL_PORT": "1433",
        "PREDICTILY_SOURCE_ERP_CENTRAL_TRUST_SERVER_CERTIFICATE": "true",
        "PREDICTILY_SOURCE_ERP_CENTRAL_STATEMENT_TIMEOUT_SECONDS": "300",
        "OTRA_VARIABLE": "x",
    })  # fmt: skip

    profile = profiles.get("ERP_Central")

    assert profiles.names() == ["erp_central"]
    assert profile.connector == "sql_sqlserver"
    assert profile.config == {"host": "10.1.2.3", "port": 1433, "trust_server_certificate": True, "statement_timeout_seconds": 300}
    assert profile.credentials() == {"username": "lector", "password": "S3creta!"}


def test_what_the_model_can_see_of_a_profile_has_no_secret_and_no_host() -> None:
    profiles = SourceProfiles.load(None, {"PREDICTILY_SOURCE_ERP_USERNAME": "lector", "PREDICTILY_SOURCE_ERP_PASSWORD": "S3creta!", "PREDICTILY_SOURCE_ERP_HOST": "10.1.2.3"})

    described = json.dumps(profiles.describe()) + repr(profiles.get("erp"))

    assert profiles.describe() == [{"profile": "erp", "connector": None, "has_credentials": True, "provides": ["host"]}]
    assert "S3creta!" not in described and "lector" not in described and "10.1.2.3" not in json.dumps(profiles.describe())


def test_the_file_and_the_environment_merge_and_the_environment_wins(tmp_path) -> None:
    file = tmp_path / "perfiles.json"
    file.write_text(json.dumps({"erp": {"username": "viejo", "password": "vieja", "host": "erp.local", "database": "ventas", "encrypt": False}}), encoding="utf-8")
    file.chmod(0o600)

    profile = SourceProfiles.load(file, {"PREDICTILY_SOURCE_ERP_PASSWORD": "nueva"}).get("erp")

    assert profile.credentials() == {"username": "viejo", "password": "nueva"}
    assert profile.config == {"host": "erp.local", "database": "ventas", "encrypt": False}


def test_an_unknown_or_incomplete_profile_fails_without_revealing_anything() -> None:
    profiles = SourceProfiles.load(None, {"PREDICTILY_SOURCE_ERP_USERNAME": "lector"})

    with pytest.raises(ProfileError, match="disponibles: erp") as missing:
        profiles.get("otro")
    with pytest.raises(ProfileError, match="usuario y contraseña"):
        profiles.get("erp").credentials()
    assert "lector" not in str(missing.value)


@pytest.mark.parametrize(
    "env",
    [
        {"PREDICTILY_SOURCE_ERP_CONNECTOR": "oracle"},
        {"PREDICTILY_SOURCE_ERP_PORT": "mil"},
        {"PREDICTILY_SOURCE_ERP_ENCRYPT": "tal vez"},
    ],
)
def test_a_bad_profile_stops_the_server_at_start(env: dict[str, str]) -> None:
    from predictily_mcp.config import ConfigError

    with pytest.raises(ConfigError, match="erp"):
        SourceProfiles.load(None, env)


def test_an_unreadable_profiles_file_does_not_echo_its_content(tmp_path) -> None:
    file = tmp_path / "perfiles.json"
    file.write_text('{"erp": {"password": "S3creta!" ', encoding="utf-8")
    file.chmod(0o600)

    with pytest.raises(ConfigError) as error:
        SourceProfiles.load(file, {})

    assert "S3creta!" not in str(error.value)
