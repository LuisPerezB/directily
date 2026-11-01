# Contratos y guiones de orquestación del arquitecto

Esta carpeta guarda contratos y guiones de orquestación preparados por el arquitecto, para que no se pierdan si el
entorno de trabajo se recicla y para que cualquier sesión pueda continuar. Una vez activado, la copia de esta carpeta
queda como registro histórico: la versión vigente es la de su ubicación definitiva.

| Carpeta | Contenido | Estado |
|---|---|---|
| `w1/workflow/v25-w1-p0-fixes.js` | Guion del workflow de W1 (correcciones P0: A1 escritura temporal de ventas, A2 lectura as-of del motor, B seguridad) con implementación, revisión adversarial y corrección por paquete | **Ejecutado y cerrado** el 1-nov-2026 (revisión adversarial y corrección de los tres paquetes; ver `V25-ESTADO-Y-PROXIMOS-PASOS.md`) |
| `w2/migrations/` | `2026_03_03_000100_create_data_quality_lineage_and_identity_tables.php` (ADR-V25-24) y `2026_03_04_000100_create_factor_intelligence_and_taxonomy_contracts.php` (ADR-V25-25) | **Activadas** el 1-nov-2026 en `backend/database/migrations/` (subida, reversión y subida verificadas; Pint aplicado a la copia activa) |
| `w2/backend/` | Interfaz `BronzeStore`, implementación mínima `FilesystemBronzeStore`, modelo `BronzeObject`, `config/bronze.php` y `CanonicalContracts` (versiones) | **Activados** en `backend/` (`BronzeStore` → `FilesystemBronzeStore` en `AppServiceProvider`; tablas nuevas clasificadas en `TenantDataExporter`) |
| `w2/docs/data/DATA_QUALITY_ENGINE.md` | Esqueleto del documento con secciones asignadas a W2-A y W2-C | **Activado** en `docs/data/DATA_QUALITY_ENGINE.md` |
| `w2/workflow/v25-w2.js` | Guion del workflow de W2 (seis paquetes con archivos asignados) | En ejecución (ver `V25-ESTADO-Y-PROXIMOS-PASOS.md`) |

## Por qué el contrato de W2 esperó al cierre de W1

Mientras W1 corre, sus agentes migran bases de prueba propias con `migrate:fresh`. Poner ahora las migraciones
de W2 en `backend/database/migrations/` cambiaría su esquema a mitad de trabajo; además, la prueba
`TenantDataExporterStage2Test::test_every_tenant_table_is_classified_as_exported_or_deliberately_excluded`
falla en cuanto aparece una tabla nueva sin clasificar para la exportación LOPDP.

## Cómo activar W2 (después de integrar W1)

1. Confirmar que no queda ningún agente de W1 en ejecución y que W1 está integrado (sección 5 del documento de
   estado).
2. Copiar a su ubicación definitiva, conservando rutas relativas:
   - `w2/migrations/*.php` → `backend/database/migrations/`
   - `w2/backend/**` → `backend/**`
   - `w2/docs/data/DATA_QUALITY_ENGINE.md` → `docs/data/DATA_QUALITY_ENGINE.md` (solo si no existe)
3. Registrar en el contenedor de Laravel `App\Services\DataCore\Bronze\BronzeStore` →
   `FilesystemBronzeStore` (proveedor de servicios).
4. Clasificar las tablas nuevas en `backend/app/Services/Privacy/TenantDataExporter.php` (exportadas o
   excluidas deliberadamente; los catálogos públicos con `tenant_id` NULL no se exportan como datos de la
   empresa).
5. Verificar: `cd backend && DB_DATABASE=<bd_desechable> php artisan migrate:fresh --force`, rollback de los dos
   pasos y suite completa del backend en verde.
6. Confirmar y subir el contrato activado; lanzar el workflow `w2/workflow/v25-w2.js` pasando
   `args = { base: "<commit del contrato activado>" }` para que los revisores comparen contra esa base.

## Contratos de W3 a W6

| Carpeta | Contenido | Estado |
|---|---|---|
| `w3/` | Migración `2026_03_11_000100_add_ml_quality_promotion_and_shadow_contracts.php` y guion `v25-w3.js` (4 paquetes) | **Activado** el 1-nov-2026 (migración en `backend/database/migrations/`; tablas nuevas y columnas de `customer_predictions` clasificadas en la exportación LOPDP; contrato canónico `forecast@2026.03.2`) |
| `w4/` | Migración `2026_03_12_000100_add_model_lifecycle_publication_and_usage_metering.php` y guion `v25-w4.js` (3 paquetes) | **Activado** el 1-nov-2026 (CHECK de estados del registro, tablas nuevas exportadas sin `published_object_key`, contrato canónico `model_version@2026.03.2`) |
| `w5/` | Migración `2026_03_13_000100_create_decision_radar_outcome_and_supply_contracts.php` y guion `v25-w5.js` (5 paquetes) | Preparado, no activado |
| `w6/` | Migración `2026_03_14_000100_create_enterprise_security_bridge_and_gateway_contracts.php` y guion `v25-w6.js` (6 paquetes) | Preparado, no activado |

Activación de cada oleada (igual que W2): copiar la migración, clasificar las tablas nuevas en `TenantDataExporter`,
subir la versión de los contratos canónicos afectados (`CanonicalContractsTest`), verificar subida/reversión/subida y
la suite del backend, confirmar y lanzar el guion con `args.base` = commit de activación.

