from __future__ import annotations

import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))
sys.path.insert(0, str(Path(__file__).resolve().parent))
FIXTURES = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def fixtures_dir() -> Path:
    return FIXTURES


@pytest.fixture
def home(tmp_path: Path) -> Path:
    """Directorio de estado del agente (0700) aislado por prueba."""
    d = tmp_path / "bridge-home"
    d.mkdir(mode=0o700)
    return d
