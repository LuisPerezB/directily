# Predictily V2.5 · Documentación de la hoja de ruta

> Índice generado por `infra/scripts/v25_roadmap_docs.py`. Los documentos marcados «generado» se regeneran desde los JSON de la auditoría; no se editan a mano.

Regla de la V2.5 (§23, §28): ningún estado se declara sin evidencia ejecutable; lo bloqueado por terceros se registra como `BLOQUEADO`.

## Cómo regenerar

Desde la raíz del repositorio (solo biblioteca estándar de Python 3):

```bash
python3 infra/scripts/v25_roadmap_docs.py           # reescribe trazabilidad, brechas, diferencias, riesgos y este índice
python3 infra/scripts/v25_roadmap_docs.py --check   # sale con código 1 si algún documento generado está desactualizado
```

Cuando cambie un JSON de `docs/roadmap/audit/2026-10-31` (estado de un requisito, defecto nuevo, veredicto), regenerar y revisar el diff.

## Fuentes

| Documento | Tipo | Contenido |
| --- | --- | --- |
| [`sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md`](sources/PREDICTILY_V2_5_ENTERPRISE_MASTER_RECONCILIADO.md) | manual | Especificación objetivo V2.5 (prompt maestro reconciliado, §0-§28). Fuente de los requisitos y de las pruebas T01-T17. |
| [`sources/V1_PROMPT_MAESTRO_ORIGINAL.md`](sources/V1_PROMPT_MAESTRO_ORIGINAL.md) | manual | Prompt maestro V1 recuperado de forma literal (22 secciones); base del cotejo de V1. |

## Auditoría

| Documento | Tipo | Contenido |
| --- | --- | --- |
| [`audit/2026-10-31/datos.json`](audit/2026-10-31/datos.json) | datos | Datos, calidad y disponibilidad temporal: 97 requisitos (IMPLEMENTADO 11, PARCIAL 70, PENDIENTE 15, BLOQUEADO 1), 32 defectos, 11 conflictos. |
| [`audit/2026-10-31/conectores.json`](audit/2026-10-31/conectores.json) | datos | Conectores, Bridge, Auto Mapper y cargas: 96 requisitos (IMPLEMENTADO 12, PARCIAL 40, PENDIENTE 39, BLOQUEADO 5), 20 defectos, 8 conflictos. |
| [`audit/2026-10-31/demanda.json`](audit/2026-10-31/demanda.json) | datos | Demand Core, selección de modelos y horizontes: 103 requisitos (IMPLEMENTADO 27, PARCIAL 62, PENDIENTE 13, BLOQUEADO 1), 22 defectos, 8 conflictos. |
| [`audit/2026-10-31/cliente.json`](audit/2026-10-31/cliente.json) | datos | Customer Core y agenda comercial: 78 requisitos (IMPLEMENTADO 13, PARCIAL 36, PENDIENTE 28, BLOQUEADO 1), 16 defectos, 8 conflictos. |
| [`audit/2026-10-31/modelos_aws.json`](audit/2026-10-31/modelos_aws.json) | datos | Ciclo de vida de modelos, Registry y AWS: 107 requisitos (IMPLEMENTADO 12, PARCIAL 59, PENDIENTE 22, BLOQUEADO 14), 27 defectos, 9 conflictos. |
| [`audit/2026-10-31/supply_decision.json`](audit/2026-10-31/supply_decision.json) | datos | Supply Core, Decision Engine y Outcomes: 119 requisitos (IMPLEMENTADO 20, PARCIAL 47, PENDIENTE 49, BLOQUEADO 2, NO_APLICA 1), 22 defectos, 9 conflictos. |
| [`audit/2026-10-31/seguridad_ui.json`](audit/2026-10-31/seguridad_ui.json) | datos | Seguridad, UI, Intelligence Gateway y onboarding: 123 requisitos (IMPLEMENTADO 25, PARCIAL 67, PENDIENTE 23, BLOQUEADO 6, NO_APLICA 2), 27 defectos, 8 conflictos. |
| [`audit/2026-10-31/factores_sector.json`](audit/2026-10-31/factores_sector.json) | datos | Factor Intelligence, taxonomía y módulos sectoriales: 118 requisitos (IMPLEMENTADO 4, PARCIAL 56, PENDIENTE 56, BLOQUEADO 2), 13 defectos, 8 conflictos. |
| [`audit/2026-10-31/v1_literal.json`](audit/2026-10-31/v1_literal.json) | datos | Cotejo literal de V1: 471 filas y tabla de fuentes por versión. |
| [`audit/2026-10-31/v2_literal.json`](audit/2026-10-31/v2_literal.json) | datos | Cotejo literal de V2 (371 filas) y del complemento V2C (281 filas). |
| [`audit/2026-10-31/baseline_metrics.json`](audit/2026-10-31/baseline_metrics.json) | datos | Línea base técnica: política de selección heredada, hiperparámetros fijos, variables externas, restricciones del entorno e incidencias B-01..B-15. |

## Evidencia

| Documento | Tipo | Contenido |
| --- | --- | --- |
| [`evidence/2026-10-31-baseline/`](evidence/2026-10-31-baseline/) | evidencia | Registros de la ejecución de la línea base (SUMMARY.txt, backend-phpunit.log, engine-pytest.log, frontend-build.log, frontend-lint.log, frontend-typecheck.log, frontend-vitest.log). |
| [`evidence/2026-11-01-premerge/`](evidence/2026-11-01-premerge/) | evidencia | Registros de la ejecución de la línea base (SUMMARY.txt, backend-phpunit.log, engine-pytest.log, frontend-build.log, frontend-lint.log, frontend-typecheck.log, frontend-vitest.log). |
| [`evidence/2026-11-01-w1-integration/`](evidence/2026-11-01-w1-integration/) | evidencia | Registros de la ejecución de la línea base (SUMMARY.txt, backend-phpunit.log, demo_metrics.json, e2e-mvp-acceptance.log, e2e-stage2-acceptance.log, engine-pytest.log, frontend-build.log, frontend-lint.log, frontend-typecheck.log, frontend-vitest.log). |

## Plan y criterios de salida

| Documento | Tipo | Contenido |
| --- | --- | --- |
| [`V25_IMPLEMENTATION_PLAN.md`](V25_IMPLEMENTATION_PLAN.md) | manual | Plan por oleadas W0-W6 y paquetes (S01-S18, W1-A1/A2/B), bloqueos del propietario y definición de terminado. |
| [`V25_RELEASE_GATES.md`](V25_RELEASE_GATES.md) | manual | Criterios de salida (Gates A-E y niveles P0-P3) con GO / GO CONDICIONAL / NO-GO / BLOQUEADO. |
| [`docs/architecture/adr/V25-ADRS.md`](../architecture/adr/V25-ADRS.md) | manual | Decisiones de arquitectura ADR-V25-xx que rigen la implementación. |
| [`docs/testing/V25_TEST_MATRIX.md`](../testing/V25_TEST_MATRIX.md) | manual | Matriz de pruebas T01-T17 desglosada en casos, con pruebas existentes y estado. |

## Documentos generados desde la auditoría

| Documento | Tipo | Contenido |
| --- | --- | --- |
| [`V25_SOURCE_TRACEABILITY.md`](V25_SOURCE_TRACEABILITY.md) | generado | Trazabilidad de 841 requisitos con las columnas de V2.5 §0.2, tabla de fuentes y una sección por módulo. |
| [`V25_GAP_ANALYSIS.md`](V25_GAP_ANALYSIS.md) | generado | Brechas IMPLEMENTADO / PARCIAL / PENDIENTE / BLOQUEADO / NO APLICA, verificación adversarial, defectos, conflictos y bloqueos del propietario. |
| [`V25_VERSION_DIFF.md`](V25_VERSION_DIFF.md) | generado | Cotejo literal de V1, V2 y V2C con etiquetas de V2.5 §28, requisitos no mencionados por V2.5 y versiones sin texto fuente. |
| [`V25_RISK_REGISTER.md`](V25_RISK_REGISTER.md) | generado | Registro de riesgos R-001... con severidad, probabilidad, mitigación, paquete del plan, responsable y estado. |
| [`README.md`](README.md) | generado | Este índice. |
| [`V25_RISK_IDS.json`](V25_RISK_IDS.json) | generado | Libro de IDs de riesgo (clave → R-nnn) que mantiene estables los identificadores al regenerar. |
