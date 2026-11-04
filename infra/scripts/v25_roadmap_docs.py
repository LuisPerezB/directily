#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generador determinista de la documentación de hoja de ruta V2.5 de Predictily.

Lee los JSON de la auditoría (``docs/roadmap/audit/<fecha>/*.json``) y escribe en ``docs/roadmap/``:

* ``V25_SOURCE_TRACEABILITY.md``  trazabilidad de fuentes y requisitos (columnas de V2.5 §0.2)
* ``V25_GAP_ANALYSIS.md``         brechas IMPLEMENTADO / PARCIAL / PENDIENTE / BLOQUEADO / NO APLICA
* ``V25_VERSION_DIFF.md``         cotejo literal de V1, V2 y complemento, y versiones sin texto fuente
* ``V25_RISK_REGISTER.md``        registro de riesgos con ID estable (R-001, ...)
* ``README.md``                   índice de los documentos de ``docs/roadmap``

Uso, desde la raíz del repositorio::

    python3 infra/scripts/v25_roadmap_docs.py            # regenera los cinco documentos
    python3 infra/scripts/v25_roadmap_docs.py --check    # código 1 si algún documento está desactualizado

Solo biblioteca estándar de Python 3. Sin marcas de tiempo ni aleatoriedad: la misma entrada produce
la misma salida byte a byte (además de los JSON, lee la tabla «Bloqueos que dependen del propietario» y las oleadas de
``V25_IMPLEMENTATION_PLAN.md``, los archivos fuente de las citas literales —V1, V2 e ``instrucctions.md``— para calcular su
literalidad, y el libro de IDs ``V25_RISK_IDS.json``, que el propio generador mantiene para que los R-nnn no cambien al regenerar). Regla de honestidad: ningún estado ni evidencia se inventa; todo sale de
los JSON. Donde un requisito tiene ``verification.verdict``, el estado vigente es ``status``
(``status_auditor`` conserva el del auditor original).
"""
from __future__ import annotations

import argparse
import collections
import json
import math
import re
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_AUDIT_DIR = "docs/roadmap/audit/2026-10-31"
# Progreso por oleada: cada archivo JSON registra cambios de estado de requisitos y defectos con evidencia, SIN tocar la
# auditoría base (que es evidencia de la línea base). Se aplican en orden de nombre de archivo.
PROGRESS_DIR = "docs/roadmap/audit/progress"
DEFECT_STATES = ("corregido", "corregido parcialmente", "mitigado", "diferido")
OUT_DIR = "docs/roadmap"
GENERATOR = "infra/scripts/v25_roadmap_docs.py"
SPEC_PATH = "docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md"
PLAN_PATH = "docs/roadmap/V25_IMPLEMENTATION_PLAN.md"
BASELINE_COMMIT = "78c5029"

OUT_FILES = {
    "trace": "V25_SOURCE_TRACEABILITY.md",
    "gap": "V25_GAP_ANALYSIS.md",
    "diff": "V25_VERSION_DIFF.md",
    "risk": "V25_RISK_REGISTER.md",
    "readme": "README.md",
}

STATUSES = ("IMPLEMENTADO", "PARCIAL", "PENDIENTE", "BLOQUEADO", "NO_APLICA")
STATUS_HEAD = {"NO_APLICA": "NO APLICA"}
STATUS_GAP_RANK = {"BLOQUEADO": 0, "PENDIENTE": 1, "PARCIAL": 2, "NO_APLICA": 3, "IMPLEMENTADO": 4}
STATUS_LEVEL = {"PENDIENTE": 0, "BLOQUEADO": 0, "PARCIAL": 1, "IMPLEMENTADO": 2}
PRIORITIES = ("P0", "P1", "P2", "P3")
SEVERITIES = ("HIGH", "MEDIUM", "LOW")
VERDICTS = ("CONFIRMED", "DOWNGRADED", "UPGRADED", "ADDED")
LABELS = ("LEGACY_PRESERVED", "EXTENDED", "MOVED_PHASE", "CONFLICT_RESOLVED", "NOT_VERIFIED")
LITERAL_STATUSES = ("IMPLEMENTADO", "IMPLEMENTADO_SINTETICO", "PARCIAL", "PENDIENTE", "BLOQUEADO", "NO_APLICA")

# Módulos (áreas) en el orden del plan; los prefijos y sus secciones salen de las notas de cada JSON.
AREAS = (
    {"key": "datos", "title": "Datos, calidad y disponibilidad temporal",
     "prefixes": {"CAN": "Contratos canónicos, proveniencia e identidades (§3)",
                  "DQE": "Data Quality Engine y gates (§5)",
                  "CAL": "Calendarios y feature pipeline (§25.4)",
                  "AVL": "Disponibilidad temporal (§24.5)"}},
    {"key": "conectores", "title": "Conectores, Bridge, Auto Mapper y cargas",
     "prefixes": {"BRG": "Bridge y adaptadores (§4)",
                  "MAP": "Auto Mapper (§4)",
                  "UPL": "Cargas a AWS (§4)"}},
    {"key": "demanda", "title": "Demand Core, selección de modelos y horizontes",
     "prefixes": {"DEM": "Demand Core (§6)",
                  "SEL": "Selección y validación de modelos (§8)",
                  "HOR": "Catálogo de horizontes (§25.1)",
                  "APQ": "API/QA y aceptación V2 (§25.7)"}},
    {"key": "cliente", "title": "Customer Core y agenda comercial",
     "prefixes": {"CUS": "Customer Core (§7, §25.2, T04)",
                  "AGD": "Agenda comercial y contratos API V2.2 (§25.3, T16)"}},
    {"key": "modelos_aws", "title": "Ciclo de vida de modelos, Registry y AWS",
     "prefixes": {"REG": "Model lifecycle, Registry, CLI y serving (§9, T05)",
                  "AWS": "AWS con costo controlado (§14, T09)"}},
    {"key": "supply_decision", "title": "Supply Core, Decision Engine y Outcomes",
     "prefixes": {"SUP": "Supply Core (§10, §25.5, T06)",
                  "DEC": "Decision Engine (§11)",
                  "OUT": "Outcomes, ROI y experimentación (§12, T07)"}},
    {"key": "seguridad_ui", "title": "Seguridad, UI, Intelligence Gateway y onboarding",
     "prefixes": {"GWY": "Intelligence Gateway, agentes y WhatsApp (§13)",
                  "SEC": "Seguridad y privacidad (§15, T08)",
                  "UIX": "UI/UX y planes comerciales (§16)",
                  "ONB": "Onboarding Enterprise y operación del propietario (§17)"}},
    {"key": "factores_sector", "title": "Factor Intelligence, taxonomía y módulos sectoriales",
     "prefixes": {"FAC": "Factor Intelligence (§24, T11, T12, T14)",
                  "TAX": "Taxonomía de productos (§24.2, T13)",
                  "SCT": "Perfiles y módulos sectoriales (§24.11, §24.12, §25.6, T15, T17)"}},
)
AREA_KEYS = tuple(a["key"] for a in AREAS)
AREA_BY_KEY = {a["key"]: a for a in AREAS}

# Oleada del plan (docs/roadmap/V25_IMPLEMENTATION_PLAN.md §3) a la que pertenece cada paquete (slice).
SLICE_WAVE = {"S01": "W2", "S02": "W2", "S03": "W2", "S04": "W3", "S05": "W3", "S06": "W4", "S13": "W4",
              "S07": "W5", "S08": "W5", "S09": "W5", "S10": "W5", "S14": "W5",
              "S11": "W6", "S12": "W6", "S15": "W6", "S16": "W6", "S17": "W6", "S18": "W6"}

W1_PACKAGES = {
    "W1-A1": "W1-A1 · temporal backend",
    "W1-A2": "W1-A2 · motor as-of",
    "W1-B": "W1-B · seguridad",
}

FAMILY_NAME = {"V2.5": "V2.5", "V2.1": "V2.1", "V2.2": "V2.2", "V2.3": "V2.3", "V2.4": "V2.4 estándar",
               "V2.4E": "V2.4 Enterprise", "V2": "V2", "V1": "V1", "OTRA": "otra"}
RECONSTRUCTED_FAMILIES = ("V2.1", "V2.2", "V2.3", "V2.4", "V2.4E")

LABEL_MEANING = {
    "LEGACY_PRESERVED": "V2.5 conserva el requisito sin cambiar su alcance (explícitamente o por la regla de no pérdida de §0.5 y §23).",
    "EXTENDED": "V2.5 lo conserva y añade obligaciones o alcance.",
    "MOVED_PHASE": "V2.5 lo conserva pero cambia su fase o prioridad (P0-P3).",
    "CONFLICT_RESOLVED": "La versión fuente y V2.5 difieren y V2.5 fija una resolución (§25.1, §27).",
    "NOT_VERIFIED": "No se puede afirmar la conciliación: V2.5 no lo menciona o no lo enuncia de forma verificable, o el texto fuente no se recuperó.",
}

# Dependencias externas de los requisitos BLOQUEADO (se evalúan, en orden, sobre el texto «Requiere ...»).
DEP_CATEGORIES = (
    ("whatsapp", "Cuenta de WhatsApp Business", re.compile(r"whatsapp", re.I)),
    ("windows", "Host Windows y certificado de firma de código", re.compile(r"windows|authenticode", re.I)),
    ("legal", "Revisión legal y contratos de tratamiento", re.compile(r"asesor[ií]a legal|abogado", re.I)),
    ("responsabilidades", "Responsabilidades humanas del propietario y del cliente piloto",
     re.compile(r"acciones humanas|participaci[oó]n del cliente", re.I)),
    ("aws", "Cuenta AWS, presupuesto y credenciales de despliegue",
     re.compile(r"\baws\b|\bs3\b|\bkms\b|\becs\b|\bsqs\b|cloudfront|eventbridge", re.I)),
    ("datos", "Datos reales autorizados del cliente piloto",
     re.compile(r"datos reales|\bvpn\b|export|autoriz|hist[oó]ric|piloto", re.I)),
)
DEP_OTHER = ("otra", "Otra dependencia externa")
# Filas del plan (§4) que corresponden a cada categoría, por palabra clave de su primera celda.
DEP_PLAN_KEYWORD = {"aws": r"aws", "datos": r"datos reales", "windows": r"windows", "whatsapp": r"whatsapp",
                    "legal": r"legal"}
DEP_ENV_KEYWORD = {"aws": r"sin cuenta ni credenciales aws", "datos": r"sin datos reales", "windows": r"sin host windows"}
DEP_OWNER_FALLBACK = {
    "aws": "crear cuenta/organización, presupuesto y alertas; rol de despliegue con mínimo privilegio; dominio y certificados",
    "datos": "contrato y autorización de tratamiento; sesión con acceso a la VPN y usuario de solo lectura",
    "windows": "equipo Windows 10/11 o Server para empaquetar y probar; certificado de firma",
    "whatsapp": "cuenta y número de WhatsApp Business verificados",
    "legal": "revisión por abogado y firma de cada cliente en los contratos de tratamiento",
    "responsabilidades": ("ejecutar las acciones del propietario (cuenta y presupuesto AWS, dominios, credenciales, acuerdos, aprobación de "
                          "mapeos, reglas y modelos; V2.5 §17) y obtener del cliente piloto la validación de definiciones, KPI y outcomes"),
}
# Separa «lo que requiere» de «lo construible localmente» en el texto de un requisito BLOQUEADO.
BUILD_SPLIT = re.compile(
    r"(?i)(?:\blo\s+que\s+se\s+puede\s+construir|\blo\s+construible|\bse\s+puede\s+construir|\bconstruible)"
    r"(?:\s+(?:localmente|aquí|ahora|hoy))*(?:\s+y\s+verificable)?\s*:?\s*")
LEDGER_NAME = "V25_RISK_IDS.json"
KIND_NAME = {"defecto": "defectos de la auditoría", "dependencia": "dependencias bloqueadas",
             "entorno": "restricciones del entorno", "regresion": "cotejo literal (regresión o pérdida)"}

ID_RE = re.compile(r"V25-[A-Z]{3}-\d{3}")
PATH_RE = re.compile(r"(?:[\w.-]+/)+[\w.-]+\.[A-Za-z]{1,5}")
TOKEN_RE = re.compile(r"[a-záéíóúñü][a-záéíóúñü0-9_]{3,}")
STOPWORDS = frozenset(
    "de la el los las un una unos unas y o u e en a con por para que se del al como es son su sus lo le no si "
    "más mas sin sobre entre desde hasta este esta estos estas ese esa pero ya ser hay cada otro otra tras ante "
    "bajo cuando donde cual cuyo solo también tambien puede pueden debe deben todo toda todos todas hoy ahora "
    "aún aun".split())

# ----------------------------------------------------------------------------------------------
# Utilidades de texto Markdown
# ----------------------------------------------------------------------------------------------

_WS = re.compile(r"\s+")
_TAG = re.compile(r"<(?=[A-Za-z/!?])")
_UNDERSCORE = re.compile(r"(?<![A-Za-z0-9])_|_(?![A-Za-z0-9])")
_BACKSLASH = re.compile(r"\\(?=[!-/:-@\[-`{-~]|$)")


def squash(text) -> str:
    """Colapsa espacios y saltos de línea."""
    return _WS.sub(" ", "" if text is None else str(text)).strip()


def clip(text, limit: int) -> str:
    """Recorta a ``limit`` caracteres en un límite de palabra, con elipsis."""
    t = squash(text)
    if len(t) <= limit:
        return t
    cut = t[: limit - 1]
    sp = cut.rfind(" ")
    if sp >= int(limit * 0.6):
        cut = cut[:sp]
    return cut.rstrip(" ,;:.-–—(¿¡[") + "…"


def _esc_plain(t: str) -> str:
    t = _BACKSLASH.sub(lambda m: "\\\\", t)
    t = t.replace("|", "\\|").replace("*", "\\*").replace("$", "\\$")
    t = _TAG.sub("&lt;", t)
    return _UNDERSCORE.sub(lambda m: "\\_", t)


def esc(text) -> str:
    """Escapa texto libre para usarlo en una celda de tabla o en un párrafo (conserva los `códigos`)."""
    t = squash(text)
    if t.count("`") % 2:
        t += "`"
    parts = t.split("`")
    for i, p in enumerate(parts):
        parts[i] = _esc_plain(p) if i % 2 == 0 else p.replace("|", "\\|")
    return "`".join(parts)


def cell(text, limit: int | None = None) -> str:
    """Recorta (opcional) y escapa; vacío => guion largo."""
    t = squash(text)
    if not t:
        return "—"
    if limit:
        t = clip(t, limit)
    return esc(t)


def md_table(headers, rows, align: str | None = None) -> str:
    """Tabla GFM; todas las filas deben tener el mismo número de celdas que la cabecera."""
    n = len(headers)
    marks = []
    for i in range(n):
        a = (align or "l" * n)[i]
        marks.append({"r": "---:", "c": ":---:"}.get(a, "---"))
    out = ["| " + " | ".join(headers) + " |", "| " + " | ".join(marks) + " |"]
    for row in rows:
        if len(row) != n:
            raise ValueError(f"fila con {len(row)} celdas en una tabla de {n} columnas: {row[:2]}")
        out.append("| " + " | ".join(row) + " |")
    return "\n".join(out)


def slug(heading: str) -> str:
    """Ancla de encabezado al estilo de GitHub."""
    s = heading.strip().lower()
    s = re.sub(r"[^\w\s-]", "", s, flags=re.UNICODE)
    return re.sub(r"\s", "-", s)


def num(n: int) -> str:
    return str(n)


def pct(a: int, b: int) -> str:
    return "0,0 %" if not b else f"{100.0 * a / b:.1f} %".replace(".", ",")


def status_head(s: str) -> str:
    return STATUS_HEAD.get(s, s)


def counter_row(counter, keys) -> list[str]:
    return [num(counter.get(k, 0)) for k in keys]


# ----------------------------------------------------------------------------------------------
# Carga y enriquecimiento de datos
# ----------------------------------------------------------------------------------------------

class InputError(RuntimeError):
    pass


def load_json(path: Path):
    try:
        with path.open(encoding="utf-8") as fh:
            return json.load(fh)
    except FileNotFoundError as exc:
        raise InputError(f"falta el archivo de entrada: {path}") from exc
    except json.JSONDecodeError as exc:
        raise InputError(f"JSON inválido en {path}: {exc}") from exc


def version_family(sv: str) -> str:
    s = (sv or "").strip()
    if s in ("V2.5", "NUEVO_V25"):
        return "V2.5"
    m = re.match(r"^V2\.(\d)(E?)(?![A-Za-z0-9])", s)
    if m:
        return f"V2.{m.group(1)}{m.group(2)}"
    if re.match(r"^V2(?![.\w])", s):
        return "V2"
    if re.match(r"^V1(?![.\w])", s):
        return "V1"
    return "OTRA"


def source_commit(sources, prefixes, default):
    for s in sources:
        v = s.get("version", "")
        if any(v.startswith(p) for p in prefixes) and s.get("commit"):
            return str(s["commit"]).split()[0]
    return default


def source_path(sources, prefixes, default):
    for s in sources:
        v = s.get("version", "")
        if any(v.startswith(p) for p in prefixes):
            m = re.search(r"[\w./-]+\.md", s.get("location", ""))
            if m:
                return m.group(0)
    return default


# Marcas de formato Markdown en línea que una cita puede omitir respecto del archivo fuente (negrita y código).
MD_INLINE = re.compile(r"\*\*|`")
QUOTE_SEP = " | "   # separa los fragmentos literales de una fila que agrupa varios ítems de lista


def quote_literalness(root: Path, rel: str, rows) -> collections.Counter | None:
    """Compara las citas (``quote``) de ``rows`` con el archivo fuente ``rel`` (ruta relativa a la raíz del repositorio).

    Clases (excluyentes, en este orden): ``exact`` la cita completa es subcadena del archivo; ``grouped`` no lo es, pero lo es cada
    fragmento separado por « | » (ítems de lista agrupados en una fila); ``formatted`` lo es cada fragmento una vez quitadas del
    archivo las marcas de negrita (dos asteriscos) y de código (comilla invertida), que la cita omite; ``unmatched`` el resto.
    Devuelve ``None`` si el archivo no existe o no se puede leer."""
    try:
        text = (root / rel).read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return None
    plain = MD_INLINE.sub("", text)
    out = collections.Counter()
    for r in rows:
        q = r["quote"]
        frags = q.split(QUOTE_SEP)
        if q in text:
            out["exact"] += 1
        elif len(frags) > 1 and all(f in text for f in frags):
            out["grouped"] += 1
        elif all(f in plain for f in frags):
            out["formatted"] += 1
        else:
            out["unmatched"] += 1
    out["total"] = len(rows)
    return out


def quote_summary(stats: collections.Counter | None) -> str:
    """Texto de una línea con el resultado de ``quote_literalness`` (``no verificadas`` si no se pudo leer el archivo)."""
    if stats is None:
        return "no verificadas (falta el archivo fuente)"

    def verb(n: int, one: str, many: str) -> str:
        return one if n == 1 else many

    n = stats["total"]
    parts = [f"{stats['exact']} de {n} {verb(stats['exact'], 'es subcadena exacta', 'son subcadenas exactas')}"]
    g, f, u = stats["grouped"], stats["formatted"], stats["unmatched"]
    if g:
        parts.append(f"{g} {verb(g, 'agrupa', 'agrupan')} fragmentos separados por « | » (cada fragmento es literal)")
    if f:
        parts.append(f"{f} {verb(f, 'omite', 'omiten')} marcas de formato Markdown (negrita o código) del original")
    if u:
        parts.append(f"{u} {verb(u, 'NO SE HALLA', 'NO SE HALLAN')} en el archivo (revisar el JSON)")
    return "; ".join(parts)


class Ctx:
    """Todo lo leído y derivado una sola vez."""

    def __init__(self, root: Path, audit_dir: str):
        self.root = root
        self.audit_dir = audit_dir
        self.audit_date = Path(audit_dir).name
        audit = root / audit_dir
        self.audit_rel = "audit/" + Path(audit_dir).name  # relativo a docs/roadmap
        self.areas = []
        for a in AREAS:
            d = load_json(audit / f"{a['key']}.json")
            for k in ("requirements", "defects", "conflicts"):
                if not isinstance(d.get(k), list):
                    raise InputError(f"{a['key']}.json: falta la lista '{k}'")
            self.areas.append(dict(a, data=d))
        self.v1 = load_json(audit / "v1_literal.json")
        self.v2 = load_json(audit / "v2_literal.json")
        self.baseline = load_json(audit / "baseline_metrics.json")
        plan = root / PLAN_PATH
        self.plan_text = plan.read_text(encoding="utf-8") if plan.exists() else ""
        # Requisitos enriquecidos (copias; no se muta el JSON)
        self.reqs = []
        for a in self.areas:
            for r in a["data"]["requirements"]:
                e = dict(r)
                e["area"] = a["key"]
                e["prefix"] = r["id"].split("-")[1] if r["id"].startswith("V25-") else ""
                e["status"] = str(r.get("status", "")).strip().upper().replace(" ", "_")
                e["verdict"] = (r.get("verification") or {}).get("verdict")
                e["family"] = version_family(r.get("source_version", ""))
                e["status_auditor_n"] = (str(r["status_auditor"]).strip().upper()
                                         if r.get("status_auditor") else None)
                self.reqs.append(e)
        self.by_id = {r["id"]: r for r in self.reqs}
        self.progress, self.defect_progress = load_progress(root, self)
        self.reqs_by_area = {k: [r for r in self.reqs if r["area"] == k] for k in AREA_KEYS}
        self.sources = self.v1.get("sources", [])
        self.v2_sources = self.v2.get("sources", [])
        self.v1_path = source_path(self.sources, ("V1",), "docs/roadmap/sources/V1_PROMPT_MAESTRO_ORIGINAL.md")
        self.v2_path = source_path(self.sources + self.v2_sources, ("V2 (", "V2"), "PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md")
        self.v1_commit = source_commit(self.sources, ("V1",), BASELINE_COMMIT)
        self.v2_commit = source_commit(self.sources, ("V2 (",), "193ca45")
        meta = self.baseline.get("meta") or {}
        self.audited_code = (meta.get("repo") or {}).get("audited_code", "")
        self.severity_scale = meta.get("severity_scale", {})
        self.owner_blocks = parse_owner_blocks(self.plan_text)
        self.risks = []          # se rellena en build_risks()
        self.defect_risk = {}    # (area, índice) -> ID de riesgo
        self.v1_rows = self.v1["requirements"]
        self.v2_rows = [r for r in self.v2["requirements"] if r["id"].startswith("V2-")]
        self.v2c_rows = [r for r in self.v2["requirements"] if r["id"].startswith("V2C-")]
        self.v2c_path = "instrucctions.md"
        # Literalidad de las citas frente al archivo fuente (se calcula al generar; None si falta el archivo)
        self.quote_stats = {"V1": quote_literalness(root, self.v1_path, self.v1_rows),
                            "V2": quote_literalness(root, self.v2_path, self.v2_rows),
                            "V2C": quote_literalness(root, self.v2c_path, self.v2c_rows)}
        self.retired = []
        self.pkg_basis = collections.Counter()   # cómo se asignó el paquete de cada defecto
        self.ledger_text = ""
        self.blocked = classify_blocked(self)


def load_progress(root: Path, ctx: "Ctx"):
    """Aplica `docs/roadmap/audit/progress/*.json` sobre los requisitos (copias) y devuelve (archivos, progreso de defectos).

    Formato: {"meta": {"wave", "date", "commit", "summary"}, "requirements": [{"id", "status", "gap"?, "evidence": [...], "note"}],
    "defects": [{"area", "index", "title_prefix", "state", "evidence": [...], "note"}]}. `status_baseline` conserva el estado de la
    auditoría; un requisito IMPLEMENTADO debe llegar con `gap` vacío y con evidencia (la prueba que lo afirma)."""
    pdir = root / PROGRESS_DIR
    files, defects = [], {}
    if not pdir.is_dir():
        return files, defects
    areas = {a["key"]: a for a in ctx.areas}
    for f in sorted(pdir.glob("*.json")):
        rel = f.relative_to(root).as_posix()
        p = load_json(f)
        meta = p.get("meta") or {}
        wave = meta.get("wave")
        if not wave:
            raise InputError(f"{rel}: falta meta.wave")
        changed = []
        for e in p.get("requirements", []):
            r = ctx.by_id.get(e.get("id"))
            if r is None:
                raise InputError(f"{rel}: requisito desconocido {e.get('id')!r}")
            status = str(e.get("status", "")).strip().upper().replace(" ", "_")
            if status not in STATUSES:
                raise InputError(f"{rel}: {e['id']}: estado {status!r} fuera de {STATUSES}")
            if not e.get("evidence"):
                raise InputError(f"{rel}: {e['id']}: un cambio de estado exige evidencia")
            r.setdefault("status_baseline", r["status"])
            before = r["status"]
            r["status"] = status
            if "gap" in e:
                r["gap"] = e["gap"]
            r["evidence"] = list(r.get("evidence") or []) + [f"{wave}: {x}" for x in e["evidence"]]
            r.setdefault("progress", []).append(dict(e, wave=wave, file=rel, before=before))
            changed.append((r["id"], before, status))
        for e in p.get("defects", []):
            a = areas.get(e.get("area"))
            idx = e.get("index")
            if a is None or not isinstance(idx, int) or not (0 <= idx < len(a["data"]["defects"])):
                raise InputError(f"{rel}: defecto inexistente {e.get('area')!r} #{idx!r}")
            title = a["data"]["defects"][idx].get("title", "")
            if not title.startswith(str(e.get("title_prefix", ""))) or not e.get("title_prefix"):
                raise InputError(f"{rel}: {e['area']} #{idx}: title_prefix no coincide con el título del defecto")
            if e.get("state") not in DEFECT_STATES:
                raise InputError(f"{rel}: {e['area']} #{idx}: estado {e.get('state')!r} fuera de {DEFECT_STATES}")
            if not e.get("evidence"):
                raise InputError(f"{rel}: {e['area']} #{idx}: falta evidencia")
            defects[(e["area"], idx)] = dict(e, wave=wave, file=rel)
        files.append(dict(file=rel, meta=meta, changed=changed, defects=len(p.get("defects", []))))
    return files, defects


def progress_section(ctx: "Ctx", number: str) -> list[str]:
    """Sección «Progreso por oleada» (cambios de estado registrados después de la auditoría base)."""
    L = ["", f"### {number} Progreso por oleada (después de la auditoría base)", ""]
    if not ctx.progress:
        return L + ["Sin cambios registrados: los estados son los de la auditoría base.", ""]
    L += [f"Cambios registrados en `{PROGRESS_DIR}/` (la auditoría base no se modifica). Estado de cada requisito: el vigente; "
          "«antes» es el de la auditoría.", ""]
    rows = []
    for p in ctx.progress:
        m = p["meta"]
        moves = collections.Counter(f"{b} → {s}" for _, b, s in p["changed"] if b != s)
        rows.append([esc(m.get("wave", "")), esc(m.get("date", "")), f"`{esc(m.get('commit', '—'))}`", num(len(p["changed"])),
                     cell(" · ".join(f"{k} {v}" for k, v in sorted(moves.items())) or "—", 200), num(p["defects"]),
                     cell(m.get("summary", ""), 300), f"[{esc(Path(p['file']).name)}]({esc(Path(p['file']).relative_to(OUT_DIR).as_posix())})"])
    L.append(md_table(["Oleada", "Fecha", "Commit", "Requisitos", "Cambios", "Defectos", "Resumen", "Archivo"], rows, "lllrlrll"))
    L.append("")
    return L


def parse_owner_blocks(plan_text: str):
    """Filas (bloqueo, afecta, acción) de la tabla «Bloqueos que dependen del propietario» del plan."""
    m = re.search(r"^##\s*4\.\s*Bloqueos que dependen del propietario\s*$(.*?)(?=^##\s|\Z)", plan_text, re.S | re.M)
    rows = []
    if not m:
        return rows
    for line in m.group(1).splitlines():
        line = line.strip()
        if not line.startswith("|") or re.match(r"^\|\s*:?-+", line) or line.lower().startswith("| bloqueo"):
            continue
        cells = [c.strip() for c in line.strip("|").split("|")]
        if len(cells) >= 3:
            rows.append((cells[0], cells[1], cells[2]))
    return rows


def plan_slice_waves(plan_text: str) -> dict[str, str]:
    """Oleada (W2 ... W6) de cada paquete ``S##`` según las tablas de la sección 3 de V25_IMPLEMENTATION_PLAN.md."""
    out: dict[str, str] = {}
    wave = None
    for line in plan_text.splitlines():
        m = re.match(r"^###\s+(W\d)\b", line)
        if m:
            wave = m.group(1)
            continue
        if re.match(r"^##\s", line):
            wave = None
            continue
        m = re.match(r"^\|\s*(S\d{2})\b", line)
        if m and wave:
            out.setdefault(m.group(1), wave)
    return out


def package_label(slice_name: str) -> str:
    code = (slice_name or "")[:3]
    wave = SLICE_WAVE.get(code)
    return f"{wave} · {slice_name}" if wave else (slice_name or "—")


def req_link(rid: str, doc: str = OUT_FILES["trace"]) -> str:
    return f"[{rid}]({doc}#{rid.lower()})"


def req_anchor(rid: str) -> str:
    return f'<a id="{rid.lower()}"></a>{rid}'


def responsible(r) -> str:
    return "Propietario" if r["status"] == "BLOQUEADO" else "Agente implementador"


def state_cell(r) -> str:
    s = r["status"]
    v = r["verdict"]
    t = s if not v else f"{s} ✓ {v}"
    if v in ("DOWNGRADED", "UPGRADED") and r["status_auditor_n"]:
        t += f" (antes {r['status_auditor_n']})"
    if r.get("progress"):
        t += f" · {r['progress'][-1]['wave']} (auditoría: {r['status_baseline']})"
    return esc(t)


def defect_state(ctx: "Ctx", area: str, index: int) -> str:
    p = ctx.defect_progress.get((area, index))
    return f"{p['state']} ({p['wave']})" if p else "abierto"


def source_cell(ctx: Ctx, r) -> str:
    fam = r["family"]
    sec = clip(r.get("section", ""), 70)
    if fam == "V2.5":
        return esc(f"NUEVO_V25 · V2.5 {sec}")
    if fam in RECONSTRUCTED_FAMILIES:
        return esc(f"RECONSTRUIDO_PARCIALMENTE (citado en V2.5 {sec})")
    if fam == "V1":
        return esc(f"LITERAL · {ctx.v1_path} @ {ctx.v1_commit} (V2.5 {sec})")
    if fam == "V2":
        return esc(f"LITERAL · {ctx.v2_path} @ {ctx.v2_commit} (V2.5 {sec})")
    return esc(f"{r.get('source_version', '?')} (V2.5 {sec})")


def source_bucket(r) -> str:
    fam = r["family"]
    if fam == "V2.5":
        return "NUEVO_V25"
    if fam in RECONSTRUCTED_FAMILIES:
        return f"RECONSTRUIDO_PARCIALMENTE · {FAMILY_NAME[fam]}"
    if fam in ("V1", "V2"):
        return f"LITERAL · {FAMILY_NAME[fam]}"
    return "OTRA"


def source_bucket_order(b: str) -> int:
    order = ["NUEVO_V25", "RECONSTRUIDO_PARCIALMENTE · V2.1", "RECONSTRUIDO_PARCIALMENTE · V2.2",
             "RECONSTRUIDO_PARCIALMENTE · V2.3", "RECONSTRUIDO_PARCIALMENTE · V2.4 estándar",
             "RECONSTRUIDO_PARCIALMENTE · V2.4 Enterprise", "LITERAL · V2", "LITERAL · V1", "OTRA"]
    return order.index(b) if b in order else len(order)


def best_gap(r) -> tuple[str, bool]:
    """Brecha del requisito; si el JSON la dejó vacía, la nota de verificación (marcada)."""
    g = squash(r.get("gap", ""))
    if g:
        return g, False
    note = squash((r.get("verification") or {}).get("note", ""))
    return note, True


def evidence_cell(r, limit: int, n: int = 2, more: bool = True) -> str:
    ev = [e for e in r.get("evidence", []) if squash(e)]
    parts = [esc(clip(e, limit)) for e in ev[:n]]
    if more and len(ev) > n:
        parts.append(f"(+{len(ev) - n} en el JSON)")
    return "<br>".join(parts) if parts else "—"


# ----------------------------------------------------------------------------------------------
# Consistencia de los datos de entrada
# ----------------------------------------------------------------------------------------------

def key_names(obj, found=None, path=""):
    """Recorre un JSON y devuelve los nombres de clave con su ruta (para buscar campos opcionales)."""
    if found is None:
        found = []
    if isinstance(obj, dict):
        for k, v in obj.items():
            found.append((path + "/" + k, k))
            key_names(v, found, path + "/" + k)
    elif isinstance(obj, list):
        for v in obj:
            key_names(v, found, path + "[]")
    return found


def check_consistency(ctx: Ctx):
    """Lista de comprobaciones (nombre, nº de incidencias, detalle, severidad_info)."""
    checks = []
    reqs = ctx.reqs
    ids = collections.Counter(r["id"] for r in reqs)
    dup = sorted(k for k, v in ids.items() if v > 1)
    checks.append(("IDs de requisito duplicados entre módulos", len(dup), ", ".join(dup)))
    bad_status = sorted(r["id"] for r in reqs if r["status"] not in STATUSES)
    checks.append(("Estado fuera de IMPLEMENTADO / PARCIAL / PENDIENTE / BLOQUEADO / NO_APLICA", len(bad_status),
                   ", ".join(bad_status[:12])))
    no_gap = sorted(r["id"] for r in reqs if r["status"] in ("PARCIAL", "PENDIENTE", "BLOQUEADO") and not squash(r.get("gap")))
    checks.append(("Requisitos PARCIAL / PENDIENTE / BLOQUEADO con `gap` vacío (se usa la nota de verificación)",
                   len(no_gap), ", ".join(no_gap)))
    impl_gap = sorted(r["id"] for r in reqs if r["status"] == "IMPLEMENTADO" and squash(r.get("gap")))
    checks.append(("Requisitos IMPLEMENTADO con `gap` no vacío", len(impl_gap), ", ".join(impl_gap[:12])))
    vocab = collections.Counter(r.get("source_version") for r in reqs
                                if r["family"] == "V2.5" and r.get("source_version") != "V2.5")
    checks.append(("`source_version` NUEVO_V25 (debería ser V2.5; se trata como V2.5)", sum(vocab.values()),
                   ", ".join(sorted(r["id"] for r in reqs if r.get("source_version") == "NUEVO_V25"))))
    sec = [r["id"] for r in reqs if re.search(r"§", str(r.get("source_version", "")))]
    checks.append(("`source_version` con sección incrustada (p. ej. «V2.1 §24.5»; se normaliza a la versión)", len(sec),
                   ", ".join(sorted(collections.Counter(r["source_version"] for r in reqs
                                                        if re.search("§", str(r.get("source_version", "")))))) ))
    dec = collections.Counter(r.get("decision") for r in reqs)
    outside = {k: v for k, v in dec.items() if k not in ("CONSERVAR", "AMPLIAR", "DEPRECAR", "CONFLICTO")}
    checks.append(("`decision` fuera del vocabulario de V2.5 §0.2 (CONSERVAR / AMPLIAR / DEPRECAR / CONFLICTO): la auditoría añade NUEVO_V25",
                   sum(outside.values()), ", ".join(f"{k}: {v}" for k, v in sorted(outside.items()))))
    aud = collections.Counter()
    for r in reqs:
        if r["verdict"] == "ADDED":
            aud[str(r.get("status_auditor"))] += 1
    checks.append(("Requisitos ADDED cuyo `status_auditor` es «NO_EXISTIA» en lugar de null (vocabulario distinto entre módulos)",
                   aud.get("NO_EXISTIA", 0), ", ".join(f"{k}: {v}" for k, v in sorted(aud.items()))))
    incoh = []
    for r in reqs:
        v, s, a = r["verdict"], r["status"], r["status_auditor_n"]
        if v is None:
            incoh.append(f"{r['id']} (sin veredicto)")
        elif v == "CONFIRMED" and a != s:
            incoh.append(f"{r['id']} (CONFIRMED con {a} → {s})")
        elif v == "DOWNGRADED" and not (a in STATUS_LEVEL and s in STATUS_LEVEL and STATUS_LEVEL[s] < STATUS_LEVEL[a]):
            incoh.append(f"{r['id']} (DOWNGRADED {a} → {s})")
        elif v == "UPGRADED" and not (a in STATUS_LEVEL and s in STATUS_LEVEL and STATUS_LEVEL[s] > STATUS_LEVEL[a]):
            incoh.append(f"{r['id']} (UPGRADED {a} → {s})")
        elif v == "ADDED" and a not in (None, "NO_EXISTIA"):
            incoh.append(f"{r['id']} (ADDED con status_auditor {a})")
        elif v not in VERDICTS:
            incoh.append(f"{r['id']} (veredicto {v})")
    checks.append(("Veredicto de verificación incoherente con los estados (auditor → vigente)", len(incoh),
                   ", ".join(incoh[:12])))
    badprio = sorted(r["id"] for r in reqs if r.get("priority") not in PRIORITIES)
    checks.append(("Prioridad fuera de P0-P3", len(badprio), ", ".join(badprio[:12])))
    badslice = sorted({r.get("slice") for r in reqs if (r.get("slice") or "")[:3] not in SLICE_WAVE})
    checks.append(("Paquete (`slice`) sin oleada asignada en el generador (`SLICE_WAVE`)", len(badslice), ", ".join(map(str, badslice))))
    plan_waves = plan_slice_waves(ctx.plan_text)
    if plan_waves:
        drift = sorted(f"{k}: generador {SLICE_WAVE[k]} / plan {plan_waves[k]}" for k in SLICE_WAVE
                       if k in plan_waves and plan_waves[k] != SLICE_WAVE[k])
        absent = sorted(k for k in SLICE_WAVE if k not in plan_waves)
        checks.append((f"Oleada de un paquete en el generador distinta de la del plan ({PLAN_PATH} §3) o paquete que el plan no menciona",
                       len(drift) + len(absent), "; ".join(drift + [f"{k}: no figura en el plan" for k in absent])))
    else:
        checks.append((f"Oleadas del plan ({PLAN_PATH} §3) no legibles: no se pudo contrastar `SLICE_WAVE`", 1, "plan ausente o con otro formato"))
    badprefix = sorted({r["prefix"] for r in reqs if r["prefix"] not in AREA_BY_KEY[r["area"]]["prefixes"]})
    checks.append(("Prefijo de ID sin submódulo declarado en el generador", len(badprefix), ", ".join(badprefix)))
    noev = sorted(r["id"] for r in reqs if not [e for e in r.get("evidence", []) if squash(e)])
    checks.append(("Requisitos sin ninguna cita de evidencia", len(noev), ", ".join(noev[:12])))
    # Defectos
    nover = 0
    refuted = []
    for a in ctx.areas:
        for i, d in enumerate(a["data"]["defects"]):
            v = (d.get("verification") or {}).get("verdict")
            if v is None:
                nover += 1
            if v == "REFUTED":
                refuted.append(f"{a['key']} #{i + 1}")
    total_def = sum(len(a["data"]["defects"]) for a in ctx.areas)
    checks.append((f"Defectos sin anotación de verificación (de {total_def}; solo demanda, cliente y seguridad_ui la traen)",
                   nover, ", ".join(f"{a['key']}: {sum(1 for d in a['data']['defects'] if not d.get('verification'))}"
                                    for a in ctx.areas)))
    checks.append(("Defectos refutados por la verificación (se conservan en la tabla marcados; no cuentan como riesgo abierto)",
                   len(refuted), ", ".join(refuted)))
    badsev = sorted(f"{a['key']} #{i + 1}" for a in ctx.areas for i, d in enumerate(a["data"]["defects"])
                    if d.get("severity") not in SEVERITIES)
    checks.append(("Defectos con severidad fuera de HIGH / MEDIUM / LOW", len(badsev), ", ".join(badsev)))
    # Campo opcional del cotejo literal
    hits = []
    for name, data in (("v1_literal.json", ctx.v1), ("v2_literal.json", ctx.v2)):
        hits += [name for _, k in key_names(data) if "regress" in k.lower()]
    checks.append(("Campo `regressions_risk` en el cotejo literal (V1/V2): ausente ⇒ el riesgo de regresión se deriva "
                   "de las filas no cubiertas por V2.5", 0 if hits else 1, "presente" if hits else "ausente en ambos JSON"))
    # Etiquetas / estados del cotejo
    badlabel = sorted(r["id"] for r in ctx.v1_rows + ctx.v2_rows + ctx.v2c_rows if r.get("label") not in LABELS)
    checks.append(("Cotejo literal: etiqueta fuera de las cinco de V2.5 §28", len(badlabel), ", ".join(badlabel[:12])))
    badlit = sorted(r["id"] for r in ctx.v1_rows + ctx.v2_rows + ctx.v2c_rows if r.get("current_status") not in LITERAL_STATUSES)
    checks.append(("Cotejo literal: estado actual fuera del vocabulario", len(badlit), ", ".join(badlit[:12])))
    v1_unc_lab = sum(1 for r in ctx.v1_rows if squash(r["v25_coverage"]).lower().startswith("no cubierto")
                     and r["label"] != "NOT_VERIFIED")
    v2_unc_lab = sum(1 for r in ctx.v2_rows + ctx.v2c_rows if squash(r["v25_coverage"]).lower().startswith("no cubierto")
                     and r["label"] != "NOT_VERIFIED")
    checks.append(("Criterio de NOT_VERIFIED distinto entre V1 y V2: filas «no cubierto en V2.5» que V1 etiqueta LEGACY_PRESERVED "
                   "y V2/V2C etiquetan NOT_VERIFIED", v1_unc_lab,
                   f"V1: {v1_unc_lab} filas no cubiertas con otra etiqueta; V2/V2C: {v2_unc_lab}"))
    nv_nocov = sorted(r["id"] for r in ctx.v1_rows + ctx.v2_rows + ctx.v2c_rows
                      if r["label"] == "NOT_VERIFIED" and not squash(r["v25_coverage"]).lower().startswith("no cubierto"))
    checks.append(("Cotejo literal: filas NOT_VERIFIED cuya cobertura no empieza por «no cubierto en V2.5» (las notas de `v2_literal.json` dicen que sí)",
                   len(nv_nocov), ", ".join(nv_nocov)))
    # Bandera `literal` de las fuentes frente a lo que dicen sus notas (la tabla de fuentes muestra lo que dicen las notas)
    flag = [f"{src.get('version', '?')}: literal=true, se muestra «{literal_cell(src)}»" for src in ctx.sources
            if src.get("literal") and literal_cell(src) != "Sí"]
    checks.append(("`sources[].literal` = true en v1_literal.json aunque las notas dicen que la fuente no es literal o lo es solo en parte "
                   "(la tabla de fuentes sigue las notas)", len(flag), "; ".join(flag)))
    # Literalidad de las citas: las notas de v2_literal.json afirman «citas verificadas como subcadenas exactas»
    not_exact = 0
    unmatched = 0
    qdetail = []
    for k in ("V1", "V2", "V2C"):
        st = ctx.quote_stats.get(k)
        if st is None:
            qdetail.append(f"{k}: sin verificar (falta el archivo fuente)")
            continue
        not_exact += st["total"] - st["exact"]
        unmatched += st["unmatched"]
        qdetail.append(f"{k}: {st['exact']} de {st['total']} exactas, {st['grouped']} agrupadas con « | », {st['formatted']} sin formato Markdown")
    checks.append(("Cotejo literal: citas que no son subcadena exacta del archivo fuente (agrupan fragmentos con « | » u omiten negrita o código "
                   "del original), pese a que las notas de `v2_literal.json` las dan por «subcadenas exactas»", not_exact,
                   "; ".join(qdetail) + f"; sin hallar: {unmatched}"))
    return checks


# ----------------------------------------------------------------------------------------------
# Defectos -> riesgos
# ----------------------------------------------------------------------------------------------

W1_RULES = (
    (re.compile(r"ssrf", re.I), ("W1-B",)),
    (re.compile(r"zip[ -]?bomb", re.I), ("W1-B",)),
    (re.compile(r"joblib|pickle|deserializ", re.I), ("W1-B",)),
    (re.compile(r"huecos|ceros", re.I), ("W1-A2",)),
    (re.compile(r"compras y devoluciones|estado actual del documento", re.I), ("W1-A2",)),
    (re.compile(r"marca de agua|watermark", re.I), ("W1-A1",)),
    (re.compile(r"fuera de orden|sobrescribe la versi", re.I), ("W1-A1",)),
    (re.compile(r"tipos y estados", re.I), ("W1-A1",)),
    (re.compile(r"cargadas tarde|anulaci|anuladas|sin versiones", re.I), ("W1-A1", "W1-A2")),
)


def w1_packages(defect) -> tuple[str, ...]:
    if defect.get("severity") != "HIGH":
        return ()
    title = defect.get("title", "")
    for rx, pk in W1_RULES:
        if rx.search(title):
            return pk
    return ()


class Related:
    """Relaciona un defecto con el requisito más afín del mismo módulo (para heredar su paquete)."""

    def __init__(self, ctx: Ctx):
        self.ctx = ctx
        self.paths = {}
        df = collections.Counter()
        for r in ctx.reqs:
            txt = " ".join(r.get("evidence", [])) + " " + r.get("gap", "") + " " + r.get("risk", "") + " " + r.get("acceptance_test", "")
            ps = set(PATH_RE.findall(txt))
            self.paths[r["id"]] = ps
            df.update(ps)
        self.n = len(ctx.reqs)
        self.pdf = df
        wdf = collections.Counter()
        self.counts = {}
        for r in ctx.reqs:
            c = collections.Counter(self.tokens(r["title"] + " " + r["requirement"] + " " + r.get("gap", "")))
            self.counts[r["id"]] = c
            wdf.update(c.keys())
        self.wdf = wdf
        self.vecs = {k: self.vec(c) for k, c in self.counts.items()}
        self.norms = {k: math.sqrt(sum(x * x for x in v.values())) or 1.0 for k, v in self.vecs.items()}

    @staticmethod
    def tokens(text):
        return [w for w in TOKEN_RE.findall(text.lower()) if w not in STOPWORDS]

    def widf(self, w):
        return math.log((self.n + 1) / (self.wdf.get(w, 0) + 0.5))

    def pidf(self, p):
        return math.log((self.n + 1) / (self.pdf.get(p, 0) + 0.5))

    def vec(self, counter):
        return {w: (1 + math.log(c)) * self.widf(w) for w, c in counter.items()}

    def cos_paths(self, a, b):
        if not a or not b:
            return 0.0
        num_ = sum(self.pidf(p) for p in a & b)
        da = math.sqrt(sum(self.pidf(p) ** 2 for p in a))
        db = math.sqrt(sum(self.pidf(p) ** 2 for p in b))
        return num_ / (da * db) if da and db else 0.0

    def relate(self, area_key, defect):
        """(id de requisito, base, puntuación). Base: 'id' (citado) o 'inferido'."""
        for part in (defect.get("fix", ""), defect.get("title", ""), defect.get("impact", ""), defect.get("evidence", "")):
            for rid in ID_RE.findall(part):
                if rid in self.ctx.by_id:
                    return rid, "id", 1.0
        dps = set(PATH_RE.findall(defect.get("evidence", "") + " " + defect.get("fix", "")))
        dv = self.vec(collections.Counter(self.tokens(
            defect.get("title", "") + " " + defect.get("fix", "") + " " + defect.get("impact", "") + " " + defect.get("evidence", ""))))
        dn = math.sqrt(sum(x * x for x in dv.values())) or 1.0
        cands = []
        for r in self.ctx.reqs_by_area[area_key]:
            v = self.vecs[r["id"]]
            lex = sum(x * v.get(w, 0.0) for w, x in dv.items()) / (dn * self.norms[r["id"]])
            sc = round(0.5 * lex + 0.5 * self.cos_paths(dps, self.paths[r["id"]]), 6)
            cands.append((sc, r["id"], r["slice"]))
        if not cands:
            return None, "sin requisitos", 0.0
        cands.sort(key=lambda t: (-t[0], t[1]))
        top = cands[:3] if cands[0][0] >= 0.10 else cands[:8]
        votes = collections.Counter()
        for sc, _, sl in top:
            votes[sl] += sc
        win = sorted(votes.items(), key=lambda kv: (-kv[1], kv[0]))[0][0]
        best = [c for c in top if c[2] == win][0]
        return best[1], "inferido", best[0]


def classify_blocked(ctx: "Ctx"):
    """Clasifica los requisitos BLOQUEADO por dependencia externa y separa «requiere» de «construible localmente»."""
    out = []
    for r in ctx.reqs:
        if r["status"] != "BLOQUEADO":
            continue
        gap = squash(r.get("gap", ""))
        m = BUILD_SPLIT.search(gap)
        if m:
            req_part, build_part = gap[: m.start()].strip(), gap[m.end():].strip()
        else:
            req_part, build_part = gap, ""
            acc = squash(r.get("acceptance_test", ""))
            ma = BUILD_SPLIT.search(acc)
            if ma:
                build_part = acc[ma.end():].strip() + " (del criterio de aceptación)"
        key, label = DEP_OTHER
        for k, lab, rx in DEP_CATEGORIES:
            if rx.search(req_part):
                key, label = k, lab
                break
        r["dep"], r["dep_label"], r["req_part"], r["build_part"] = key, label, req_part, build_part
        out.append(r)
    return out


def risk_key(r) -> str:
    """Clave de identidad de un riesgo para el libro de IDs (no depende de la posición ni de los conteos)."""
    if r.get("key"):
        return r["key"]
    return f"{r['kind']}|{r['area']}|{squash(r['title']).lower()}"


def assign_ids(ctx: "Ctx", risks):
    """IDs R-nnn estables: se conservan los ya asignados (libro V25_RISK_IDS.json) y los nuevos se añaden al final."""
    path = ctx.root / OUT_DIR / LEDGER_NAME
    entries = {}
    if path.exists():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            for e in data.get("entries", []):
                entries[e["key"]] = {"id": e["id"], "key": e["key"]}
        except (json.JSONDecodeError, KeyError, TypeError) as exc:
            raise InputError(f"libro de IDs ilegible ({path}): {exc}") from exc
    nxt = max((int(e["id"][2:]) for e in entries.values()), default=0) + 1
    seen = set()
    for r in risks:
        k = risk_key(r)
        if k in seen:
            raise InputError(f"clave de riesgo duplicada: {k[:100]}")
        seen.add(k)
        e = entries.get(k)
        if e is None:
            e = {"id": f"R-{nxt:03d}", "key": k}
            entries[k] = e
            nxt += 1
        r["id"] = e["id"]
    ordered = sorted(entries.values(), key=lambda e: e["id"])
    for e in ordered:
        e["retired"] = e["key"] not in seen
        if not e["retired"]:
            e.pop("retired")
        else:
            e["retired"] = True
    ctx.retired = [e for e in ordered if e.get("retired")]
    ctx.ledger_text = json.dumps(
        {"version": 1,
         "note": ("Libro de IDs de riesgo generado por " + GENERATOR + ". Conserva la correspondencia clave -> ID para que los R-nnn no "
                  "cambien al regenerar; no editar a mano. Las claves retiradas nunca se reutilizan."),
         "entries": ordered}, ensure_ascii=False, indent=1) + "\n"


def probability_of(severity, reproduced):
    if severity == "HIGH" or (severity == "MEDIUM" and reproduced):
        return "Alta"
    if severity == "MEDIUM" or (severity == "LOW" and reproduced):
        return "Media"
    return "Baja"


REPRO_RE = re.compile(r"sonda|reprodu|ejecutad|snippet|probe|midi[óo]|medido|\bprobado\b", re.I)


def build_risks(ctx: Ctx):
    """Construye la lista de riesgos con ID estable y la guarda en ``ctx.risks``."""
    related = Related(ctx)
    risks = []

    def add(**kw):
        risks.append(kw)

    # 1) Defectos de auditoría (orden: módulo, posición en el JSON)
    for a in ctx.areas:
        for i, d in enumerate(a["data"]["defects"]):
            sev = d.get("severity", "LOW")
            ver = d.get("verification") or {}
            verdict = ver.get("verdict")
            w1 = w1_packages(d)
            rid, basis, score = related.relate(a["key"], d)
            req = ctx.by_id.get(rid) if rid else None
            if w1:
                pkg = " + ".join(W1_PACKAGES[p] for p in w1)
                pkg_note = "tema del defecto HIGH (plan W1)"
                ctx.pkg_basis["tema W1"] += 1
            elif req:
                pkg = package_label(req["slice"])
                pkg_note = (f"requisito citado {rid}" if basis == "id"
                            else f"inferido de {rid}" + ("" if score >= 0.10 else ", baja confianza"))
                ctx.pkg_basis["citado" if basis == "id" else ("inferido" if score >= 0.10 else "baja confianza")] += 1
            else:
                pkg = "—"
                pkg_note = "sin requisito relacionado"
                ctx.pkg_basis["sin relación"] += 1
            repro = bool(REPRO_RE.search(d.get("evidence", "") + " " + ver.get("note", "")))
            refuted = verdict == "REFUTED"
            progress = ctx.defect_progress.get((a["key"], i))
            if refuted:
                state = "descartado (refutado en la verificación)"
            elif progress:
                state = f"{progress['state']} ({progress['wave']})"
            elif w1:
                state = "en corrección (W1)"
            else:
                state = "abierto"
            add(kind="defecto", area=a["key"], index=i, severity=sev, title=d.get("title", ""),
                origin=f"Defecto {sev} · {a['key']} #{i + 1}" + (f" · verificación {verdict}" if verdict else ""),
                origin_link=f"{ctx.audit_rel}/{a['key']}.json",
                probability=("n/a" if refuted else probability_of(sev, repro)),
                prob_reason=("refutado por la verificación" if refuted else (
                    "severidad HIGH" if sev == "HIGH" else (
                        f"{sev}" + (" con fallo reproducido" if repro else " sin reproducción")))),
                impact=d.get("impact", ""), mitigation=d.get("fix", ""), package=pkg, package_note=pkg_note,
                owner=("Agente implementador" + (f" ({', '.join(w1)})" if w1 else "")), state=state,
                related=rid if req else None, w1=w1)
    # 2) Dependencias bloqueadas (BLOQUEADO)
    cats = {}
    for r in ctx.blocked:
        cats.setdefault(r["dep"], {"label": r["dep_label"], "reqs": []})["reqs"].append(r)
    env_items = (ctx.baseline.get("8_environment_constraints") or {}).get("items", [])
    used_env = set()
    for key in [c[0] for c in DEP_CATEGORIES] + [DEP_OTHER[0]]:
        if key not in cats:
            continue
        c = cats[key]
        rs = sorted(c["reqs"], key=lambda x: (PRIORITIES.index(x["priority"]) if x["priority"] in PRIORITIES else 9, x["id"]))
        top_prio = rs[0]["priority"]
        sev = {"P0": "HIGH", "P1": "MEDIUM"}.get(top_prio, "LOW")
        prio_count = collections.Counter(x["priority"] for x in rs)
        slices = collections.Counter(x["slice"] for x in rs).most_common(3)
        plan_row = None
        kw = DEP_PLAN_KEYWORD.get(key)
        if kw:
            for row in ctx.owner_blocks:
                if re.search(kw, row[0], re.I):
                    plan_row = row
                    break
        owner_action = plan_row[2] if plan_row else DEP_OWNER_FALLBACK.get(key, "aportar lo indicado en «Requiere» de cada requisito")
        affects = plan_row[1] if plan_row else ""
        env_note = ""
        ek = DEP_ENV_KEYWORD.get(key)
        if ek:
            for j, item in enumerate(env_items):
                if re.search(ek, item, re.I):
                    used_env.add(j)
                    env_note = f" · restricción del entorno: «{clip(item, 110)}»"
                    break
        ids = ", ".join(x["id"] for x in rs[:8]) + (f" … (+{len(rs) - 8})" if len(rs) > 8 else "")
        add(kind="dependencia", area="—", index=None, severity=sev, key=f"dependencia|{key}",
            title=f"Dependencia externa sin resolver: {c['label']} — bloquea {len(rs)} "
                  f"{'requisito' if len(rs) == 1 else 'requisitos'} BLOQUEADO ({ids})",
            origin="Dependencia bloqueada (requisitos BLOQUEADO)" + env_note,
            origin_link=None, probability="Alta", prob_reason="hecho comprobado: la dependencia no existe en este entorno",
            impact=("Prioridades afectadas: " + ", ".join(f"{p} × {prio_count[p]}" for p in PRIORITIES if prio_count.get(p))
                    + (f". Afecta: {affects}." if affects else ".") + " Riesgo declarado en el requisito más prioritario: "
                    + squash(rs[0].get("risk", ""))),
            mitigation=("Construir localmente lo independiente (columna «Construible localmente» de la sección BLOQUEADOS de "
                        f"{OUT_FILES['gap']}) y entregar al propietario instrucciones exactas. Acción del propietario: {owner_action}."),
            package=" · ".join(f"{package_label(s)} ({n})" for s, n in slices), package_note="paquetes de los requisitos bloqueados",
            owner="Propietario", state="abierto", related=None, w1=())
    # 3) Restricciones del entorno que no son un bloqueo de requisitos
    info_rx = re.compile(r"^(disponibles:|restricciones de proceso|base de desarrollo)", re.I)
    env_rules = (
        (re.compile(r"^docker:", re.I), "MEDIUM", "W4 · S13-aws-iac", "Propietario (host con Docker) / Agente implementador (CI)",
         "No se pueden construir ni levantar imágenes en este entorno y `docker compose config` no valida sin .env: el despliegue en contenedores (T09, V25-AWS-003) no se ejercita localmente.",
         "Construir y probar las imágenes en CI o en un host del propietario con daemon; validar la composición con un .env de prueba y documentar el comando exacto en `docs/operations/AWS_DEPLOYMENT.md`."),
        (re.compile(r"^sin servidores mysql", re.I), "MEDIUM", "W6 · S12-bridge-conectores", "Propietario",
         "Los conectores MySQL/MariaDB y SQL Server quedan `implemented_untested` (2 pruebas omitidas del backend) y T01 exige probar al menos una fuente SQL real con su driver exacto (Gate A).",
         "Instancia de prueba o acceso de solo lectura (plan §4); entretanto no declarar esos conectores como soportados (V2.5 §4)."),
        (re.compile(r"^sin gpu", re.I), "LOW", "W4 · S06-registry-cli", "Propietario",
         "Solo se valida el camino CPU del entrenamiento; el camino GPU opcional (V2.5 §9) no se ejercita.",
         "La CLI no tiene dependencia obligatoria de GPU; validar el camino GPU en el equipo del propietario cuando corresponda."),
        (re.compile(r"^red de salida", re.I), "LOW", "W4 · S13-aws-iac", "Propietario",
         "No se pueden descargar binarios externos (Terraform, motores SQL propietarios) y los conectores MCP quedan bloqueados por el proxy: la IaC solo se verifica por síntesis.",
         "Verificar la IaC con `cdk synth` (ADR-V25-09) y el controlador S3 contra un emulador etiquetado (ADR-V25-08); pedir al propietario las verificaciones que exigen binarios o red externa."),
        (re.compile(r"^python del sistema", re.I), "LOW", "W6 · S17-docs-gates", "Agente implementador",
         "Ejecutar con el intérprete del sistema en lugar del entorno virtual del motor puede producir fallos o resultados distintos.",
         "Usar siempre `engine/.venv` en scripts y CI y fijar la versión de Python en la canalización."),
        (re.compile(r"^ci/cd:", re.I), "MEDIUM", "W4 · S13-aws-iac", "Agente implementador",
         "Sin canalización efectiva no hay evidencia reproducible de los gates en cada cambio.",
         "Crear la canalización de CI con las suites de la línea base (ver V25_IMPLEMENTATION_PLAN.md §2) y publicar su registro."),
        (re.compile(r"^head de ", re.I), "LOW", "W6 · S17-docs-gates", "Agente implementador",
         "Las citas archivo:línea de la auditoría pueden no coincidir con el árbol vivo cuando otros agentes cambian el código en paralelo.",
         f"Tomar el commit de línea base `{BASELINE_COMMIT}` como referencia de las citas y regenerar esta documentación al cierre de cada paquete (plan §6)."),
    )
    skip_rx = re.compile(r"^(sin cuenta ni credenciales aws|sin datos reales|sin host windows)", re.I)
    skipped_info = 0
    skipped_dep = 0
    for j, item in enumerate(env_items):
        it = squash(item)
        if info_rx.search(it):
            skipped_info += 1
            continue
        if skip_rx.search(it) or j in used_env:
            skipped_dep += 1
            continue
        first = clip(re.split(r"(?<=[.;])\s", it, maxsplit=1)[0], 170).rstrip(";,: ")
        for rx, sev, pkg, owner, impact, mit in env_rules:
            if rx.search(it):
                extra = ""
                see = None
                if rx.pattern.startswith("^ci/cd"):
                    see = re.compile(r"CI efectivo", re.I)
                    for r in ctx.reqs:
                        if r["title"].lower().startswith("ci/cd"):
                            extra = f" Requisito relacionado: {r['id']}."
                            break
                add(kind="entorno", area="—", index=None, severity=sev, title=f"Restricción del entorno: {first}",
                    key=f"entorno|{it.lower()[:90]}", see=see,
                    origin=f"Restricción del entorno (baseline_metrics.json · 8_environment_constraints #{j + 1})",
                    origin_link=f"{ctx.audit_rel}/baseline_metrics.json", probability="Alta",
                    prob_reason="hecho comprobado en el entorno de desarrollo", impact=impact + extra, mitigation=mit,
                    package=pkg, package_note="criterio del generador", owner=owner, state="abierto", related=None, w1=())
                break
        else:
            add(kind="entorno", area="—", index=None, severity="LOW", title=f"Restricción del entorno: {first}",
                key=f"entorno|{it.lower()[:90]}",
                origin=f"Restricción del entorno (baseline_metrics.json · 8_environment_constraints #{j + 1})",
                origin_link=f"{ctx.audit_rel}/baseline_metrics.json", probability="Alta",
                prob_reason="hecho comprobado en el entorno de desarrollo",
                impact="Restricción sin clasificar por el generador: revisar el texto completo en el JSON.",
                mitigation="Decidir con el arquitecto si requiere una acción.", package="—", package_note="sin clasificar",
                owner="Arquitecto", state="abierto", related=None, w1=())
    ctx.env_skipped = (skipped_info, skipped_dep)
    # 4) Riesgo de pérdida / regresión del cotejo literal (V1, V2, V2C)
    regress_field = any("regress" in k.lower() for _, k in key_names(ctx.v1) + key_names(ctx.v2))
    groups = (("V1", ctx.v1_rows, "V1"), ("V2", ctx.v2_rows, "V2"), ("V2C (instrucctions.md)", ctx.v2c_rows, "V2C"))
    for name, rows, short in groups:
        unc = [r for r in rows if r["label"] == "NOT_VERIFIED" or squash(r["v25_coverage"]).lower().startswith("no cubierto")]
        done = [r for r in unc if r["current_status"] in ("IMPLEMENTADO", "IMPLEMENTADO_SINTETICO")]
        todo = [r for r in unc if r["current_status"] in ("PARCIAL", "PENDIENTE", "BLOQUEADO")]
        origin = ("Cotejo literal (campo regressions_risk)" if regress_field
                  else "Cotejo literal V1/V2: filas no cubiertas por V2.5 (el JSON no trae `regressions_risk`)")

        def idlist(rs):
            return ", ".join(r["id"] for r in rs[:10]) + (f" … (+{len(rs) - 10})" if len(rs) > 10 else "")

        if done:
            add(kind="regresion", area="—", index=None, severity="MEDIUM", key=f"regresion|{short}|implementados",
                title=(f"Regresión silenciosa: {len(done)} {'requisito' if len(done) == 1 else 'requisitos'} {name} "
                       + ("implementado hoy no está cubierto" if len(done) == 1 else "implementados hoy no están cubiertos")
                       + f" por V2.5 ({idlist(done)})"),
                origin=origin, origin_link=f"{ctx.audit_rel}/{'v1_literal' if short == 'V1' else 'v2_literal'}.json",
                probability="Media", prob_reason="sin defecto reproducido; depende de que el trabajo de V2.5 los toque sin protegerlos",
                impact="Capacidades ya construidas que la especificación objetivo no enuncia: V2.5 §0.5 prohíbe eliminar funciones demostradas, pero ningún gate las vigila.",
                mitigation=("Mantener pruebas de regresión de estos IDs (V2.5 §25.7: «Mantener compatibilidad de V1 mediante pruebas de regresión») "
                            f"y confirmar con el propietario cuáles se preservan. Lista completa en {OUT_FILES['diff']} (sección «No mencionados por la V2.5»)."),
                package=package_label("S17-docs-gates"), package_note="criterio del generador", owner="Agente implementador",
                state="abierto", related=None, w1=())
        if todo:
            add(kind="regresion", area="—", index=None, severity="MEDIUM", key=f"regresion|{short}|sin-implementar",
                title=(f"Pérdida de requisitos: {len(todo)} {'requisito' if len(todo) == 1 else 'requisitos'} {name} "
                       f"{'no cubierto' if len(todo) == 1 else 'no cubiertos'} por V2.5 y sin implementar por completo ({idlist(todo)})"),
                origin=origin, origin_link=f"{ctx.audit_rel}/{'v1_literal' if short == 'V1' else 'v2_literal'}.json",
                probability="Alta", prob_reason="nadie los posee: ni V2.5 ni el código los respaldan hoy",
                impact="Requisitos heredados que desaparecerían sin decisión explícita (V2.5 §0.5: no eliminar requisitos válidos solo por cambiar el producto prioritario).",
                mitigation=f"Decisión del propietario por fila (incorporar a un paquete o descartar con ADR). Lista en {OUT_FILES['diff']}.",
                package=package_label("S17-docs-gates"), package_note="criterio del generador", owner="Propietario",
                state="abierto", related=None, w1=())
    assign_ids(ctx, risks)
    for r in risks:
        if r["kind"] == "defecto":
            ctx.defect_risk[(r["area"], r["index"])] = r["id"]
        if r.get("see"):
            for o in risks:
                if o["kind"] == "defecto" and r["see"].search(o["title"]):
                    r["impact"] = squash(r["impact"]) + f" Ver también {o['id']}."
                    break
    ctx.risks = risks


# ----------------------------------------------------------------------------------------------
# Documento A: trazabilidad de fuentes y requisitos
# ----------------------------------------------------------------------------------------------

def header_block(title: str, ctx: Ctx, what: str) -> list[str]:
    return [
        f"# {title}",
        "",
        f"> Documento **generado** por `{GENERATOR}` a partir de `{ctx.audit_dir}/*.json` ({what}). "
        f"No editar a mano: corregir el JSON de la auditoría y regenerar con `python3 {GENERATOR}`.",
        "",
    ]


def status_matrix(rows_by_key, key_order, key_header, statuses=STATUSES, total_label="Total"):
    """Matriz clave × estado con totales. rows_by_key: {clave: Counter de estados}."""
    heads = [key_header] + [status_head(s) for s in statuses] + ["Total"]
    rows = []
    tot = collections.Counter()
    for k in key_order:
        c = rows_by_key.get(k, collections.Counter())
        tot.update(c)
        rows.append([esc(k)] + counter_row(c, statuses) + [num(sum(c.get(s, 0) for s in statuses))])
    rows.append([f"**{total_label}**"] + [f"**{num(tot.get(s, 0))}**" for s in statuses]
                + [f"**{num(sum(tot.get(s, 0) for s in statuses))}**"])
    return md_table(heads, rows, "l" + "r" * (len(statuses) + 1))


def build_traceability(ctx: Ctx) -> str:
    L = header_block("Predictily V2.5 · Trazabilidad de fuentes y requisitos", ctx,
                     "auditoría por 8 áreas con verificación adversarial")
    total = len(ctx.reqs)
    by_status = collections.Counter(r["status"] for r in ctx.reqs)
    by_verdict = collections.Counter(r["verdict"] for r in ctx.reqs)
    L += [
        md_table(["Dato", "Valor"], [
            ["Fecha de la auditoría", esc(ctx.audit_date)],
            ["Commit de línea base", f"`{BASELINE_COMMIT}`; código auditado: {esc(ctx.audited_code) or 'ver baseline_metrics.json'}"],
            ["Rama", "`v25/enterprise-core`"],
            ["Especificación", f"`{SPEC_PATH}` (V2.5 §0.2 define las columnas de las tablas)"],
            ["Requisitos trazados", f"{total} en {len(ctx.areas)} módulos (áreas)"],
            ["Estados vigentes", esc(" · ".join(f"{s} {by_status.get(s, 0)}" for s in STATUSES))],
            ["Verificación adversarial", esc(" · ".join(f"{v} {by_verdict.get(v, 0)}" for v in VERDICTS))
             + f" ({sum(by_verdict.get(v, 0) for v in VERDICTS)} de {total} con veredicto)"],
        ]),
        "",
        "## Contenido",
        "",
        "- [1. Método](#1-método)",
        "- [2. Fuentes](#2-fuentes)",
        "- [3. Resumen](#3-resumen)",
        "- [4. Lectura de las columnas](#4-lectura-de-las-columnas)",
    ]
    for i, a in enumerate(ctx.areas, start=1):
        L.append(f"- [5.{i} Módulo {a['key']}](#5{i}-módulo-{slug(a['key'])})")
    L += ["", "## 1. Método", ""]
    L += [
        "1. **Auditoría por 8 áreas.** Un auditor por área leyó la especificación V2.5 y contrastó cada obligación con el código real "
        "(migraciones, servicios, motor, pruebas, rutas, documentos) de la rama `v25/enterprise-core`, con sondas de solo lectura "
        "que no escriben en bases de datos. Cada obligación se extrajo como un requisito atómico con ID estable `V25-<PREFIJO>-<n>`.",
        "2. **Regla de estado.** IMPLEMENTADO exige código, una prueba que lo afirme y coincidencia con la redacción de V2.5; "
        "las referencias a clases o documentos no cuentan. Salvo indicación expresa, la evidencia usa datos sintéticos: "
        "ningún requisito está validado con datos reales de clientes.",
        "3. **Verificación adversarial.** Un segundo revisor independiente abrió las evidencias citadas, leyó el cuerpo de las pruebas, "
        "intentó refutar los IMPLEMENTADO y PARCIAL y buscó evidencia oculta en los PENDIENTE. Veredictos: CONFIRMED, DOWNGRADED "
        "(estado rebajado), UPGRADED (estado subido) y ADDED (requisito omitido por el auditor). El campo `status` del JSON es el estado "
        "vigente; `status_auditor` conserva el original y `verification.note` explica la decisión.",
        f"4. **Cotejo literal** de V1, V2 y su complemento contra V2.5 y contra el repositorio: ver [{OUT_FILES['diff']}]({OUT_FILES['diff']}).",
        f"5. **Línea base.** Commit `{BASELINE_COMMIT}`; suites y métricas en `docs/roadmap/evidence/` y `{ctx.audit_rel}/baseline_metrics.json`. "
        f"Las citas archivo:línea corresponden a ese commit.",
        "6. **Ortografía de las etiquetas.** V2.5 §0.2 escribe `RECONSTRUIDO_PARCIAMENTE`; aquí se usa `RECONSTRUIDO_PARCIALMENTE`.",
        "",
        "## 2. Fuentes",
        "",
        "Qué se halló y qué no (V2.5 §0, instrucción retrospectiva 1). V2.1, V2.2, V2.3, V2.4 estándar y V2.4 Enterprise **no se hallaron**: "
        "sus requisitos son `RECONSTRUIDO_PARCIALMENTE` a partir de las citas de la V2.5 y no se declaran cotejados literalmente "
        "(V2.5 §0.2 y §28).",
        "",
    ]
    L.append(sources_table(ctx))
    L += [
        "",
        "Reglas de la columna **Fuente**:",
        "",
        "- `NUEVO_V25 · V2.5 §x`: el requisito nace de la V2.5 (campo `source_version` = V2.5).",
        "- `RECONSTRUIDO_PARCIALMENTE (citado en V2.5 §x)`: atribuido a V2.1, V2.2, V2.3 o V2.4 Enterprise; el texto fuente no existe en el "
        "repositorio y solo se conoce por la cita de la V2.5 indicada.",
        f"- `LITERAL · <ruta> @ <commit> (V2.5 §x)`: atribuido a V1 o V2, cuyo texto sí existe en el repositorio (`{ctx.v1_path}` @ `{ctx.v1_commit}` "
        f"para V1; `{ctx.v2_path}` @ `{ctx.v2_commit}` para V2, extracto de §0 a §10).",
        "",
        "## 3. Resumen",
        "",
        "### 3.1 Módulos por estado vigente",
        "",
    ]
    L.append(status_matrix({a["key"]: collections.Counter(r["status"] for r in ctx.reqs_by_area[a["key"]]) for a in ctx.areas},
                           AREA_KEYS, "Módulo"))
    L += ["", "### 3.2 Fuente por estado vigente", ""]
    buckets = collections.defaultdict(collections.Counter)
    for r in ctx.reqs:
        buckets[source_bucket(r)][r["status"]] += 1
    L.append(status_matrix(buckets, sorted(buckets, key=source_bucket_order), "Fuente"))
    L += ["", "### 3.3 Veredictos de la verificación por módulo", ""]
    rows = []
    for a in ctx.areas:
        c = collections.Counter(r["verdict"] for r in ctx.reqs_by_area[a["key"]])
        rows.append([esc(a["key"])] + counter_row(c, VERDICTS) + [num(len(ctx.reqs_by_area[a["key"]]))])
    rows.append(["**Total**"] + [f"**{by_verdict.get(v, 0)}**" for v in VERDICTS] + [f"**{total}**"])
    L.append(md_table(["Módulo"] + list(VERDICTS) + ["Total"], rows, "l" + "r" * 5))
    L += [
        "",
        "## 4. Lectura de las columnas",
        "",
        "Las columnas siguen V2.5 §0.2 (ID, descripción, fuente y ubicación, versión, módulo, decisión, estado real, evidencia, prueba, "
        "prioridad, responsable, riesgos).",
        "",
        md_table(["Columna", "Contenido"], [
            ["ID", "ID estable `V25-<PREFIJO>-<n>`; cada ID es un ancla (`#v25-xxx-nnn`) enlazable desde otros documentos."],
            ["Requisito", "Título y enunciado breve (recortado)."],
            ["Fuente", "Ver reglas de la sección 2."],
            ["Versión", "Valor `source_version` del JSON, sin normalizar."],
            ["Módulo", "`<área>/<prefijo>`; los prefijos se explican en cada módulo."],
            ["Decisión", "CONSERVAR, AMPLIAR o CONFLICTO (V2.5 §0.2). La auditoría añade NUEVO_V25 (requisito nuevo); DEPRECAR no aparece."],
            ["Estado", "Estado vigente. `✓ VEREDICTO` indica que la verificación adversarial lo comprobó; en DOWNGRADED y UPGRADED se muestra el estado previo del auditor."],
            ["Evidencia", "Hasta 2 citas recortadas a 140 caracteres; `(+N en el JSON)` indica más citas en el detalle."],
            ["Prueba", "Criterio de aceptación (`acceptance_test`) recortado."],
            ["Prioridad", "P0-P3 según V2.5 §20 y §26."],
            ["Responsable", "Propietario si el estado es BLOQUEADO; Agente implementador en otro caso (V2.5 §17)."],
            ["Riesgo", "Riesgo declarado por la auditoría, recortado."],
        ]),
        "",
    ]
    heads = ["ID", "Requisito", "Fuente", "Versión", "Módulo", "Decisión", "Estado", "Evidencia", "Prueba", "Prioridad",
             "Responsable", "Riesgo"]
    for i, a in enumerate(ctx.areas, start=1):
        rs = ctx.reqs_by_area[a["key"]]
        sc = collections.Counter(r["status"] for r in rs)
        vc = collections.Counter(r["verdict"] for r in rs)
        L += [
            f"## 5.{i} Módulo {a['key']}",
            "",
            f"**{esc(a['title'])}.** Secciones auditadas: {esc(', '.join(a['data'].get('sections', [])))}.",
            "",
            f"{len(rs)} requisitos · " + " · ".join(f"{s} {sc.get(s, 0)}" for s in STATUSES)
            + " · verificación: " + " · ".join(f"{v} {vc.get(v, 0)}" for v in VERDICTS) + ".",
            "",
            md_table(["Prefijo", "Submódulo", "Requisitos"],
                     [[f"`{p}`", esc(d), num(sum(1 for r in rs if r["prefix"] == p))] for p, d in a["prefixes"].items()], "llr"),
            "",
        ]
        rows = []
        for r in rs:
            req_txt = f"**{esc(clip(r['title'], 110))}** — {esc(clip(r['requirement'], 120))}"
            rows.append([
                req_anchor(r["id"]),
                req_txt,
                source_cell(ctx, r),
                esc(r.get("source_version", "")),
                esc(f"{r['area']}/{r['prefix']}"),
                esc(r.get("decision", "")),
                state_cell(r),
                evidence_cell(r, 140),
                cell(r.get("acceptance_test"), 150),
                esc(r.get("priority", "")),
                responsible(r),
                cell(r.get("risk"), 110),
            ])
        L.append(md_table(heads, rows))
        L += ["", f"Detalle completo (evidencia íntegra, brecha, esfuerzo, paquete y nota de verificación de cada requisito): "
                  f"[{a['key']}.json]({ctx.audit_rel}/{a['key']}.json).", ""]
    return "\n".join(L).rstrip() + "\n"


def literal_cell(src) -> str:
    notes = squash(src.get("notes", ""))
    if not src.get("literal"):
        return "No"
    if re.match(r"(?i)literal parcial", notes):
        return "Parcial (extracto)"
    if re.search(r"NO es una versi[oó]n literal", notes):
        return "Archivo íntegro; no es cita literal de V2"
    return "Sí"


def sources_table(ctx: Ctx) -> str:
    rows = []
    v2_by = {}
    for s in ctx.v2_sources:
        key = "comp" if "complement" in s.get("version", "").lower() else "v2"
        v2_by[key] = s
    for s in ctx.sources:
        ver = s.get("version", "")
        low = ver.lower()
        key = "comp" if "complement" in low else ("v2" if re.match(r"^v2( |$)", low) else None)
        extra = v2_by.get(key) if key else None
        loc = squash(s.get("location", ""))
        commit = squash(s.get("commit", ""))
        loc_cell = esc(loc) + ((f"<br>Commit: `{esc(commit)}`") if commit and commit not in loc else "")
        notes = esc(squash(s.get("notes", "")))
        if extra:
            notes += "<br>**Cotejo (v2_literal.json):** " + esc(squash(extra.get("notes", "")))
        cmds = s.get("search_commands") or []
        if cmds:
            notes += f"<br>Búsqueda documentada: {len(cmds)} {'comando' if len(cmds) == 1 else 'comandos'} (`sources[].search_commands`)."
        rows.append([f"**{esc(ver)}**", "Sí" if s.get("found") else "**No**", esc(literal_cell(s)), loc_cell, notes])
    return md_table(["Versión", "Hallada", "Literal", "Ubicación / commit", "Notas"], rows)


# ----------------------------------------------------------------------------------------------
# Documento B: análisis de brechas
# ----------------------------------------------------------------------------------------------

def extract_between(text: str, start: str, ends) -> str | None:
    i = text.find(start)
    if i < 0:
        return None
    j = len(text)
    for e in ends:
        k = text.find(e, i + len(start))
        if k >= 0:
            j = min(j, k)
    return text[i:j]


def numbered_items(segment: str, pattern: str):
    parts = re.split(pattern, segment)
    return [squash(p) for p in parts[1:] if squash(p)]


def build_gap(ctx: Ctx) -> str:
    L = header_block("Predictily V2.5 · Análisis de brechas", ctx, "requisitos, defectos y conflictos de la auditoría")
    total = len(ctx.reqs)
    st = collections.Counter(r["status"] for r in ctx.reqs)
    nonimpl = [r for r in ctx.reqs if r["status"] != "IMPLEMENTADO"]
    defects = [(a["key"], i, d) for a in ctx.areas for i, d in enumerate(a["data"]["defects"])]
    sev = collections.Counter(d.get("severity") for _, _, d in defects)
    conflicts = sum(len(a["data"]["conflicts"]) for a in ctx.areas)
    L += [
        f"Fecha de la auditoría: {ctx.audit_date} · Commit de línea base: `{BASELINE_COMMIT}` · Rama `v25/enterprise-core` · "
        f"Especificación: `{SPEC_PATH}`.",
        "",
        f"**{total} requisitos** de 8 módulos: " + " · ".join(f"{s} {st.get(s, 0)}" for s in STATUSES)
        + f". **{len(defects)} defectos** (" + " · ".join(f"{s} {sev.get(s, 0)}" for s in SEVERITIES)
        + f") y **{conflicts} conflictos** entre el código existente y V2.5.",
        "",
        "## Contenido",
        "",
        "- [1. Criterios](#1-criterios)",
        "- [2. Resúmenes](#2-resúmenes)",
        "- [3. Estadística de la verificación adversarial](#3-estadística-de-la-verificación-adversarial)",
        "- [4. Brechas por módulo](#4-brechas-por-módulo)",
        "- [5. Defectos](#5-defectos)",
        "- [6. Conflictos](#6-conflictos)",
        "- [7. Requisitos BLOQUEADOS](#7-requisitos-bloqueados)",
        "- [8. Hallazgos transversales del cotejo literal](#8-hallazgos-transversales-del-cotejo-literal)",
        "- [9. Control de consistencia de los datos de entrada](#9-control-de-consistencia-de-los-datos-de-entrada)",
        "",
        "## 1. Criterios",
        "",
        md_table(["Estado", "Significado"], [
            ["IMPLEMENTADO", "Hay código, una prueba que lo afirma y coincide con la redacción de V2.5. Salvo indicación expresa, la prueba usa datos sintéticos: ningún requisito está validado con datos reales de clientes."],
            ["PARCIAL", "Existe una parte del comportamiento o falta la prueba o la coincidencia con la redacción de V2.5."],
            ["PENDIENTE", "No existe en el repositorio (solo nombres, columnas sin lector, mocks o documentos)."],
            ["BLOQUEADO", "Depende de un tercero o del propietario (cuenta AWS, datos reales, host Windows, firmas legales). Se indica qué se puede construir localmente."],
            ["NO APLICA", "El requisito es condicional o una regla documental sin artefacto (justificación en el campo `gap`)."],
        ]),
        "",
        "El estado vigente es el campo `status`; donde hay veredicto de la verificación, `status` ya recoge el resultado "
        "(`status_auditor` conserva el original). Las brechas se ordenan con P0 primero; en cada prioridad: BLOQUEADO, PENDIENTE, PARCIAL.",
        "",
        "## 2. Resúmenes",
        "",
        "### 2.1 Módulo × estado",
        "",
    ]
    mat = {a["key"]: collections.Counter(r["status"] for r in ctx.reqs_by_area[a["key"]]) for a in ctx.areas}
    heads = ["Módulo"] + [status_head(s) for s in STATUSES] + ["Total", "% IMPLEMENTADO"]
    rows = []
    for a in ctx.areas:
        c = mat[a["key"]]
        n = sum(c.values())
        rows.append([esc(a["key"])] + counter_row(c, STATUSES) + [num(n), pct(c.get("IMPLEMENTADO", 0), n)])
    rows.append(["**Total**"] + [f"**{st.get(s, 0)}**" for s in STATUSES] + [f"**{total}**", f"**{pct(st.get('IMPLEMENTADO', 0), total)}**"])
    L.append(md_table(heads, rows, "l" + "r" * 7))
    L += ["", "El porcentaje es «requisitos IMPLEMENTADO / total» y se apoya en evidencia sintética: no es una medida de avance del producto.", "",
          "### 2.2 Prioridad × estado", ""]
    pm = {p: collections.Counter(r["status"] for r in ctx.reqs if r["priority"] == p) for p in PRIORITIES}
    L.append(status_matrix(pm, PRIORITIES, "Prioridad"))
    L += ["", "### 2.3 Paquete del plan × prioridad (requisitos no IMPLEMENTADOS)", "",
          "Oleada y paquete según [V25_IMPLEMENTATION_PLAN.md](V25_IMPLEMENTATION_PLAN.md). Los defectos HIGH tienen además paquetes W1-A1, W1-A2 y W1-B "
          f"(ver [{OUT_FILES['risk']}]({OUT_FILES['risk']})).", ""]
    slices = sorted({r["slice"] for r in ctx.reqs}, key=lambda s: (s[:3], s))
    rows = []
    tot = collections.Counter()
    totb = 0
    for s in slices:
        rs = [r for r in nonimpl if r["slice"] == s]
        c = collections.Counter(r["priority"] for r in rs)
        b = sum(1 for r in rs if r["status"] == "BLOQUEADO")
        tot.update(c)
        totb += b
        rows.append([esc(s), esc(SLICE_WAVE.get(s[:3], "—"))] + counter_row(c, PRIORITIES) + [num(len(rs)), num(b)])
    rows.append(["**Total**", "—"] + [f"**{tot.get(p, 0)}**" for p in PRIORITIES] + [f"**{len(nonimpl)}**", f"**{totb}**"])
    L.append(md_table(["Paquete (slice)", "Oleada"] + list(PRIORITIES) + ["Total", "de ellos BLOQUEADO"], rows, "ll" + "r" * 6))
    L += ["", "### 2.4 Defectos: módulo × severidad", ""]
    rows = []
    for a in ctx.areas:
        c = collections.Counter(d.get("severity") for d in a["data"]["defects"])
        rows.append([esc(a["key"])] + counter_row(c, SEVERITIES) + [num(len(a["data"]["defects"])), num(len(a["data"]["conflicts"]))])
    rows.append(["**Total**"] + [f"**{sev.get(s, 0)}**" for s in SEVERITIES] + [f"**{len(defects)}**", f"**{conflicts}**"])
    L.append(md_table(["Módulo"] + list(SEVERITIES) + ["Defectos", "Conflictos"], rows, "l" + "r" * 5))
    L += progress_section(ctx, "2.5")
    # 3. verificación
    L += ["", "## 3. Estadística de la verificación adversarial", "",
          "Requisitos originales del auditor = requisitos finales − ADDED. «Rebajados» y «subidos» cambiaron de estado; "
          "«añadidos» son requisitos que el auditor omitió.", ""]
    rows = []
    tv = collections.Counter()
    for a in ctx.areas:
        rs = ctx.reqs_by_area[a["key"]]
        c = collections.Counter(r["verdict"] for r in rs)
        tv.update(c)
        rows.append([esc(a["key"]), num(len(rs) - c.get("ADDED", 0)), num(c.get("CONFIRMED", 0)), num(c.get("DOWNGRADED", 0)),
                     num(c.get("UPGRADED", 0)), num(c.get("ADDED", 0)), num(len(rs))])
    rows.append(["**Total**", f"**{total - tv.get('ADDED', 0)}**", f"**{tv.get('CONFIRMED', 0)}**", f"**{tv.get('DOWNGRADED', 0)}**",
                 f"**{tv.get('UPGRADED', 0)}**", f"**{tv.get('ADDED', 0)}**", f"**{total}**"])
    L.append(md_table(["Módulo", "Originales del auditor", "Confirmados", "Rebajados", "Subidos", "Añadidos", "Finales"], rows, "l" + "r" * 6))
    L += ["", "### 3.1 Estados antes y después de la verificación", "",
          "Antes: estado del auditor (sin los requisitos añadidos). Después: estado vigente.", ""]
    before = {a["key"]: collections.Counter(r["status_auditor_n"] for r in ctx.reqs_by_area[a["key"]] if r["verdict"] != "ADDED") for a in ctx.areas}
    rows = []
    btot = collections.Counter()
    for a in ctx.areas:
        b, af = before[a["key"]], mat[a["key"]]
        btot.update(b)
        rows.append([esc(a["key"])] + [f"{b.get(s, 0)} → {af.get(s, 0)}" for s in STATUSES])
    rows.append(["**Total**"] + [f"**{btot.get(s, 0)} → {st.get(s, 0)}**" for s in STATUSES])
    L.append(md_table(["Módulo"] + [status_head(s) for s in STATUSES], rows, "l" + "r" * 5))
    L += ["", "### 3.2 Verificación de los defectos", "",
          "Solo demanda, cliente y seguridad_ui anotaron un veredicto por defecto; en el resto de módulos la verificación se hizo "
          "sobre los requisitos y el recuento de defectos creció sin anotación individual.", ""]
    vdefs = collections.defaultdict(collections.Counter)
    allv = collections.Counter()
    for k, _, d in defects:
        v = (d.get("verification") or {}).get("verdict") or "sin anotar"
        vdefs[k][v] += 1
        allv[v] += 1
    vkeys = sorted(allv, key=lambda x: (x == "sin anotar", x))
    rows = [[esc(a["key"])] + counter_row(vdefs[a["key"]], vkeys) + [num(len(a["data"]["defects"]))] for a in ctx.areas]
    rows.append(["**Total**"] + [f"**{allv.get(v, 0)}**" for v in vkeys] + [f"**{len(defects)}**"])
    L.append(md_table(["Módulo"] + [esc(v) for v in vkeys] + ["Total"], rows, "l" + "r" * (len(vkeys) + 1)))
    L += ["", "### 3.3 Requisitos rebajados, subidos y añadidos", ""]
    chg = [r for r in ctx.reqs if r["verdict"] in ("DOWNGRADED", "UPGRADED", "ADDED")]
    order = {"DOWNGRADED": 0, "UPGRADED": 1, "ADDED": 2}
    chg.sort(key=lambda r: (order[r["verdict"]], AREA_KEYS.index(r["area"]), r["id"]))
    rows = []
    for r in chg:
        if r["verdict"] == "ADDED":
            ch = f"ADDED → {r['status']}"
        else:
            ch = f"{r['status_auditor_n']} → {r['status']}"
        rows.append([req_link(r["id"]), esc(r["verdict"]), esc(r["area"]), esc(ch), cell(r["title"], 90),
                     cell((r.get("verification") or {}).get("note"), 260)])
    L.append(md_table(["ID", "Veredicto", "Módulo", "Cambio de estado", "Requisito", "Nota de la verificación"], rows))
    # 4. brechas por módulo
    L += ["", "## 4. Brechas por módulo", "",
          "Requisitos no IMPLEMENTADOS, P0 primero. «Brecha» es el campo `gap`; si el JSON lo dejó vacío (requisito rebajado), "
          "se muestra la nota de verificación precedida por «[nota]». La evidencia es la primera cita (el JSON trae el resto).", ""]
    for i, a in enumerate(ctx.areas, start=1):
        rs = [r for r in ctx.reqs_by_area[a["key"]] if r["status"] != "IMPLEMENTADO"]
        rs.sort(key=lambda r: (PRIORITIES.index(r["priority"]) if r["priority"] in PRIORITIES else 9, STATUS_GAP_RANK[r["status"]], r["id"]))
        c = collections.Counter(r["priority"] for r in rs)
        L += [f"### 4.{i} {a['key']}", "",
              f"{len(rs)} de {len(ctx.reqs_by_area[a['key']])} requisitos no IMPLEMENTADOS: "
              + " · ".join(f"{p} {c.get(p, 0)}" for p in PRIORITIES) + ".", ""]
        rows = []
        for r in rs:
            g, fallback = best_gap(r)
            gtxt = ("[nota] " + g) if fallback else g
            rows.append([req_link(r["id"]), esc(r["priority"]), esc(r["status"]), cell(r["title"], 100), cell(gtxt, 600),
                         evidence_cell(r, 140, n=1, more=False), esc(package_label(r["slice"]))])
        L.append(md_table(["ID", "Prio.", "Estado", "Requisito", "Brecha", "Evidencia", "Paquete"], rows))
        L += ["", f"Detalle: [{a['key']}.json]({ctx.audit_rel}/{a['key']}.json).", ""]
    # 5. defectos
    L += ["## 5. Defectos", "",
          f"Los {len(defects)} defectos de los 8 módulos, por severidad (HIGH, MEDIUM, LOW) y después por módulo y posición. "
          f"La columna «Riesgo» enlaza con [{OUT_FILES['risk']}]({OUT_FILES['risk']}). «Verif.» es el veredicto de la verificación cuando existe.", ""]
    sev_order = {s: i for i, s in enumerate(SEVERITIES)}
    ds = sorted(defects, key=lambda t: (sev_order.get(t[2].get("severity"), 9), AREA_KEYS.index(t[0]), t[1]))
    rows = []
    for k, i, d in ds:
        rid = ctx.defect_risk.get((k, i), "—")
        link = f"[{rid}]({OUT_FILES['risk']}#{rid.lower()})" if rid != "—" else "—"
        v = (d.get("verification") or {}).get("verdict")
        rows.append([link, esc(k), esc(d.get("severity", "")), cell(d.get("title"), 220), cell(d.get("evidence"), 240),
                     cell(d.get("fix"), 260), esc(v) if v else "—", esc(defect_state(ctx, k, i))])
    L.append(md_table(["Riesgo", "Módulo", "Severidad", "Título", "Evidencia", "Corrección", "Verif.", "Estado"], rows))
    # 6. conflictos
    L += ["", "## 6. Conflictos", "",
          "Conflictos entre lo que existe y lo que V2.5 exige, con la propuesta de resolución de cada auditor.", ""]
    for i, a in enumerate(ctx.areas, start=1):
        cs = a["data"]["conflicts"]
        L += [f"### 6.{i} {a['key']}", "", f"{len(cs)} conflictos.", ""]
        rows = [[cell(c.get("topic"), 130), cell(c.get("existing"), 300), cell(c.get("v25"), 260), cell(c.get("proposal"), 320)] for c in cs]
        L.append(md_table(["Tema", "Existente", "V2.5", "Propuesta"], rows))
        L.append("")
    # 7. bloqueados
    L += blocked_section(ctx)
    # 8. hallazgos transversales
    L += ["", "## 8. Hallazgos transversales del cotejo literal", ""]
    L += cross_findings(ctx)
    # 9. consistencia
    L += ["", "## 9. Control de consistencia de los datos de entrada", "",
          "Comprobaciones calculadas por el generador sobre los JSON. «Incidencias» = 0 significa que la comprobación pasa; "
          "las demás son hallazgos sobre la propia auditoría que el arquitecto debe conocer.", ""]
    rows = [[cell(n), num(k), cell(det, 300)] for n, k, det in check_consistency(ctx)]
    L.append(md_table(["Comprobación", "Incidencias", "Detalle"], rows, "lrl"))
    return "\n".join(L).rstrip() + "\n"


def blocked_section(ctx: Ctx) -> list[str]:
    blocked = list(ctx.blocked)
    L = ["", "## 7. Requisitos BLOQUEADOS", "",
         f"{len(blocked)} requisitos dependen de un tercero o del propietario (cuenta AWS, datos reales, host Windows, firmas legales). "
         "Cada fila separa lo que **requiere** la dependencia de lo que se puede **construir localmente** (texto del campo `gap`; si el `gap` "
         "no lo trae, el del criterio de aceptación). Nada de esto se declara ejecutado mientras no exista la dependencia "
         "(V2.5, instrucción inicial y §23.5).", ""]
    cats = collections.defaultdict(list)
    for r in blocked:
        cats[r["dep"]].append(r)
    L += ["### 7.1 Dependencias y acción del propietario", ""]
    rows = []
    cat_order = [c[0] for c in DEP_CATEGORIES] + [DEP_OTHER[0]]
    labels = {c[0]: c[1] for c in DEP_CATEGORIES}
    labels[DEP_OTHER[0]] = DEP_OTHER[1]
    for key in cat_order:
        if key not in cats:
            continue
        rs = cats[key]
        pc = collections.Counter(r["priority"] for r in rs)
        plan_row = None
        kw = DEP_PLAN_KEYWORD.get(key)
        if kw:
            for row in ctx.owner_blocks:
                if re.search(kw, row[0], re.I):
                    plan_row = row
                    break
        action = plan_row[2] if plan_row else DEP_OWNER_FALLBACK.get(key, "aportar lo indicado en «Requiere» de cada requisito")
        affects = plan_row[1] if plan_row else "—"
        rows.append([esc(labels[key]), num(len(rs)), esc(", ".join(f"{p} × {pc[p]}" for p in PRIORITIES if pc.get(p))),
                     cell(affects, 200), cell(action, 320)])
    L.append(md_table(["Dependencia", "Requisitos", "Prioridades", "Afecta (plan §4)", "Acción del propietario"], rows, "lrlll"))
    if ctx.owner_blocks:
        extra = []
        for b, af, ac in ctx.owner_blocks:
            if not any(re.search(DEP_PLAN_KEYWORD[k], b, re.I) for k in DEP_PLAN_KEYWORD if k in cats):
                extra.append(f"«{esc(b)}» (afecta {esc(af)}; acción: {esc(ac)})")
        if extra:
            L += ["", "Bloqueos del plan §4 sin ningún requisito BLOQUEADO asociado en la auditoría (afectan a requisitos PARCIAL o PENDIENTE): "
                  + "; ".join(extra) + "."]
    L += ["", "### 7.2 Detalle por requisito", ""]
    rows = []
    blocked.sort(key=lambda r: (cat_order.index(r["dep"]), PRIORITIES.index(r["priority"]) if r["priority"] in PRIORITIES else 9, r["id"]))
    for r in blocked:
        rows.append([req_link(r["id"]), esc(r["priority"]), cell(r["title"], 100), esc(labels[r["dep"]]),
                     cell(r["req_part"], 320), cell(r["build_part"], 420), esc(package_label(r["slice"]))])
    L.append(md_table(["ID", "Prio.", "Requisito", "Dependencia", "Requiere", "Construible localmente", "Paquete"], rows))
    return L


def cross_findings(ctx: Ctx) -> list[str]:
    L = []
    n1 = ctx.v1.get("notes", "")
    n2 = ctx.v2.get("notes", "")
    seg = extract_between(n1, "HALLAZGOS QUE CONTRADICEN LA DOCUMENTACIÓN EXISTENTE", ["LIMITACIONES."])
    items = numbered_items(seg, r"\s\(\d+\)\s") if seg else []
    L += ["### 8.1 Contradicciones entre la documentación existente y el código (cotejo de V1)", ""]
    if items:
        L += [f"{i}. {esc(t)}" for i, t in enumerate(items, start=1)]
    else:
        L.append("No se pudo extraer la lista de `v1_literal.json` (formato de las notas cambiado); ver el campo `notes`.")
    seg = extract_between(n2, "HALLAZGOS DE ESTADO DEL REPOSITORIO TRANSVERSALES", [])
    items = []
    if seg:
        items = [squash(x) for x in re.split(r"\n\s+-\s", seg)[1:]]
    L += ["", "### 8.2 Estado transversal del repositorio (cotejo de V2 y complemento)", ""]
    if items:
        L += [f"- {esc(t)}" for t in items]
    else:
        L.append("No se pudo extraer la lista de `v2_literal.json` (formato de las notas cambiado); ver el campo `notes`.")
    return L


# ----------------------------------------------------------------------------------------------
# Documento C: diferencias entre versiones
# ----------------------------------------------------------------------------------------------

def uncovered(row) -> bool:
    return row["label"] == "NOT_VERIFIED" or squash(row["v25_coverage"]).lower().startswith("no cubierto")


def literal_table(rows) -> str:
    out = []
    for r in rows:
        out.append([esc(r["id"]), cell(r["quote"], 150), cell(r["v25_coverage"], 150), esc(r["label"]),
                    esc(r["current_status"]), cell(r["evidence"], 150)])
    return md_table(["ID", "Cita literal", "Cobertura en V2.5", "Etiqueta", "Estado actual", "Evidencia"], out)


def grouped_literal(rows, section_key, split_sub=False):
    groups = collections.OrderedDict()
    for r in rows:
        sec = r[section_key]
        if split_sub and " · " in sec:
            sec = sec.split(" · ", 1)[0]
        groups.setdefault(sec, []).append(r)
    return groups


def build_diff(ctx: Ctx) -> str:
    L = header_block("Predictily V2.5 · Diferencias entre versiones", ctx, "cotejo literal de V1, V2 y complemento")
    sets = (("V1", ctx.v1_rows, "v1_section"), ("V2", ctx.v2_rows, "v2_section"), ("V2C", ctx.v2c_rows, "v2_section"))
    L += [
        f"Fecha de la auditoría: {ctx.audit_date} · Commit de línea base: `{BASELINE_COMMIT}` · Especificación: `{SPEC_PATH}`.",
        "",
        f"Filas literales comparadas: **V1 {len(ctx.v1_rows)}** (`{ctx.v1_path}`), **V2 {len(ctx.v2_rows)}** y **complemento V2C {len(ctx.v2c_rows)}** "
        f"(`{ctx.v2_path}` e `{ctx.v2c_path}`).",
        "",
        "Literalidad de las citas, calculada al generar este documento contra el archivo fuente (la cita es el texto de la columna «Cita literal», "
        "sin recortar en el JSON):",
        "",
        f"- V1: {esc(quote_summary(ctx.quote_stats['V1']))}.",
        f"- V2: {esc(quote_summary(ctx.quote_stats['V2']))}.",
        f"- V2C: {esc(quote_summary(ctx.quote_stats['V2C']))}.",
        "",
        "> **Advertencia (V2.5 §28, «Fin del trabajo»):** no declarar «todo conciliado literalmente» mientras el original/V1 y V2.4 estándar "
        "estén ausentes; recuperar si existen en el repositorio o en Git, o etiquetar la brecha documental. V2.5 §0 pide verificar las "
        "fuentes originales antes de declarar conciliación literal del 100 %. Aquí **V1 sí se recuperó** (superando la afirmación de V2.5 §0) "
        "y V2 solo en extracto (§0-§10); **V2.1, V2.2, V2.3, V2.4 estándar y V2.4 Enterprise siguen sin texto fuente**.",
        "",
        "## Contenido",
        "",
        "- [1. Etiquetas y resumen](#1-etiquetas-y-resumen)",
        "- [2. No mencionados por la V2.5 (riesgo de pérdida)](#2-no-mencionados-por-la-v25-riesgo-de-pérdida)",
        "- [3. Conflictos resueltos por V2.5](#3-conflictos-resueltos-por-v25)",
        "- [4. Versiones sin texto fuente recuperado](#4-versiones-sin-texto-fuente-recuperado)",
        "- [5. Hallazgos del cotejo de V2 frente a lo que V2.5 afirma](#5-hallazgos-del-cotejo-de-v2-frente-a-lo-que-v25-afirma)",
        "- [6. Filas literales de V1](#6-filas-literales-de-v1)",
        "- [7. Filas literales de V2](#7-filas-literales-de-v2)",
        "- [8. Filas literales del complemento V2C](#8-filas-literales-del-complemento-v2c)",
        "",
        "## 1. Etiquetas y resumen",
        "",
        md_table(["Etiqueta (V2.5 §28)", "Significado"], [[esc(k), esc(v)] for k, v in LABEL_MEANING.items()]),
        "",
        "Criterio de `NOT_VERIFIED` en el cotejo: en V1 solo se etiqueta así lo que V2.5 no cubre **y** el repositorio no implementa; "
        "las filas no cubiertas pero implementadas quedan como `LEGACY_PRESERVED` (por la regla de no pérdida). En V2 y V2C se etiqueta "
        "`NOT_VERIFIED` toda fila que V2.5 no menciona o no enuncia de forma verificable, aunque esté implementada. Por eso la sección 2 "
        "reúne ambas.",
        "",
        "### 1.1 Versión × etiqueta",
        "",
    ]
    rows = []
    tl = collections.Counter()
    for name, rs, _ in sets:
        c = collections.Counter(r["label"] for r in rs)
        tl.update(c)
        rows.append([name] + counter_row(c, LABELS) + [num(len(rs))])
    rows.append(["**Total**"] + [f"**{tl.get(l, 0)}**" for l in LABELS] + [f"**{sum(tl.values())}**"])
    L.append(md_table(["Versión"] + list(LABELS) + ["Total"], rows, "l" + "r" * 6))
    L += ["", "### 1.2 Versión × estado actual", ""]
    rows = []
    ts = collections.Counter()
    for name, rs, _ in sets:
        c = collections.Counter(r["current_status"] for r in rs)
        ts.update(c)
        rows.append([name] + counter_row(c, LITERAL_STATUSES) + [num(len(rs))])
    rows.append(["**Total**"] + [f"**{ts.get(s, 0)}**" for s in LITERAL_STATUSES] + [f"**{sum(ts.values())}**"])
    L.append(md_table(["Versión"] + [status_head(s) for s in LITERAL_STATUSES] + ["Total"], rows, "l" + "r" * 7))
    L += ["", "`IMPLEMENTADO_SINTETICO` (solo V2/V2C): implementado y verificado únicamente con datos sintéticos. "
          "Validado con datos reales: ninguno.", "", "### 1.3 Etiqueta × estado actual (V1 + V2 + V2C)", ""]
    allrows = ctx.v1_rows + ctx.v2_rows + ctx.v2c_rows
    lab_state = {l: collections.Counter(r["current_status"] for r in allrows if r["label"] == l) for l in LABELS}
    L.append(status_matrix(lab_state, LABELS, "Etiqueta", statuses=LITERAL_STATUSES))
    # 2. no mencionados
    L += ["", "## 2. No mencionados por la V2.5 (riesgo de pérdida)", "",
          "Filas con etiqueta `NOT_VERIFIED` o cuya cobertura empieza por «no cubierto en V2.5»: V2.5 no las menciona (o solo de forma "
          "incidental). Si están IMPLEMENTADAS, el riesgo es una **regresión silenciosa** durante el trabajo de V2.5; si no, una **pérdida** "
          "sin dueño. Cada fila necesita una decisión del propietario (conservar con prueba de regresión, incorporar a un paquete o descartar con ADR).", ""]
    rows = []
    unc_by = {}
    for name, rs, _ in sets:
        u = [r for r in rs if uncovered(r)]
        unc_by[name] = u
        c = collections.Counter(r["current_status"] for r in u)
        rows.append([name, num(len(u)), pct(len(u), len(rs))] + counter_row(c, LITERAL_STATUSES))
    tot_u = sum(len(v) for v in unc_by.values())
    cu = collections.Counter(r["current_status"] for u in unc_by.values() for r in u)
    rows.append(["**Total**", f"**{tot_u}**", f"**{pct(tot_u, len(allrows))}**"] + [f"**{cu.get(s, 0)}**" for s in LITERAL_STATUSES])
    L.append(md_table(["Versión", "Filas no cubiertas", "% de la versión"] + [status_head(s) for s in LITERAL_STATUSES], rows, "l" + "r" * 8))
    for name, rs, key in sets:
        u = unc_by[name]
        L += ["", f"### 2.{[s[0] for s in sets].index(name) + 1} {name} ({len(u)} filas)", ""]
        if not u:
            L.append("Ninguna.")
            continue
        out = []
        for r in u:
            out.append([esc(r["id"]), cell(r["quote"], 150), cell(r["requirement"], 130), esc(r["label"]), esc(r["current_status"]),
                        cell(r["evidence"], 150)])
        L.append(md_table(["ID", "Cita literal", "Requisito", "Etiqueta", "Estado actual", "Evidencia"], out))
    # 3. conflictos resueltos
    L += ["", "## 3. Conflictos resueltos por V2.5", "",
          "Filas `CONFLICT_RESOLVED`: la versión fuente y V2.5 difieren y V2.5 (§25.1, §27 o una decisión explícita) fija la resolución.", ""]
    rows = []
    for name, rs, _ in sets:
        for r in rs:
            if r["label"] == "CONFLICT_RESOLVED":
                rows.append([esc(r["id"]), esc(name), cell(r["quote"], 140), cell(r["v25_coverage"], 200), esc(r["current_status"])])
    L.append(md_table(["ID", "Versión", "Cita literal", "Resolución de V2.5", "Estado actual"], rows))
    # 4. versiones sin texto
    L += ["", "## 4. Versiones sin texto fuente recuperado", "",
          "Para estas versiones no existe texto fuente en el repositorio ni en el historial Git: todo requisito atribuido a ellas es "
          "`RECONSTRUIDO_PARCIALMENTE` desde las citas de la V2.5 y su etiqueta de cotejo es **NOT_VERIFIED** (V2.5 §28). "
          f"El recuento sale de los {len(ctx.reqs)} requisitos de la auditoría por área ({OUT_FILES['trace']}).", ""]
    cites = {
        "V2.1": "§0, §20, §24 (24.1-24.13: requisitos V2.1 «íntegros»), §26 (T11-T15), §27, §28",
        "V2.2": "§0, §25.1-§25.3 (horizontes, Customer Core, agenda y contratos /api/v1/*), §26 (T16), §27",
        "V2.3": "§0, §4 (legado V2.3: Bridge y Auto Mapper), §16, §25.1 (7/15/30), §27",
        "V2.4": "§0 (regla de precedencia 0.5: «continuidad de V2.4 estándar cuando se recupere»), §28",
        "V2.4E": "§0, §1, §14-§17, §19-§20, §27",
    }
    wanted = (("V2.1", "V2.1"), ("V2.2", "V2.2"), ("V2.3", "V2.3"), ("V2.4", "V2.4 estándar"), ("V2.4E", "V2.4 Enterprise"))
    by_ver = {}
    for s in ctx.sources:
        by_ver[s.get("version", "")] = s
    rows = []
    for fam, name in wanted:
        src = next((s for v, s in by_ver.items() if v.lower().startswith(name.lower())), None)
        rs = [r for r in ctx.reqs if r["family"] == fam]
        sc = collections.Counter(r["status"] for r in rs)
        found = "Sí" if (src and src.get("found")) else "No"
        loc = squash(src.get("location", "")) if src else "—"
        rows.append([f"**{esc(name)}**", found, "NOT_VERIFIED", esc(cites[fam]),
                     f"{len(rs)}" + (" (" + ", ".join(f"{s} {sc[s]}" for s in STATUSES if sc.get(s)) + ")" if rs else " (ninguno atribuido)"),
                     cell(loc, 330)])
    L.append(md_table(["Versión", "Texto hallado", "Etiqueta", "Dónde la cita la V2.5", "Requisitos de la auditoría atribuidos", "Ubicación según la búsqueda"], rows))
    L += ["",
          "Las secciones de la V2.5 que citan las versiones: §0 (lista de fuentes y regla de no pérdida), §4 para V2.3, §24 para V2.1, "
          "§25 para V2 y V2.2, y §27 (resoluciones de conflictos entre versiones).",
          "",
          "> V2.5 §28: **No declarar «todo conciliado literalmente»** mientras original/V1 y V2.4 estándar estén ausentes; recuperar si existen "
          "en repositorio/Git o etiquetar brecha documental. V2.5 §0: verificar las fuentes originales en Git/docs antes de declarar "
          "conciliación literal del 100 %.",
          "",
          "Estado de la búsqueda (de `v1_literal.json`, `sources[]`): V1 recuperado (literal); V2 en extracto de §0 a §10 (§11 truncada); "
          "V2 «íntegro» (`Texto pegado(9).txt`) que V2.5 dice tener no está en el repositorio; el complemento `instrucctions.md` es un documento "
          "propio de la etapa 2, no una cita literal de V2."]
    # 5. hallazgos V2
    seg = extract_between(ctx.v2.get("notes", ""), "COTEJO LITERAL DE LO QUE V2.5", ["CONFLICTOS Y MOVIMIENTOS DE FASE"])
    L += ["", "## 5. Hallazgos del cotejo de V2 frente a lo que V2.5 afirma", ""]
    items = numbered_items(seg, r"\n\s+\d+\.\s") if seg else []
    if items:
        L += [f"{i}. {esc(t)}" for i, t in enumerate(items, start=1)]
    else:
        L.append("No se pudo extraer la lista de `v2_literal.json` (formato de las notas cambiado); ver el campo `notes`.")
    # 6-8 tablas literales
    for n, (title, rs, key, sub) in enumerate((("V1", ctx.v1_rows, "v1_section", True), ("V2", ctx.v2_rows, "v2_section", False),
                                               ("V2C", ctx.v2c_rows, "v2_section", False)), start=6):
        name = {"V1": "Filas literales de V1", "V2": "Filas literales de V2", "V2C": "Filas literales del complemento V2C"}[title]
        src_path = {"V1": ctx.v1_path, "V2": ctx.v2_path, "V2C": "instrucctions.md"}[title]
        L += ["", f"## {n}. {name}", "",
              f"{len(rs)} filas de `{src_path}`. Columnas: ID, cita literal (recortada a 150 caracteres), cobertura en V2.5, etiqueta, estado actual y evidencia.", ""]
        for sec, group in grouped_literal(rs, key, split_sub=sub).items():
            L += [f"### {esc(sec)} ({len(group)})", "", literal_table(group), ""]
    return "\n".join(L).rstrip() + "\n"


# ----------------------------------------------------------------------------------------------
# Documento D: registro de riesgos
# ----------------------------------------------------------------------------------------------

def build_risk(ctx: Ctx) -> str:
    risks = ctx.risks
    L = header_block("Predictily V2.5 · Registro de riesgos", ctx, "defectos, bloqueos, restricciones del entorno y cotejo literal")
    sevc = collections.Counter(r["severity"] for r in risks)
    stc = collections.Counter(r["state"] for r in risks)
    kinds = collections.Counter(r["kind"] for r in risks)
    L += [
        f"Fecha de la auditoría: {ctx.audit_date} · Commit de línea base: `{BASELINE_COMMIT}` · Rama `v25/enterprise-core` · "
        "Plan: [V25_IMPLEMENTATION_PLAN.md](V25_IMPLEMENTATION_PLAN.md) · Gates: [V25_RELEASE_GATES.md](V25_RELEASE_GATES.md).",
        "",
        f"**{len(risks)} riesgos**: " + " · ".join(f"{s} {sevc.get(s, 0)}" for s in SEVERITIES)
        + ". Por origen: " + " · ".join(f"{KIND_NAME.get(k, k)} {v}" for k, v in sorted(kinds.items()))
        + ". Por estado: " + " · ".join(f"{k} {v}" for k, v in sorted(stc.items())) + ".",
        "",
        "## Contenido",
        "",
        "- [1. Criterios](#1-criterios)",
        "- [2. Resumen](#2-resumen)",
        "- [3. Registro](#3-registro)",
    ] + (["- [4. IDs retirados](#4-ids-retirados)"] if ctx.retired else []) + [
        "",
        "## 1. Criterios",
        "",
        f"**ID estable.** `R-001`, `R-002`, ... se asignan por primera aparición, en este orden: defectos (módulo y posición en `defects[]`), "
        "dependencias bloqueadas, restricciones del entorno y riesgos del cotejo literal. La correspondencia clave → ID se guarda en "
        f"[{LEDGER_NAME}]({LEDGER_NAME}) (libro generado, no editar): al regenerar, un riesgo conserva su ID aunque se añadan defectos "
        "o cambien los conteos; un riesgo nuevo recibe el siguiente número y uno que desaparece del JSON queda «retirado» y su ID no se reutiliza. "
        "La clave de un defecto es su módulo y su título; si se corrige el título en el JSON, el defecto recibe un ID nuevo y el anterior se retira.",
        "",
        "**Severidad.**",
        "",
    ]
    if ctx.severity_scale:
        for k in ("high", "medium", "low"):
            if k in ctx.severity_scale:
                L.append(f"- {k.upper()}: {esc(ctx.severity_scale[k])} (escala de `baseline_metrics.json`).")
    L += [
        "- Defectos: la severidad asignada por la auditoría. Dependencias bloqueadas: la prioridad más alta de los requisitos BLOQUEADO "
        "afectados (P0 ⇒ HIGH, P1 ⇒ MEDIUM, P2/P3 ⇒ LOW). Restricciones del entorno: criterio del generador según el gate que limitan. "
        "Cotejo literal: MEDIUM (sin defecto reproducido).",
        "",
        "**Probabilidad** (alta / media / baja), según severidad y evidencia:",
        "",
        "- **Alta**: defecto HIGH; o MEDIUM con fallo reproducido (la evidencia o la verificación citan una sonda, ejecución o medición); "
        "o un hecho comprobado (dependencia o restricción que ya existe en el entorno).",
        "- **Media**: MEDIUM sin reproducción (hallado por lectura de código); LOW con fallo reproducido; riesgos de regresión sin defecto reproducido.",
        "- **Baja**: LOW sin reproducción.",
        "- **n/a**: defecto refutado por la verificación (se conserva como descartado).",
        "",
        "**Paquete del plan.** Defectos HIGH: W1-A1 (temporal backend), W1-A2 (motor as-of) o W1-B (seguridad) según el tema "
        "(carga tardía, anulaciones, estados desconocidos, escritura fuera de orden y marca de agua ⇒ W1-A1 / W1-A2; huecos como ceros y "
        "lectura de compras de cliente ⇒ W1-A2; SSRF, ZIP bomb y joblib ⇒ W1-B). Resto de defectos: el `slice` del requisito relacionado, "
        "que es el que el defecto cita por ID o, si no cita ninguno, el más afín del mismo módulo por rutas de evidencia compartidas y "
        "vocabulario (marcado «inferido»; revisar si la confianza es baja). En esta versión: "
        + ", ".join(f"{k} {v}" for k, v in sorted(ctx.pkg_basis.items())) + " (defectos).",
        "",
        "**Estado.** `abierto`; los HIGH asignados a W1 quedan `en corrección (W1)`; `descartado` si la verificación refutó el defecto.",
        "",
        f"No se registran como riesgos independientes los hechos informativos del entorno ({ctx.env_skipped[0]}) ni los ya cubiertos por una "
        f"dependencia bloqueada ({ctx.env_skipped[1]}).",
        "",
        "## 2. Resumen",
        "",
        "### 2.1 Severidad × estado",
        "",
    ]
    states = sorted(stc)
    rows = []
    for s in SEVERITIES:
        c = collections.Counter(r["state"] for r in risks if r["severity"] == s)
        rows.append([s] + counter_row(c, states) + [num(sum(c.values()))])
    rows.append(["**Total**"] + [f"**{stc.get(x, 0)}**" for x in states] + [f"**{len(risks)}**"])
    L.append(md_table(["Severidad"] + [esc(x) for x in states] + ["Total"], rows, "l" + "r" * (len(states) + 1)))
    L += ["", "### 2.2 Severidad × probabilidad", ""]
    probs = ["Alta", "Media", "Baja", "n/a"]
    rows = []
    for s in SEVERITIES:
        c = collections.Counter(r["probability"] for r in risks if r["severity"] == s)
        rows.append([s] + counter_row(c, probs) + [num(sum(c.values()))])
    L.append(md_table(["Severidad"] + probs + ["Total"], rows, "l" + "r" * 5))
    L += ["", "### 2.3 Riesgos HIGH", ""]
    rows = []
    for r in risks:
        if r["severity"] == "HIGH":
            rows.append([f"[{r['id']}](#{r['id'].lower()})", cell(r["title"], 150), esc(r["probability"]), esc(r["package"]), esc(r["state"])])
    L.append(md_table(["ID", "Riesgo", "Probabilidad", "Paquete del plan", "Estado"], rows))
    L += ["", "## 3. Registro", "",
          "Orden por ID. «Origen» enlaza con el JSON de la auditoría.", ""]
    heads = ["ID", "Riesgo", "Origen", "Severidad", "Probabilidad", "Impacto", "Mitigación", "Paquete del plan", "Responsable", "Estado"]
    rows = []
    for r in risks:
        origin = esc(r["origin"])
        if r.get("origin_link"):
            origin = f"[{origin}]({r['origin_link']})" if r["kind"] == "defecto" else origin + f" · [JSON]({r['origin_link']})"
        pkg = esc(r["package"]) + (f"<br>({esc(r['package_note'])})" if r.get("package_note") else "")
        prob = esc(r["probability"]) + (f"<br>({esc(r['prob_reason'])})" if r.get("prob_reason") else "")
        rows.append([req_anchor_risk(r["id"]), cell(r["title"], 230), origin, esc(r["severity"]), prob, cell(r["impact"], 280),
                     cell(r["mitigation"], 320), pkg, esc(r["owner"]), esc(r["state"])])
    L.append(md_table(heads, rows))
    if ctx.retired:
        L += ["", "## 4. IDs retirados", "",
              "IDs del libro cuyo riesgo ya no existe en los JSON de la auditoría (no se reutilizan).", "",
              md_table(["ID", "Clave"], [[esc(e["id"]), cell(e["key"], 200)] for e in ctx.retired])]
    return "\n".join(L).rstrip() + "\n"


def req_anchor_risk(rid: str) -> str:
    return f'<a id="{rid.lower()}"></a>{rid}'


# ----------------------------------------------------------------------------------------------
# Documento E: índice
# ----------------------------------------------------------------------------------------------

def build_readme(ctx: Ctx) -> str:
    root = ctx.root
    audit = root / ctx.audit_dir
    L = ["# Predictily V2.5 · Documentación de la hoja de ruta", "",
         f"> Índice generado por `{GENERATOR}`. Los documentos marcados «generado» se regeneran desde los JSON de la auditoría; "
         "no se editan a mano.", "",
         "Regla de la V2.5 (§23, §28): ningún estado se declara sin evidencia ejecutable; lo bloqueado por terceros se registra como `BLOQUEADO`.",
         "",
         "## Cómo regenerar", "",
         "Desde la raíz del repositorio (solo biblioteca estándar de Python 3):", "",
         "```bash",
         f"python3 {GENERATOR}           # reescribe trazabilidad, brechas, diferencias, riesgos y este índice",
         f"python3 {GENERATOR} --check   # sale con código 1 si algún documento generado está desactualizado",
         "```", "",
         "Cuando cambie un JSON de `" + ctx.audit_dir + "` (estado de un requisito, defecto nuevo, veredicto), regenerar y revisar el diff.", ""]

    def exists(rel):
        return (root / rel).exists()

    def line(rel, text, gen=False):
        mark = "generado" if gen else ("manual" if exists(rel) else "pendiente")
        shown = rel.replace("docs/roadmap/", "")
        link = f"[`{shown}`]({_rel_link(rel)})" if (gen or exists(rel)) else f"`{shown}`"
        return [link, mark, esc(text)]

    rows = [
        line("docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md", "Especificación objetivo V2.5 (prompt maestro reconciliado, §0-§28). Fuente de los requisitos y de las pruebas T01-T17."),
        line("docs/roadmap/sources/V1_PROMPT_MAESTRO_ORIGINAL.md", "Prompt maestro V1 recuperado de forma literal (22 secciones); base del cotejo de V1."),
    ]
    L += ["## Fuentes", "", md_table(["Documento", "Tipo", "Contenido"], rows), "", "## Auditoría", ""]
    rows = []
    for a in ctx.areas:
        d = a["data"]
        c = collections.Counter(r["status"] for r in ctx.reqs_by_area[a["key"]])
        rows.append([f"[`{ctx.audit_rel}/{a['key']}.json`]({ctx.audit_rel}/{a['key']}.json)", "datos",
                     esc(f"{a['title']}: {len(d['requirements'])} requisitos ({', '.join(f'{s} {c[s]}' for s in STATUSES if c.get(s))}), "
                         f"{len(d['defects'])} defectos, {len(d['conflicts'])} conflictos.")])
    rows.append([f"[`{ctx.audit_rel}/v1_literal.json`]({ctx.audit_rel}/v1_literal.json)", "datos",
                 esc(f"Cotejo literal de V1: {len(ctx.v1_rows)} filas y tabla de fuentes por versión.")])
    rows.append([f"[`{ctx.audit_rel}/v2_literal.json`]({ctx.audit_rel}/v2_literal.json)", "datos",
                 esc(f"Cotejo literal de V2 ({len(ctx.v2_rows)} filas) y del complemento V2C ({len(ctx.v2c_rows)} filas).")])
    rows.append([f"[`{ctx.audit_rel}/baseline_metrics.json`]({ctx.audit_rel}/baseline_metrics.json)", "datos",
                 esc("Línea base técnica: política de selección heredada, hiperparámetros fijos, variables externas, restricciones del entorno e incidencias B-01..B-15.")])
    L += [md_table(["Documento", "Tipo", "Contenido"], rows), "", "## Evidencia", ""]
    ev = root / "docs/roadmap/evidence"
    rows = []
    if ev.exists():
        for p in sorted(ev.iterdir()):
            if p.is_dir():
                files = sorted(f.name for f in p.iterdir() if f.is_file())
                rows.append([f"[`evidence/{p.name}/`](evidence/{p.name}/)", "evidencia",
                             esc(f"Registros de la ejecución de la línea base ({', '.join(files)}).")])
    if not rows:
        rows.append(["`evidence/`", "pendiente", "Sin evidencia registrada."])
    L += [md_table(["Documento", "Tipo", "Contenido"], rows), "", "## Plan y criterios de salida", ""]
    rows = [
        line("docs/roadmap/V25_IMPLEMENTATION_PLAN.md", "Plan por oleadas W0-W6 y paquetes (S01-S18, W1-A1/A2/B), bloqueos del propietario y definición de terminado."),
        line("docs/roadmap/V25_RELEASE_GATES.md", "Criterios de salida (Gates A-E y niveles P0-P3) con GO / GO CONDICIONAL / NO-GO / BLOQUEADO."),
        line("docs/architecture/adr/V25-ADRS.md", "Decisiones de arquitectura ADR-V25-xx que rigen la implementación."),
        line("docs/testing/V25_TEST_MATRIX.md", "Matriz de pruebas T01-T17 desglosada en casos, con pruebas existentes y estado."),
    ]
    L += [md_table(["Documento", "Tipo", "Contenido"], rows), "", "## Documentos generados desde la auditoría", ""]
    rows = [
        line("docs/roadmap/" + OUT_FILES["trace"], f"Trazabilidad de {len(ctx.reqs)} requisitos con las columnas de V2.5 §0.2, tabla de fuentes y una sección por módulo.", True),
        line("docs/roadmap/" + OUT_FILES["gap"], "Brechas IMPLEMENTADO / PARCIAL / PENDIENTE / BLOQUEADO / NO APLICA, verificación adversarial, defectos, conflictos y bloqueos del propietario.", True),
        line("docs/roadmap/" + OUT_FILES["diff"], "Cotejo literal de V1, V2 y V2C con etiquetas de V2.5 §28, requisitos no mencionados por V2.5 y versiones sin texto fuente.", True),
        line("docs/roadmap/" + OUT_FILES["risk"], "Registro de riesgos R-001... con severidad, probabilidad, mitigación, paquete del plan, responsable y estado.", True),
        line("docs/roadmap/README.md", "Este índice.", True),
        line("docs/roadmap/" + LEDGER_NAME, "Libro de IDs de riesgo (clave → R-nnn) que mantiene estables los identificadores al regenerar.", True),
    ]
    L += [md_table(["Documento", "Tipo", "Contenido"], rows)]
    return "\n".join(L).rstrip() + "\n"


def _rel_link(rel: str) -> str:
    """Enlace relativo desde docs/roadmap/."""
    if rel.startswith("docs/roadmap/"):
        return rel[len("docs/roadmap/"):]
    if rel.startswith("docs/"):
        return "../" + rel[len("docs/"):]
    return "../../" + rel


# ----------------------------------------------------------------------------------------------
# Validación del Markdown generado
# ----------------------------------------------------------------------------------------------

_SEP = re.compile(r"^\|(?:\s*:?-{3,}:?\s*\|)+\s*$")


def count_cells(line: str) -> int:
    """Celdas de una fila GFM: barras sin escapar menos las dos del borde."""
    n = 0
    i = 0
    while i < len(line):
        ch = line[i]
        if ch == "\\":
            i += 2
            continue
        if ch == "|":
            n += 1
        i += 1
    return n - 1


def validate_markdown(name: str, text: str) -> dict:
    """Comprueba tablas (mismas columnas), fences balanceados y ausencia de celdas rotas. Devuelve estadísticas."""
    lines = text.split("\n")
    in_fence = False
    tables = 0
    rows = 0
    expected = None
    for ln, line in enumerate(lines, start=1):
        if line.startswith("```"):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        if line.startswith("|"):
            if not line.rstrip().endswith("|") or line.rstrip().endswith("\\|"):
                raise ValueError(f"{name}:{ln}: fila de tabla sin barra final")
            n = count_cells(line)
            if expected is None:
                expected = n
                tables += 1
                nxt = lines[ln] if ln < len(lines) else ""
                if not _SEP.match(nxt):
                    raise ValueError(f"{name}:{ln}: la tabla no tiene fila separadora")
                if count_cells(nxt) != n:
                    raise ValueError(f"{name}:{ln}: separador con distinto número de columnas")
            elif n != expected:
                raise ValueError(f"{name}:{ln}: fila con {n} celdas; la tabla tiene {expected}")
            rows += 1
        else:
            expected = None
    if in_fence:
        raise ValueError(f"{name}: bloque de código sin cerrar")
    if text.count("\r"):
        raise ValueError(f"{name}: contiene retornos de carro")
    return {"tablas": tables, "filas": rows, "líneas": len(lines), "bytes": len(text.encode("utf-8"))}


# ----------------------------------------------------------------------------------------------
# Programa principal
# ----------------------------------------------------------------------------------------------

def generate(ctx: Ctx) -> dict:
    build_risks(ctx)
    docs = {
        OUT_FILES["trace"]: build_traceability(ctx),
        OUT_FILES["gap"]: build_gap(ctx),
        OUT_FILES["diff"]: build_diff(ctx),
        OUT_FILES["risk"]: build_risk(ctx),
        OUT_FILES["readme"]: build_readme(ctx),
    }
    return docs


def summary(ctx: Ctx, docs: dict, stats: dict) -> list[str]:
    st = collections.Counter(r["status"] for r in ctx.reqs)
    ver = collections.Counter(r["verdict"] for r in ctx.reqs)
    sev = collections.Counter(d.get("severity") for a in ctx.areas for d in a["data"]["defects"])
    out = [
        f"requisitos: {len(ctx.reqs)} · " + " · ".join(f"{s} {st.get(s, 0)}" for s in STATUSES),
        "verificación: " + " · ".join(f"{v} {ver.get(v, 0)}" for v in VERDICTS),
        f"defectos: {sum(sev.values())} · " + " · ".join(f"{s} {sev.get(s, 0)}" for s in SEVERITIES)
        + f" · conflictos: {sum(len(a['data']['conflicts']) for a in ctx.areas)}",
        "cotejo literal: V1 %d · V2 %d · V2C %d filas; etiquetas " % (len(ctx.v1_rows), len(ctx.v2_rows), len(ctx.v2c_rows))
        + " · ".join(f"{l} {sum(1 for r in ctx.v1_rows + ctx.v2_rows + ctx.v2c_rows if r['label'] == l)}" for l in LABELS),
        f"riesgos: {len(ctx.risks)} · " + " · ".join(f"{s} {sum(1 for r in ctx.risks if r['severity'] == s)}" for s in SEVERITIES)
        + " · estados " + ", ".join(f"{k} {v}" for k, v in sorted(collections.Counter(r['state'] for r in ctx.risks).items())),
    ]
    for name, s in stats.items():
        out.append(f"{name}: {s['tablas']} tablas, {s['filas']} filas, {s['líneas']} líneas, {s['bytes']} bytes")
    return out


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", default=str(REPO_ROOT), help="raíz del repositorio (por defecto, la del script)")
    ap.add_argument("--audit-dir", default=DEFAULT_AUDIT_DIR, help="carpeta de la auditoría, relativa a la raíz")
    ap.add_argument("--check", action="store_true", help="no escribe; falla si algún documento está desactualizado")
    ap.add_argument("--quiet", action="store_true", help="no imprime el resumen")
    args = ap.parse_args(argv)
    root = Path(args.root).resolve()
    try:
        ctx = Ctx(root, args.audit_dir)
        docs = generate(ctx)
        stats = {name: validate_markdown(name, text) for name, text in docs.items()}
        json.loads(ctx.ledger_text)
    except (InputError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2
    out_dir = root / OUT_DIR
    stale = []
    outputs = dict(docs)
    outputs[LEDGER_NAME] = ctx.ledger_text
    for name, text in outputs.items():
        path = out_dir / name
        current = path.read_text(encoding="utf-8") if path.exists() else None
        if current != text:
            stale.append(name)
            if not args.check:
                path.write_text(text, encoding="utf-8", newline="\n")
    if not ctx.plan_text:
        print(f"AVISO: no se encontró {PLAN_PATH}; se usaron las acciones del propietario por defecto", file=sys.stderr)
    if not args.quiet:
        for line in summary(ctx, docs, stats):
            print(line)
        problems = [(n, k, d) for n, k, d in check_consistency(ctx) if k]
        print(f"consistencia de las entradas: {len(problems)} comprobaciones con incidencias (ver V25_GAP_ANALYSIS.md §9)")
    if args.check:
        if stale:
            print("DESACTUALIZADOS: " + ", ".join(stale), file=sys.stderr)
            return 1
        print("OK: documentos al día")
        return 0
    print("escritos: " + (", ".join(stale) if stale else "ninguno (sin cambios)"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
