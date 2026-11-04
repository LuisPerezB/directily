#!/usr/bin/env python3
"""Prueba de aceptación E2E de Predictily V2.5 (W6-F, paso 1; especificación §23.5–7 y §26).

Recorre la API pública real (Laravel) con el motor Python y el worker de colas corriendo, como lo haría un cliente, con empresas,
personas y datos NUEVOS y FICTICIOS creados por el propio script:

  0. Preparación        empresa A (registro, sucursales, catálogo, ventas, inventario, plazos por CSV); miembros de A (aprobador
                        administrador y vendedor analista) creados por la administración; empresa B del aprobador; empresa C (historia corta)
  1. Seguridad (W6-A)   MFA TOTP (RFC 6238): alta, desafío al iniciar sesión, un solo uso, sin repetición; step-up en acciones sensibles;
                        tokens con alcance: un token restringido no emite otro más amplio, solo hace lo concedido, revocación inmediata
  2. Datos (W2)         CSV con errores: la confirmación se bloquea; con skip_invalid_rows los documentos inválidos van a cuarentena;
                        fuente SQL (ERP ficticio PostgreSQL de solo lectura): mapeo aprobado, sincronización, cuarentena, liberación con
                        mapeo de valores, descarte con motivo, re-sincronización sin duplicados, linaje Bronze
  3. Modelos (W2-C/W4)  entrenamiento con gates de calidad; registro con aprobación SEPARADA (segregación de funciones + step-up) y firma
                        del artefacto (verificación independiente del SHA-256 y del manifiesto); inferencia (demanda y compradores);
                        un fallo de calidad que BLOQUEA (empresa C con < 28 días de historia); reversión (rollback) de demanda
  4. Radar (W5)         Revenue Radar materializado, decisión por el workflow (asignar → revisar → aprobar con step-up → ejecutar)
                        con segregación de funciones y bloqueo optimista, seguimiento idempotente y clasificación honesta del outcome
  5. Vendedor (W6-A)    el rol comercial «vendedor» ve solo su cartera en Radar y decisiones; vistas de equipo cerradas
  6. Bridge (W6-B)      registro de dispositivo (Ed25519 local, código de un solo uso) y latido firmado; repetición, firma alterada,
                        reloj desfasado y revocación inmediata rechazados
  7. Gateway (W6-D)     con el asistente activo: fila de auditoría en gateway_tool_calls; sin clave de proveedor: desactivado (AVISO)
  8. Aislamiento        cambio de empresa con la misma sesión (A ↔ B, A ↔ C) sin filtraciones; quien no es miembro no entra
  AWS: BLOQUEADO — el E2E corre en la pila local (sin cuenta AWS del propietario) y lo declara en el resumen.

Uso:
  engine/.venv/bin/python e2e/v25_acceptance.py --api http://127.0.0.1:8000/api/v1 --engine-dir engine \\
      --log <directorio de evidencias>/v25-acceptance.log

Requisitos: backend + `queue:work` (colas imports, forecasting, default) + motor activos; datos demo en data/demo/; PostgreSQL con un rol que
pueda crear bases (por defecto predictily/secret en 127.0.0.1; E2E_PG_HOST, E2E_PG_PORT, E2E_PG_USER, E2E_PG_PASSWORD). Crea la base «ERP de
origen» ficticia `predictily_erp_source_v25e2e` (solo base de prueba local; se recrea en cada ejecución).
Un solo intérprete: engine/.venv (httpx, psycopg y cryptography); la firma del Bridge reutiliza el código del agente (bridge/src/predictily_bridge/crypto.py).

Registro limitado (10 por hora y por IP, 429 «Too Many Attempts»): cada ejecución registra UN solo usuario (propietario de A). El aprobador y el
vendedor se crean como miembros de A con `POST /tenant/users` (la API devuelve una contraseña temporal que el script usa y nunca imprime); el
aprobador crea la empresa B. Los códigos TOTP se calculan aquí (RFC 6238, HMAC-SHA1) a partir del secreto que devuelve `/auth/mfa/setup`; ni el
secreto ni los códigos se imprimen.

Convenciones de salida (las de stage2_acceptance.py): `[OK ]` verificación cumplida · `[FALLA]` criterio no cumplido (el script continúa) ·
`[AVISO]` observación que no es un fallo del criterio (límite conocido, dependencia del entorno o bloqueo declarado).
Código de salida: 0 si no hay FALLA; 1 en caso contrario.
"""
from __future__ import annotations

import argparse
import base64
import csv
import datetime as dt
import hashlib
import hmac
import json
import os
import struct
import sys
import time
import uuid
from pathlib import Path
from urllib.parse import urlsplit

import httpx

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
import mvp_acceptance as mvp  # noqa: E402  (Client, setup_company, run_import, wait_import)
import stage2_acceptance as s2  # noqa: E402  (check/warn/info/block/Tee, esperas, get_all, run_demand, esquema del ERP ficticio)

check, warn, info, block = s2.check, s2.warn, s2.info, s2.block   # un solo registro de resultados (s2.RESULTS)
DEMO = ROOT / "data" / "demo"
ERP_DB = "predictily_erp_source_v25e2e"
PASSWORD_A = "Prueba2026segura"   # la de mvp_acceptance.register (cuenta ficticia en la pila de prueba)
OPTS: argparse.Namespace | None = None
S: dict = {"ids": {}}
GATE_OK = {"PASS", "WARN", "DEGRADE"}


# ───────────────────────────── cliente y utilidades ─────────────────────────────
class Api(mvp.Client):
    """Cliente de una persona: token Bearer propio y empresa activa (X-Tenant-ID)."""

    def __init__(self, api: str, label: str, token: str | None = None, tenant: str | None = None) -> None:
        super().__init__(api)
        self.label = label
        self.tenant = tenant
        if token:
            self.set_token(token)

    def set_token(self, token: str) -> None:
        self.http.headers["Authorization"] = f"Bearer {token}"

    def raw(self, method: str, path: str, tenant: str | None = "__same__", **kw) -> httpx.Response:
        """Petición sin verificar el estado (para las verificaciones negativas). `tenant=None` envía sin empresa."""
        headers = dict(kw.pop("headers", {}))
        t = self.tenant if tenant == "__same__" else tenant
        if t:
            headers["X-Tenant-ID"] = t
        return self.http.request(method, path, headers=headers, **kw)

    def req(self, method: str, path: str, expect: int | tuple[int, ...] = 200, tenant: str | None = "__same__", **kw) -> httpx.Response:
        r = self.raw(method, path, tenant=tenant, **kw)
        allowed = (expect,) if isinstance(expect, int) else expect
        if r.status_code not in allowed:
            raise SystemExit(f"{method} {path} → {r.status_code} (esperado {allowed}): {r.text[:500]}")
        return r

    def json(self, method: str, path: str, expect: int | tuple[int, ...] = 200, **kw) -> dict:
        return self.req(method, path, expect, **kw).json()

    def clone(self, label: str, tenant: str | None) -> "Api":
        c = Api(str(self.http.base_url).rstrip("/"), label, tenant=tenant)
        c.http.headers["Authorization"] = self.http.headers["Authorization"]
        return c


def code_of(r: httpx.Response) -> str | None:
    try:
        body = r.json()
    except ValueError:
        return None
    return body.get("code") if isinstance(body, dict) else None


def data_of(r: httpx.Response):
    try:
        return r.json().get("data")
    except (ValueError, AttributeError):
        return None


def short(r: httpx.Response, n: int = 160) -> str:
    return f"HTTP {r.status_code} {code_of(r) or ''} {r.text[:n] if r.status_code >= 400 else ''}".strip()


def login(api: str, label: str, email: str, password: str) -> tuple[Api, httpx.Response]:
    c = Api(api, label)
    r = c.raw("POST", "/auth/login", tenant=None, json={"email": email, "password": password})
    if r.status_code == 200:
        c.set_token(r.json()["data"]["token"])
    return c, r


def today_local() -> dt.date:
    return s2.today_local()


# ───────────────────────────── TOTP (RFC 6238 / RFC 4226) ─────────────────────────────
class Totp:
    """Calcula códigos TOTP (HMAC-SHA1, 6 dígitos, 30 s) y nunca repite un paso: el servidor rechaza un paso ya aceptado (RFC 6238 §5.2),
    así que cada verificación usa un paso estrictamente mayor que el anterior (dentro de la ventana ±1), esperando al siguiente si hace falta."""

    def __init__(self, secret_b32: str, period: int = 30, digits: int = 6) -> None:
        self.key = base64.b32decode(secret_b32.strip().upper() + "=" * (-len(secret_b32.strip()) % 8))
        self.period, self.digits = period, digits
        self.last: int | None = None

    def at(self, step: int) -> str:
        mac = hmac.new(self.key, struct.pack(">Q", step), hashlib.sha1).digest()
        off = mac[-1] & 0x0F
        return str((struct.unpack(">I", mac[off:off + 4])[0] & 0x7FFFFFFF) % 10 ** self.digits).zfill(self.digits)

    def now_step(self) -> int:
        return int(time.time()) // self.period

    def next(self) -> str:
        while True:
            now = self.now_step()
            step = now if self.last is None else max(now, self.last + 1)
            if step <= now + 1:
                self.last = step
                return self.at(step)
            time.sleep(max(0.5, (step - 1) * self.period - time.time() + 0.5))

    def wrong(self) -> str:
        """Un código de 6 dígitos que NO es válido en la ventana actual (para la verificación negativa)."""
        valid = {self.at(self.now_step() + d) for d in (-2, -1, 0, 1, 2)}
        for cand in ("000000", "123456", "999999", "424242"):
            if cand not in valid:
                return cand
        return "111111"


def step_up(c: Api, totp: Totp, label: str) -> bool:
    r = c.raw("POST", "/auth/mfa/step-up", tenant=None, json={"code": totp.next()})
    d = data_of(r) or {}
    return check(f"[MFA] {label}: step-up con un código TOTP nuevo → 200 (segundo factor fresco, con vencimiento)",
                 r.status_code == 200 and d.get("verified") is True and bool(d.get("fresh_until")), f"{short(r)} hasta {d.get('fresh_until')}")


# ───────────────────────────── 0. Preparación ─────────────────────────────
def add_branches(c: Api, slug: str) -> int:
    n = 0
    with (DEMO / f"{slug}_branches.csv").open(encoding="utf-8") as fh:
        for row in csv.DictReader(fh):
            c.json("POST", "/branches", 201, json={k: row[k] for k in ("code", "name", "province", "canton", "commercial_zone") if row.get(k)})
            n += 1
    return n


def new_tenant(c: Api, name: str, sector: str, slug: str) -> str:
    t = c.json("POST", "/tenants", 201, json={"name": name, "sector": sector, "timezone": "America/Guayaquil", "currency": "USD"})["data"]
    c.tenant = t["uuid"]
    add_branches(c, slug)
    c.json("PATCH", "/tenant", 200, json={"settings": {"service_level": 0.95, "review_period_days": 7, "default_lead_time_days": 10}})
    return t["uuid"]


def block_setup() -> None:
    api = OPTS.api
    r = httpx.get(api.replace("/api/v1", "") + "/up", timeout=10)
    check("backend responde (/up)", r.status_code == 200)
    try:
        h = httpx.get(OPTS.engine_url.rstrip("/") + "/health", timeout=10)
        check("motor responde (/health)", h.status_code == 200, f"HTTP {h.status_code}")
    except httpx.HTTPError as exc:
        check("motor responde (/health)", False, str(exc)[:120])

    # ── empresa A (único registro de la ejecución) ──
    a = Api(api, "A")
    mvp.setup_company(a, "ferreteria_andina", "Ferretería E2E V2.5 (prueba ficticia)", "ferreteria", "A")
    me = a.json("GET", "/auth/me", tenant=None)
    S["u1"] = {"id": me["data"]["user"]["id"], "email": me["data"]["user"]["email"]}
    S["a"] = a
    t0 = time.time()
    for entity in ("products", "sales", "inventory", "lead_times"):
        job = mvp.run_import(a, DEMO / f"ferreteria_andina_{entity}.csv", entity)
        st = job["stats"]
        if entity == "sales":
            S["ids"]["sales_import"] = job["id"]
        check(f"[A] importa {entity} por CSV", job["status"] == "completed",
              f"{st.get('rows_valid')} filas válidas" + (f", {st.get('documents_new')} documentos" if entity == "sales" else "") + f" ({time.time() - t0:.0f} s acumulados)")

    # ── miembros de A creados por la administración (sin usar el registro público) ──
    suffix = uuid.uuid4().hex[:8]
    members = {}
    for key, role, name in (("u2", "admin", "Aprobador E2E V2.5 (ficticio)"), ("u3", "analyst", "Vendedor E2E V2.5 (ficticio)")):
        email = f"e2e.v25.{key}.{suffix}@predictily.test"
        r = a.raw("POST", "/tenant/users", json={"email": email, "name": name, "role": role})
        d = data_of(r) or {}
        ok = check(f"[A] la administración agrega a {name} con rol {role} (cuenta nueva con contraseña temporal mostrada una vez)",
                   r.status_code == 201 and d.get("account_created") is True and bool(d.get("temporary_password")) and d.get("role") == role,
                   f"{short(r)} usuario {d.get('id')}")
        if ok:
            members[key] = {"id": d["id"], "email": email, "password": d["temporary_password"]}
    S.update(members)
    for key in ("u2", "u3"):
        if key not in S:
            raise SystemExit(f"no se pudo crear el miembro {key}")
        c, r = login(api, key, S[key]["email"], S[key]["password"])
        check(f"[{key.upper()}] inicia sesión con la contraseña temporal (token Bearer)", r.status_code == 200, short(r))
        c.tenant = a.tenant
        S[key]["c"] = c
    lst = {m["id"]: m for m in a.json("GET", "/tenant/users")["data"]}
    check("[A] GET /tenant/users lista a los tres miembros con su rol de acceso",
          {S["u1"]["id"], S["u2"]["id"], S["u3"]["id"]} <= set(lst) and lst[S["u2"]["id"]]["role"] == "admin" and lst[S["u3"]["id"]]["role"] == "analyst",
          f"{len(lst)} miembros")

    # ── empresa B: del aprobador (miembro de A); empresa C: del propietario de A (historia corta) ──
    u2 = S["u2"]["c"]
    b = u2.clone("B", None)
    S["b_tenant"] = new_tenant(b, "Distribuidora E2E V2.5 (prueba ficticia)", "distribuidora", "distribuidora_costa")
    S["b"] = b
    check("[B] el aprobador crea su propia empresa B (es propietario de B y administrador de A)", bool(S["b_tenant"]), S["b_tenant"])
    c = a.clone("C", None)
    S["c_tenant"] = new_tenant(c, "Ferretería historia corta V2.5 (prueba ficticia)", "ferreteria", "ferreteria_andina")
    S["c"] = c
    check("[C] el propietario de A crea una segunda empresa C (historia corta, para el gate que bloquea)", bool(S["c_tenant"]), S["c_tenant"])
    st = a.json("GET", "/assistant/status")["data"]
    S["assistant_enabled"] = bool(st.get("enabled"))
    info(f"asistente: enabled={st.get('enabled')} reason={st.get('reason')}")


# ───────────────────────────── 1. Seguridad Enterprise (W6-A) ─────────────────────────────
def block_security() -> None:
    api, a, u3 = OPTS.api, S["a"], S["u3"]["c"]
    u2 = S["u2"]["c"]
    email2, pass2 = S["u2"]["email"], S["u2"]["password"]
    # ── alta del segundo factor (aprobador) ──
    r = u2.raw("POST", "/auth/mfa/setup", tenant=None, json={"password": pass2})
    st = data_of(r) or {}
    check("[MFA] alta: /auth/mfa/setup con la contraseña actual devuelve secreto Base32 y URI otpauth (RFC 6238: SHA1, 6 dígitos, 30 s)",
          r.status_code == 201 and bool(st.get("secret")) and str(st.get("otpauth_uri", "")).startswith("otpauth://totp/")
          and int(st.get("digits", 0)) == 6 and int(st.get("period", 0)) == 30 and str(st.get("algorithm", "")).upper() == "SHA1",
          f"{short(r)}; emisor {st.get('issuer')}")
    if r.status_code != 201:
        raise SystemExit("sin alta de MFA no se puede seguir este bloque")
    totp = Totp(st["secret"], int(st.get("period", 30)), int(st.get("digits", 6)))
    S["u2"]["totp"] = totp
    pend = u2.json("GET", "/auth/mfa", tenant=None)["data"]
    check("[MFA] antes de verificar el primer código el segundo factor NO está activo (pendiente)", pend.get("enabled") is False and pend.get("pending") is True, json.dumps({k: pend.get(k) for k in ("enabled", "pending")}))
    r = u2.raw("POST", "/auth/mfa/confirm", tenant=None, json={"code": totp.next(), "password": pass2})
    d = data_of(r) or {}
    codes = d.get("recovery_codes") or []
    check("[MFA] confirmación con el primer código: activo y 10 códigos de recuperación de un solo uso (mostrados una vez)",
          r.status_code == 200 and len(codes) == 10 and len(set(codes)) == 10, f"{short(r)}; {len(codes)} códigos")
    stt = u2.json("GET", "/auth/mfa", tenant=None)["data"]
    check("[MFA] GET /auth/mfa: activo, 10 códigos restantes, sin exponer el secreto", stt.get("enabled") is True and stt.get("recovery_codes_remaining") == 10
          and st["secret"] not in json.dumps(stt), json.dumps({k: stt.get(k) for k in ("enabled", "recovery_codes_remaining")}))
    # ── inicio de sesión con desafío ──
    u2.raw("POST", "/auth/logout", tenant=None)
    fresh = Api(api, "u2")
    r = fresh.raw("POST", "/auth/login", tenant=None, json={"email": email2, "password": pass2})
    d = data_of(r) or {}
    check("[MFA] con MFA activo, la contraseña correcta NO inicia sesión: 202 mfa_required con desafío y sin token",
          r.status_code == 202 and code_of(r) == "mfa_required" and bool(d.get("challenge")) and "token" not in d, short(r))
    challenge = d.get("challenge")
    r = fresh.raw("POST", "/auth/mfa/challenge", tenant=None, json={"challenge": challenge, "code": totp.wrong()})
    check("[MFA] un código incorrecto no completa el inicio de sesión (422 mfa_invalid_code, sin token)",
          r.status_code == 422 and code_of(r) == "mfa_invalid_code" and "token" not in (data_of(r) or {}), short(r))
    code = totp.next()
    r = fresh.raw("POST", "/auth/mfa/challenge", tenant=None, json={"challenge": challenge, "code": code})
    d = data_of(r) or {}
    ok = check("[MFA] desafío + código TOTP válido → inicio de sesión completo (token Bearer)", r.status_code == 200 and bool(d.get("token")), short(r))
    if not ok:
        raise SystemExit("el aprobador no pudo iniciar sesión con MFA")
    fresh.set_token(d["token"])
    fresh.tenant = a.tenant
    S["u2"]["c"] = u2 = fresh
    S["b"].set_token(d["token"])
    r = fresh.raw("POST", "/auth/mfa/challenge", tenant=None, json={"challenge": challenge, "code": totp.wrong()})
    check("[MFA] el desafío es de un solo uso (reutilizarlo → 422 mfa_challenge_invalid)", r.status_code == 422 and code_of(r) == "mfa_challenge_invalid", short(r))
    r = u2.raw("POST", "/auth/mfa/step-up", tenant=None, json={"code": code})
    check("[MFA] un código TOTP ya aceptado no se acepta otra vez (repetición → 422)", r.status_code == 422 and code_of(r) in ("mfa_invalid_code", "mfa_code_replayed"), short(r))
    r = u2.raw("GET", "/tenant/users")
    members = {m["id"]: m for m in (data_of(r) or [])}
    check("[MFA] la administración ve mfa_enabled=true del aprobador (GET /tenant/users, acción sensible con segundo factor fresco)",
          r.status_code == 200 and members.get(S["u2"]["id"], {}).get("mfa_enabled") is True, short(r))
    # ── token restringido del aprobador (para probar el step-up en acciones sensibles más adelante) ──
    r = u2.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "e2e-v25-aprobador-restringido",
                                                          "abilities": ["data.view", "forecast.view", "models.approve", "decisions.view", "decisions.manage", "decisions.approve"], "expires_in_days": 1})
    d = data_of(r) or {}
    check("[Tokens] el aprobador (con MFA fresco) emite un token personal atado a A con habilidades explícitas", r.status_code == 201 and bool(d.get("token")), short(r))
    if r.status_code == 201:
        S["u2"]["restricted"] = Api(api, "u2r", token=d["token"], tenant=a.tenant)
        r = S["u2"]["restricted"].raw("POST", "/auth/mfa/step-up", tenant=None, json={"code": totp.next()})
        check("[Tokens] un token restringido no puede verificar el segundo factor (step-up → 403 token_scope_insufficient)",
              r.status_code == 403 and code_of(r) == "token_scope_insufficient", short(r))

    # ── tokens con alcance (propietario de A, sin MFA) ──
    r = a.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "e2e-v25-lectura", "abilities": ["data.view", "forecast.view"], "expires_in_days": 1})
    t = data_of(r) or {}
    exp = t.get("expires_at")
    days = None
    if exp:
        days = (dt.datetime.fromisoformat(exp.replace("Z", "+00:00")) - dt.datetime.now(dt.timezone.utc)).total_seconds() / 86400
    check("[Tokens] emisión con alcance mínimo: habilidades explícitas, atado a la empresa, caducidad pedida (1 día), valor mostrado una vez",
          r.status_code == 201 and bool(t.get("token")) and sorted(t.get("abilities") or []) == ["data.view", "forecast.view"] and days is not None and 0.9 < days <= 1.01,
          f"{short(r)}; caduca en {days and round(days, 3)} días")
    if r.status_code != 201:
        return
    ro = Api(api, "ro", token=t["token"], tenant=a.tenant)
    r1 = ro.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "mas-amplio", "abilities": ["data.view", "forecast.view", "forecast.run", "users.manage"]})
    r2 = ro.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "igual", "abilities": ["data.view"]})
    check("[Tokens] un token restringido NO emite otro token, ni más amplio ni igual (403 token_scope_insufficient)",
          r1.status_code == 403 and code_of(r1) == "token_scope_insufficient" and r2.status_code == 403 and code_of(r2) == "token_scope_insufficient",
          f"más amplio: {short(r1)}; igual: {short(r2)}")
    r3 = ro.raw("GET", "/tenants", tenant=None)
    check("[Tokens] un token restringido no lista ni crea empresas (ruta de cuenta → 403 token_scope_insufficient)", r3.status_code == 403 and code_of(r3) == "token_scope_insufficient", short(r3))
    r4 = ro.raw("GET", "/dashboard/summary", params={"horizon": 30})
    r5 = ro.raw("POST", "/forecast-runs", json={"horizon_days": 7})
    check("[Tokens] el token solo hace lo concedido: lee el dashboard (200) pero no ejecuta un pronóstico (403)", r4.status_code == 200 and r5.status_code == 403, f"lectura {short(r4)}; escritura {short(r5)}")
    # Un token ligado a una empresa IGNORA X-Tenant-ID (ResolveTenant): pedir C con él debe servir A (o negarse), nunca C.
    r6 = ro.raw("GET", "/tenant", tenant=S["c_tenant"])
    served = (data_of(r6) or {}).get("uuid")
    me = ro.raw("GET", "/auth/me", tenant=None)
    tens = [x["uuid"] for x in ((data_of(me) or {}).get("tenants") or [])]
    check("[Tokens] el token atado a A no opera la otra empresa del mismo dueño: con X-Tenant-ID de C sigue en A (la cabecera se ignora) y /auth/me solo muestra A",
          ((r6.status_code == 200 and served == a.tenant) or r6.status_code in (403, 404)) and served != S["c_tenant"] and tens == [a.tenant],
          f"GET /tenant con la cabecera de C → {short(r6)} empresa servida {'A' if served == a.tenant else served}; /auth/me {len(tens)} empresa(s)")
    r7 = u3.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "analista-aprobar", "abilities": ["data.view", "models.approve"]})
    r8 = a.raw("POST", "/auth/tokens", tenant=None, json={"tenant": a.tenant, "name": "comodin", "abilities": ["*"]})
    check("[Tokens] no se emiten habilidades fuera del rol (analista con models.approve → 422) ni el comodín «*» (422)", r7.status_code == 422 and r8.status_code == 422, f"{short(r7)}; {short(r8)}")
    mine = a.json("GET", "/auth/tokens", tenant=None)
    listed = [x for x in mine["data"] if x.get("id") == t["id"]]
    check("[Tokens] el listado de tokens propios muestra el token sin su valor", listed and t["token"] not in json.dumps(mine), f"{len(mine['data'])} tokens")
    rd = a.raw("DELETE", f"/auth/tokens/{t['id']}", tenant=None)
    r9 = ro.raw("GET", "/dashboard/summary", params={"horizon": 30})
    check("[Tokens] revocación inmediata: la siguiente petición con el token revocado → 401", rd.status_code in (200, 204) and r9.status_code == 401, f"revocar {short(rd)}; después {short(r9)}")


# ───────────────────────────── 2. Datos: CSV y SQL, calidad y cuarentena (W2) ─────────────────────────────
def build_erp(skus: list[tuple[str, str, str]]) -> None:
    """ERP ficticio PostgreSQL (esquema de stage2): 6 documentos válidos + 1 con estado desconocido y 1 con tipo desconocido."""
    with s2.pg_connect("postgres", autocommit=True) as conn:
        conn.execute(f'DROP DATABASE IF EXISTS "{ERP_DB}" WITH (FORCE)')
        conn.execute(f'CREATE DATABASE "{ERP_DB}"')
    with s2.pg_connect(ERP_DB, autocommit=True) as conn:
        conn.execute(s2.ERP_SCHEMA)
        conn.execute("INSERT INTO erp_clientes (ruc, nombre) VALUES ('1710034065', 'Cliente Ficticio Uno'), ('9999999999999', 'CONSUMIDOR FINAL')")
        for sku, name, cat in skus:
            conn.execute("INSERT INTO erp_productos (cod_item, descripcion, linea, unidad, precio, costo) VALUES (%s, %s, %s, 'unidad', 8.5, 6.9)", (sku, name, cat))
        today = today_local()
        d = lambda ago: (today - dt.timedelta(days=ago)).isoformat()  # noqa: E731
        s1, s2_, s3 = skus[0][0], skus[1][0], skus[2][0]
        docs = [
            ("902-001-000000001", "FACTURA", "EMITIDA", f"{d(4)} 09:15:00", 1, "LOCAL", [(s1, 10, 8.5, 0, 10.2, 85, 6.9), (s2_, 5, 7.2, 1, 5.25, 35, 5.1)]),
            ("902-001-000000002", "FACTURA", "EMITIDA", f"{d(4)} 11:00:00", 2, "MAYOREO", [(s1, 4, 8.5, 0, 4.08, 34, 6.9)]),
            ("902-001-000000003", "FACTURA", "ANULADA", f"{d(3)} 16:30:00", None, None, [(s3, 2, 15, 0, 3.6, 30, 11)]),
            ("902-001-000000004", "FACTURA", "EMITIDA", f"{d(3)} 10:05:00", 1, "LOCAL", [(s3, 3, 15, 0, 5.4, 45, 11), (s1, 6, 8.5, 0, 6.12, 51, 6.9)]),
            ("902-001-000000005", "FACTURA", "EMITIDA", f"{d(2)} 15:45:00", 2, "LOCAL", [(s2_, 8, 7.2, 0, 6.91, 57.6, 5.1)]),
            ("902-001-000000006", "NOTA DE CREDITO", "EMITIDA", f"{d(1)} 12:00:00", 1, "LOCAL", [(s1, 1, 8.5, 0, 1.02, 8.5, 6.9)]),
            # calidad: estado fuera del vocabulario (REVIEW_REQUIRED) y tipo fuera del vocabulario (REVIEW_REQUIRED)
            ("902-001-000000007", "FACTURA", "RECHAZADA", f"{d(2)} 10:30:00", 1, "LOCAL", [(s2_, 2, 7.2, 0, 1.73, 14.4, 5.1)]),
            ("902-001-000000008", "PROFORMA", "EMITIDA", f"{d(1)} 09:00:00", 2, "LOCAL", [(s3, 1, 15, 0, 1.8, 15, 11)]),
        ]
        for number, tipo, estado, issued, cust, canal, lines in docs:
            fid = conn.execute("INSERT INTO erp_facturas (num_factura, tipo, estado, fecha_emision, cod_sucursal, id_cliente, canal, modificado_en) VALUES (%s,%s,%s,%s,'001',%s,%s,%s::timestamp AT TIME ZONE 'America/Guayaquil') RETURNING id_factura",
                               (number, tipo, estado, issued, cust, canal, issued)).fetchone()[0]
            for i, (sku, qty, price, disc, tax, net, cost) in enumerate(lines, 1):
                conn.execute("INSERT INTO erp_factura_lineas (id_factura, linea, cod_item, cantidad, precio_unit, descuento, iva, subtotal, costo_unit) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                             (fid, i, sku, qty, price, disc, tax, net, cost))


def erp_scalar(sql: str):
    with s2.pg_connect(ERP_DB, autocommit=True) as conn:
        return conn.execute(sql).fetchone()[0]


def docs_total(a: Api) -> int:
    return a.json("GET", "/sales/summary", params={"granularity": "month", "date_from": "2024-01-01", "date_to": "2026-12-31"})["data"]["totals"]["documents"]


def quarantine(a: Api, **params) -> list:
    return s2.get_all(a, "/data-quality/quarantine", params)[0]


def block_data() -> None:
    a, ids = S["a"], S["ids"]
    # ── CSV con errores: bloqueo y cuarentena ──
    bad = mvp.run_import(a, DEMO / "ferreteria_andina_sales_with_errors.csv", "sales", confirm=False)
    errors = a.json("GET", f"/imports/{bad['id']}/errors", params={"per_page": 100})["data"]
    codes = sorted({e["error_code"] for e in errors})
    qr = bad.get("quality_report") or {}
    # /imports/{id}/errors trae dq_class solo para los códigos de revisión del contrato W1; la clase completa queda en data_quality_issues.
    check("[Calidad] el CSV con errores se valida con hallazgos por fila (código estable, columna, fila) e informe de calidad con puntaje",
          bad["status"] == "validated" and (bad["stats"].get("rows_error") or 0) > 0 and bool(codes) and any(e.get("row_number") is not None and e.get("severity") == "error" for e in errors)
          and qr.get("score") is not None and qr.get("score") < 100,
          f"{bad['stats'].get('rows_error')} filas con error; códigos {codes[:9]}; puntaje {qr.get('score')}")
    blocked = a.raw("POST", f"/imports/{bad['id']}/confirm")
    check("[Calidad] FALLO QUE BLOQUEA (carga): con errores y sin skip_invalid_rows la confirmación se rechaza (409 has_errors)", blocked.status_code == 409 and code_of(blocked) == "has_errors", short(blocked))
    before = docs_total(a)
    a.json("PUT", f"/imports/{bad['id']}/mapping", 202, json={"mapping": bad["column_mapping"] or bad["suggested_mapping"], "options": {"skip_invalid_rows": True}})
    bad = mvp.wait_import(a, bad["id"], {"validated"})
    proj = (bad.get("quality_report") or {}).get("summary", {}).get("documents_quarantined", bad["stats"].get("documents_quarantined"))
    a.json("POST", f"/imports/{bad['id']}/confirm", 202)
    bad = mvp.wait_import(a, bad["id"], {"completed"})
    recs = quarantine(a, import_job_id=bad["id"])
    ids["csv_quarantine"] = [q["id"] for q in recs]
    check("[Cuarentena] CSV: con skip_invalid_rows los documentos con hallazgos de revisión/inválidos quedan en cuarentena (no se descartan) y el resto se escribe",
          bad["status"] == "completed" and len(recs) > 0 and all(q["status"] == "pending" and q["entity"] == "sales" and q["issue_codes"] for q in recs),
          f"proyectados {proj}; {len(recs)} registros pendientes; códigos {sorted({c for q in recs for c in q['issue_codes']})}; documentos {before} → {docs_total(a)}")
    if recs:
        hard = {"invalid_date", "negative_price", "invalid_number", "required"}   # QUARANTINED: no se corrigen con un mapeo de valores
        q0 = next((q for q in recs if hard & set(q["issue_codes"])), recs[0])
        det = a.json("GET", f"/data-quality/quarantine/{q0['id']}")["data"]
        check("[Cuarentena] detalle del registro con su carga, objeto Bronze y hallazgos", det["import_job_id"] == bad["id"] and det.get("bronze_object_id") is not None,
              f"{det['record_key']} · Bronze {det.get('bronze_object_id')}")
        rel = a.raw("POST", f"/data-quality/quarantine/{q0['id']}/release")
        check("[Cuarentena] liberar un documento que sigue inválido → 422 quarantine_still_invalid (no se escribe nada)",
              rel.status_code == 422 and code_of(rel) == "quarantine_still_invalid", short(rel))
        dis = a.raw("POST", f"/data-quality/quarantine/{q0['id']}/discard", json={"reason": "Prueba E2E: dato inválido descartado"})
        dis2 = a.raw("POST", f"/data-quality/quarantine/{q0['id']}/discard", json={"reason": "otra vez"})
        check("[Cuarentena] descarte con motivo (200 discarded) y un descarte no se repite (409)",
              dis.status_code == 200 and (data_of(dis) or {}).get("status") == "discarded" and dis2.status_code == 409, f"{short(dis)}; repetido {short(dis2)}")

    # ── fuente SQL (ERP ficticio PostgreSQL) ──
    prods = a.json("GET", "/products", params={"per_page": 3, "sort": "-units_90d"})["data"]
    skus = [(p["sku"], p["name"], (p["category"] or {}).get("name") or "General") for p in prods]
    build_erp(skus)
    check("[SQL] base «ERP de origen» ficticia creada (8 documentos, 10 líneas; incluye un estado y un tipo fuera del vocabulario)",
          erp_scalar("select count(*) from erp_facturas") == 8 and erp_scalar("select count(*) from erp_factura_lineas") == 10, ERP_DB)
    cfg = {"host": os.environ.get("E2E_PG_HOST", "127.0.0.1"), "port": int(os.environ.get("E2E_PG_PORT", "5432")), "database": ERP_DB, "statement_timeout_seconds": 30}
    cred = {"username": os.environ.get("E2E_PG_USER", "predictily"), "password": os.environ.get("E2E_PG_PASSWORD", "secret")}
    src = a.json("POST", "/data-sources", 201, json={"type": "connector", "connector_key": "sql_postgresql", "name": "ERP ficticio E2E V2.5 (PostgreSQL)", "config": cfg, "credentials": cred})["data"]
    ids["source_id"] = src["id"]
    test = a.raw("POST", f"/data-sources/{src['id']}/test")
    td = data_of(test) or {}
    leak = cred["password"] in json.dumps(src) + test.text + a.raw("GET", "/data-sources").text
    check("[SQL] fuente creada; prueba de conexión OK en transacción de solo lectura; las credenciales no se devuelven",
          test.status_code == 200 and td.get("ok") is True and "read_only_enforcement" in (td.get("details") or {}) and not leak,
          f"servidor {(td.get('details') or {}).get('server_version')}; fuga de credenciales: {leak}")
    before_rows = erp_scalar("select count(*) from erp_facturas")
    rej = [a.raw("POST", f"/data-sources/{src['id']}/mappings", json={"entity": "sales", "query": q, "field_map": s2.SALES_MAP, "options": {}})
           for q in ("UPDATE erp_facturas SET estado = 'ANULADA'", "SELECT 1; DROP TABLE erp_facturas", "WITH d AS (DELETE FROM erp_factura_lineas RETURNING *) SELECT * FROM d")]
    check("[SQL] consultas con escritura, varias sentencias o efectos laterales → 422 sql_query_rejected y el origen no cambia",
          all(r.status_code == 422 and code_of(r) == "sql_query_rejected" for r in rej) and erp_scalar("select count(*) from erp_facturas") == before_rows,
          ", ".join(short(r, 0) for r in rej))
    mp = a.json("POST", f"/data-sources/{src['id']}/mappings", 201, json={"entity": "sales", "query": s2.SALES_QUERY, "field_map": s2.SALES_MAP,
                                                                         "options": {"import": {"skip_invalid_rows": True}}})["data"]
    ids["mapping_id"] = mp["id"]
    blocked = a.raw("POST", f"/data-sources/{src['id']}/sync", json={"entity": "sales", "mode": "full"})
    check("[SQL] el mapeo nace sin aprobar y no sincroniza (409 mapping_not_approved)", mp["is_active"] is False and blocked.status_code == 409 and code_of(blocked) == "mapping_not_approved", short(blocked))
    a.json("POST", f"/data-sources/{src['id']}/mappings/{mp['id']}/approve", 200)
    d0 = docs_total(a)
    sy = a.json("POST", f"/data-sources/{src['id']}/sync", 202, json={"entity": "sales", "mode": "full"})["data"]
    s1 = s2.wait_sync(a, sy["id"])
    ids["sync_id"] = s1["id"]
    imp = (s1.get("stats") or {}).get("import", {})
    d1 = docs_total(a)
    recs = quarantine(a, source_sync_id=s1["id"])
    keys = {q["record_key"]: q for q in recs}
    check("[SQL] sincronización completa: 10 líneas extraídas; 6 documentos válidos escritos (con anulada y nota de crédito) y 2 en cuarentena",
          s1["status"] == "completed" and s1["rows_extracted"] == 10 and imp.get("documents_new") == 6 and d1 > d0 and len(recs) == 2,
          f"estado {s1['status']}, {s1['rows_extracted']} filas, nuevos {imp.get('documents_new')}, documentos {d0} → {d1}; cuarentena {sorted(keys)}; stats {json.dumps({k: imp.get(k) for k in ('documents_quarantined', 'rows_error', 'documents_unchanged')})}")
    rechazada = next((q for k, q in keys.items() if k.endswith("902-001-000000007")), None)
    proforma = next((q for k, q in keys.items() if k.endswith("902-001-000000008")), None)
    check("[Cuarentena] SQL: estado y tipo fuera del vocabulario retenidos con su código (unknown_document_status / unknown_document_type), enlazados a la sincronización y a Bronze",
          rechazada is not None and proforma is not None and "unknown_document_status" in rechazada["issue_codes"] and "unknown_document_type" in proforma["issue_codes"]
          and all(q["source_sync_id"] == s1["id"] and q["bronze_object_id"] for q in recs),
          {k: q["issue_codes"] for k, q in keys.items()})
    if rechazada:
        r = a.raw("POST", f"/data-quality/quarantine/{rechazada['id']}/release")
        check("[Cuarentena] sin mapeo de valores el estado desconocido sigue en revisión (422 quarantine_still_invalid)", r.status_code == 422 and code_of(r) == "quarantine_still_invalid", short(r))
        r = a.raw("POST", f"/data-quality/quarantine/{rechazada['id']}/release", json={"value_maps": {"status": {"RECHAZADA": "ANULADA"}}})
        d = data_of(r) or {}
        mine = [x for x in s2.get_all(a, "/sales", {"date_from": (today_local() - dt.timedelta(days=10)).isoformat(), "date_to": today_local().isoformat()})[0]
                if x["document_number"] == "902-001-000000007"]
        check("[Cuarentena] liberación con mapeo de valores (RECHAZADA → anulada): 200 released y el documento se escribe como anulado",
              r.status_code == 200 and d.get("status") == "released" and len(mine) == 1 and mine[0]["status"] == "voided",
              f"{short(r)}; escritura {(d.get('write') or {}).get('state')}; venta {[(x['document_number'], x['status']) for x in mine]}")
    if proforma:
        r = a.raw("POST", f"/data-quality/quarantine/{proforma['id']}/discard", json={"reason": "Prueba E2E: una proforma no es una venta"})
        check("[Cuarentena] la proforma se descarta con motivo (no entra a Silver)", r.status_code == 200 and (data_of(r) or {}).get("status") == "discarded", short(r))
    d2 = docs_total(a)
    s_2 = s2.wait_sync(a, a.json("POST", f"/data-sources/{src['id']}/sync", 202, json={"entity": "sales", "mode": "full"})["data"]["id"])
    imp2 = (s_2.get("stats") or {}).get("import", {})
    d3 = docs_total(a)
    check("[SQL] re-sincronizar no duplica: 0 documentos nuevos y el total de documentos no cambia",
          s_2["status"] in ("completed", "needs_review") and imp2.get("documents_new") == 0 and d3 == d2,
          f"estado {s_2['status']}, nuevos {imp2.get('documents_new')}, sin cambios {imp2.get('documents_unchanged')}, documentos {d2} → {d3}; cuarentena tras re-sincronizar {len(quarantine(a, source_sync_id=s_2['id']))}")
    if s1.get("bronze_object_id"):
        br = a.raw("GET", f"/lineage/bronze/{s1['bronze_object_id']}")
        bd = data_of(br) or {}
        check("[Linaje] el original de la sincronización está en Bronze con su SHA-256 (antes de normalizar)", br.status_code == 200 and bool(bd.get("sha256")), f"{short(br)} sha256 {str(bd.get('sha256'))[:12]}…")
    ids["quarantine_id"] = (rechazada or proforma or {}).get("id")


# ───────────────────────────── 3. Modelos: gates, aprobación separada, firma, inferencia, bloqueo y reversión ─────────────────────────────
def find_artifact(tenant_id, sha: str) -> Path | None:
    base = Path(OPTS.engine_dir) / "storage" / "artifacts" / str(tenant_id)
    if not base.is_dir():
        return None
    for p in base.rglob("*.joblib"):
        try:
            if hashlib.sha256(p.read_bytes()).hexdigest() == sha:
                return p
        except OSError:
            continue
    return None


def verify_signed_artifact(model: dict, tenant_id, label: str) -> None:
    sig = (model.get("artifact_compat") or {}).get("signature") or {}
    path = find_artifact(tenant_id, model["artifact_sha256"])
    if path is None:
        warn(f"[Firma] {label}: artefacto no visible en {OPTS.engine_dir}/storage/artifacts", "no se verifica de forma independiente")
        return
    man_path = path.with_name(path.name + ".manifest.json")
    env, inner = {}, {}
    try:
        env = json.loads(man_path.read_text(encoding="utf-8"))
        inner = json.loads(env.get("manifest") or "{}")
    except (OSError, ValueError):
        pass
    sig_bytes = base64.b64decode(env.get("signature") or "", validate=False) if env.get("signature") else b""
    art = inner.get("artifact") or {}
    check(f"[Firma] {label}: artefacto firmado (Ed25519) y su manifiesto coincide con el registro (SHA-256, empresa, corrida, clave) — verificación independiente",
          sig.get("status") == "signed" and env.get("algorithm") == "ed25519" and len(sig_bytes) == 64 and art.get("sha256") == model["artifact_sha256"]
          and art.get("file_name") == path.name and str(inner.get("tenant_id")) == str(tenant_id) and env.get("key_id") == sig.get("key_id") == inner.get("key_id"),
          f"{path.name}: firma {sig.get('status')}, clave {sig.get('key_id')}, manifiesto {man_path.name if man_path.exists() else 'ausente'}, sha {model['artifact_sha256'][:12]}…")


def run_customer(a: Api, operation: str, **body) -> dict:
    r = a.req("POST", "/customer/runs", (202,), json={"operation": operation, **body})
    run = r.json()["data"]
    job = s2.wait_run(a, (run.get("training_job") or {}).get("id"), f"comprador {operation}", timeout=1800)
    full = a.json("GET", f"/customer/runs/{run['id']}")["data"]
    check(f"[Compradores] {operation} completado", job["status"] == "succeeded" and full["status"] == "completed",
          f"run {full['id']} calidad {full.get('quality_status')} {full.get('error_message') or ''}")
    return full


def block_models() -> None:
    a, ids = S["a"], S["ids"]
    u2, u3, totp = S["u2"]["c"], S["u3"]["c"], S["u2"].get("totp")
    run1 = s2.run_demand(a, 30)
    env = run1.get("envelope") or {}
    S["tenant_id_a"] = env.get("tenant_id")
    check("[Gates] el entrenamiento de demanda pasó los gates de calidad (no BLOCK): calidad declarada y limitaciones listadas",
          run1["status"] == "completed" and env.get("quality_status") in {"ok", "degraded"} and isinstance(env.get("limitation_codes"), list),
          f"calidad {env.get('quality_status')}, limitaciones {env.get('limitation_codes')}, avisos {[(w if isinstance(w, str) else json.dumps(w))[:60] for w in ((run1.get('summary') or {}).get('warnings') or [])][:3]}")
    run2 = s2.run_demand(a, 30)
    ids["run1"], ids["run2"], ids["demand_job"] = run1["id"], run2["id"], (run2.get("training_job") or {}).get("id")
    check("[Registro] segunda ejecución vigente (versión +1)", run2["version"] == run1["version"] + 1 and run2["is_current"], f"v{run1['version']} → v{run2['version']}")

    # ── inferencia ──
    series = a.json("GET", "/forecasts/series", params={"horizon": 30})["data"]
    check("[Inferencia] demanda: serie a 30 días servida por la ejecución vigente, con respaldo por horizonte", series["meta"]["run_id"] == run2["id"] and len(series["forecast"]) == 30
          and series["meta"]["horizon_support"]["status"] in {"ok", "degraded"}, f"{len(series['forecast'])} puntos, respaldo {series['meta']['horizon_support']['status']}")
    train = run_customer(a, "train", horizons=[7, 30, 90])
    pred = run_customer(a, "predict")
    ids["customer_train"], ids["customer_predict"] = train["id"], pred["id"]
    cp = a.json("GET", "/customer/predictions", params={"horizon": 30, "per_page": 50})
    rows = cp["data"]
    check("[Inferencia] compradores: predicciones a 30 días con envoltura (calidad, incertidumbre) y probabilidades en (0, 1)",
          cp["meta"]["total"] > 0 and cp["envelope"]["run_id"] == pred["id"] and all(0 < x["purchase_probability"] < 1 for x in rows),
          f"{cp['meta']['total']} filas; calidad {cp['envelope']['quality_status']}; limitaciones {cp['envelope'].get('limitation_codes')}")

    # ── firma de artefactos (verificación independiente) ──
    models = s2.get_all(a, "/models", {"core": "demand", "run_id": run2["id"]})[0] + s2.get_all(a, "/models", {"core": "customer"})[0]
    signed = [m for m in models if m.get("artifact_sha256")]
    info(f"registro de A: {len(models)} modelos; con artefacto {len(signed)}; estados {sorted({(m['core'], m['status']) for m in models})}")
    for m in [x for x in signed if x["core"] == "demand"][:1] + [x for x in signed if x["core"] == "customer"][:1]:
        verify_signed_artifact(m, S["tenant_id_a"], f"{m['core']} {m['model_family']} (modelo {m['id']}, {m['status']})")
    if not signed:
        check("[Firma] hay al menos un modelo con artefacto firmado en el registro", False, "ningún modelo con artifact_sha256")

    # ── aprobación separada (W4) ──
    cands = [m for m in models if m["status"] in ("validated", "candidate") and m.get("serving_basis") != "approved"]
    cands.sort(key=lambda m: (m["status"] != "validated", not m.get("artifact_sha256"), m["core"] != "customer", m["id"]))
    if not cands:
        check("[Aprobación] hay un modelo validado o candidato para la aprobación separada", False, f"estados {sorted({m['status'] for m in models})}")
    else:
        m = cands[0]
        ids["approved_model"] = m["id"]
        info(f"modelo para la aprobación separada: {m['id']} {m['core']} {m['model_family']} ({m['status']}, artefacto {'sí' if m.get('artifact_sha256') else 'no'}, base {m.get('serving_basis')})")
        check("[Aprobación] el modelo elegido no está en producción ni tiene aprobación registrada", m["status"] != "production" and not m.get("approved_by") and not m.get("approved_at"),
              f"{m['status']} · {m.get('serving_basis')}")
        if totp:
            step_up(u2, totp, "antes de decidir sobre el modelo")
        rp = u2.raw("POST", f"/models/{m['id']}/promote", json={})
        check("[Aprobación] un modelo NO aprobado no se promueve a producción, ni con la habilidad y el segundo factor (409)", rp.status_code == 409, short(rp))
        if m["status"] == "candidate":
            rv = u2.raw("POST", f"/models/{m['id']}/validate", json={"reason": "Validación E2E V2.5"})
            check("[Aprobación] validar el candidato ejecuta las compuertas (candidate → validated) y no lo vuelve servible", rv.status_code == 200 and (data_of(rv) or {}).get("status") == "validated", short(rv))
        r1 = a.raw("POST", f"/models/{m['id']}/approve", json={"reason": "Intento del responsable"})
        check("[Aprobación] segregación de funciones: quien disparó el entrenamiento no puede aprobarlo (403 approval_segregation_of_duties)",
              r1.status_code == 403 and code_of(r1) == "approval_segregation_of_duties", short(r1))
        r2 = u3.raw("POST", f"/models/{m['id']}/approve", json={"reason": "Intento del analista"})
        check("[Aprobación] un analista no tiene models.approve (403)", r2.status_code == 403, short(r2))
        rr = S["u2"].get("restricted")
        if rr is not None:
            r3 = rr.raw("POST", f"/models/{m['id']}/approve", json={"reason": "Token sin segundo factor fresco"})
            check("[MFA] acción sensible (aprobar un modelo) sin segundo factor fresco → 403 mfa_step_up_required", r3.status_code == 403 and code_of(r3) == "mfa_step_up_required", short(r3))
        r4 = u2.raw("POST", f"/models/{m['id']}/approve", json={"reason": "Aprobación E2E V2.5 por una persona distinta del responsable"})
        d4 = data_of(r4) or {}
        check("[Aprobación] otra persona (administrador con step-up) aprueba: validated → approved, con aprobador registrado",
              r4.status_code == 200 and d4.get("status") == "approved" and d4.get("approved_by") == S["u2"]["id"] and bool(d4.get("approved_at")), short(r4))
        det = a.json("GET", f"/models/{m['id']}")["data"]
        ev = [e for e in det.get("events", []) if e.get("to_status") == "approved"]
        gates = ((ev[-1].get("evidence") or {}).get("gates") or {}) if ev else {}
        sig_st = (gates.get("signature") or {}).get("status")
        dg = gates.get("data_gate") or {}
        check("[Aprobación] el evento de aprobación guarda las compuertas: firma (verified o not_applicable sin artefacto) y gate de datos ≠ BLOCK",
              bool(ev) and ev[-1].get("actor_user_id") == S["u2"]["id"] and sig_st in ({"verified"} if m.get("artifact_sha256") else {"verified", "not_applicable"})
              and str(dg.get("outcome", "")).upper() in GATE_OK,
              f"firma {sig_st}, gate de datos {dg.get('outcome')} ({dg.get('source') or dg.get('basis')}), eventos {[(e.get('from_status'), e.get('to_status'), e.get('actor_kind')) for e in det.get('events', [])]}")
        if r4.status_code == 200:
            r5 = u2.raw("POST", f"/models/{m['id']}/promote", json={"reason": "Promoción E2E"})
            d5 = data_of(r5) or {}
            det = a.json("GET", f"/models/{m['id']}")["data"]
            check("[Aprobación] promoción del aprobado: approved → production con serving_basis = approved (retira al del mismo alcance)",
                  r5.status_code == 200 and det["status"] == "production" and det.get("serving_basis") == "approved",
                  f"{short(r5)}; retirados {d5.get('retired_model_ids')}")

    # ── reversión (demanda) ──
    r = a.raw("POST", "/models/demand/rollback", json={"run_id": run2["id"]})
    check("[Reversión] revertir a la ejecución vigente → 409 rollback_already_current", r.status_code == 409 and code_of(r) == "rollback_already_current", short(r))
    r = a.raw("POST", "/models/demand/rollback", json={"run_id": run1["id"]})
    rb = data_of(r) or {}
    check("[Reversión] reversión de la ejecución vigente a la anterior (modelos restaurados y retirados en la misma transacción)",
          r.status_code == 200 and rb.get("from_run_id") == run2["id"] and rb.get("to_run_id") == run1["id"] and rb.get("restored_model_ids") and rb.get("retired_model_ids"),
          f"{short(r)} v{rb.get('from_version')} → v{rb.get('to_version')}, artefactos verificados: {rb.get('artifacts_verified')} {json.dumps(rb.get('artifacts_verification') or {}, ensure_ascii=False)[:120]}")
    cur = [x for x in a.json("GET", "/forecast-runs")["data"] if x["is_current"]]
    s_after = a.json("GET", "/forecasts/series", params={"horizon": 30})["data"]
    prod = a.json("GET", "/models", params={"core": "demand", "status": "production"})["data"]
    check("[Reversión] tras revertir, la vigente única es la anterior y la serie y los modelos en producción apuntan a ella",
          len(cur) == 1 and cur[0]["id"] == run1["id"] and s_after["meta"]["run_id"] == run1["id"] and prod and all(x["forecast_run_id"] == run1["id"] for x in prod),
          f"vigente {cur[0]['id'] if cur else None}; producción de {sorted({x['forecast_run_id'] for x in prod})}")
    if ids.get("approved_model"):
        dm = a.json("GET", f"/models/{ids['approved_model']}")["data"]
        if dm["core"] == "demand":
            check("[Reversión] el modelo aprobado de la ejecución revertida queda retirado y conserva su aprobación (no hay dos producciones)",
                  dm["status"] == "retired" and dm.get("approved_by") == S["u2"]["id"], f"{dm['status']} · {dm.get('serving_basis')}")
    r = a.raw("POST", "/models/demand/rollback", json={"run_id": run2["id"]})
    check("[Reversión] «avanzar» con rollback a una ejecución más nueva → 409 rollback_target_not_previous", r.status_code == 409 and code_of(r) == "rollback_target_not_previous", short(r))

    # ── un fallo de calidad que BLOQUEA (empresa C: < 28 días de historia) ──
    c = S["c"]
    with (DEMO / "ferreteria_andina_sales.csv").open(encoding="utf-8") as fh:
        rows = list(csv.DictReader(fh))
    last = max(r_["issued_at"][:10] for r_ in rows)
    first = (dt.date.fromisoformat(last) - dt.timedelta(days=19)).isoformat()
    out = Path(OPTS.workdir) / "e2e_v25_sales_20d.csv"
    with out.open("w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        w.writeheader()
        w.writerows([r_ for r_ in rows if r_["issued_at"][:10] >= first])
    job = mvp.run_import(c, out, "sales")
    check("[C] importa solo 20 días de ventas", job["status"] == "completed", f"{job['stats'].get('documents_new')} documentos desde {first}")
    r = c.raw("POST", "/forecast-runs", json={"horizon_days": 7})
    if r.status_code == 202:
        run = data_of(r)
        wj = s2.wait_run(c, (run.get("training_job") or {}).get("id"), "pronóstico H=7 con 20 días de historia")
        full = c.json("GET", f"/forecast-runs/{run['id']}")["data"]
        msg = f"{full.get('error_message') or ''} {wj.get('error_message') or ''}"
        check("[Gates] FALLO DE CALIDAD QUE BLOQUEA (entrenamiento): con < 28 días de historia el gate BLOCK detiene el pronóstico con código estable y sin modelo servido",
              wj["status"] == "failed" and full["status"] == "failed" and "dq_demand_insufficient_history" in msg
              and (full.get("summary") or {}).get("quality_status", "insufficient_data") == "insufficient_data" and not full.get("is_current"),
              f"run {full['status']}, calidad {(full.get('summary') or {}).get('quality_status')}, mensaje: {msg.strip()[:220]}")
        mc = c.json("GET", "/models", params={"core": "demand", "status": "production"})["data"]
        check("[Gates] la empresa bloqueada no tiene modelos de demanda en producción", mc == [], f"{len(mc)} en producción")
    else:
        check("[Gates] FALLO DE CALIDAD QUE BLOQUEA (entrenamiento): la solicitud con < 28 días de historia se rechaza con un motivo de datos",
              r.status_code == 422 and ("dq_" in r.text or code_of(r) in ("insufficient_data", "insufficient_history")), short(r, 300))


# ───────────────────────────── 4. Revenue Radar, decisión y outcome (W5) ─────────────────────────────
def transition(c: Api, rec_id: int, to: str, **extra) -> httpx.Response:
    cur = c.json("GET", f"/decisions/{rec_id}")["data"]
    return c.raw("POST", f"/decisions/{rec_id}/transition", json={"to_status": to, "idempotency_key": f"e2e-v25-{rec_id}-{to}-{uuid.uuid4().hex[:8]}",
                                                                  "expected_version": cur["version"], **extra})


def block_radar() -> None:
    a, ids = S["a"], S["ids"]
    u2, u3, totp = S["u2"]["c"], S["u3"]["c"], S["u2"].get("totp")
    mat = a.json("POST", "/radar/opportunities/materialize", 200, json={})["data"]
    check("[Radar] materializa las oportunidades de la predicción vigente por el Decision Engine (propone, no escribe por su cuenta)",
          mat["status"] in ("completed", "partial") and mat["created"] > 0 and mat["run_id"] == ids.get("customer_predict"),
          f"{mat['status']}: candidatos {mat['candidates']}, creadas {mat['created']}, existentes {mat['existing']}, fallidas {mat['failed']}, horizontes {mat['horizons']}")
    again = a.json("POST", "/radar/opportunities/materialize", 200, json={})["data"]
    check("[Radar] materializar otra vez con los mismos insumos no duplica (idempotente)", again["created"] == 0 and again["existing"] >= mat["created"], f"creadas {again['created']}, existentes {again['existing']}")
    opps = s2.get_all(a, "/radar/opportunities")[0]
    S["opps_all"] = opps
    summ = a.json("GET", "/radar/summary")["data"]
    check("[Radar] lista de trabajo y resumen sobre lo materializado (sin nombres: solo el código del comprador)",
          len(opps) > 0 and all(o["customer"].get("id") and "name" not in o["customer"] for o in opps),
          f"{len(opps)} oportunidades; resumen {json.dumps(summ.get('totals'), ensure_ascii=False)[:160]}")
    by_c: dict[int, list] = {}
    for o in opps:
        if o.get("kind") in ("contact", "recovery") and not o.get("assigned_user"):
            by_c.setdefault(o["customer"]["id"], []).append(o)
    multi = [cid for cid, lst in by_c.items() if len(lst) >= 2]
    if not multi or len(by_c) < 3:
        raise SystemExit(f"el Radar no trae suficientes oportunidades para el workflow (clientes con ≥2: {len(multi)}, clientes: {len(by_c)})")
    c1 = multi[0]
    others = [cid for cid in by_c if cid != c1]
    o1, o4 = by_c[c1][0], by_c[c1][1]
    o2, o3 = by_c[others[0]][0], by_c[others[1]][0]
    o5 = by_c[others[2]][0] if len(others) > 2 else None
    S["radar"] = {"c1": c1, "o1": o1["id"], "o2": o2["id"], "o3": o3["id"], "o4": o4["id"], "o5": o5["id"] if o5 else None}
    ids["opportunity"] = o1["id"]
    d1 = a.json("GET", f"/decisions/{o1['id']}")["data"]
    check("[Decisión] la oportunidad es una recomendación del Decision Engine: potencial NO probado, sin incremental, con fórmula y proponente (quien materializó)",
          d1["attribution_label"] == "potential_unproven" and d1.get("incremental_estimate") is None and bool(d1.get("score_formula_version"))
          and (d1.get("proposed_by") or {}).get("user_id", (d1.get("proposed_by") or {}).get("id")) == S["u1"]["id"],
          f"{d1['kind']} h{d1['horizon_days']} · {d1['attribution_label']} · propuesta por {d1.get('proposed_by')}")

    # ── rol comercial del vendedor: cartera = cliente c1 ──
    r = a.raw("PUT", f"/tenant/users/{S['u3']['id']}/commercial-role", json={"commercial_role": "vendedor", "commercial_scope": {"customer_ids": [c1]}})
    d = data_of(r) or {}
    check("[Vendedor] la administración asigna el rol comercial «vendedor» con su cartera (se suma al rol de acceso analista)",
          r.status_code == 200 and d.get("commercial_role") == "vendedor" and d.get("access_role") == "analyst" and (d.get("commercial_scope") or {}).get("customer_ids") == [c1], short(r))
    bad = a.raw("PUT", f"/tenant/users/{S['u3']['id']}/commercial-role", json={"commercial_role": "Vendedor"})
    check("[Vendedor] solo gerente/vendedor/servicio o null se escriben (otra mayúscula → 422)", bad.status_code == 422, short(bad))

    # ── workflow de la decisión o1 ──
    r = transition(a, o1["id"], "assigned", assignee_user_id=S["u3"]["id"])
    check("[Decisión] asignar al vendedor (suggested → assigned)", r.status_code == 200 and (data_of(r) or {}).get("status") == "assigned", short(r))
    stale = a.raw("POST", f"/decisions/{o1['id']}/transition", json={"to_status": "reviewed", "idempotency_key": f"e2e-v25-stale-{uuid.uuid4().hex[:8]}", "expected_version": d1["version"]})
    check("[Decisión] bloqueo optimista: una versión desfasada → 409 decision_version_conflict", stale.status_code == 409 and code_of(stale) == "decision_version_conflict", short(stale))
    cur = u3.json("GET", f"/decisions/{o1['id']}")["data"]
    key = f"e2e-v25-review-{uuid.uuid4().hex[:8]}"
    rv1 = u3.raw("POST", f"/decisions/{o1['id']}/transition", json={"to_status": "reviewed", "idempotency_key": key, "expected_version": cur["version"]})
    rv2 = u3.raw("POST", f"/decisions/{o1['id']}/transition", json={"to_status": "reviewed", "idempotency_key": key, "expected_version": cur["version"]})
    check("[Decisión] el vendedor asignado revisa (assigned → reviewed); repetir con la misma clave no repite la transición",
          rv1.status_code == 200 and (data_of(rv1) or {}).get("status") == "reviewed" and rv2.status_code == 200 and ((rv2.json().get("meta") or {}).get("idempotent_replay") is True),
          f"{short(rv1)}; repetición {short(rv2)} meta {rv2.json().get('meta') if rv2.status_code == 200 else ''}")
    r = transition(a, o1["id"], "approved")
    check("[Decisión] segregación: quien propuso (materializó a mano) no aprueba (403 decision_segregation_of_duties)", r.status_code == 403 and code_of(r) == "decision_segregation_of_duties", short(r))
    r = transition(u3, o1["id"], "approved")
    check("[Decisión] el vendedor (analista, ejecutor) no aprueba (403)", r.status_code == 403, short(r))
    rr = S["u2"].get("restricted")
    if rr is not None:
        r = transition(rr, o1["id"], "approved")
        check("[MFA] acción sensible (aprobar una decisión) sin segundo factor fresco → 403 mfa_step_up_required", r.status_code == 403 and code_of(r) == "mfa_step_up_required", short(r))
    if totp:
        step_up(u2, totp, "antes de aprobar la decisión")
    r = transition(u2, o1["id"], "approved")
    check("[Decisión] el aprobador (administrador, con step-up) aprueba (reviewed → approved)", r.status_code == 200 and (data_of(r) or {}).get("status") == "approved", short(r))
    r = transition(u2, o1["id"], "in_progress")
    check("[Decisión] quien aprobó no ejecuta (403 decision_segregation_of_duties)", r.status_code == 403 and code_of(r) == "decision_segregation_of_duties", short(r))
    r = transition(u3, o1["id"], "in_progress")
    r2 = transition(u3, o1["id"], "executed") if r.status_code == 200 else r
    check("[Decisión] el asignado inicia y registra la ejecución (approved → in_progress → executed; Predictily no ejecuta nada)",
          r.status_code == 200 and r2.status_code == 200 and (data_of(r2) or {}).get("status") == "executed", f"{short(r)}; {short(r2)}")
    full = a.json("GET", f"/decisions/{o1['id']}")["data"]
    trail = [(e.get("from_status"), e.get("to_status"), e.get("actor_user_id")) for e in full.get("events", []) if e.get("event") != "comment"]
    want = [("suggested", "assigned", S["u1"]["id"]), ("assigned", "reviewed", S["u3"]["id"]), ("reviewed", "approved", S["u2"]["id"]),
            ("approved", "in_progress", S["u3"]["id"]), ("in_progress", "executed", S["u3"]["id"])]
    check("[Decisión] auditoría: cada transición deja un evento con estado previo, nuevo y autor", all(w in trail for w in want), f"eventos {trail}")
    r = a.raw("POST", f"/decisions/{o1['id']}/transition", json={"to_status": "measured", "idempotency_key": f"e2e-v25-m-{uuid.uuid4().hex[:8]}", "expected_version": full["version"]})
    check("[Outcome] una persona no puede declarar «measured» (lo pone solo la medición del sistema)", r.status_code in (409, 422), short(r))

    # ── asignaciones para el alcance del vendedor ──
    r3 = transition(a, o3["id"], "assigned", assignee_user_id=S["u3"]["id"])
    r4 = transition(a, o4["id"], "assigned", assignee_user_id=S["u2"]["id"])
    check("[Decisión] se asignan o3 (cliente fuera de la cartera) al vendedor y o4 (cliente de su cartera) al aprobador", r3.status_code == 200 and r4.status_code == 200, f"{short(r3)}; {short(r4)}")

    # ── seguimiento de la agenda (idempotente) ──
    if o5:
        body = {"state": "contactado", "note": "Llamada registrada por la prueba E2E", "idempotency_key": f"e2e-v25-fb-{uuid.uuid4().hex[:10]}"}
        f1 = a.raw("POST", f"/radar/opportunities/{o5['id']}/feedback", json=body)
        f2 = a.raw("POST", f"/radar/opportunities/{o5['id']}/feedback", json=body)
        f3 = a.raw("POST", f"/radar/opportunities/{o5['id']}/feedback", json={**body, "state": "interesado"})
        check("[Radar] seguimiento de la agenda auditable e idempotente: 201 la primera vez, 200 replayed al repetir, 409 con la misma clave y otro contenido",
              f1.status_code == 201 and f2.status_code == 200 and f2.headers.get("Idempotent-Replayed") is not None and f3.status_code == 409 and code_of(f3) == "idempotency_key_reused",
              f"{short(f1)}; {short(f2)}; {short(f3)}")

    # ── outcome con clasificación honesta ──
    roi = a.json("GET", "/decisions/roi")["data"]
    fun = (roi.get("summary") or {}).get("funnel") or {}
    pend = fun.get("forecast_pending") or {}
    byc = (roi.get("summary") or {}).get("by_classification") or {}
    outs = a.json("GET", "/decisions/outcomes", params={"recommendation_id": o1["id"]})
    win_to = (dt.date.fromisoformat(d1["as_of"]) + dt.timedelta(days=int(d1["horizon_days"]))).isoformat()
    check("[Outcome] la decisión ejecutada queda «prevista» (forecast) mientras su ventana está abierta: ningún resultado inventado ni causalidad",
          outs["data"] == [] and pend.get("window_open", 0) >= 1 and (byc.get("causally_attributable") or 0) == 0 and (byc.get("incremental_estimated") or 0) == 0,
          f"ventana {d1['as_of']} + {d1['horizon_days']} d (hasta {win_to}); pendientes {pend}; medidas {fun.get('measured')}; método {roi.get('method')}; roi {json.dumps(roi.get('roi'), ensure_ascii=False)[:120]}")
    check("[Outcome] el informe de ROI declara su método y sus limitaciones (no hay ROI probado)", bool(roi.get("method")) and isinstance(roi.get("limitations"), list),
          f"{len(roi.get('limitations') or [])} limitaciones")
    warn("[Outcome] la clasificación medida (observed / temporally_associated) la escribe el comando programado predictily:outcomes-measure",
         f"no hay ruta pública que mida; la ventana de la decisión cierra el {win_to} + 3 d de margen: el E2E verifica el estado «previsto» y que no se inventa ningún resultado")


# ───────────────────────────── 5. Vendedor: solo su cartera (W6-A) ─────────────────────────────
def block_seller() -> None:
    a, u3, rd = S["a"], S["u3"]["c"], S["radar"]
    u3id = S["u3"]["id"]
    all_a = s2.get_all(a, "/radar/opportunities")[0]
    mine = s2.get_all(u3, "/radar/opportunities")[0]
    expected = {o["id"] for o in all_a if (not o.get("assigned_user") and o["customer"]["id"] == rd["c1"]) or ((o.get("assigned_user") or {}).get("id") == u3id)}
    got = {o["id"] for o in mine}
    check("[Vendedor] Radar: ve exactamente lo asignado a él y lo SIN asignar de su cartera (nada de otros clientes ni lo asignado a otra persona)",
          got == expected and rd["o3"] in got and rd["o4"] not in got and rd["o2"] not in got and len(got) < len(all_a),
          f"ve {len(got)} de {len(all_a)}; esperado {len(expected)}; diferencia {sorted(got ^ expected)[:6]}")
    sa, s3 = a.json("GET", "/radar/summary")["data"], u3.json("GET", "/radar/summary")["data"]
    check("[Vendedor] el resumen del Radar se calcula sobre lo visible (no incluye totales de clientes ajenos)", json.dumps(s3.get("totals")) != json.dumps(sa.get("totals")),
          f"vendedor {json.dumps(s3.get('totals'), ensure_ascii=False)[:120]}")
    det = {k: u3.raw("GET", f"/radar/opportunities/{rd[k]}").status_code for k in ("o1", "o2", "o3", "o4")}
    check("[Vendedor] detalle por id: lo suyo 200; un cliente ajeno o lo asignado a otra persona → 404 (sin oráculo de existencia)",
          det["o1"] == 200 and det["o3"] == 200 and det["o2"] == 404 and det["o4"] == 404, json.dumps(det))
    fb = u3.raw("POST", f"/radar/opportunities/{rd['o2']}/feedback", json={"state": "contactado", "idempotency_key": f"e2e-v25-x-{uuid.uuid4().hex[:8]}"})
    check("[Vendedor] escribir seguimiento sobre una oportunidad fuera de su alcance → 404 y no cambia nada", fb.status_code == 404
          and a.json("GET", f"/radar/opportunities/{rd['o2']}")["data"]["state"] in ("pendiente", None), short(fb))
    decs = s2.get_all(u3, "/decisions")[0]
    bad = [d["id"] for d in decs if not ((d.get("assigned_user_id") == u3id) or (d.get("assigned_user_id") is None and (d.get("customer") or {}).get("id") == rd["c1"]))]
    check("[Vendedor] decisiones: la lista solo trae lo visible (asignado a él o sin asignar de su cartera)", decs and not bad and rd["o2"] not in {d["id"] for d in decs},
          f"{len(decs)} decisiones visibles; fuera de alcance {bad[:5]}")
    team = {p: u3.raw("GET", p).status_code for p in ("/decisions/outcomes", "/decisions/roi")}
    mat = u3.raw("POST", "/radar/opportunities/materialize", json={})
    check("[Vendedor] vistas de EQUIPO cerradas (outcomes, ROI, materializar → 403 commercial_scope_forbidden)",
          all(v == 403 for v in team.values()) and mat.status_code == 403, f"{json.dumps(team)}; materializar {short(mat)}")
    p0 = u3.raw("GET", "/customer/predictions", params={"horizon": 30})
    p1 = u3.raw("GET", "/customer/predictions", params={"horizon": 30, "customer_id": rd["c1"]})
    other = next((o["customer"]["id"] for o in S["opps_all"] if o["customer"]["id"] != rd["c1"]), None)
    p2 = u3.raw("GET", "/customer/predictions", params={"horizon": 30, "customer_id": other})
    check("[Vendedor] predicciones de compradores: exige un cliente visible (403 sin filtro, 200 su cliente, 404 un cliente ajeno)",
          p0.status_code == 403 and p1.status_code == 200 and p2.status_code == 404, f"{p0.status_code}/{p1.status_code}/{p2.status_code}")
    st = u3.json("GET", "/assistant/status")["data"]
    cs = st.get("commercial_scope") or {}
    check("[Vendedor] el asistente informa el alcance comercial restringido sin revelar los ids de la cartera",
          cs.get("role") == "vendedor" and cs.get("restricted") is True and str(rd["c1"]) not in json.dumps(cs), json.dumps(cs, ensure_ascii=False)[:160])


# ───────────────────────────── 6. Predictily Bridge (W6-B) ─────────────────────────────
def bridge_crypto():
    sys.path.insert(0, str(ROOT / "bridge" / "src"))
    from predictily_bridge import crypto  # noqa: PLC0415  (código del agente: firma Ed25519 y mensaje canónico)
    from predictily_bridge.transport import _code_hash  # noqa: PLC0415

    return crypto, _code_hash


def block_bridge() -> None:
    a = S["a"]
    try:
        crypto, code_hash = bridge_crypto()
    except ImportError as exc:
        warn("[Bridge] no se pudo cargar la criptografía del agente (cryptography / bridge/src)", str(exc)[:160])
        return
    api_path = urlsplit(OPTS.api).path.rstrip("/")
    src = a.json("POST", "/data-sources", 201, json={"type": "connector", "connector_key": "predictily_bridge", "name": "Bridge E2E V2.5 (equipo ficticio)"})["data"]
    S["ids"]["bridge_source"] = src["id"]
    check("[Bridge] fuente «Predictily Bridge» creada inactiva hasta registrar un dispositivo", src.get("status") == "inactive", f"fuente {src['id']} {src.get('status')}")
    iss = a.json("POST", f"/data-sources/{src['id']}/bridge/enrolments", 201, json={"device_name_hint": "Equipo ficticio E2E", "ttl_minutes": 30})["data"]
    code = iss["code"]
    check("[Bridge] código de registro de un solo uso (BRG-…), con vencimiento", code.startswith("BRG-") and iss.get("single_use") is True and bool(iss.get("expires_at")), f"vence {iss.get('expires_at')}")
    key = crypto.DeviceKey.generate()
    pub = key.public_b64

    def enrol_body(k, c, ts=None):
        ts = int(time.time()) if ts is None else ts
        return {"code": c, "device_name": "equipo-ficticio-e2e", "public_key": k.public_b64, "timestamp": ts, "agent_version": "0.1.0", "platform": "other",
                "proof": k.sign(crypto.enrol_message(code_hash(c), k.public_b64, ts))}

    http = httpx.Client(base_url=OPTS.api, timeout=60, headers={"Accept": "application/json"})
    other = crypto.DeviceKey.generate()
    forged = enrol_body(other, code)
    forged["public_key"] = pub        # prueba de posesión de OTRA clave
    r = http.post("/bridge/enrol", json=forged)
    check("[Bridge] registro con prueba de posesión inválida → 422 invalid_proof (el código no se consume)", r.status_code == 422 and code_of(r) == "invalid_proof", short(r))
    r = http.post("/bridge/enrol", json=enrol_body(key, code))
    d = data_of(r) or {}
    ok = check("[Bridge] registro del dispositivo con par Ed25519 generado localmente: solo viaja la clave pública; el token se devuelve una vez",
               r.status_code == 201 and str(d.get("token", "")).startswith("brt_") and d.get("tenant", {}).get("uuid") == a.tenant, short(r))
    if not ok:
        return
    token, device_id = d["token"], d["device_id"]
    S["ids"]["bridge_device"] = device_id
    r = http.post("/bridge/enrol", json=enrol_body(crypto.DeviceKey.generate(), code))
    check("[Bridge] el código ya canjeado no sirve otra vez (respuesta sin oráculo)", r.status_code in (404, 409, 422) and "token" not in (data_of(r) or {}), short(r))

    def signed(method: str, path: str, payload: dict | None, *, ts: int | None = None, nonce: str | None = None, body_override: bytes | None = None,
               k=key, tok: str | None = None) -> tuple[httpx.Response, dict]:
        raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8") if payload is not None else b""
        ts = int(time.time()) if ts is None else ts
        nonce = nonce or crypto.new_nonce()
        msg = crypto.request_message(method, api_path + path, "", ts, nonce, raw)
        h = {"Authorization": f"Bearer {tok or token}", "X-Bridge-Key": k.public_b64, "X-Bridge-Timestamp": str(ts), "X-Bridge-Nonce": nonce,
             "X-Bridge-Signature": k.sign(msg), "Content-Type": "application/json"}
        return http.request(method, path, content=body_override if body_override is not None else raw, headers=h), {"ts": ts, "nonce": nonce, "raw": raw, "headers": h}

    hb = {"agent_version": "0.1.0", "platform": "other", "health": {"status": "ok", "pending_batches": 0, "spool_bytes": 0, "warnings": []}}
    r, sent = signed("POST", "/bridge/heartbeat", hb)
    dd = data_of(r) or {}
    check("[Bridge] latido firmado (token + firma Ed25519 de método, ruta, marca de tiempo, nonce y SHA-256 del cuerpo) → 200 con el contrato del servidor",
          r.status_code == 200 and dd.get("status") == "active" and dd.get("protocol") == 1 and abs(int(dd.get("server_time", 0)) - int(time.time())) < 300,
          f"{short(r)} entidades {dd.get('entities')}")
    rep = http.post("/bridge/heartbeat", content=sent["raw"], headers=sent["headers"])
    check("[Bridge] repetir exactamente la misma petición (mismo nonce) → 401 device_replay", rep.status_code == 401 and code_of(rep) == "device_replay", short(rep))
    r, _ = signed("POST", "/bridge/heartbeat", hb, body_override=json.dumps({**hb, "platform": "windows"}, separators=(",", ":")).encode())
    check("[Bridge] cuerpo alterado después de firmar → 401 device_signature_invalid", r.status_code == 401 and code_of(r) == "device_signature_invalid", short(r))
    r, _ = signed("POST", "/bridge/heartbeat", hb, ts=int(time.time()) - 900)
    check("[Bridge] marca de tiempo fuera de ±5 min → 401 device_clock_skew", r.status_code == 401 and code_of(r) == "device_clock_skew", short(r))
    r, _ = signed("POST", "/bridge/heartbeat", hb, k=other)
    check("[Bridge] firma con una clave distinta de la registrada → 401 device_signature_invalid (un token robado sin la clave privada no sirve)",
          r.status_code == 401 and code_of(r) == "device_signature_invalid", short(r))
    r = http.post("/bridge/heartbeat", json=hb, headers={"Authorization": a.http.headers["Authorization"]})
    check("[Bridge] un token de USUARIO no sirve en las rutas del agente (401 device_unauthorized)", r.status_code == 401 and code_of(r) == "device_unauthorized", short(r))
    r = http.get("/dashboard/summary", headers={"Authorization": f"Bearer {token}", "X-Tenant-ID": a.tenant})
    check("[Bridge] el token del dispositivo no sirve en el resto de la API (401)", r.status_code == 401, short(r))
    dev = a.json("GET", f"/bridge/devices/{device_id}")["data"]
    check("[Bridge] la administración ve el dispositivo activo con su huella (SHA-256 de la clave pública), último contacto y salud reportada",
          dev["status"] == "active" and dev["public_key_fingerprint"] == key.fingerprint and bool(dev.get("last_seen_at")) and (dev.get("health") or {}).get("status") == "ok",
          f"{dev['status']}, huella {dev['public_key_fingerprint'][:12]}…, visto {dev.get('last_seen_at')}")
    ck, _ = signed("GET", "/bridge/checkpoints", None)
    check("[Bridge] checkpoints del dispositivo (GET firmado, sin cuerpo)", ck.status_code == 200 and isinstance(data_of(ck), list), short(ck))
    rv = a.raw("POST", f"/bridge/devices/{device_id}/revoke", json={"reason": "Fin de la prueba E2E"})
    r, _ = signed("POST", "/bridge/heartbeat", hb)
    check("[Bridge] revocación inmediata: la siguiente petición del dispositivo → 401 device_revoked", rv.status_code == 200 and r.status_code == 401 and code_of(r) == "device_revoked",
          f"revocar {short(rv)}; latido {short(r)}")


# ───────────────────────────── 7. Intelligence Gateway (W6-D) ─────────────────────────────
def block_gateway() -> None:
    a = S["a"]
    st = a.json("GET", "/assistant/status")["data"]
    tools = st.get("tools") or []
    check("[Gateway] catálogo de herramientas tipadas con habilidad y alcance de datos por herramienta", tools and all(("name" in t or "key" in t) for t in tools),
          f"{len(tools)} herramientas; habilitado {st.get('enabled')} ({st.get('reason')})")
    before = a.json("GET", "/gateway/tool-calls", params={"per_page": 100})
    n0 = (before.get("meta") or {}).get("total", len(before.get("data") or []))
    wa = a.raw("GET", "/gateway/channels/whatsapp")
    check("[Gateway] canal de mensajería sin proveedor: no existe (404 channel_not_available)", wa.status_code == 404 and code_of(wa) == "channel_not_available", short(wa))
    if not st.get("enabled"):
        r = a.raw("POST", "/assistant/messages", json={"message": "¿Qué oportunidades tengo esta semana?"})
        after = a.json("GET", "/gateway/tool-calls", params={"per_page": 100})
        n1 = (after.get("meta") or {}).get("total", len(after.get("data") or []))
        check("[Gateway] sin proveedor el asistente responde 503 assistant_disabled y no ejecuta herramientas (sin filas nuevas de auditoría)",
              r.status_code == 503 and code_of(r) == "assistant_disabled" and n1 == n0, f"{short(r)}; filas {n0} → {n1}")
        warn("[Gateway] asistente desactivado (sin clave de proveedor LLM): no hay llamada a herramienta que auditar en gateway_tool_calls",
             f"motivo {st.get('reason')}; con CONVERSATION_API_KEY configurada el E2E verifica la fila de auditoría de la llamada")
        return
    r = a.raw("POST", "/assistant/messages", json={"message": "Resume mis oportunidades del Radar de esta semana."}, timeout=90)
    after = s2.get_all(a, "/gateway/tool-calls")[0]
    mine = [x for x in after if x.get("user_id") == S["u1"]["id"]]
    check("[Gateway] la conversación ejecuta herramientas por el gateway y cada llamada deja su fila de auditoría (decisión, latencia, argumentos minimizados)",
          r.status_code == 200 and len(after) > n0 and mine and all(x.get("decision") and x.get("latency_ms") is not None for x in mine),
          f"{short(r)}; filas {n0} → {len(after)}; decisiones {sorted({x.get('decision') for x in mine})}")


# ───────────────────────────── 8. Aislamiento y cambio de empresa ─────────────────────────────
def block_isolation() -> None:
    a, b, ids = S["a"], S["b"], S["ids"]
    u2, u3 = S["u2"]["c"], S["u3"]["c"]
    if S["u2"].get("totp"):
        step_up(u2, S["u2"]["totp"], "antes de cambiar de empresa (rutas sensibles como dispositivos y fuentes)")
    sj = mvp.run_import(b, DEMO / "distribuidora_costa_sales.csv", "sales")
    mvp.run_import(b, DEMO / "distribuidora_costa_inventory.csv", "inventory")
    check("[B] importa sus propias ventas e inventario", sj["status"] == "completed", f"{sj['stats'].get('documents_new')} documentos")
    run_b = s2.run_demand(b, 7)
    check("[B] pronóstico propio (versión 1, vigente) con otro tenant_id", run_b["version"] == 1 and run_b["is_current"]
          and (run_b.get("envelope") or {}).get("tenant_id") != S.get("tenant_id_a"), f"ejecución {run_b['id']}")
    rd = S.get("radar") or {}
    probes = [(f"/forecast-runs/{ids.get('run2')}", "ejecución de demanda"), (f"/jobs/{ids.get('demand_job')}", "trabajo de entrenamiento"),
              (f"/models/{ids.get('approved_model')}", "modelo aprobado"), (f"/customer/runs/{ids.get('customer_predict')}", "predicción de compradores"),
              (f"/imports/{ids.get('sales_import')}", "importación CSV"), (f"/data-sources/{ids.get('source_id')}/mappings", "mapeos de la fuente SQL"),
              (f"/syncs/{ids.get('sync_id')}", "sincronización"), (f"/data-quality/quarantine/{ids.get('quarantine_id')}", "registro en cuarentena"),
              (f"/decisions/{ids.get('opportunity')}", "decisión"), (f"/radar/opportunities/{rd.get('o3')}", "oportunidad del Radar"),
              (f"/bridge/devices/{ids.get('bridge_device')}", "dispositivo Bridge"), (f"/customers/{rd.get('c1')}/overview", "ficha de un comprador de A")]
    probes = [(p, w) for p, w in probes if "None" not in p]
    seen_in_a = {p: u2.raw("GET", p, tenant=a.tenant).status_code for p, _ in probes}
    check("[Cambio de empresa] el aprobador, en el contexto de A, sí ve los recursos de A (200: existen y le pertenecen)",
          all(v == 200 for v in seen_in_a.values()), json.dumps({w: seen_in_a[p] for p, w in probes}, ensure_ascii=False))
    for p, what in probes:
        r = u2.raw("GET", p, tenant=S["b_tenant"])
        check(f"[Cambio de empresa] misma sesión con la empresa B activa: no accede a {what} de A", r.status_code in (403, 404), f"GET {p} → {r.status_code}")
    lists = [("/forecast-runs", "ejecuciones"), ("/models", "modelos"), ("/imports", "importaciones"), ("/data-sources", "fuentes"), ("/decisions", "decisiones"),
             ("/radar/opportunities", "oportunidades"), ("/data-quality/quarantine", "cuarentena"), ("/bridge/devices", "dispositivos Bridge"), ("/gateway/tool-calls", "auditoría del gateway")]
    for path, what in lists:
        mine = {x["id"] for x in s2.get_all(a, path)[0]}
        theirs = {x["id"] for x in s2.get_all(b, path)[0]}
        check(f"[Cambio de empresa] el listado de {what} de B no incluye nada de A", not (mine & theirs), f"A {len(mine)} · B {len(theirs)}")
    me2 = {t["uuid"] for t in u2.json("GET", "/auth/me", tenant=None)["data"]["tenants"]}
    me3 = {t["uuid"] for t in u3.json("GET", "/auth/me", tenant=None)["data"]["tenants"]}
    me1 = {t["uuid"] for t in a.json("GET", "/auth/me", tenant=None)["data"]["tenants"]}
    check("[Cambio de empresa] /auth/me: cada persona ve solo sus empresas (aprobador A+B, vendedor A, propietario A+C)",
          me2 == {a.tenant, S["b_tenant"]} and me3 == {a.tenant} and me1 == {a.tenant, S["c_tenant"]}, f"{len(me2)}/{len(me3)}/{len(me1)}")
    r1 = u3.raw("GET", "/dashboard/summary", tenant=S["b_tenant"])
    r2 = a.raw("GET", "/dashboard/summary", tenant=S["b_tenant"])
    r3 = b.raw("GET", "/dashboard/summary", tenant=S["c_tenant"])
    check("[Aislamiento] quien no es miembro no puede usar el X-Tenant-ID de otra empresa (vendedor→B, propietario de A→B, aprobador→C: 404)",
          r1.status_code == 404 and r2.status_code == 404 and r3.status_code in (403, 404), f"{r1.status_code}/{r2.status_code}/{r3.status_code}")
    c = S["c"]
    for path, what in (("/forecast-runs", "ejecuciones"), ("/imports", "importaciones"), ("/data-sources", "fuentes"), ("/decisions", "decisiones")):
        mine = {x["id"] for x in s2.get_all(a, path)[0]}
        theirs = {x["id"] for x in s2.get_all(c, path)[0]}
        check(f"[Cambio de empresa] el propietario cambia de A a C: el listado de {what} de C no incluye nada de A", not (mine & theirs), f"A {len(mine)} · C {len(theirs)}")
    ra = a.json("GET", f"/forecast-runs/{ids.get('run1')}")["data"] if ids.get("run1") else None
    check("[Aislamiento] tras todos los intentos, la empresa A conserva su ejecución vigente y su fuente", ra is None or ra["is_current"], f"vigente {ra and ra['id']}")


# ───────────────────────────── principal ─────────────────────────────
def main() -> int:
    global OPTS
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--api", default="http://127.0.0.1:8000/api/v1")
    ap.add_argument("--engine-url", default="http://127.0.0.1:8100", help="URL del motor (solo para comprobar /health)")
    ap.add_argument("--engine-dir", default=str(ROOT / "engine"), help="directorio del motor (verificación independiente de artefactos y manifiestos)")
    ap.add_argument("--log", help="archivo donde guardar la salida (además de la consola)")
    ap.add_argument("--workdir", default=str(Path(os.environ.get("TMPDIR", "/tmp"))), help="directorio para archivos temporales")
    OPTS = ap.parse_args()
    s2.OPTS = OPTS
    log_fh = None
    if OPTS.log:
        Path(OPTS.log).parent.mkdir(parents=True, exist_ok=True)
        log_fh = open(OPTS.log, "w", encoding="utf-8")
        sys.stdout = s2.Tee(sys.__stdout__, log_fh)
    t_start = time.time()
    print(f"Predictily · aceptación E2E V2.5 · {dt.datetime.now(s2.TZ):%Y-%m-%d %H:%M} (America/Guayaquil) · API {OPTS.api}")
    print("AWS: BLOQUEADO — esta ejecución usa la pila local (sin cuenta AWS del propietario: S3, inferencia y despliegue en AWS no se verifican aquí)")

    with block("0 · Preparación (empresa A por CSV, miembros, empresas B y C)"):
        block_setup()
    if S.get("a") is None or "u2" not in S or "u3" not in S:
        print("No se pudo preparar la empresa A y sus miembros: se aborta.")
    else:
        for name, fn in (("1 · Seguridad Enterprise: MFA, step-up y tokens con alcance (W6-A)", block_security),
                         ("2 · Datos: CSV y fuente SQL, calidad y cuarentena (W2)", block_data),
                         ("3 · Modelos: gates, aprobación separada, firma, inferencia, bloqueo y reversión (W2-C, W4)", block_models),
                         ("4 · Revenue Radar, decisión con workflow y outcome (W5)", block_radar)):
            with block(name):
                fn()
        if S.get("radar"):
            with block("5 · Vendedor: solo su cartera (W6-A)"):
                block_seller()
        else:
            check("[Vendedor] bloque no ejecutado: el Radar no quedó preparado", False)
        for name, fn in (("6 · Predictily Bridge: registro y latido firmado (W6-B)", block_bridge),
                         ("7 · Intelligence Gateway (W6-D)", block_gateway),
                         ("8 · Cambio de empresa sin filtraciones (aislamiento)", block_isolation)):
            with block(name):
                fn()
    warn("AWS: BLOQUEADO", "el E2E corre en la pila local; S3 real, inferencia en AWS y despliegue quedan sin verificar (sin cuenta del propietario)")

    res = s2.RESULTS
    ok = sum(1 for _, s, _ in res if s == "OK")
    fail = [(n, d) for n, s, d in res if s == "FALLA"]
    avisos = [(n, d) for n, s, d in res if s == "AVISO"]
    print("\n══ Tiempos por bloque ══")
    for k, v in s2.TIMES.items():
        print(f"  {v:6.0f} s  {k}")
    print(f"  {time.time() - t_start:6.0f} s  TOTAL")
    print(f"\nResumen: {ok} de {ok + len(fail)} verificaciones OK · {len(fail)} FALLA · {len(avisos)} AVISO")
    for n, d in fail:
        print(f"  [FALLA] {n} — {d}")
    for n, d in avisos:
        print(f"  [AVISO] {n} — {d}")
    if log_fh:
        sys.stdout = sys.__stdout__
        log_fh.close()
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
