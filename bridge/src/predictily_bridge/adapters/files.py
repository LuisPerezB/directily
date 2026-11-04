"""Archivos CSV y Excel (.xlsx) de solo lectura. Estado: ``tested``.

Cada corrida lee los archivos que coinciden con ``path`` (admite ``*``), en orden de NOMBRE, y fija su lista al empezar (nombre + SHA-256), de
modo que reanudar a mitad de un archivo usa la misma lista. El cursor es (archivo, última fila entregada). Con ``known`` (archivos entregados
por corridas COMPLETAS anteriores: nombre → SHA-256) se omiten los que no cambiaron; un archivo con otro contenido se vuelve a leer completo
(la importación es idempotente por clave natural + huella). Los archivos se abren solo en modo lectura; no se modifican, mueven ni borran.
"""

from __future__ import annotations

import csv
import glob
import hashlib
from collections.abc import Iterator
from pathlib import Path
from typing import Any

from predictily_bridge.adapters.base import Page, SourceError, driver_errors
from predictily_bridge.config import Template

csv.field_size_limit(10_000_000)


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


class FileAdapter:
    read_only_enforcement = "archivos abiertos solo en modo lectura; nunca se modifican, mueven ni borran"

    def __init__(self, kind: str) -> None:
        self.kind = kind

    def open(self) -> None:
        pass

    def close(self) -> None:
        pass

    def describe(self) -> dict[str, Any]:
        return {"kind": self.kind, "server_version": None, "read_only_enforcement": self.read_only_enforcement, "writable_account": False, "warnings": []}

    def start_run(self, template: Template, known: dict[str, str] | None = None) -> dict[str, Any]:
        with driver_errors("No se pudo listar o leer los archivos de origen"):
            pattern = str(Path(str(template.path)).expanduser())
            paths = sorted((Path(p) for p in glob.glob(pattern) if Path(p).is_file()), key=lambda p: p.name)
            files = []
            for path in paths:
                sha = _sha256(path)
                if known is not None and known.get(path.name) == sha:
                    continue
                files.append({"name": path.name, "path": str(path), "sha256": sha})
            return {"files": files}

    def fetch_page(self, template: Template, *, since: Any, after: tuple[Any, ...] | None, limit: int, context: dict[str, Any]) -> Page:
        with driver_errors("No se pudieron leer los archivos de origen"):
            rows: list[dict[str, Any]] = []
            last: tuple[Any, ...] | None = None
            done_name, done_row = (after[0], int(after[1])) if after else (None, 0)
            for entry in context.get("files", []):
                if done_name is not None and entry["name"] < done_name:
                    continue
                skip = done_row if entry["name"] == done_name else 0
                for number, row in self._rows(template, Path(entry["path"])):
                    if number <= skip:
                        continue
                    if len(rows) == limit:
                        return Page(rows, last, False)  # hay al menos una fila más
                    rows.append(row)
                    last = (entry["name"], number)
            return Page(rows, last, True)

    def _rows(self, template: Template, path: Path) -> Iterator[tuple[int, dict[str, Any]]]:
        if self.kind == "csv":
            yield from self._csv_rows(template, path)
        else:
            yield from self._excel_rows(template, path)

    def _index(self, template: Template, header: list[Any], path: Path) -> dict[str, int]:
        names = [str(h).strip() if h is not None else "" for h in header]
        index = {}
        for canonical, source in template.columns.items():
            if source not in names:
                raise SourceError(f"El archivo {path.name} no tiene la columna «{source}» (campo {canonical}).")
            index[canonical] = names.index(source)
        return index

    def _csv_rows(self, template: Template, path: Path) -> Iterator[tuple[int, dict[str, Any]]]:
        try:
            with path.open("r", encoding=template.encoding, newline="") as handle:
                reader = csv.reader(handle, delimiter=template.delimiter)
                header = next(reader, None)
                if header is None:
                    return
                index = self._index(template, header, path)
                for number, record in enumerate(reader, start=1):
                    if not any(cell.strip() for cell in record):
                        continue
                    yield number, {c: (record[i].strip() if i < len(record) and record[i].strip() != "" else None) for c, i in index.items()}
        except (OSError, UnicodeDecodeError, csv.Error) as e:
            raise SourceError(f"No se pudo leer {path.name}: {type(e).__name__}") from None

    def _excel_rows(self, template: Template, path: Path) -> Iterator[tuple[int, dict[str, Any]]]:
        try:
            import openpyxl
        except ImportError:
            raise SourceError("Falta openpyxl: pip install 'predictily-bridge[excel]'.") from None
        try:
            workbook = openpyxl.load_workbook(path, read_only=True, data_only=True)
        except Exception as e:
            raise SourceError(f"No se pudo abrir {path.name}: {type(e).__name__}") from None
        try:
            sheet = workbook[template.sheet] if template.sheet else workbook.worksheets[0]
            iterator = sheet.iter_rows(values_only=True)
            header = next(iterator, None)
            if header is None:
                return
            index = self._index(template, list(header), path)
            for number, record in enumerate(iterator, start=1):
                if record is None or all(c is None or (isinstance(c, str) and not c.strip()) for c in record):
                    continue
                yield number, {c: (None if i >= len(record) or record[i] is None or (isinstance(record[i], str) and not record[i].strip()) else record[i]) for c, i in index.items()}
        finally:
            workbook.close()
