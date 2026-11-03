# Predictily V2.5 · Estado actual, pendientes y próximos pasos

**Corte de este informe:** 1-nov-2026, 14:30 UTC (cierre de W1 a las 05:45 UTC y de W2 a las 14:15 UTC) · **Rama de trabajo:** `v25/w1-cierre-y-w2` (creada desde `main`
@ `6c679d8`) · **PR:** se abre al terminar la sesión de trabajo (una rama, commits por paquete).
**Especificación en la que se basa el trabajo:**
[`docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md`](docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md)
(V2.5, en adelante «la especificación»). Plan detallado:
[`docs/roadmap/V25_IMPLEMENTATION_PLAN.md`](docs/roadmap/V25_IMPLEMENTATION_PLAN.md).

> **En una línea:** **W1 y W2 están cerrados**: cada paquete pasó revisión adversarial y corrección, las suites
> completas están en verde (backend 1082 + 8 omitidas, motor 780, frontend 329) y los E2E dan 32/32 y 256/256. La demo
> no tiene regresión y sus gates de calidad dan PASS. **W3 (Calidad ML) está en curso**: contrato activado y paquetes A, B
> y C en implementación. Los contratos de W4 a W6 están listos. El piloto con datos reales tiene acceso por VPN y espera
> a que el propietario registre la fuente SQL en la app local. Ningún gate está en GO.
---

## 1. Dónde se quedó el trabajo (instancia exacta)

### 1.1 Orden de trabajo de la especificación (§23)

| Paso §23 | Qué pide | Estado |
|---|---|---|
| 1 | Inventario de requisitos por versión y matriz de fuentes no recuperadas | ✅ Hecho |
| 2 | Auditar, ejecutar pruebas, fotografiar métricas de línea base (incluida la política heredada) y clasificar defectos | ✅ Hecho |
| 3 | Priorizar datos / as-of / fugas / idempotencia / aislamiento antes que tableros o LLM | ✅ **W1 cerrado** (1-nov-2026): 7 de los 13 defectos HIGH corregidos y 6 corregidos parcialmente; lo pendiente de cada uno está en `docs/roadmap/audit/progress/2026-11-01-w1.json` |
| 4 | Implementar por paquetes verticales con migración aditiva, pruebas y registros | ✅ W2 cerrado (1-nov-2026) · 🔄 **W3 en curso** (contrato activado en `4eb34f4`) · W4–W6: contratos listos (`docs/roadmap/contracts/w4..w6/`) |
| 5 | E2E en staging autorizado con 2 tenants, fuente SQL y CSV, entrenamiento validado, Registry/S3, inferencia AWS, Radar/Decisión/Outcome | ⏳ Pendiente; staging AWS **BLOQUEADO** |
| 6 | Métricas reales sobre holdout y evaluación prospectiva | 🟡 **Acceso disponible** (SQL Server de Soyoda/Resgasa por VPN); falta que el propietario registre la fuente en la app local |
| 7 | `V25_RELEASE_GATES.md` con GO / GO CONDICIONAL / NO-GO y changelog de lo hecho y lo pendiente | 🟡 Actualizado con el cierre de W1 (todo NO-GO); este documento es el changelog vigente |

### 1.2 Cierre de W1 (correcciones P0)

Cada paquete pasó por **revisión adversarial → corrección → verificación del arquitecto** con subagentes. Las suites
completas se corrieron en un Mac con los `.env` de desarrollo presentes; así se prueba que no dependen de la
configuración local.

| Paquete | Revisión adversarial | Qué se corrigió | Commit |
|---|---|---|---|
| **W1-A1 · Escritura temporal de ventas** | 1 HIGH, 1 MEDIUM, 7 LOW | **Doble conteo as-of**: una re-carga `backfill` después de revisiones incrementales dejaba dos versiones visibles del mismo documento (144 de 336 secuencias de escritura). La re-carga colapsa ahora toda la cadena archivada y `SalesVersionWindowsInvariantTest` fija la invariante de 0 o 1 versión visible en las 336 secuencias, en Guayaquil y en Madrid. También: cota única de `source_recorded_at`, `unchanged_mode_mismatch`, marca de origen conservada y aviso `document_number_shared_with_unknown_type`. Por decisión del arquitecto, la factura válida se escribe completa aunque otra fila de tipo desconocido comparta su número, porque los ERP numeran por tipo o serie | `eea5321` |
| **W1-A2 · Lectura as-of en el motor** | 3 MEDIUM, 6 LOW (núcleo correcto, verificado contra una implementación independiente en 8 zonas) | ejecución vigente con corte limitado por la cobertura (no perdía la carga nocturna); huella `data_snapshot` con huecos, cierres y clasificación de días; evaluación realizada y deriva sin contar huecos como ceros; propagación de nivel en huecos largos; pruebas de BD con horario de verano | `c44076e` |
| **W1-B · Seguridad** | 2 HIGH, 3 MEDIUM, 4 LOW (firma y SSRF sin evasión) | **ZIP bomb**: el inspector cuenta los bytes reales (un directorio falsificado anulaba los límites: de 62–73 s a 2 ms) y resuelve las partes por relación (cadenas compartidas renombradas); ventana interactiva acotada en tiempo y bytes; **hermeticidad** de las suites frente a los `.env` de desarrollo; un nombre de host listado ya no autoriza por sí solo redes privadas; `run_id` ligado a la firma | `11c7086` |
| Integración (arquitecto) | — | sesión PostgreSQL en UTC (en un servidor con otra zona, las marcas de tiempo se guardaban desplazadas: 5 h en este Mac); prueba de conexión independiente de `trust`; **intervalo de cantidad de Customer** que dejaba el punto fuera tras el ajuste de coherencia (defecto de la etapa 2 que detectó el E2E); progreso por oleada en el generador de la hoja de ruta | `d13e44f`, `6150f00` y commit de integración |

**Resultados de la integración** (evidencia en `docs/roadmap/evidence/2026-11-01-w1-integration/`):

| Verificación | Línea base (78c5029, Linux) | Antes de fusionar W1 parcial (1d3b876) | **Cierre de W1** (macOS) |
|---|---|---|---|
| Backend (`php artisan test`) | 449 aprobadas, 2 omitidas | 696 aprobadas, 2 omitidas | **736 aprobadas, 3 omitidas** (739); las omitidas requieren servidores MySQL y SQL Server de prueba |
| Motor (`pytest`) | 418 | 524 | **550 aprobadas** (+1 de la corrección de Customer) |
| Frontend (type-check, lint, vitest, build) | 313 | 313 | **313**, build correcto |
| E2E `mvp_acceptance.py` | 32/32 | no ejecutado | **32/32** |
| E2E `stage2_acceptance.py` | 256/256 (1 aviso) | no ejecutado | **256/256** (el mismo aviso de diseño) |

**Métricas de la demo (datos ficticios)** comparadas con la línea base (`demo_metrics.json`):

| Medición | Línea base (Linux) | W1, regla de huecos inferidos apagada | W1 por defecto (regla encendida) |
|---|---|---|---|
| Ferretería Andina · WAPE seleccionado / Seasonal Naive / cobertura 80 % | 0,4928 / 0,6631 / 0,852 | 0,4934 / **0,6631** / 0,845 (15 458 obs., igual que la línea base) | 0,4908 / 0,6604 / 0,850 (15 400 obs.) |
| Distribuidora Costa · ídem | 0,4519 / 0,6020 / 0,801 | — | 0,4488 / 0,5983 / 0,803 |
| Paridad en el mismo equipo (memoria, sintético) | pre-W1: 0,4931 / 0,6631 / 0,850 | **idéntico** al pre-W1 en las dos empresas | 0,4910 / 0,6604 / 0,848 |
| Customer Core (Ferretería) | 7 y 30 días: referencia de popularidad; 90 días: logística, Brier 0,1383 | — | igual: 7 y 30 días con la referencia; 90 días logística con Brier 0,1383 |

Cómo leer la tabla: la lectura as-of y la clasificación de días **no cambian ningún número** cuando la regla
inferida está apagada. La diferencia de 0,0003 a 0,0006 frente a la línea base Linux es de plataforma (LightGBM en
arm64 frente a x86). Con la regla encendida, el cambio viene solo de excluir 18 sucursal-días (Ferretería) y
7 (Distribuidora) que la regla marca como `missing_source`. La regla debe aprobarse en el piloto, porque un cierre no
declarado se confunde con una caída de la fuente. La demo se carga como `backfill`, así que no ejercita versiones
tardías; eso lo cubren las pruebas T02 y lo cubrirá el piloto.

**Auditoría** (`docs/roadmap/audit/progress/2026-11-01-w1.json`; la auditoría base no se modifica):

- **Defectos HIGH.** Corregidos: cliente #1, conectores #2, datos #1, #2 y #4, demanda #1 y #2. Corregidos
  parcialmente:
  - marca de agua: falta la alerta por extracciones vacías;
  - SSRF (dos defectos): falta la lista blanca obligatoria en producción;
  - tipos y estados desconocidos: falta la cuarentena (W2-A);
  - pickle: falta un formato sin pickle o un subproceso sin credenciales;
  - ZIP: el análisis sigue dentro de la petición, acotado a 10 s.
- **Requisitos que cambiaron.** Pasan a IMPLEMENTADO: V25-AVL-010, V25-AVL-013, V25-BRG-057, V25-SEC-033,
  V25-REG-045 y V25-CUS-038. Pasan de PENDIENTE a PARCIAL: V25-REG-051, V25-REG-073 y V25-REG-028.

---

## 2. Qué está hecho (verificado)

| Entregable | Ubicación | Notas |
|---|---|---|
| Fuentes versionadas | `docs/roadmap/sources/` | V2.5 literal; **V1 recuperado literal**; V2 literal hasta §10. V2.1, V2.2, V2.3, V2.4 y V2.4 Enterprise **no se hallaron** (`RECONSTRUIDO_PARCIALMENTE`) |
| Auditoría por área con verificación adversarial | `docs/roadmap/audit/2026-10-31/*.json` | 8 áreas, **841 requisitos**, 179 defectos (13 HIGH, 73 MEDIUM, 93 LOW) |
| Progreso por oleada | `docs/roadmap/audit/progress/` | W1: 20 defectos y 9 requisitos con evidencia `archivo::prueba`; el generador lo valida y lo muestra |
| Línea base | `docs/roadmap/evidence/2026-10-31-baseline/`, `baseline_metrics.json` | Motor 418 · backend 449 · frontend 313 · E2E 32/32 y 256/256 |
| **W1 cerrado** | commits `11c7086`, `c44076e`, `eea5321` | ventas versionadas con tiempo de conocimiento (ADR-V25-23), lectura as-of del motor y clasificación de días (ADR-V25-03), SSRF, ZIP y firma Ed25519 (ADR-V25-20); evidencia en `docs/roadmap/evidence/2026-11-01-w1-integration/` |
| Decisiones de arquitectura | `docs/architecture/adr/V25-ADRS.md` | ADR-V25-01 a ADR-V25-25 |
| Contrato de W2 (**activado**) | `backend/database/migrations/2026_03_03_000100_*`, `2026_03_04_000100_*`, `backend/app/Services/DataCore/Bronze/`, `backend/app/Support/Canonical/`, `docs/data/DATA_QUALITY_ENGINE.md` | migraciones probadas (subida, reversión y subida), `BronzeStore` registrado, tablas nuevas clasificadas en la exportación LOPDP; suite del backend en verde |
| Herramienta de descubrimiento del piloto | `infra/scripts/pilot/sql_source_discovery.php` | SELECT fijos sobre el catálogo de SQL Server (tablas, columnas, filas estimadas, claves), sin leer filas de negocio; salida a `reports/private/` |
| Plan, gates y documentos de hoja de ruta | `docs/roadmap/` | regenerables con `python3 infra/scripts/v25_roadmap_docs.py` (`--check` verifica) |
| Matriz de pruebas T01–T17 | `docs/testing/V25_TEST_MATRIX.md` | 193 casos (actualización de estados pendiente con W2) |

### Estado de los requisitos de la especificación

Base 2026-10-31 más el progreso de W1 (detalle por requisito en
[`docs/roadmap/V25_GAP_ANALYSIS.md`](docs/roadmap/V25_GAP_ANALYSIS.md) §2.5 y
[`docs/roadmap/V25_SOURCE_TRACEABILITY.md`](docs/roadmap/V25_SOURCE_TRACEABILITY.md)): 6 requisitos más IMPLEMENTADOS
y 3 que pasan de PENDIENTE a PARCIAL respecto de la línea base (118 IMPLEMENTADO · 435 PARCIAL · 253 PENDIENTE en la
base).

---

## 3. Qué falta

### 3.1 Según las prioridades de la especificación (§20)

**P0 · Core Verified**

| Elemento de §20 | Estado | Dónde se resuelve |
|---|---|---|
| Auditoría y trazabilidad | ✅ | W0 (y progreso por oleada desde W1) |
| Seguridad tenant | 🟡 | W1-B cerrado (SSRF, ZIP, firma); falta W6 · S11 (roles Enterprise, MFA, RLS, pruebas negativas en colas, caché y almacén) y la lista blanca SQL obligatoria en producción |
| Fuentes CSV + SQL | 🟡 | CSV/XLSX y PostgreSQL probados; **SQL Server real accesible** (piloto), pendiente de conciliar |
| Modelo canónico | 🔄 | W2 (contratos versionados, identidades, linaje) |
| Bridge operativo mínimo | ⏳ | W6 · S12 (instalador Windows **BLOQUEADO**) |
| Quality Engine | 🔄 | W2-A y W2-C |
| Reconstrucción temporal | ✅ ventas · 🔄 resto | W1 cerrado para ventas; W2-D (validador de disponibilidad, T11); inventario, catálogo y costos sin versiones |
| Demand baseline + ML y backtest | 🟡 | backtest as-of por ventana (W1); W2-E (horizonte 15) y W3 · S04 |
| Customer recompra + cantidad + ventana | 🟡 | W3 · S05 |
| Registry / CLI local / publicación privada / serving AWS | 🟡 | firma (W1-B); W4 · S06; AWS **BLOQUEADO** |
| Revenue Radar inicial | ⏳ | W5 · S07 |
| Testeabilidad y costo | 🟡 | matriz T01–T17; modelo de costos en W4 · S13 |

**P1 · Enterprise Pilot:** Decision Engine (W5 · S08), restricciones de Supply (W5 · S10), aprobaciones y tareas
(W5 · S08), Outcome/ROI (W5 · S09), tablero ejecutivo (W5 · S14), métricas por segmento (W3), controles
operativos, onboarding repetible y reportes (W6 · S17), **piloto real (en preparación)**.

**P2 / P3:** Factor Intelligence ampliado, conectores adicionales, agentes/LLM/WhatsApp, verticales Industry, Agro y
Export; se conservan los contratos y no bloquean el piloto P0/P1.

### 3.2 Pendientes de W1 que quedan registrados (no bloquean W2)

- **Datos.** Carrera de «primera carga» entre dos importaciones simultáneas de una empresa vacía (documentada). La
  categoría `closure` todavía no se acepta en el backend ni en la UI. La clasificación día a día no se persiste (solo
  su resumen). Supply, Causal, Escenarios y Costos leen el estado vigente.
- **Customer.** La huella del Customer no incluye la cobertura declarada. El upsert del registro no actualiza
  `core_run_id`, así que todavía no se compara `run_id` en Customer.
- **Seguridad.** Faltan la lista blanca SQL obligatoria en producción, un formato de artefactos sin pickle o
  deserializar en un subproceso sin credenciales, mover el análisis XLSX a la cola, y límites de puertos y TLS
  obligatorio.
- **Interfaz.** El formulario de fuente SQL Server no expone `encrypt` ni `trust_server_certificate` (en el piloto se
  ajustan en la configuración).

### 3.3 Criterios de salida (§19 y §26)

| Gate | Estado | Qué falta para GO |
|---|---|---|
| A · Integridad de datos | NO-GO | W2 (calidad, cuarentena, Bronze, reconciliación por capas) y conciliación mes a mes con la fuente SQL real (piloto) |
| B · Calidad ML | NO-GO | horizonte 15 (W2-E), métricas por segmento, ventana y número de pedidos en Customer (W3); datos reales |
| C · Seguridad / ML Ops | NO-GO | pendientes de seguridad de 3.2; CLI completa, aprobación y publicación (W4); AWS |
| D · Flujo empresarial | NO-GO | Radar, Decision Engine, workflow con aprobaciones, outcomes y ROI (W5) |
| E · Piloto prospectivo | BLOQUEADO | contrato, KPI y ventana de observación con el cliente |

Detalle: [`docs/roadmap/V25_RELEASE_GATES.md`](docs/roadmap/V25_RELEASE_GATES.md).

### 3.4 Bloqueos que dependen del propietario

| Bloqueo | Afecta | Acción del propietario |
|---|---|---|
| **Registrar la fuente SQL del piloto** en la app local | piloto (Gates A/B), paso 6 de §23 | en http://127.0.0.1:5173: cuenta local, empresa «Resgasa (piloto)», fuentes SQL Server «Resgasa ERP» y «Soyoda ERP» (el host ya está en la lista blanca local) |
| **Usuario de solo lectura** en SQL Server | regla 1 del runbook del piloto | el propietario decidió usar una cuenta con más permisos que solo lectura (riesgo aceptado; detalle en `reports/private/`). Se recomienda un login con solo `db_datareader` |
| Cuenta AWS, presupuesto y credenciales | T09, Gate C, costos reales | cuenta/organización, presupuesto y alertas; rol de despliegue de mínimo privilegio |
| Host Windows y certificado de firma de código | instalador del Bridge | equipo Windows 10/11 o Server; certificado |
| Servidores SQL Server / MySQL de prueba | T01 (3 pruebas omitidas) | instancia de prueba |
| Clave de proveedor LLM · WhatsApp Business · revisión legal (LOPDP) | P2 · Gates C/D | según el plan |

---

## 4. Próximo a realizar (en este orden)

### Paso 1 · Oleada W2 · Data Core P0 (✅ cerrada el 1-nov-2026)

Estado final por paquete (commits `e484c43` F, `43a0873` B, `8ed0faa` A, `8a69363` D, `df6531e` C, `d7d468c` E, `fbb1739` integración, `8e1679c` cierre):

| Paquete | Implementación | Revisión adversarial | Corrección |
|---|---|---|---|
| W2-A · calidad de ingestión, cuarentena, Bronze, deriva, reconciliación por carga | ✅ 62 pruebas nuevas | 1 HIGH (liberar de cuarentena pisaba una versión más nueva), 3 MEDIUM (reproceso no idempotente en cuarentena, AUTO_FIX decimal incorrecto, PII en hallazgos) | ✅ corregido |
| W2-B · Bronze, S3 contra emulador, contratos canónicos, alias, reconciliación por ventana | ✅ 89 pruebas | 1 HIGH (objeto Bronze «fantasma» si la escritura falla en silencio), 3 MEDIUM | ✅ 109 pruebas; backend 1062/8 omitidas |
| W2-C · métricas de dataset y gates PASS/WARN/DEGRADE/BLOCK | ✅ 104 pruebas | 2 MEDIUM (% inválido contaba importaciones fallidas → BLOCK falso; PASS sin aviso si una señal falla) | ✅ corregido |
| W2-D · validador de disponibilidad, contratos de features, T11 | ✅ 85 pruebas | 3 MEDIUM (calendario público/privado mezclado, EXPIRED sin verificar usable, validador vigente en ventanas del backtest) | ✅ corregido |
| W2-E · catálogo 7/14/15/30/60/90 y planes | ✅ H15 de punta a punta | 1 MEDIUM (conteo de series sin cota en rutas síncronas) | ✅ backend 994/8 omitidas |
| W2-F · Factor Intelligence P0, taxonomía, SharedForecastGrant | ✅ 78 pruebas | 3 MEDIUM (operaciones masivas sobre el catálogo público, periodos fuera del alcance, verificación sin versión) | ✅ 126 pruebas; backend 1062/8 omitidas |

Decisión del arquitecto (contrato entre W2-D y W2-F): un factor está activo en el motor solo si `factors.enabled` es
true y `tenants.settings.factor_activation.<clave>.active` (escrito por la API de W2-F) es true.

Integración: backend 1090 pruebas (1082 aprobadas, 8 omitidas: emulador S3 y servidores MySQL/SQL Server), motor 780,
frontend 329, E2E 32/32 y 256/256. El E2E de la etapa 2 se ajustó para leer el catálogo de horizontes del contrato
compartido (fijaba el catálogo anterior). Demo con gate PASS (Ferretería WAPE 0,4905 vs Seasonal Naive 0,6604; cob. 80 %
0,852). Auditoría: 25 requisitos pasan a IMPLEMENTADO y 18 de PENDIENTE a PARCIAL
(`docs/roadmap/audit/progress/2026-11-01-w2.json`). El stack local del piloto corre desde un *worktree* fijo en
`fbb1739` (W2 integrado), para que los cambios en curso de W3 no lo afecten: el piloto ya usa Bronze, calidad,
cuarentena y reconciliación.

### Estado actual (1-nov-2026, tarde)

- **W3 · ✅ cerrada:** W3-A (`a656521`), W3-B (`562f2b0`), W3-C (`552f6c0`) y W3-D (`7586c57`), cada uno tras revisión
  adversarial y corrección (1 HIGH y 8 MEDIUM en total). Integración sobre `7586c57`: backend 1217 (1209 aprobadas, 8
  omitidas), motor 1071, frontend 389; E2E 32/32 y 256/256; demo sin cambio en lo servido (el motor de W2 sobre los mismos
  datos sirve lo mismo fila a fila) y reproducible entre procesos. Evidencia: `docs/roadmap/evidence/2026-11-01-w3-integration/`;
  auditoría: `docs/roadmap/audit/progress/2026-11-01-w3.json` (IMPLEMENTADO 149 → 171).
- **W4 · ✅ cerrada:** W4-A (`c77deab`), W4-C (`bc54d9c`) y W4-B (`53400d4`: CLI de entrenamiento privado, almacén de
  objetos, serving solo de lo aprobado o del pipeline propio con firma verificada; revisión: 1 HIGH, 4 MEDIUM, 8 LOW
  corregidos). Integración en un stack aislado con `vendor` propio: E2E 32/32 y 256/256, demo idéntica a W3; backend
  1219, motor 1327, frontend 389. Evidencia: `docs/roadmap/evidence/2026-11-02-w4-integration/`; auditoría:
  `2026-11-02-w4.json` (IMPLEMENTADO 171 → 188). AWS real sigue BLOQUEADO.
- **Base de datos:** FKs compuestas `ON DELETE SET NULL` que solo anulan la referencia (`e0378db`) y nunca
  `tenant_id`, tampoco donde es NULLABLE (`003fd69`, migración `2026_03_12_000500`; en `model_registry` borrar el
  modelo anterior convertía el de la empresa en uno de plataforma).
- **W5 · en curso:** contrato `d54076e`; W5-D (`f18541b`) y W5-A + W5-B (`6fb4819`) confirmados tras revisión y
  corrección. W5-C (outcomes y ROI; revisión: 2 HIGH, 10 MEDIUM) y W5-E (vistas Enterprise; revisión: 1 HIGH, 5 MEDIUM, con
  la vista contra el backend real) en corrección; el hallazgo común es que el ROI no debe sumar valores de tipos
  distintos. Después: integración de W5. Las empresas demo declaran `data_origin = demo` (`ab8816d`).
- **W6 · en curso:** contrato activado (`d46f839`; tablas clasificadas en la exportación sin `token_hash` del Bridge).
  En implementación en paralelo: W6-A seguridad Enterprise (MFA, roles comerciales y alcance del vendedor en el
  Radar), W6-B Bridge de solo lectura, W6-C Auto Mapper, W6-D Intelligence Gateway y W6-E factores y contratos
  sectoriales; W6-F (cierre, E2E V2.5 y evaluación final de gates) al final.
- **Límite de uso:** el 2-nov se alcanzó el límite semanal y los siete agentes se detuvieron; se reanudaron el
  3-nov desde donde quedaron (su trabajo estaba guardado, sin confirmar).
- **Piloto:** sin cambios: falta que el propietario registre las fuentes SQL Server en la app local.
- **Rama sin subir:** el push a `origin` falla (403) porque `gh`/git en este Mac están autenticados como otra cuenta;
  el propietario debe iniciar sesión con la cuenta dueña del repositorio para subir la rama y abrir el PR.

### Paso 1b · Oleada W3 · Calidad ML P0 (✅ cerrada el 1-nov-2026)

Contrato activado (`4eb34f4`): `customer_predictions` con nº de pedidos, cantidad por pedido, unidades y ventana;
`model_promotion_decisions`; `shadow_forecast_values`. Guion: `docs/roadmap/contracts/w3/workflow/v25-w3.js`.

| Paquete | Alcance | Estado |
|---|---|---|
| W3-A | métricas por horizonte y segmento (volumen y vida de la serie as-of), escenarios de forma de serie | ✅ `a656521` |
| W3-B | política heredada parametrizada y V2.5, champion/challenger, shadow mode, Optuna acotado | ✅ `562f2b0` |
| W3-C | Customer S05: nº de pedidos (hurdle), cantidad por pedido, unidades, ventana validada, ranking y calibración | ✅ `552f6c0` |
| W3-D | API y UI de la calidad ML, exportación LOPDP de lo nuevo | ✅ `7586c57` |

### Paso 2 · Piloto con datos reales (en cuanto exista la fuente)

Según [`docs/operations/PILOT_RUNBOOK.md`](docs/operations/PILOT_RUNBOOK.md), en **solo lectura**:

1. Ajustar `encrypt` y `trust_server_certificate` en la configuración de la fuente, si hace falta.
2. Probar la conexión.
3. Descubrir el esquema con `infra/scripts/pilot/sql_source_discovery.php`.
4. Calcular los totales de control por mes en el origen.
5. Hacer los mapeos aprobados, la carga histórica `backfill` y la conciliación mes a mes.
6. Obtener las líneas base de demanda y de Customer Core.

Los resultados van **solo** a `reports/private/` (ignorado por git). Resgasa tiene el histórico de ventas más
actualizado.

### Paso 3 · Oleadas W3 a W6

- **W3 · Calidad ML P0:** S04 Demand y selección (métricas por horizonte y segmento, política heredada parametrizada y
  política de promoción V2.5, champion/challenger, *shadow mode*, Optuna acotado) y S05 Customer Core (número de
  pedidos, cantidad, ventana, PR-AUC, lift@K, calibración por segmento).
- **W4 · MLOps y AWS:** S06 Registry y CLI (estados con aprobación separada, publicación, serving solo de lo aprobado)
  y S13 IaC, costos por tenant y runbooks (despliegue real BLOQUEADO).
- **W5 · Flujo empresarial P1:** S07 Revenue Radar y Agenda, S08 Decision Engine y workflow, S09 Outcomes y ROI,
  S10 Supply y S14 vistas Enterprise.
- **W6 · Plataforma y cierre:** S11 seguridad Enterprise, S12 Bridge y Auto Mapper, S15 factores y taxonomía
  ampliados, S18 gateway, S16 contratos sectoriales, S17 documentación completa, E2E V2.5 y evaluación final de
  gates.

---

## 5. Avisos

- **Zona horaria de la base.** Antes del commit `d13e44f`, en un PostgreSQL cuya zona no era UTC, las marcas
  `timestamptz` que escribía Laravel quedaban desplazadas (Laravel envía UTC sin zona y el servidor las leía como hora
  local). En Docker no ocurría, porque la imagen usa UTC. La conexión fija ahora la sesión en UTC. Las filas escritas
  antes en una base así conservan el desplazamiento. La demo se recreó después de la corrección.
- **Cuenta del piloto con permisos de escritura.** Es decisión del propietario. SQL Server no tiene transacciones de solo
  lectura, así que la protección contra escrituras depende del filtro `SqlQueryGuard` (una sola sentencia SELECT,
  `ApplicationIntent=ReadOnly`). El descubrimiento usa solo SELECT fijos en READ UNCOMMITTED, para no bloquear a los
  escritores del ERP.
- **Defecto previo corregido (piloto):** el pipeline de demanda abortaba con `ZeroDivisionError` cuando ninguna venta
  traía costo (probable con un ERP real). `pipeline/core.py::recent_unit_costs` devuelve NaN; aplicado también al
  stack del piloto.
- **Entorno macOS:** el archivo `__editable__.predictily_engine-0.1.0.pth` del venv del motor recibió el atributo
  `hidden` y Python 3.12 lo ignora (`ModuleNotFoundError` fuera de `engine/`); se repara con
  `chflags nohidden engine/.venv/lib/python3.12/site-packages/__editable__*.pth`.
- **Repositorio público.** Nada del piloto se versiona: `reports/private/` y `data/private/` están ignorados, y las
  credenciales viven solo en la fuente cifrada de la app local.
- **Sin cifras inventadas.** Todas las métricas citadas son de la demo ficticia o de datos sintéticos.

## 6. Cómo retomar el entorno

El entorno vigente es **macOS con Homebrew** (no Docker). Ya está preparado en este equipo; para recrearlo:

1. **Servicios:** `brew services start postgresql@15` y `brew services start redis`; rol local
   `predictily/secret` con `CREATEDB` y bases `predictily`, `predictily_test`, `predictily_engine_test` y
   `predictily_erp_source_p3` (esta última para las pruebas de sincronización SQL).
2. **PHP 8.3:** extensiones `redis`, `sqlsrv` y `pdo_sqlsrv` por `pecl`. Si falla con «failed to mkdir … pecl»:
   `mkdir -p /opt/homebrew/lib/php/pecl`. Para SQL Server: `CPPFLAGS=-I/opt/homebrew/opt/unixodbc/include
   LDFLAGS=-L/opt/homebrew/opt/unixodbc/lib pecl install sqlsrv pdo_sqlsrv` (ODBC Driver 18 ya instalado). Los ajustes
   equivalentes a `backend/docker/php/*.ini` (memoria 1024M, subida de 25 MB) están en
   `~/.predictily/php/conf.d/predictily-local.ini`; se cargan con
   `PHP_INI_SCAN_DIR=/opt/homebrew/etc/php/8.3/conf.d:$HOME/.predictily/php/conf.d`.
3. **Dependencias:** `cd backend && composer install && php artisan migrate`; `cd engine && python3.12 -m venv .venv &&
   .venv/bin/pip install -e ".[dev]"` (LightGBM necesita `brew install libomp`); `cd frontend && npm ci`.
4. **Firma de artefactos:** la clave privada local está en `~/.predictily/keys/` (0600, fuera del repositorio).
   `ENGINE_SIGNING_*` y `ENGINE_TRUSTED_PUBLIC_KEYS` están en `engine/.env` y la pública en `backend/.env`
   (ninguno se versiona).
5. **Lista blanca SQL:** `PREDICTILY_SQL_ALLOWED_HOSTS=127.0.0.1,localhost,<IP del ERP del piloto>` y
   `PREDICTILY_SQL_ALLOWED_CIDRS=127.0.0.0/8` en `backend/.env` (local; la IP del piloto no se versiona).

```bash
export PHP_INI_SCAN_DIR="/opt/homebrew/etc/php/8.3/conf.d:$HOME/.predictily/php/conf.d"
cd engine && .venv/bin/uvicorn predictily_engine.api.main:app --host 127.0.0.1 --port 8100   # motor
cd backend && php artisan serve --host=127.0.0.1 --port=8000                                  # API
cd backend && php artisan queue:work redis --queue=imports,forecasting,default --timeout=1000 --tries=2 --memory=1024
cd frontend && npm run dev -- --host 127.0.0.1 --port 5173                                    # SPA
# Suites (herméticas: pasan con los .env de desarrollo presentes)
cd backend && DB_DATABASE=predictily_test TEST_ERP_DATABASE=predictily_erp_source_p3 php artisan test
cd engine && ENGINE_TEST_DATABASE_URL=postgresql+psycopg://predictily:secret@127.0.0.1:5432/predictily_engine_test .venv/bin/python -m pytest -q
cd frontend && npm run type-check && npm run lint && npm run test -- --run && npm run build
# E2E (stack arriba)
engine/.venv/bin/python e2e/mvp_acceptance.py --api http://127.0.0.1:8000/api/v1
engine/.venv/bin/python e2e/stage2_acceptance.py --api http://127.0.0.1:8000/api/v1
# Documentos de hoja de ruta
python3 infra/scripts/v25_roadmap_docs.py && python3 infra/scripts/v25_roadmap_docs.py --check
```

Varias suites a la vez en el mismo equipo: aislar el disco de pruebas con `LARAVEL_STORAGE_PATH=<directorio propio>`
(`Storage::fake` borra `storage/framework/testing`) y usar una base de prueba distinta por suite.
