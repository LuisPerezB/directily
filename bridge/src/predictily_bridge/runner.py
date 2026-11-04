"""Ciclo del agente: extracción incremental → búfer cifrado → subida por URL prefirmada → checkpoint confirmado por el servidor.

Reglas (ADR-V25-10; las mismas de ``SourceSyncService`` donde aplican):
 - Marca de agua ACOTADA al inicio de la extracción: ``min(máx. fecha de registro legible, inicio)``; una fecha futura (más de 5 minutos
   adelante) no la adelanta; nunca retrocede. Cada corrida relee ``solape`` minutos antes de la marca (cambios en curso).
 - Solo se confirma al servidor un lote que YA está en Bronze, y solo se avanza el estado local tras la respuesta del servidor
   («checkpoints confirmados solo tras el acuse»). Una caída en cualquier punto se repite sin duplicar: el lote es idempotente por SHA-256.
 - Un lote se envía EN ORDEN; si la red cae, la extracción sigue llenando el búfer cifrado (acotado) y se reenvía al volver.
 - Un lote que el servidor rechaza por su esquema (422) se aparta cifrado y la entidad queda «bloqueada» hasta que cambie la plantilla.
"""

from __future__ import annotations

import logging
import random
import sys
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from typing import Any

from predictily_bridge import __version__
from predictily_bridge.adapters.base import SourceAdapter, SourceError
from predictily_bridge.batching import EncodedBatch, encode_batch, to_utc
from predictily_bridge.config import Config, Template
from predictily_bridge.cursor import Cursor, CursorError, decode_value, encode_value
from predictily_bridge.logging_utils import redact
from predictily_bridge.retry import Backoff, RetryExhausted
from predictily_bridge.spool import Spool, SpoolEntry, SpoolFull
from predictily_bridge.state import AgentState, EntityState, iso, parse_iso
from predictily_bridge.transport import DeviceRevoked, ServerAPI, ServerRejected

log = logging.getLogger("predictily_bridge.runner")

#: Tolerancia de la cota de fecha de registro (igual que ``SalesLineNormalizer::recordedAtBound``: 5 minutos).
RECORDED_AT_TOLERANCE = timedelta(minutes=5)
SCHEMA_ERRORS = {"unknown_columns", "missing_required_columns", "batch_invalid", "entity_not_allowed", "validation", "invalid_row", "row_count_mismatch", "batch_quarantined"}
#: Rechazos de una corrida cuyo estado no coincide con el del servidor (hueco, posición ocupada): se descarta la corrida y se vuelve a extraer desde lo confirmado.
RUN_ERRORS = {"run_incomplete", "run_batch_conflict", "run_batches_required", "run_batch_index_mismatch"}
#: El servidor no acepta a ESTE dispositivo ahora mismo: el ciclo entero queda en pausa (nada que extraer ni enviar hasta que se corrija).
PAUSE_CODES = {"device_clock_skew", "device_signature_invalid", "device_unauthorized", "device_replay", "tenant_inactive", "data_source_unavailable"}
DEFAULT_MAX_BATCH_BYTES = 32 * 1024 * 1024


class Offline(Exception):
    """No hay conexión con Predictily tras agotar los reintentos: los lotes quedan en el búfer."""


class BudgetExhausted(Exception):
    """Se alcanzó el máximo de lotes pedido para esta ejecución (interrupción limpia y reanudable)."""


class EntityBlocked(Exception):
    pass


class EntityPaused(Exception):
    pass


@dataclass
class EntityReport:
    entity: str
    status: str = "idle"  # idle | ok | offline | blocked | paused | error | interrupted
    batches_sent: int = 0
    rows_sent: int = 0
    error: str | None = None


@dataclass
class RunReport:
    entities: dict[str, EntityReport] = field(default_factory=dict)
    interrupted: bool = False
    revoked: bool = False

    @property
    def batches_sent(self) -> int:
        return sum(e.batches_sent for e in self.entities.values())

    @property
    def rows_sent(self) -> int:
        return sum(e.rows_sent for e in self.entities.values())


class Agent:
    def __init__(
        self, config: Config, api: ServerAPI, adapter: SourceAdapter, state: AgentState, spool: Spool, *,
        clock: Callable[[], float] = time.time, sleep: Callable[[float], None] = time.sleep, on_revoked: Callable[[], None] | None = None,
        key_backend: str | None = None,
    ) -> None:
        self.config = config
        self.api = api
        self.adapter = adapter
        self.state = state
        self.spool = spool
        self.clock = clock
        self.sleep = sleep
        self.on_revoked = on_revoked
        self.key_backend = key_backend
        self.cycle_backoff = Backoff(base=5.0, cap=300.0)  # espera entre ciclos fallidos de run_forever (retroceso exponencial con jitter)
        self.limits: dict[str, Any] = {"max_batch_bytes": DEFAULT_MAX_BATCH_BYTES}
        self.warnings: list[str] = []
        self.writable_account = False
        self._offline = False
        self._budget: int | None = None
        self._server_checkpoints: dict[str, dict[str, Any]] = {}
        self._last_error: str | None = None
        self._key_warned = False

    # ────────────────────────────── API pública ──────────────────────────────
    def now(self) -> datetime:
        return datetime.fromtimestamp(self.clock(), UTC)

    def heartbeat(self) -> bool:
        """Latido (best effort): actualiza ``last_seen_at`` en el servidor y aprende sus límites. False si no hay conexión."""
        try:
            response = self.api.heartbeat(self.health_payload())
        except RetryExhausted:
            return False
        except DeviceRevoked:
            self._revoked()
            raise
        except ServerRejected as e:
            if e.status == 422:
                # La salud informada es informativa: si el servidor la rechaza (validación) no se detiene la extracción.
                log.warning("El servidor rechazó la salud informada en el latido (%s): se continúa.", e.code)
                return True
            raise   # 401/403/423…: run_once lo convierte en pausa legible
        self.limits.update(response.get("limits") or {})
        return True

    def describe_rejection(self, e: ServerRejected) -> str:
        """Mensaje legible de un rechazo del servidor (sin secretos). La deriva de reloj se informa con su magnitud."""
        if e.code == "device_clock_skew" and isinstance(e.context.get("server_time"), (int, float)):
            skew = int(self.clock() - float(e.context["server_time"]))
            return f"El reloj de este equipo difiere unos {abs(skew)} s del servidor (máximo permitido: 300 s). Sincronice la hora del equipo (NTP); el agente no envía hasta entonces."
        return f"{e.code}: {e.message}"[:300]

    def health_payload(self) -> dict[str, Any]:
        entities = []
        for name in self.config.entities():
            es = self.state.entity(name)
            entities.append({k: v for k, v in {
                "entity": name, "status": "blocked" if es.blocked else ("pending" if es.run else "idle"), "watermark": es.watermark,
                "rows_sent": es.rows_sent, "last_error": (es.last_error or "")[:300] or None,
            }.items() if v is not None})
        health: dict[str, Any] = {
            "status": "blocked" if any(self.state.entity(n).blocked for n in self.config.entities()) else ("degraded" if self._last_error else "ok"),
            "pending_batches": self.spool.pending(), "spool_bytes": self.spool.total_bytes(), "entities": entities,
            "templates": [{"entity": t.entity, "id": t.id, "version": t.version, "sha256": t.fingerprint()} for t in self.config.templates if t is self.config.template_for(t.entity)],
            "source": {"kind": self.adapter.kind, "read_only_enforcement": self.adapter.read_only_enforcement[:80], "writable_account": self.writable_account},
        }
        if self._last_error:
            health["last_error"] = self._last_error[:300]
        successes = [self.state.entity(n).last_success_at for n in self.config.entities() if self.state.entity(n).last_success_at]
        if successes:
            health["last_success_at"] = max(successes)
        if self.warnings:
            health["warnings"] = [w[:300] for w in self.warnings[:10]]
        return {"agent_version": __version__, "platform": _platform(), "health": health}

    def run_once(self, max_batches: int | None = None) -> RunReport:
        """Un ciclo completo sobre todas las entidades configuradas. ``max_batches``: interrumpe de forma limpia tras N lotes confirmados."""
        report = RunReport()
        self._budget = max_batches
        self._offline = False
        try:
            self._check_source()
            if self.heartbeat():
                self._load_checkpoints()
            else:
                self._offline = True
        except SourceError as e:
            self._last_error = str(e)
            for name in self.config.entities():
                report.entities[name] = EntityReport(name, "error", error=str(e))
            self.adapter.close()
            return report
        except RetryExhausted:
            self._offline = True
        except DeviceRevoked:
            report.revoked = True
            return report
        except ServerRejected as e:
            # El servidor no acepta a este dispositivo ahora (reloj, firma, empresa suspendida, fuente eliminada…): ciclo en pausa, informado, sin caer.
            message = self.describe_rejection(e)
            self._last_error = message
            log.error("Ciclo en pausa: %s", redact(message))
            for name in self.config.entities():
                report.entities[name] = EntityReport(name, "paused", error=message)
            self.adapter.close()
            return report
        for name in self.config.entities():
            entity_report = report.entities.setdefault(name, EntityReport(name))
            try:
                self._sync_entity(name, entity_report)
                entity_report.status = "offline" if self.spool.pending(name) else "ok"
            except BudgetExhausted:
                entity_report.status = "interrupted"
                report.interrupted = True
                break
            except Offline:
                entity_report.status = "offline"
                self._offline = True
            except EntityBlocked as e:
                entity_report.status, entity_report.error = "blocked", str(e)
            except EntityPaused as e:
                entity_report.status, entity_report.error = "paused", str(e)
            except DeviceRevoked:
                report.revoked = True
                break
            except (SourceError, CursorError) as e:
                self.state.entity(name).last_error = str(e)
                entity_report.status, entity_report.error = "error", str(e)
                self._last_error = str(e)
                log.error("Entidad %s: error de lectura del origen: %s", name, e)
            except ServerRejected as e:
                self.state.entity(name).last_error = f"{e.code}: {e.message}"
                entity_report.status, entity_report.error = "error", f"{e.code}: {e.message}"
                self._last_error = entity_report.error
            except Exception as e:  # noqa: BLE001 - un defecto inesperado en una entidad no derriba al agente ni a las demás entidades
                text = f"Error inesperado ({type(e).__name__}); revise el registro del agente."
                log.error("Entidad %s: %s", name, redact(f"{type(e).__name__}: {e}")[:300])
                self.state.entity(name).last_error = text
                entity_report.status, entity_report.error = "error", text
                self._last_error = text
            finally:
                self.state.save()
        self.adapter.close()
        return report

    def run_forever(self, stop: Callable[[], bool], *, interval: int | None = None) -> None:
        """Bucle de servicio. Cada ciclo va protegido: una excepción inesperada se registra (redactada), se espera con retroceso exponencial y jitter y el bucle sigue;
        solo una revocación o la señal de parada lo termina (revisión adversarial B-2)."""
        interval = interval or self.config.agent.interval_seconds
        rng = random.Random()
        failures = 0
        while not stop():
            try:
                report = self.run_once(self.config.agent.max_batches_per_cycle or None)
                failures = 0
            except DeviceRevoked:
                return
            except Exception as e:  # noqa: BLE001
                failures += 1
                self._last_error = f"Ciclo fallido ({type(e).__name__})"
                log.error("Ciclo del agente fallido (%s): %s", type(e).__name__, redact(str(e))[:300])
                self._wait(self.cycle_backoff.delay(min(failures, 10)), stop)
                continue
            if report.revoked:
                return
            deadline = self.clock() + interval * (0.9 + 0.2 * rng.random())  # jitter ±10 %: los agentes no se sincronizan
            next_heartbeat = self.clock() + self.config.agent.heartbeat_seconds
            while not stop() and self.clock() < deadline:
                self.sleep(1.0)
                if self.clock() >= next_heartbeat:
                    try:
                        self.heartbeat()
                    except DeviceRevoked:
                        return
                    except Exception as e:  # noqa: BLE001 - el latido es informativo: nunca derriba el bucle
                        log.warning("Latido fallido (%s)", type(e).__name__)
                    next_heartbeat = self.clock() + self.config.agent.heartbeat_seconds

    def _wait(self, seconds: float, stop: Callable[[], bool]) -> None:
        end = self.clock() + seconds
        while not stop() and self.clock() < end:
            self.sleep(1.0)

    def _note_key_backend(self) -> None:
        """B-13: sin almacén de claves del sistema, la clave maestra está en un archivo (master.key): se advierte en el registro y en la salud."""
        if self.key_backend == "file":
            warning = ("La clave maestra de cifrado local está en un archivo (master.key, 0600) y no en el almacén de claves del sistema: protege contra copias y otros usuarios, "
                       "no contra quien controle esta cuenta. Instale el extra «keyring» o defina PREDICTILY_BRIDGE_MASTER_KEY.")
            if warning not in self.warnings:
                self.warnings.append(warning)
            if not self._key_warned:
                self._key_warned = True
                log.warning(warning)

    # ────────────────────────────── por entidad ──────────────────────────────
    def _check_source(self) -> None:
        info = self.adapter.describe()
        self.warnings = list(info.get("warnings") or [])
        self.writable_account = bool(info.get("writable_account"))
        self._note_key_backend()
        if self.writable_account and self.config.require_readonly_account:
            raise SourceError("La cuenta del origen tiene privilegios de escritura y require_readonly_account está activo (por defecto en MySQL y SQL Server): el agente no extrae. Use una cuenta de solo lectura.")

    def _load_checkpoints(self) -> None:
        try:
            self._server_checkpoints = self.api.checkpoints()
        except RetryExhausted:
            self._server_checkpoints = {}
            raise
        except DeviceRevoked:
            self._revoked()
            raise

    def _sync_entity(self, entity: str, report: EntityReport) -> None:
        template = self.config.template_for(entity)
        assert template is not None
        es = self.state.entity(entity)
        fp = template.fingerprint()
        if es.blocked and es.blocked.get("template_fp") == fp:
            raise EntityBlocked(f"Bloqueada ({es.blocked.get('code')}): corrija la plantilla {template.id} v{template.version} para reanudar.")
        if es.template_fp != fp:
            es.blocked = None
            if es.run is not None:
                log.info("Entidad %s: la plantilla cambió; se descarta la corrida en curso (el búfer pendiente se conserva).", entity)
                es.run = None
            es.template_fp = fp
        es.last_error = None
        if not self._offline:
            self._reconcile(entity, es, template)
        had_run = es.run is not None
        self._drain(entity, es, report)
        if had_run and es.run is None:
            return  # el último lote pendiente cerró la corrida anterior: la siguiente empieza en el próximo ciclo
        if es.run is None:
            self._start_run(entity, es, template)
        while es.run is not None:
            run = es.run
            if run.get("closing"):
                if not self._offline:
                    self._ship_closing(entity, es, run, report)
                return
            if run["extraction_complete"]:
                return  # lo pendiente (último lote) se enviará en el próximo ciclo
            self._extract_page(entity, es, template, run, report)

    def _reconcile(self, entity: str, es: EntityState, template: Template) -> None:
        """El servidor es la verdad de lo confirmado: si el estado local se perdió, reanuda desde su cursor; si un administrador reinició
        la entidad (sin marca de agua en el servidor), la carga vuelve a empezar completa."""
        server = self._server_checkpoints.get(entity)
        server_wm = server.get("watermark") if server else None
        if es.watermark is not None and server_wm is None and server is None:
            log.info("Entidad %s: el servidor no tiene checkpoint (reinicio solicitado): nueva carga completa.", entity)
            es.watermark, es.run, es.files_done = None, None, {}
            return
        if server_wm is not None and (es.watermark is None or parse_iso(server_wm) > parse_iso(es.watermark)):
            es.watermark = iso(parse_iso(server_wm))
        if es.run is None and server and server.get("checkpoint"):
            try:
                cursor = Cursor.decode(server["checkpoint"])
            except CursorError:
                return
            arity = 2 if template.kind in ("csv", "excel") else len(template.order_by)
            if cursor.run_id in es.abandoned:
                return   # el servidor aún guarda el cursor de una corrida que este agente descartó: se empieza una nueva (su primer checkpoint lo reemplaza)
            if cursor.template_fp != template.fingerprint()[:8] or (cursor.after is not None and len(cursor.after) != arity):
                log.warning("Entidad %s: el cursor del servidor es de otra plantilla; se ignora y la carga empieza de nuevo.", entity)
                return
            es.run = {
                "id": cursor.run_id, "mode": cursor.mode, "started_at": cursor.started_at, "since": cursor.since,
                "extract_after": [encode_value(v) for v in cursor.after] if cursor.after is not None else None,
                "committed_after": [encode_value(v) for v in cursor.after] if cursor.after is not None else None,
                "prev_watermark": es.watermark, "max_recorded": None, "future_recorded": 0, "rows": 0, "batches": cursor.batches, "extraction_complete": False, "closing": None,
                "context": self.adapter.start_run(template, es.files_done or None),
            }
            log.info("Entidad %s: se reanuda la corrida %s desde el cursor confirmado por el servidor.", entity, cursor.run_id)

    def _start_run(self, entity: str, es: EntityState, template: Template) -> None:
        started = int(self.clock())
        since = None
        if es.watermark is not None and template.kind not in ("csv", "excel"):
            since = int((parse_iso(es.watermark) - timedelta(minutes=self.config.agent.overlap_minutes)).timestamp())
        mode = "incremental" if es.watermark is not None else "full"
        run_id = f"{started:x}{random.getrandbits(24):06x}"
        es.run = {
            "id": run_id, "mode": mode, "started_at": started, "since": since, "extract_after": None, "committed_after": None, "prev_watermark": es.watermark,
            "max_recorded": None, "future_recorded": 0, "rows": 0, "batches": 0, "extraction_complete": False, "closing": None,
            "context": self.adapter.start_run(template, es.files_done or None),
        }
        self.state.save()

    def _extract_page(self, entity: str, es: EntityState, template: Template, run: dict[str, Any], report: EntityReport) -> None:
        limit = self.config.agent.batch_rows
        max_bytes = int(self.limits.get("max_batch_bytes") or DEFAULT_MAX_BATCH_BYTES)
        after = tuple(decode_value(v) for v in run["extract_after"]) if run["extract_after"] is not None else None
        since = datetime.fromtimestamp(run["since"], UTC).astimezone(_zone(self.config.agent.source_timezone)) if run["since"] is not None else None
        while True:
            page = self.adapter.fetch_page(template, since=since, after=after, limit=limit, context=run["context"])
            if not page.rows:
                break
            batch = encode_batch(page.rows)
            if batch.size_bytes > max_bytes and len(page.rows) > 1:
                limit = max(1, len(page.rows) // 2)  # lote demasiado grande para el servidor: se parte
                continue
            if batch.size_bytes > max_bytes:
                raise SourceError("Una sola fila supera el tamaño máximo de lote del servidor.")
            break
        if not page.rows:
            # Sin filas en esta página: si ya había lotes, se cierra la corrida con una confirmación sin lote.
            run["extraction_complete"] = True
            run["closing"] = {"watermark": self._final_watermark(es, run, template)}
            self.state.save()
            if not self._offline:
                self._ship_closing(entity, es, run, report)
            return
        self._track_recorded(template, run, page.rows)
        final = page.exhausted
        after_encoded = [encode_value(v) for v in page.cursor_after or ()]
        index = run["batches"] + 1  # posición del lote dentro de la corrida (1…N): el servidor comprueba que sean contiguos antes de mover la marca de agua
        meta = {
            "entity": entity, "row_count": batch.row_count, "columns": batch.columns, "run_id": run["id"], "run_mode": run["mode"], "seq": es.next_seq, "run_batch_index": index,
            "final": final, "after": after_encoded, "template_version": template.version, "template_fp": template.fingerprint()[:8],
            "run_started_at": run["started_at"], "run_since": run["since"],
            "watermark": self._final_watermark(es, run, template) if final else None,
        }
        try:
            entry = self.spool.put(entity, es.next_seq, batch.sha256, meta, batch.content)
        except SpoolFull as e:
            log.warning("Entidad %s: %s", entity, e)
            raise Offline(str(e)) from None
        es.next_seq = max(es.next_seq, entry.seq + 1)
        run["extract_after"] = after_encoded
        run["rows"] += batch.row_count
        run["batches"] = index
        if final:
            run["extraction_complete"] = True
        self.state.save()
        if not self._offline:
            try:
                self._drain(entity, es, report)
            except Offline:
                self._offline = True  # sigue extrayendo hacia el búfer hasta llenarlo; se reenvía al volver la red

    def _track_recorded(self, template: Template, run: dict[str, Any], rows: list[dict[str, Any]]) -> None:
        if not template.recorded_at_column:
            return
        bound = datetime.fromtimestamp(run["started_at"], UTC) + RECORDED_AT_TOLERANCE
        best = datetime.fromtimestamp(run["max_recorded"], UTC) if run["max_recorded"] is not None else None
        for row in rows:
            recorded = to_utc(row.get(template.recorded_at_column), self.config.agent.source_timezone)
            if recorded is None:
                continue
            if recorded > bound:
                run["future_recorded"] += 1  # anomalía: no adelanta la marca de agua
                continue
            if best is None or recorded > best:
                best = recorded
        run["max_recorded"] = int(best.timestamp()) if best is not None else None

    def _final_watermark(self, es: EntityState, run: dict[str, Any], template: Template) -> str:
        """``min(máx. fecha de registro legible, inicio)``; sin filas con fecha, la marca previa; nunca retrocede."""
        started = datetime.fromtimestamp(run["started_at"], UTC)
        previous = parse_iso(run["prev_watermark"]) if run["prev_watermark"] else None
        if not template.recorded_at_column:
            candidate = started
        else:
            recorded = datetime.fromtimestamp(run["max_recorded"], UTC) if run["max_recorded"] is not None else None
            candidate = min(recorded or previous or started, started)
        if previous is not None and candidate < previous:
            candidate = previous
        return iso(candidate)

    # ────────────────────────────── envío ──────────────────────────────
    def _drain(self, entity: str, es: EntityState, report: EntityReport) -> None:
        """Envía en orden los lotes pendientes del búfer. Lanza ``Offline`` si no hay conexión."""
        for entry in self.spool.list(entity):
            if self._offline:
                raise Offline("Sin conexión con Predictily: los lotes quedan en el búfer cifrado.")
            if self._budget is not None and self._budget <= 0:
                raise BudgetExhausted
            meta, payload = self.spool.read(entry)
            try:
                self._ship(entity, es, entry, meta, payload)
            except RetryExhausted:
                self._offline = True
                raise Offline("Sin conexión con Predictily.") from None
            except DeviceRevoked:
                self._revoked()
                raise
            except ServerRejected as e:
                self._handle_rejection(entity, es, entry, e)
            report.batches_sent += 1
            report.rows_sent += int(meta["row_count"])
            es.rows_sent += int(meta["row_count"])
            es.last_success_at = iso(self.now())
            if self._budget is not None:
                self._budget -= 1
            self.state.save()

    def _ship(self, entity: str, es: EntityState, entry: SpoolEntry, meta: dict[str, Any], payload: bytes) -> None:
        sha = str(meta["sha256"])
        last: ServerRejected | None = None
        for attempt in range(2):
            declaration = self.api.declare_upload({
                "entity": entity, "sha256": sha, "size_bytes": len(payload), "row_count": meta["row_count"], "content_type": "application/gzip", "columns": meta["columns"],
                "source_timezone": self.config.agent.source_timezone, "run_id": meta["run_id"], "seq": meta["seq"], "run_mode": meta["run_mode"],
                "template_version": meta["template_version"],
            })
            try:
                if declaration["status"] == "upload":
                    self.api.put_batch(declaration["path"], payload)
                else:
                    self._act_on_duplicate(entity, es, declaration)
                self._commit(entity, es, meta, sha)
            except ServerRejected as e:
                # URL vencida o inválida (403) o lote desconocido para el servidor (p. ej. purgado): se declara de nuevo UNA vez.
                if attempt == 0 and (e.status == 403 or e.code == "batch_not_found"):
                    last = e
                    continue
                raise
            self.spool.delete(entry)
            return
        assert last is not None
        raise last

    def _act_on_duplicate(self, entity: str, es: EntityState, declaration: dict[str, Any]) -> None:
        """El servidor ya tenía el lote. Su estado de procesamiento importa: `needs_review` se informa (hay filas con error esperando a una persona); `failed` se informa y el
        servidor lo vuelve a encolar —si aún no se corrigió la causa, rechazará el checkpoint (`batch_failed`) y el agente espera al siguiente ciclo."""
        processing = str(declaration.get("processing") or "")
        if processing == "needs_review":
            note = f"Un lote de «{entity}» ya enviado necesita revisión en Predictily (filas con error)."
            if note not in self.warnings:
                self.warnings.append(note)
        elif processing == "failed":
            log.warning("Entidad %s: el servidor tiene el lote pero su procesamiento falló; se volvió a encolar.", entity)
            es.last_error = "Un lote ya enviado falló al procesarse en Predictily; se reintenta."

    def _commit(self, entity: str, es: EntityState, meta: dict[str, Any], sha: str) -> None:
        run = es.run
        current = run is not None and run["id"] == meta["run_id"]
        final = bool(meta["final"])
        if not final and not current:
            return  # lote de una corrida descartada (la plantilla cambió): ya está en Bronze; no se mueve ningún cursor
        index = int(meta["run_batch_index"])
        body: dict[str, Any] = {"entity": entity, "run_id": meta["run_id"], "batch_sha256": sha, "run_batch_index": index, "run_complete": final}
        if final:
            body["watermark"] = meta["watermark"]
            body["run_batches"] = index  # el último lote ocupa la posición N: la corrida tiene N lotes
        else:
            body["checkpoint"] = Cursor(meta["run_id"], meta["run_mode"], int(meta["run_started_at"]), meta["run_since"], tuple(decode_value(v) for v in meta["after"]),
                                        str(meta.get("template_fp", "")), index).encode()
        self.api.commit(body)  # el servidor acusó recibo: recién ahora avanza el estado local
        if current:
            assert run is not None
            run["committed_after"] = meta["after"]
            if final:
                self._finish_run(es, run, meta["watermark"])

    def _ship_closing(self, entity: str, es: EntityState, run: dict[str, Any], report: EntityReport) -> None:
        closing = run["closing"]
        if closing is None or self.spool.pending(entity):
            return
        try:
            # Sin lote que citar: la prueba es el libro de lotes confirmados de la corrida (el servidor comprueba que sean 1…N).
            self.api.commit({"entity": entity, "run_id": run["id"], "run_complete": True, "run_batches": int(run["batches"]), "watermark": closing["watermark"]})
        except RetryExhausted:
            self._offline = True
            return
        except DeviceRevoked:
            self._revoked()
            raise
        self._finish_run(es, run, closing["watermark"])
        es.last_success_at = iso(self.now())
        self.state.save()

    def _finish_run(self, es: EntityState, run: dict[str, Any], watermark: str) -> None:
        es.watermark = watermark
        for entry in (run.get("context") or {}).get("files", []):
            es.files_done[entry["name"]] = entry["sha256"]
        if run.get("future_recorded"):
            self.warnings.append(f"{run['future_recorded']} filas traen una fecha de registro futura: no adelantan la marca de agua.")
        es.run = None

    def _handle_rejection(self, entity: str, es: EntityState, entry: SpoolEntry, e: ServerRejected) -> None:
        if e.code in SCHEMA_ERRORS or (e.status == 422 and e.code not in ("device_clock_skew",)):
            self.spool.quarantine(entry)
            template = self.config.template_for(entity)
            es.blocked = {"code": e.code, "message": e.message[:300], "template_fp": template.fingerprint() if template else None, "at": iso(self.now())}
            es.run = None
            es.last_error = f"{e.code}: {e.message}"[:300]
            self._last_error = es.last_error
            raise EntityBlocked(f"El servidor rechazó el lote ({e.code}): {e.message}") from None
        if e.code in RUN_ERRORS:
            # El libro del servidor no coincide con el de este agente: se descarta la corrida y se vuelve a extraer desde lo confirmado (los lotes son idempotentes).
            dead = str(es.run["id"]) if es.run is not None else None
            if dead is not None:
                es.abandoned = [*es.abandoned, dead][-5:]
                # Los lotes pendientes de la corrida muerta se descartan: la nueva corrida los volverá a extraer idénticos (mismo SHA-256, idempotentes en Bronze).
                for pending in self.spool.list(entity):
                    if self.spool.read(pending)[0].get("run_id") == dead:
                        self.spool.delete(pending)
            es.run = None
            es.last_error = f"{e.code}: {e.message}"[:300]
            raise EntityPaused(f"La corrida no coincide con el servidor ({e.code}); se reinicia desde la última marca de agua confirmada.") from None
        if e.code in ("source_inactive", "tenant_inactive", "data_source_unavailable"):
            raise EntityPaused(f"{e.code}: {e.message}") from None
        if e.code == "batch_failed":
            raise EntityPaused("El servidor no pudo procesar un lote (batch_failed): el agente lo conserva y lo reintenta en el siguiente ciclo.") from None
        raise e

    def _revoked(self) -> None:
        log.error("El dispositivo fue REVOCADO por la empresa: se detiene el envío y se borra el búfer local.")
        self.spool.purge_all()
        if self.on_revoked:
            self.on_revoked()


def _zone(name: str | None):  # type: ignore[no-untyped-def]
    from zoneinfo import ZoneInfo

    return ZoneInfo(name) if name else UTC


def _platform() -> str:
    if sys.platform.startswith("win"):
        return "windows"
    if sys.platform == "darwin":
        return "macos"
    if sys.platform.startswith("linux"):
        return "linux"
    return "other"
