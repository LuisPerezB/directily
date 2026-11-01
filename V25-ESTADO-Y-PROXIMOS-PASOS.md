# Predictily V2.5 · Estado actual, pendientes y próximos pasos

**Corte de este informe:** 1-nov-2026, 02:45 UTC · **Rama de trabajo:** `v25/enterprise-core` · **PR:**
[LuisPerezB/directily#3](https://github.com/LuisPerezB/directily/pull/3), fusionado en `main` a pedido del
propietario con la oleada W1 parcial.
**Especificación en la que se basa el trabajo:**
[`docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md`](docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md)
(V2.5, en adelante «la especificación»). Plan detallado:
[`docs/roadmap/V25_IMPLEMENTATION_PLAN.md`](docs/roadmap/V25_IMPLEMENTATION_PLAN.md).

> **En una línea:** la V2.5 terminó el inventario y la auditoría (pasos 1 y 2 del orden de trabajo, §23) y está a
> mitad del paso 3: corregir primero datos, as-of, fugas, idempotencia y aislamiento (oleada **W1**). Ningún
> criterio de salida está en GO. El código de W1 ya está en `main` pero **sin cerrar**: lo próximo es terminar e
> integrar W1 (sección 4, pasos 1 y 2) antes de empezar W2.

---

## 1. Dónde se quedó el trabajo (instancia exacta)

### 1.1 Orden de trabajo de la especificación (§23)

| Paso §23 | Qué pide | Estado |
|---|---|---|
| 1 | Inventario de requisitos por versión y matriz de fuentes no recuperadas | ✅ Hecho |
| 2 | Auditar, ejecutar pruebas, fotografiar métricas de línea base (incluida la política heredada) y clasificar defectos | ✅ Hecho |
| 3 | Priorizar datos / as-of / fugas / idempotencia / aislamiento antes que tableros o LLM | 🔄 **En curso: oleada W1** |
| 4 | Implementar por paquetes verticales con migración aditiva, pruebas y registros | ⏳ W2–W6 pendientes (contrato de W2 listo) |
| 5 | E2E en staging autorizado con 2 tenants, fuente SQL y CSV, entrenamiento validado, Registry/S3, inferencia AWS, Radar/Decisión/Outcome | ⏳ Pendiente; staging AWS **BLOQUEADO** |
| 6 | Métricas reales sobre holdout y evaluación prospectiva | 🔌 **BLOQUEADO**: sin datos reales autorizados |
| 7 | `V25_RELEASE_GATES.md` con GO / GO CONDICIONAL / NO-GO y changelog de lo hecho y lo pendiente | 🟡 Evaluación inicial publicada (todo NO-GO); este documento es el changelog vigente |

### 1.2 Oleada W1 (correcciones P0), en ejecución

W1 corrige los **13 defectos HIGH** de la auditoría, que violan la regla de mayor precedencia de la especificación
(seguridad, correctitud y disponibilidad temporal, §0.5). Cada paquete se ejecuta como **implementación →
revisión adversarial → corrección**, con subagentes Sonnet. El código ya subido está en commits **WIP** (puntos de
control), todavía **sin la revisión adversarial final ni las pruebas de aceptación**.

| Paquete | Alcance | Dónde quedó |
|---|---|---|
| **W1-A1 · Escritura temporal de ventas (backend)** | modo de ingestión backfill/incremental; versiones de documento archivadas antes de sobrescribir; descarte de versiones viejas (`stale_skipped`); tipos/estados desconocidos → `REVIEW_REQUIRED`; marca de agua acotada; API de huecos de cobertura | **Implementación terminada**: 507 pruebas del backend en su base (505 aprobadas, 2 omitidas, +56 nuevas), Pint y OpenAPI (187 operaciones) en verde. **Falta**: revisión adversarial y corrección |
| **W1-A2 · Lectura as-of en el motor** | lectura desde la vista `sales_line_versions`; cada ventana del backtest con los datos conocidos en su corte; Customer as-of; clasificación de días `zero_confirmed / missing_source / stockout_censored / closed`; huella as-of; el pronóstico vigente conoce hasta la hora de ejecución | Implementación casi completa (código, pruebas adversariales T02 y documentación hechos). **Falta**: suite completa del motor, informe, revisión adversarial y corrección |
| **W1-B · Seguridad** | SSRF de conectores SQL (IP fija, IPv6 mapeada, listas blancas, seguro por defecto); protección ZIP bomb en XLSX; firma Ed25519 de artefactos verificada antes de deserializar (motor y backend) | SSRF, ZIP y verificador del backend hechos. **Falta**: terminar la firma en el motor, suites completas, Pint, informe, revisión y corrección |

**Interrupción y fusión (1-nov-2026):** el workflow de W1 se detuvo a las **02:13:41 UTC** (interrupción de la
sesión) mientras A2 y B ejecutaban la suite completa del motor. Evidencia previa al corte: suite completa del motor
de A2 a las 01:38 con **523 pruebas aprobadas** (línea base: 418); comparación de paridad del motor anterior
contra el nuevo sobre datos históricos: con la regla de huecos inferidos apagada el resultado es **idéntico**
(WAPE 0,4377, sesgo −0,0067) y con la regla encendida WAPE 0,4358 y sesgo −0,0051; suite de B en curso con 282
pruebas aprobadas y ninguna fallida al cortarse. A pedido del propietario se ejecutaron las suites completas sobre
el estado final (commit `1d3b876`; evidencia en `docs/roadmap/evidence/2026-11-01-premerge/`) y se fusionó el PR #3
en `main` con W1 **parcial**:

| Suite | Línea base (78c5029) | Antes de fusionar (1d3b876) |
|---|---|---|
| Backend (`php artisan test`) | 449 aprobadas, 2 omitidas | **696 aprobadas, 2 omitidas** (698) |
| Motor (`pytest`) | 418 aprobadas | **524 aprobadas** |
| Frontend (type-check, lint, vitest, build) | 313 aprobadas | **313 aprobadas**, build correcto |
| E2E `mvp_acceptance.py` / `stage2_acceptance.py` | 32/32 · 256/256 | **no ejecutados con W1** (requieren migrar la base de desarrollo y configurar la firma; paso 2 de la sección 4) |

Suites en verde no equivalen a W1 terminado: falta la revisión adversarial de los tres paquetes y los E2E.

Guion del workflow: [`docs/roadmap/contracts/w1/workflow/v25-w1-p0-fixes.js`](docs/roadmap/contracts/w1/workflow/v25-w1-p0-fixes.js).
Bases de prueba propias de cada paquete: `predictily_test_w1a`, `predictily_engine_test_w1b`, `predictily_test_w1c`,
`predictily_engine_test_w1c`. La base de desarrollo `predictily` **todavía no tiene** la migración
`2026_03_01_000100` aplicada.

---

## 2. Qué está hecho (verificado)

| Entregable | Ubicación | Notas |
|---|---|---|
| Fuentes versionadas | `docs/roadmap/sources/` | V2.5 literal; **V1 recuperado literal** (la especificación lo daba por no recuperado); V2 literal hasta §10 (`PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md`). V2.1, V2.2, V2.3, V2.4 y V2.4 Enterprise **no se hallaron**: sus requisitos son `RECONSTRUIDO_PARCIALMENTE` desde las citas de la V2.5 |
| Auditoría por área con verificación adversarial | `docs/roadmap/audit/2026-10-31/*.json` | 8 áreas, **841 requisitos** (812 del auditor + 29 añadidos por la verificación), 179 defectos (13 HIGH, 73 MEDIUM, 93 LOW). El mensaje del commit `6456114` dice 933 por un error de suma |
| Cotejo literal V1 / V2 / complemento | `docs/roadmap/audit/2026-10-31/v1_literal.json`, `v2_literal.json`, `docs/roadmap/V25_VERSION_DIFF.md` | 1 123 filas; 108 requisitos previos que la V2.5 no menciona (riesgo de pérdida registrado) |
| Línea base | `docs/roadmap/evidence/2026-10-31-baseline/`, `baseline_metrics.json` | Motor 418 · backend 449 (2 omitidas) · frontend 313 · E2E 32/32 y 256/256 (etapa 2). Política heredada verificada: LightGBM reemplaza al mejor estadístico con mejora de WAPE ≥ 3 % y ⌈2n/3⌉ ventanas ganadas (producción: 3 de 4). Demo ficticia: WAPE 0,493 vs Seasonal Naive 0,663 |
| Decisiones de arquitectura | `docs/architecture/adr/V25-ADRS.md` | ADR-V25-01 a ADR-V25-25 |
| Contrato temporal de ventas (activo) | `backend/database/migrations/2026_03_01_000100_add_sales_knowledge_time_and_revisions.php` | Versiones de documento, modo de ingestión, vista as-of, huecos de cobertura (ADR-V25-23) |
| Plan, gates y documentos de hoja de ruta | `docs/roadmap/` | Plan por oleadas, gates (todo NO-GO), trazabilidad, brechas, diferencias, 198 riesgos; regenerables con `python3 infra/scripts/v25_roadmap_docs.py` (`--check` verifica que estén al día) |
| Matriz de pruebas T01–T17 | `docs/testing/V25_TEST_MATRIX.md` | 193 casos: ✅ 40 · 🧪 7 · 🟡 65 · ❌ 68 · 🔌 13 |
| Contrato de W2 (preparado, no activo) | `docs/roadmap/contracts/w2/` | Dos migraciones probadas, interfaz Bronze, versiones canónicas, esqueleto de calidad y guion del workflow; cómo activarlo en `docs/roadmap/contracts/README.md` |

### Estado de los requisitos de la especificación (auditoría, línea base)

| Prioridad | IMPLEMENTADO | PARCIAL | PENDIENTE | BLOQUEADO | NO APLICA | Total |
|---|---|---|---|---|---|---|
| P0 | 80 | 200 | 49 | 8 | 0 | 337 |
| P1 | 27 | 188 | 115 | 19 | 0 | 349 |
| P2 | 10 | 36 | 74 | 5 | 3 | 128 |
| P3 | 1 | 11 | 15 | 0 | 0 | 27 |
| **Total** | **118** | **435** | **253** | **32** | **3** | **841** |

Detalle por requisito: [`docs/roadmap/V25_GAP_ANALYSIS.md`](docs/roadmap/V25_GAP_ANALYSIS.md) y
[`docs/roadmap/V25_SOURCE_TRACEABILITY.md`](docs/roadmap/V25_SOURCE_TRACEABILITY.md). Estos conteos son de la línea
base; se actualizan al integrar cada oleada.

---

## 3. Qué falta

### 3.1 Según las prioridades de la especificación (§20)

**P0 · Core Verified**

| Elemento de §20 | Estado | Dónde se resuelve |
|---|---|---|
| Auditoría y trazabilidad | ✅ | W0 |
| Seguridad tenant | 🔄 parcial | W1-B (SSRF, ZIP, firma) y W6 · S11 (roles Enterprise, MFA, RLS, pruebas negativas en colas, caché y almacén) |
| Fuentes CSV + SQL | 🟡 | CSV/XLSX y PostgreSQL probados localmente; SQL real de un cliente **BLOQUEADO** |
| Modelo canónico | ⏳ | W2 (contratos versionados, identidades, linaje) |
| Bridge operativo mínimo | ⏳ | W6 · S12 (instalador Windows **BLOQUEADO**) |
| Quality Engine | ⏳ | W2 (clases, cuarentena, gates, reconciliación) |
| Reconstrucción temporal | 🔄 | W1-A1 / W1-A2 y W2 · S02 (validador de disponibilidad, T11) |
| Demand baseline + ML y backtest | 🟡 | existe; W2 · S03 (horizonte 15) y W3 · S04 (métricas por segmento, política de promoción, *shadow mode*, Optuna acotado) |
| Customer recompra + cantidad + ventana | 🟡 | W3 · S05 (número de pedidos, ventana, lift@K, calibración por segmento) |
| Registry / CLI local / publicación privada / serving AWS | 🟡 | W1-B (firma) y W4 · S06; AWS **BLOQUEADO** |
| Revenue Radar inicial | ⏳ | W5 · S07 (contratos V2.2) |
| Testeabilidad y costo | 🟡 | matriz T01–T17 hecha; modelo de costos en W4 · S13 |

**P1 · Enterprise Pilot:** Decision Engine (W5 · S08), restricciones de Supply (W5 · S10), aprobaciones y tareas
(W5 · S08), Outcome/ROI (W5 · S09), tablero ejecutivo (W5 · S14), métricas por segmento (W3), controles
operativos, onboarding repetible y reportes (W6 · S17), **piloto real (BLOQUEADO)**.

**P2 / P3:** Factor Intelligence ampliado, conectores adicionales, agentes/LLM/WhatsApp, verticales Industry, Agro y
Export; se conservan los contratos y no bloquean el piloto P0/P1 (W2 · S02/F para contratos; W6 · S15/S16/S18).

### 3.2 Criterios de salida (§19 y §26)

| Gate | Estado | Qué falta para GO |
|---|---|---|
| A · Integridad de datos | NO-GO | cerrar W1 (fugas temporales) y W2 (calidad, cuarentena, Bronze, reconciliación); fuente SQL real |
| B · Calidad ML | NO-GO | horizonte 15, métricas por segmento, ventana y número de pedidos en Customer, ranking que supere la referencia; datos reales |
| C · Seguridad / ML Ops | NO-GO | cerrar W1-B; CLI completa, aprobación y publicación (W4); AWS |
| D · Flujo empresarial | NO-GO | Radar, Decision Engine, workflow con aprobaciones, outcomes y ROI (W5) |
| E · Piloto prospectivo | BLOQUEADO | cliente piloto con datos autorizados, KPI y ventana de observación |

Detalle: [`docs/roadmap/V25_RELEASE_GATES.md`](docs/roadmap/V25_RELEASE_GATES.md).

### 3.3 Documentos exigidos por §21 y §28

Existen 11 de 53: los seis de `docs/roadmap/` (trazabilidad, brechas, plan, gates, riesgos, diferencias),
`docs/testing/V25_TEST_MATRIX.md` y cuatro en curso de W1 (`docs/data/TEMPORAL_AVAILABILITY.md`,
`docs/data/LINEAGE_AND_RECONCILIATION.md`, `docs/ml/MODEL_REGISTRY.md`, `docs/security/ENTERPRISE_SECURITY.md`).
Los 42 restantes se escriben con el paquete que implementa cada tema (cada paquete entrega su documentación) y
el cierre (W6 · S17) completa los transversales: arquitectura Enterprise y AWS, costos, aislamiento, conectores,
Bridge, onboarding, validación y selección de pronósticos, recompra, calibración, entrenamiento privado y
automatizado, despliegue de modelos, decisión, outcomes, acciones controladas, operación del propietario, piloto,
planes heredados, aceptación MVP Retail/Enterprise, compatibilidad de API heredada, aceptación sectorial y los
informes `reports/V25_PILOT_BASELINE.md` y `reports/V25_PROSPECTIVE_VALIDATION.md` (estos dos requieren datos
reales).

### 3.4 Bloqueos que dependen del propietario

| Bloqueo | Afecta | Acción del propietario |
|---|---|---|
| Cuenta AWS, presupuesto y credenciales | T09, Gate C, costos reales | crear cuenta/organización, presupuesto y alertas; rol de despliegue de mínimo privilegio; dominio y certificados |
| Datos reales autorizados (Resgasa vía Soyoda) | T10, Gates A/B/E, métricas reales | autorización de tratamiento; sesión con acceso a la VPN (Remote Control en Mac, Linux o WSL) y usuario de solo lectura |
| Host Windows y certificado de firma de código | instalador del Bridge | equipo Windows 10/11 o Server; certificado |
| Servidores SQL Server / MySQL de prueba | T01 (drivers exactos) | instancia de prueba o acceso de solo lectura |
| Clave de proveedor LLM | asistente / gateway (opcional) | clave y contrato de tratamiento |
| Cuenta de WhatsApp Business | P2 | cuenta y número verificados |
| Revisión legal (LOPDP, contratos de tratamiento) | Gates C/D | revisión por abogado |
| Restricción del entorno de desarrollo | Terraform, MariaDB, SQL Server, Docker | el entorno no permite descargar binarios externos: IaC se verificará por síntesis (CDK) o quedará documentada como bloqueada |

---

## 4. Próximo a realizar (en este orden)

### Paso 1 · Terminar W1 (en ejecución)

Implementación pendiente de A2 y B; revisión adversarial y corrección de A1, A2 y B. Si una sesión nueva debe
continuar, relanzar el guion de `docs/roadmap/contracts/w1/workflow/` indicando que el trabajo previo está en los
commits WIP desde la base `b975897` (el guion ya contiene esa instrucción de reanudación).

### Paso 2 · Integrar W1 (arquitecto)

1. Revisar los diffs de los tres paquetes contra `b975897` y los informes de revisión.
2. Aplicar la migración en la base de desarrollo (`cd backend && php artisan migrate`) y reiniciar el stack.
3. Configurar la firma de artefactos del entorno local: generar un par de claves con el CLI del motor
   (`python -m predictily_engine.cli keygen`, ver `docs/ml/MODEL_REGISTRY.md`), definir las variables en los `.env`
   locales (no versionados). Los artefactos demo existentes no tienen firma: se re-entrenan o se habilita
   explícitamente el modo legado solo en local.
4. Ejecutar las suites completas y los E2E: `e2e/mvp_acceptance.py` (debe seguir 32/32) y
   `e2e/stage2_acceptance.py` (debe seguir 256/256 o explicar cada diferencia).
5. Comparar las métricas de la demo con la línea base (WAPE 0,493 vs Seasonal Naive 0,663; cobertura 80 % 0,852;
   Customer Core) y documentar el efecto de la lectura as-of y de la clasificación de días.
6. Actualizar el estado de los requisitos y defectos corregidos en `docs/roadmap/audit/`, regenerar los documentos
   (`python3 infra/scripts/v25_roadmap_docs.py`), actualizar `V25_RELEASE_GATES.md` y este documento.
7. Commit de integración (no WIP) y actualizar título y descripción del PR #3.

### Paso 3 · Oleada W2 · Data Core P0 (contrato listo)

Activar el contrato siguiendo `docs/roadmap/contracts/README.md` y lanzar el workflow de W2 con seis paquetes:

| Paquete | Alcance |
|---|---|
| W2-A | calidad de ingestión (hallazgos con clase), cuarentena revisable, mapeos de valores, deriva de esquema, captura Bronze antes de normalizar, linaje y reconciliación por carga |
| W2-B | almacén Bronze completo (S3 contra emulador o bloqueo documentado, retención y purga), contratos canónicos versionados, alias de producto y cliente, reconciliación por ventana |
| W2-C | métricas de dataset y gates por módulo `PASS / WARN / DEGRADE / BLOCK` antes de entrenar e inferir |
| W2-D | validador de disponibilidad temporal, contratos de features y pruebas adversariales T11 |
| W2-E | catálogo de horizontes 7/14/15/30/60/90 y planes Starter/Growth/Enterprise configurables |
| W2-F | backend de Factor Intelligence P0 (catálogo de 17 familias inactivas, eventos con verificación), taxonomía básica y contrato `SharedForecastGrant` desactivado con pruebas negativas |

### Paso 4 · Oleadas W3 a W6

- **W3 · Calidad ML P0:** S04 Demand y selección (métricas persistidas por horizonte y segmento, política heredada
  parametrizada junto a la política de promoción V2.5, champion/challenger, *shadow mode*, Optuna acotado) y
  S05 Customer Core (número de pedidos, cantidad por pedido, unidades, ventana, PR-AUC, lift@K, calibración por
  segmento, caso de cubetas de huevo como *fixture*).
- **W4 · MLOps y AWS:** S06 Registry y CLI (estados con aprobación separada, `validate-data` … `diagnose`,
  publicación, serving solo de lo aprobado) y S13 IaC, costos por tenant y runbooks (despliegue real BLOQUEADO).
- **W5 · Flujo empresarial P1:** S07 Revenue Radar y Agenda (contratos V2.2), S08 Decision Engine y workflow con
  segregación de funciones, S09 Outcomes y ROI honesto con grupo de control, S10 Supply (compromisos sin doble
  conteo, perecibilidad, simulación as-of) y S14 vistas Enterprise.
- **W6 · Plataforma y cierre:** S11 seguridad Enterprise, S12 Bridge y Auto Mapper, S15 factores y taxonomía
  ampliados, S18 gateway, S16 contratos sectoriales, S17 documentación completa, **E2E V2.5** (2 tenants, SQL + CSV,
  entrenamiento validado, registro, inferencia, radar, decisión, outcome, fallo de calidad, reversión y cambio de
  tenant) y evaluación final de gates.

### Paso 5 · Con datos y cuenta del propietario

Importar el histórico autorizado, ejecutar evaluación temporal, holdout y calibración con datos reales
(`reports/V25_PILOT_BASELINE.md`), desplegar en staging AWS y abrir la ventana prospectiva del piloto con KPI y
grupo comparador acordados (`reports/V25_PROSPECTIVE_VALIDATION.md`), cerrando con la decisión GO / GO
CONDICIONAL / NO-GO del Gate E.

---

## 5. Avisos

- **El PR #3 se fusionó en `main` a pedido del propietario con W1 parcial.** `main` contiene los 22 commits de la
  etapa 2 que faltaban, los entregables de W0 y el código de W1 sin revisión adversarial final ni E2E. El trabajo
  siguiente va en una rama nueva creada desde `main` (no sobre el PR ya fusionado).
- **Commits WIP:** son puntos de control del trabajo de los subagentes para no perderlo; la entrega revisada de
  cada paquete llega en un commit de integración.
- **Límites de uso:** las ejecuciones con subagentes se interrumpieron dos veces por el límite de 5 horas y se
  reanudaron sin perder trabajo.
- **Sin cifras inventadas:** todas las métricas citadas son de la demo ficticia; ninguna es una promesa de
  precisión para una empresa real.

## 6. Cómo retomar el entorno

**Después de hacer pull** (cambios de W1 que afectan el entorno local):

1. `cd backend && php artisan migrate`: agrega versiones de documentos de venta, la vista `sales_line_versions` y
   los huecos de cobertura. Si las pruebas del motor usan su propia base, migrarla también:
   `DB_DATABASE=predictily_engine_test php artisan migrate:fresh --force`.
2. `cd engine && .venv/bin/pip install -e .`: nueva dependencia `cryptography` (firma Ed25519).
3. Firma de artefactos (ADR-V25-20): `python -m predictily_engine.cli keygen --key-id <id> --private-out <ruta segura>`
   y configurar `ENGINE_SIGNING_KEY_ID`, `ENGINE_SIGNING_PRIVATE_KEY` y `ENGINE_TRUSTED_PUBLIC_KEYS` en el `.env` del
   motor y las claves públicas en el del backend (ver `docs/ml/MODEL_REGISTRY.md` y los `.env.example`). Los
   artefactos generados antes de W1 no tienen firma y se rechazan: re-entrenar, o activar
   `ENGINE_ALLOW_UNSIGNED_ARTIFACTS=true` solo en un entorno local.
4. Conectores SQL: la política anti-SSRF ahora deniega redes privadas y loopback por defecto; para un ERP en la red
   local o por VPN, declarar `PREDICTILY_SQL_ALLOWED_HOSTS` o `PREDICTILY_SQL_ALLOWED_CIDRS` (ver `backend/.env.example`).

```bash
service postgresql start && service redis-server start
cd engine && .venv/bin/uvicorn predictily_engine.api.main:app --host 127.0.0.1 --port 8100   # motor
cd backend && php artisan serve --host=127.0.0.1 --port=8000                                  # API
cd backend && php artisan queue:work redis --queue=imports,forecasting,default --timeout=1000 --tries=2 --memory=1024
# Suites
cd engine && .venv/bin/python -m pytest -q
cd backend && php artisan test
cd frontend && npm run type-check && npm run lint && npm run test -- --run && npm run build
# Documentos de hoja de ruta
python3 infra/scripts/v25_roadmap_docs.py && python3 infra/scripts/v25_roadmap_docs.py --check
```
