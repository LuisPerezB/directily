"""Hermeticidad de las pruebas (W1-B): ninguna prueba lee `engine/.env`.

El `.env` de desarrollo del arquitecto contiene claves de firma reales de desarrollo y otros valores; si una clase de configuración lo
leyera como respaldo, pruebas como «sin clave privada» recuperarían la clave de ahí y el resultado dependería de la máquina.
"""

from __future__ import annotations

import os

import pytest

from predictily_engine.config import Settings
from predictily_engine.platform import signing


@pytest.fixture
def dotenv_in_cwd(tmp_path, monkeypatch):
    """Un `.env` con una clave de firma VÁLIDA y valores de configuración, en el directorio de trabajo (como engine/.env)."""
    key_id, private, public = signing.generate_keypair("dev-from-dotenv")
    (tmp_path / ".env").write_text(
        f"ENGINE_SIGNING_KEY_ID={key_id}\nENGINE_SIGNING_PRIVATE_KEY={private}\nENGINE_TRUSTED_PUBLIC_KEYS={key_id}:{public}\n"
        "ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true\nENGINE_RANDOM_SEED=1234567\n", encoding="utf-8")
    monkeypatch.chdir(tmp_path)
    for name in ("ENGINE_SIGNING_KEY_ID", "ENGINE_SIGNING_PRIVATE_KEY", "ENGINE_TRUSTED_PUBLIC_KEYS", "ENGINE_ALLOW_UNSIGNED_ARTIFACTS",
                 "ENGINE_RANDOM_SEED"):
        monkeypatch.delenv(name, raising=False)
    return tmp_path


def test_a_dotenv_file_in_the_working_directory_is_never_read_by_the_signing_configuration(dotenv_in_cwd):
    config = signing.load_config()
    assert config.private_key is None and config.key_id is None
    assert config.trusted == {}
    assert config.allow_unsigned is False


def test_a_dotenv_file_in_the_working_directory_is_never_read_by_the_engine_settings(dotenv_in_cwd):
    assert Settings().random_seed != 1234567


def test_every_engine_settings_class_has_its_env_file_disabled_in_the_test_session():
    from pydantic_settings import BaseSettings

    pending, classes = list(BaseSettings.__subclasses__()), []
    while pending:
        cls = pending.pop()
        pending.extend(cls.__subclasses__())
        if cls.__module__.startswith("predictily_engine"):
            classes.append(cls)
    assert classes, "no se encontró ninguna clase de configuración del motor"
    assert all(not cls.model_config.get("env_file") for cls in classes), [c.__name__ for c in classes if c.model_config.get("env_file")]
    assert os.environ.get("ENGINE_ALLOW_UNSIGNED_ARTIFACTS") is None
