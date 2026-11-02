"""CLI de entrenamiento privado (W4-B): ayuda en español, códigos de salida estables y diagnóstico sin secretos."""

from __future__ import annotations

import json

import pytest

from predictily_engine import cli
from predictily_engine.mlops import commands
from predictily_engine.mlops.errors import EXIT_CODES

NEW_COMMANDS = ["diagnose", "validate-data", "build-features", "train", "backtest", "tune", "sign", "package", "verify", "publish", "compare", "rollback",
                "list-models", "export-local", "cleanup-exports"]


@pytest.mark.parametrize("command", NEW_COMMANDS)
def test_every_command_has_spanish_help_with_the_exit_codes(command, capsys):
    with pytest.raises(SystemExit) as caught:
        cli.main([command, "--help"])
    assert caught.value.code == 0
    out = capsys.readouterr().out
    assert out.startswith("uso:") and "opciones:" in out and "Muestra esta ayuda" in out
    assert "usage:" not in out and "options:" not in out and "show this help" not in out
    assert "Códigos de salida:" in out and "0 ok" in out and "7 error interno" in out


def test_top_level_help_lists_every_command_in_spanish(capsys):
    with pytest.raises(SystemExit):
        cli.main(["--help"])
    out = capsys.readouterr().out
    assert "comandos:" in out and all(c in out for c in NEW_COMMANDS) and "generate-demo" in out and "keygen" in out and "usage:" not in out


def test_the_exit_code_table_is_stable_and_documented():
    assert EXIT_CODES == {0: "ok", 1: "comprobación negativa", 2: "uso inválido", 3: "entorno o configuración", 4: "sin autorización", 5: "no encontrado",
                          6: "conflicto", 7: "error interno"}
    assert commands.EPILOG.startswith("Códigos de salida: 0 ok")


@pytest.mark.parametrize("argv,fragment", [
    (["train"], "faltan argumentos obligatorios"),
    (["train", "--tenant-id", "abc", "--core", "demand", "--out", "x"], "debe ser un número entero"),
    (["train", "--tenant-id", "1", "--core", "otro", "--out", "x"], "valor no válido"),
    (["publish", "--dir", "x", "--env", "mundo", "--published-by-user-id", "1"], "valor no válido"),
    (["no-existe"], "valor no válido"),
])
def test_usage_errors_exit_2_with_a_spanish_message(argv, fragment, capsys):
    with pytest.raises(SystemExit) as caught:
        cli.main(argv)
    assert caught.value.code == 2
    err = capsys.readouterr().err
    assert fragment in err and "uso:" in err


def test_tune_is_for_demand_only(capsys):
    assert cli.main(["tune", "--tenant-id", "1", "--core", "customer"]) == 2
    assert "solo aplica al core demand" in capsys.readouterr().err


def test_an_invalid_date_is_a_usage_error(capsys):
    assert cli.main(["validate-data", "--tenant-id", "1", "--core", "demand", "--cutoff", "ayer"]) == 2
    assert "AAAA-MM-DD" in capsys.readouterr().err


# ───────────────────────────── diagnose ─────────────────────────────

def test_diagnose_never_prints_secrets(monkeypatch, tmp_path, capsys):
    key_id, private, public = __import__("predictily_engine.platform.signing", fromlist=["x"]).generate_keypair("diag-key")
    monkeypatch.setenv("ENGINE_SIGNING_KEY_ID", key_id)
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", private)
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", f"{key_id}:{public}")
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://usuario-db:CLAVE-DB-SECRETA@127.0.0.1:1/predictily")        # puerto cerrado: inalcanzable
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_BUCKET", "bucket-diagnostico")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ENDPOINT", "http://clave-acceso:SECRETO-S3@127.0.0.1:1")
    monkeypatch.setenv("AWS_SECRET_ACCESS_KEY", "SECRETO-AWS-DE-PRUEBA")
    monkeypatch.setenv("AWS_ACCESS_KEY_ID", "ACCESO-AWS-DE-PRUEBA")
    monkeypatch.setenv("ENGINE_API_TOKEN", "TOKEN-DE-PRUEBA-NO-DEBE-SALIR")
    code = cli.main(["diagnose"])
    captured = capsys.readouterr()
    text = captured.out + captured.err
    for secret in (private, "CLAVE-DB-SECRETA", "SECRETO-S3", "SECRETO-AWS-DE-PRUEBA", "ACCESO-AWS-DE-PRUEBA", "TOKEN-DE-PRUEBA-NO-DEBE-SALIR", "clave-acceso"):
        assert secret not in text, secret
    report = json.loads(captured.out)
    assert code == 0 and report["ok"]                                                          # sin --require, lo inalcanzable es un aviso
    assert report["signing"]["key_id"] == key_id and report["signing"]["private_key_configured"] is True
    assert report["signing"]["trusted_keys"][0]["key_id"] == key_id and len(report["signing"]["trusted_keys"][0]["fingerprint_sha256"]) == 64
    assert report["signing"]["signing_public_key_fingerprint_sha256"] == report["signing"]["trusted_keys"][0]["fingerprint_sha256"]
    assert report["database"] == {"driver": "postgresql+psycopg", "host": "127.0.0.1", "port": 1, "database": "predictily", "user": "usuario-db",
                                  "reachable": False, "error": "OperationalError"}
    assert report["object_store"]["bucket"] == "bucket-diagnostico" and report["object_store"]["endpoint_host"] == "127.0.0.1"
    assert report["compute"]["device"] == "cpu" and report["libraries"]["lightgbm"] and report["engine_version"]


def test_diagnose_require_turns_missing_pieces_into_a_failure(monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/x")
    assert cli.main(["diagnose", "--require", "db"]) == 1
    report = json.loads(capsys.readouterr().out)
    assert not report["ok"] and any(c["name"] == "base_de_datos" and c["status"] == "fail" for c in report["checks"])
    assert cli.main(["diagnose", "--require", "magia"]) == 2


def test_diagnose_flags_an_inconsistent_trust_configuration(monkeypatch, capsys):
    from predictily_engine.platform import signing

    key_id, private, _ = signing.generate_keypair("k-a")
    _, _, other_public = signing.generate_keypair("k-b")
    monkeypatch.setenv("ENGINE_SIGNING_KEY_ID", key_id)
    monkeypatch.setenv("ENGINE_SIGNING_PRIVATE_KEY", private)
    monkeypatch.setenv("ENGINE_TRUSTED_PUBLIC_KEYS", f"{key_id}:{other_public}")             # la pública de confianza NO es la de la privada
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/x")
    assert cli.main(["diagnose"]) == 1
    assert any(c["name"] == "firma" and c["status"] == "fail" for c in json.loads(capsys.readouterr().out)["checks"])


# ───────────────────────────── exportación: sin autorización no se toca nada ─────────────────────────────

def test_export_without_authorization_exits_4_before_touching_the_database(monkeypatch, tmp_path, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/x")      # si tocara la base, fallaría con 3
    base = ["export-local", "--tenant-id", "1", "--core", "demand", "--out", str(tmp_path / "ex"), "--key-file", str(tmp_path / "k"), "--generate-key"]
    assert cli.main(base) == 4
    assert "--authorized-export" in capsys.readouterr().err
    assert cli.main([*base, "--authorized-export", "AUT-2026-0042"]) == 4                     # falta quién autoriza
    assert cli.main([*base, "--authorized-export", "x", "--authorized-by", "Dueña", "--purpose", "entrenar"]) == 4      # referencia inválida
    assert not (tmp_path / "k").exists() and not (tmp_path / "ex").exists()                    # nada se creó
    assert cli.main([*base, "--authorized-export", "AUT-2026-0042", "--authorized-by", "Dueña de los datos", "--purpose", "ok"]) == 2   # propósito muy corto


def test_cleanup_of_a_missing_folder_is_not_found(tmp_path, capsys):
    assert cli.main(["cleanup-exports", "--root", str(tmp_path / "no-existe")]) == 5
    capsys.readouterr()
    assert cli.main(["cleanup-exports", "--root", str(tmp_path)]) == 0
    assert json.loads(capsys.readouterr().out) == {"root": str(tmp_path.resolve()), "dry_run": False, "removed": [], "kept": [], "skipped": []}


# ───────────────────────────── diagnose: reglas estrictas de lo que se imprime (revisión M1) ─────────────────────────────

@pytest.mark.parametrize("url", [
    "postgresql+psycopg://predictily:Pa@ssW0rd_SECRET@127.0.0.1:5432/predictily",          # «@» sin codificar en la contraseña: el analizador estándar deja parte en el «host»
    "postgresql+psycopg://u:Pa@ss:W0rd_SECRET@db.interno:5432/x",
    "postgresql+psycopg://u:Pa/ssW0rd_SECRET@db.interno:5432/x",
    "no es una url PasswordSECRETO",
    "postgresql+psycopg://u:p?W0rd_SECRET@host/x",
])
def test_diagnose_never_prints_pieces_of_a_malformed_database_url(url, monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", url)
    assert cli.main(["diagnose"]) == 0                                                         # sin --require es un aviso
    out = capsys.readouterr()
    text = out.out + out.err
    for piece in ("SECRET", "Pa@", "ssW0rd", "W0rd", "PasswordSECRETO"):
        assert piece not in text, piece
    report = json.loads(out.out)
    assert report["database"] == {"configured": False, "url": "<no válido>"}                    # no se imprime nada de la URL ni se intenta conectar
    assert any(c["name"] == "base_de_datos" and c["status"] == "warn" and "%40" in c["detail"] for c in report["checks"])
    assert cli.main(["diagnose", "--require", "db"]) == 1


def test_diagnose_percent_encoded_passwords_are_fine_and_never_printed(monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://usuario:Pa%40ssW0rd_SECRET@127.0.0.1:1/predictily")
    assert cli.main(["diagnose"]) == 0
    out = capsys.readouterr()
    assert "SECRET" not in out.out + out.err and "Pa%40" not in out.out
    assert json.loads(out.out)["database"]["host"] == "127.0.0.1" and json.loads(out.out)["database"]["user"] == "usuario"


def test_diagnose_prints_other_configuration_fields_only_when_they_look_legitimate(monkeypatch, tmp_path, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/base;DROP")                # base con carácter no permitido
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "s3")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_BUCKET", "Bucket con CLAVE@secreta")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_REGION", "us-east-1; rm -rf")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ENDPOINT", "http://u:CLAVE-DEL-EMULADOR@host;con-clave.example:9000")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_SSE", "AES256")
    cli.main(["diagnose"])
    report = json.loads(capsys.readouterr().out)
    assert report["database"]["database"] == "<no válido>"
    store = report["object_store"]
    assert store["bucket"] == "<no válido>" and store["region"] == "<no válido>" and store["server_side_encryption"] == "AES256"
    assert store["endpoint_host"] == "<no válido>" and "CLAVE" not in json.dumps(report) and "secreta" not in json.dumps(report)
    monkeypatch.setenv("ENGINE_OBJECT_STORE_DRIVER", "local")
    monkeypatch.setenv("ENGINE_OBJECT_STORE_ROOT", str(tmp_path / "con@arroba"))
    (tmp_path / "con@arroba").mkdir()
    cli.main(["diagnose"])
    store = json.loads(capsys.readouterr().out)["object_store"]
    assert store["root"] == "<no válido>" and store["exists"] is True and store["writable"] is True        # el directorio real sí se comprueba; solo se oculta su impresión


# ───────────────────────────── entorno y rangos (revisión L3, L4, L6, L7) ─────────────────────────────

def test_an_invalid_log_level_is_a_clear_configuration_error_not_a_traceback(monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_LOG_LEVEL", "VERBOSISIMO")
    assert cli.main(["list-models", "--tenant-id", "1"]) == 3
    err = capsys.readouterr().err
    assert "ENGINE_LOG_LEVEL" in err and "VERBOSISIMO" in err and "Traceback" not in err and "DEBUG, INFO" in err
    monkeypatch.setenv("ENGINE_LOG_LEVEL", "x;y secreto con espacios")
    assert cli.main(["diagnose"]) == 3
    assert "secreto" not in capsys.readouterr().err                                             # un valor raro no se repite en el mensaje
    for good in ("debug", "INFO", "Warning"):
        monkeypatch.setenv("ENGINE_LOG_LEVEL", good)
        assert cli.main(["diagnose"]) == 0


def test_an_invalid_artifact_cache_setting_fails_at_startup_with_exit_3(monkeypatch, capsys):
    for value in ("abc", "-1", "100000"):
        monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_ENTRIES", value)
        assert cli.main(["diagnose"]) == 3, value
        assert "ENGINE_ARTIFACT_CACHE_ENTRIES" in capsys.readouterr().err
    monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_ENTRIES", "4")
    monkeypatch.setenv("ENGINE_ARTIFACT_CACHE_MAX_MB", "0")
    assert cli.main(["diagnose"]) == 3 and "ENGINE_ARTIFACT_CACHE_MAX_MB" in capsys.readouterr().err


@pytest.mark.parametrize("argv", [
    ["tune", "--tenant-id", "1", "--core", "demand", "--trials", "0"], ["tune", "--tenant-id", "1", "--core", "demand", "--trials", "201"],
    ["tune", "--tenant-id", "1", "--core", "demand", "--trials", "muchas"], ["tune", "--tenant-id", "1", "--core", "demand", "--timeout-seconds", "0"],
    ["tune", "--tenant-id", "1", "--core", "demand", "--timeout-seconds", "3601"], ["tune", "--tenant-id", "1", "--core", "demand", "--max-cpu", "17"],
    ["train", "--tenant-id", "1", "--core", "demand", "--out", "x", "--max-windows", "0"], ["train", "--tenant-id", "1", "--core", "demand", "--out", "x", "--max-windows", "7"],
    ["train", "--tenant-id", "1", "--core", "demand", "--out", "x", "--seed", "-1"], ["train", "--tenant-id", "0", "--core", "demand", "--out", "x"],
    ["export-local", "--tenant-id", "1", "--core", "demand", "--out", "x", "--key-file", "k", "--ttl-hours", "0"],
    ["export-local", "--tenant-id", "1", "--core", "demand", "--out", "x", "--key-file", "k", "--ttl-hours", "721"],
    ["list-models", "--tenant-id", "1", "--limit", "0"], ["list-models", "--tenant-id", "1", "--limit", "501"],
    ["publish", "--dir", "x", "--env", "local", "--published-by-user-id", "0"],
])
def test_numeric_options_are_validated_against_their_documented_ranges(argv, monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/x")          # si se llegara a la base, la salida sería 3
    with pytest.raises(SystemExit) as caught:
        cli.main(argv)
    assert caught.value.code == 2
    err = capsys.readouterr().err
    assert "debe" in err and "uso:" in err


@pytest.mark.parametrize("core,horizons", [("demand", ["8"]), ("demand", ["7", "999"]), ("customer", ["60"]), ("customer", ["1"])])
def test_a_horizon_outside_the_cores_catalog_is_a_usage_error_before_touching_the_database(core, horizons, monkeypatch, capsys):
    monkeypatch.setenv("ENGINE_DATABASE_URL", "postgresql+psycopg://u:p@127.0.0.1:1/x")
    for command in (["train", "--out", "x"], ["backtest"], ["build-features"]):
        assert cli.main([command[0], "--tenant-id", "1", "--core", core, "--horizons", *horizons, *command[1:]]) == 2, command
        assert "Horizontes no válidos" in capsys.readouterr().err


def test_valid_boundaries_are_accepted_by_the_parser():
    from predictily_engine.mlops.commands import EsParser, _int_range

    for low, high in ((1, 200), (0, 2**31 - 1)):
        parse = _int_range(low, high)
        assert parse(str(low)) == low and parse(str(high)) == high


def test_compare_refuses_a_bundle_of_another_company(tmp_path, capsys):
    from mlops_helpers import synthetic_draft
    from predictily_engine.mlops import bundle

    directory = tmp_path / "train"
    synthetic_draft(directory, tenant_id=7)
    assert cli.main(["package", "--dir", str(directory)]) == 0
    capsys.readouterr()
    assert cli.main(["compare", "--tenant-id", "7", "--core", "customer", "--a", f"bundle:{directory}", "--b", f"bundle:{directory}"]) == 0
    capsys.readouterr()
    assert cli.main(["compare", "--tenant-id", "8", "--core", "customer", "--a", f"bundle:{directory}", "--b", f"bundle:{directory}"]) == 4
    assert "empresa 7" in capsys.readouterr().err
    draft_only = tmp_path / "draft"
    synthetic_draft(draft_only, tenant_id=7)                                                    # borrador sin empaquetar: también se comprueba
    assert cli.main(["compare", "--tenant-id", "9", "--core", "customer", "--a", f"bundle:{draft_only}", "--b", f"bundle:{draft_only}"]) == 4
    assert bundle.PACKAGE_FILE
