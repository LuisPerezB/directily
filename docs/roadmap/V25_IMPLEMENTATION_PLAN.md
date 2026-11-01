# Predictily V2.5 · Plan de implementación

Fecha: 31-oct-2026 · Rama: `v25/enterprise-core` · Responsable técnico: arquitecto (agente principal).
Ejecución: subagentes de menor capacidad (Sonnet) implementan, revisan en forma adversarial y corrigen
cada paquete; el arquitecto fija contratos (migraciones, ADR), revisa e integra.

**Estado vigente, pendientes y próximos pasos:** [`V25-ESTADO-Y-PROXIMOS-PASOS.md`](../../V25-ESTADO-Y-PROXIMOS-PASOS.md)
(raíz del repositorio). Contratos preparados y no activados: [`contracts/`](contracts/README.md).

Fuentes: `docs/roadmap/sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md` (V2.5) y la
auditoría con evidencia `docs/roadmap/audit/2026-10-31/` (requisitos extraídos y verificados,
defectos, cotejo literal de V1 y V2, línea base). Decisiones: `docs/architecture/adr/V25-ADRS.md`.

## 1. Principios (V2.5 §23)

1. Ninguna modificación sin inventario: hecho en W0 (trazabilidad, brechas, cotejo literal).
2. Primero datos, as-of, fugas, idempotencia y aislamiento; después tableros y LLM.
3. Paquetes verticales con migración aditiva, pruebas y registros reproducibles al cierre de cada uno;
   un paquete a medias no se declara completo.
4. E2E en staging autorizado o registro explícito del bloqueo.
5. Métricas reales solo con datos reales; nunca sustituir datos reales por ficticios en silencio.
6. `V25_RELEASE_GATES.md` decide GO / GO CONDICIONAL / NO-GO con evidencia.

## 2. Línea base (commit `78c5029`)

| Verificación | Resultado |
|---|---|
| Motor (`pytest`) | 418 aprobadas (544 s) |
| Backend (`php artisan test`) | 449 aprobadas, 2 omitidas (75 s) |
| Frontend (type-check, lint, vitest, build) | 313 aprobadas |
| E2E etapa 1 / etapa 2 | 32/32 · 256/256 (evidencia `docs/architecture/v2/evidence/`) |
| Política de selección heredada | existe: LightGBM reemplaza al mejor estadístico si mejora el WAPE ≥ 3 % y gana en ⌈2n/3⌉ ventanas (producción: 3 de 4) |
| Demo (datos ficticios) | WAPE diario 0,493 vs Seasonal Naive 0,663; cobertura 80 % = 0,852; Customer Core sirve la referencia de popularidad en 7/30/90 días |

Evidencia: `docs/roadmap/evidence/2026-10-31-baseline/` y `docs/roadmap/audit/2026-10-31/baseline_metrics.json`.

## 3. Oleadas y paquetes

Prioridades según V2.5 §20 y §26. «Gate» indica el criterio de salida al que contribuye.

### W0 · Inventario y contratos (hecho)

Fuentes versionadas (V1 recuperado literal, V2 literal parcial, V2.5), auditoría de 8 áreas con
verificación adversarial, cotejo literal V1/V2, línea base, ADR-V25-01..23, contrato temporal de
ventas (migración `2026_03_01_000100`).

### W1 · Correcciones P0 de correctitud temporal y seguridad (en curso)

Origen: los 13 defectos HIGH de la auditoría. Gate A y Gate C.

| Paquete | Alcance | Pruebas clave |
|---|---|---|
| W1-A1 backend | modo de ingestión backfill/incremental; versiones de documento archivadas antes de sobrescribir; descarte de versiones viejas (`stale_skipped`); tipos/estados desconocidos → `REVIEW_REQUIRED`; marca de agua acotada; API de huecos de cobertura | T01 (reintentos fuera de orden, edición histórica, watermark) |
| W1-A2 motor | lectura as-of desde `sales_line_versions`; cada ventana de backtest con datos conocidos a su corte; Customer as-of; clasificación de días `zero_confirmed / missing_source / stockout_censored / closed`; huella as-of | T02 (documento tardío, anulación posterior), T03 (huecos) |
| W1-B seguridad | SSRF de conectores; protección ZIP bomb; firma Ed25519 de artefactos verificada antes de deserializar (motor y backend) | T05 (artefactos alterados), T08 (SSRF) |

### W2 · Data Core P0

Gate A. Depende de W1.

| Paquete | Alcance |
|---|---|
| S01 Data Quality Engine | clases `AUTO_FIX_SAFE / REVIEW_REQUIRED / QUARANTINED / WARNING / BLOCKER` con original y motivo; cuarentena revisable y reprocesable; informe por dataset (cobertura, % inválido, claves, historia por SKU/cliente, ceros, estabilidad de IDs, costo/inventario/precio, cierres/quiebres, frescura); gates `BLOCK / WARN / DEGRADE` por módulo y tenant con umbrales justificados «a aprobar en el piloto»; entrenamiento e inferencia consultan el gate |
| S01 Bronze y reconciliación | `ObjectStore` (local; S3 con emulador); objetos Bronze inmutables con SHA-256, procedencia y retención; linaje Silver (`bronze_object_id`, `source_system_id`, `schema_version`); reporte fuente → Bronze → Silver → Gold por ventana (filas aceptadas/inválidas, documentos netos, unidades, importes, anulaciones, duplicados, delta de sincronización); reprocesamiento determinista |
| S01 Identidades | alias de producto y cliente con historial (cambio de código, fusión/división, homónimos); clave natural de documento que incluya la fuente cuando hay varias; unidades y monedas sin conversión silenciosa |
| S02 Disponibilidad temporal | `FeatureAvailabilityValidator` (ADR-V25-06) con clases y estados de disponibilidad; contrato de cada feature; contratos de factores (`factor_definitions`, `factor_observations`, `external_events`, `source_versions`) con pipeline desactivable; pruebas adversariales T11 (documento sanitario publicado después, pronóstico emitido mañana, precio de competencia retroactivo, noticia sin fecha, revisión estadística, API caída, campaña conocida) |
| S03 Horizontes | catálogo 7/14/15/30/60/90 parametrizado (ADR-V25-02); Enterprise 7/15/30; nombres de abstención unificados (`insufficient_data`); planes Starter/Growth/Enterprise configurables (ADR-V25-18) sin romper Básico |

### W3 · Calidad ML P0

Gate B. Depende de W2 (S02, S03).

| Paquete | Alcance |
|---|---|
| S04 Demand y selección | MASE/RMSSE/pinball persistidos por corrida, horizonte y segmento; WAPE con denominador y cobertura explícitos y métrica alternativa para series de ceros; política heredada parametrizada (fracción de ventanas y rango validado) y política de promoción V2.5 calculada en paralelo y registrada (ADR-V25-21); champion/challenger; *shadow mode*; Optuna opcional y acotado sin tocar el holdout final; demanda latente vs ventas observadas declarada |
| S05 Customer Core | `P(≥1 pedido)`, `E(nº pedidos)`, `E(cantidad por pedido)`, `E(unidades)`, ventana (cuantiles del tiempo a la próxima compra), PR-AUC, lift@K, calibración por segmento y horizonte, horizontes 7/14/15/30/90, degradación con IDs inestables, caso de cubetas de huevo como *fixture* (ADR-V25-11) |

### W4 · MLOps y AWS P0

Gate C. Depende de W1-B y W3.

| Paquete | Alcance |
|---|---|
| S06 Registry y CLI | estados `candidate / validated / approved / production / retired / rejected` (+`failed` legado) con segregación de funciones; CLI `validate-data`, `build-features`, `train --tenant`, `backtest`, `tune`, `evaluate`, `package`, `sign`, `verify`, `publish --env`, `compare`, `rollback`, `diagnose`; publicación al almacén de objetos; serving solo de lo aprobado; clave de caché tenant + versión + esquema; pruebas negativas entre tenants en registro, caché y jobs |
| S13 AWS | IaC (CDK; ADR-V25-09) con síntesis y lint locales o bloqueo registrado; `AWS_ARCHITECTURE.md`, `AWS_COST_MODEL.md` (costo por tenant con parámetros, sin precios inventados), `AWS_DEPLOYMENT.md`, `RECOVERY.md` (restauración probada localmente). Despliegue real: **BLOQUEADO** (cuenta del propietario) |

### W5 · Flujo empresarial P1

Gate D. Depende de W3.

| Paquete | Alcance |
|---|---|
| S07 Revenue Radar y Agenda | contratos V2.2 como fachadas (ADR-V25-12); estados heredados mapeados; feedback auditable; ficha de cliente |
| S08 Decision Engine | contrato de recomendación y workflow con segregación de funciones e idempotencia (ADR-V25-13) |
| S09 Outcomes y ROI | vínculo recomendación → acción → resultado; grupo de control registrado; contribución, no ingresos brutos (ADR-V25-14) |
| S10 Supply | compromisos comerciales en el balance sin doble conteo; perecibilidad/FEFO; stock de seguridad y punto de reorden explícitos; simulación cronológica as-of con métricas de servicio |
| S14 UI | Executive Overview, Revenue Radar, Decision Center, ROI & Outcomes, Administración (ADR-V25-22) |

### W6 · Plataforma, integración y cierre

| Paquete | Alcance |
|---|---|
| S11 Seguridad | roles gerente/vendedor/servicio; MFA TOTP para privilegiados; tokens con alcance y caducidad; revocación de dispositivos; RLS piloto detrás de flag; pruebas negativas en colas, caché, almacén y exportaciones |
| S12 Bridge y Auto Mapper | agente de solo lectura (ADR-V25-10) con registro de dispositivo, watermark, checkpoints, backoff con jitter, búfer cifrado y URL prefirmada; adaptadores CSV/Excel, SQLite y PostgreSQL probados, el resto `implemented_untested`; schema discovery, propuesta determinista con confianza heurística, aprobación, plantillas versionadas y drift de esquema |
| S15 Factores y taxonomía | taxonomía versionada, perfiles, 17 familias catalogadas desactivadas, script de ablation, contrato `SharedForecastGrant` con pruebas negativas (ADR-V25-16) |
| S18 Gateway | herramientas tipadas con autorización por herramienta, defensa ante inyección de prompts, contrato de WhatsApp (ADR-V25-17) |
| S16 Sectorial | contratos Retail/Export/Industry/Agro preservados; pruebas T15/T17 marcadas `P3_PENDIENTE` sin declarar cobertura |
| S17 Cierre | documentos exigidos por §21 y §28, E2E V2.5 (2 tenants, SQL + CSV, entrenamiento validado, registro, inferencia, radar, decisión, outcome, fallo de calidad, reversión, cambio de tenant), `V25_RELEASE_GATES.md` final |

## 4. Bloqueos que dependen del propietario

| Bloqueo | Afecta | Acción del propietario |
|---|---|---|
| Cuenta AWS, presupuesto y credenciales de despliegue | T09, Gate C (publicación e inferencia en AWS), costos reales | crear cuenta/organización, presupuesto y alertas; rol de despliegue con mínimo privilegio; dominio y certificados |
| Datos reales autorizados (Resgasa vía Soyoda) | T10, Gate B/E, métricas reales | contrato y autorización de tratamiento; sesión con acceso a la VPN (Remote Control en Mac, Linux o WSL) y usuario de solo lectura |
| Host Windows y certificado de firma de código | instalador del Bridge | equipo Windows 10/11 o Server para empaquetar y probar; certificado de firma |
| Servidores SQL Server / MySQL de prueba | T01 (drivers exactos) | instancia de prueba o acceso de solo lectura a la del cliente |
| Clave de proveedor LLM | S18 (opcional) | clave de Ollama Cloud u otro proveedor, con contrato de tratamiento |
| Cuenta de WhatsApp Business | P2 | cuenta y número verificados |
| Revisión legal (LOPDP, contratos de tratamiento) | Gate C/D | revisión por abogado |

## 5. Restricciones del entorno de desarrollo

Sin cuenta AWS ni Docker daemon; no se permite descargar binarios externos (Terraform, MariaDB, SQL
Server); sí paquetes de Python y npm del proyecto. PostgreSQL 16 y Redis locales. Por eso el
controlador S3 se prueba contra un emulador local etiquetado y la IaC se verifica por síntesis.

## 6. Definición de terminado por paquete

Código + migración aditiva + pruebas que afirman cada comportamiento + suites completas en verde +
revisión adversarial sin hallazgos CRITICAL/HIGH/MEDIUM abiertos + documentación con estados honestos
+ actualización del estado en la auditoría (`docs/roadmap/audit/`) y regeneración de la trazabilidad.
