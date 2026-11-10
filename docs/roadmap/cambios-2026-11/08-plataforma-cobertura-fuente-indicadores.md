# 08 · Plataforma: cobertura inferida real, fuente que se reactiva, signo del neto, indicadores anclados al corte y driver SQL Server

**Área:** backend + imagen Docker + runbook · **Prioridad:** alta · **Esfuerzo:** bajo (cada punto es pequeño).

## 8.1 La cobertura inferida por el conector no mira los datos

**Problema.** Tras una sincronización limpia, el conector declara la fuente «completa hasta el inicio de la sincronización menos un
día». Si la fuente es una copia congelada o su ETL está detenido, Predictily cree que hay cobertura (y por tanto ceros confirmados)
durante meses sin datos. En ambos pilotos la cobertura decía «completa hasta noviembre de 2026» con datos que terminaban en mayo
y en marzo.

**Código.** `backend/app/Services/DataCore/Coverage/CoverageService.php:70-98` (`recordConnectorSync`), llamado desde
`SourceSyncService.php:307-310` (`afterSuccess`). El motor la usa en `demand/coverage.py::SalesCoverage.resolve/limit_cutoff`
(96-123) y `pipeline/forecast_run.py:173-176`.

**Qué cambiar.**
1. `recordConnectorSync` recibe también la fecha máxima de evento extraída (`max(issued_at)`) y de registro (`max(source_recorded_at)`),
   que `CanonicalStager` ya conoce (`StagingResult.maxRecordedAt`, `minEventDate`; añadir `maxEventDate`).
2. `complete_through = min(inicio de la sincronización − lag, max(issued_at) + lag_tolerance)` con `lag_tolerance` por defecto 1 día:
   si la extracción no trae ventas de los últimos días, la cobertura termina donde terminan los datos.
3. Si `max(issued_at)` está a más de `stale_days` (por defecto 7) del inicio de la sincronización, marcar la fuente con
   `last_error`/aviso «la fuente no entrega datos desde …» y emitir un hallazgo de calidad `source_stale` (`IngestionQuality`).
4. Prueba: `backend/tests/Feature/DataCore/SqlSourceSyncTest.php:381-403` ampliar con una extracción cuyo último evento es 30 días
   anterior: `complete_through` = último evento + 1 y aviso `source_stale`.

## 8.2 Una fuente creada con la prueba fallida queda inactiva para siempre

**Problema.** Al crear la fuente, si la prueba de conexión falla queda `inactive`; una prueba posterior correcta (`POST
/data-sources/{id}/test`) no la reactiva, y la sincronización responde `source_inactive`.

**Código.** `DataSourceController.php:47-75` (`store`, estado en 57 y 64-65), `135-141` (`test`: solo devuelve el resultado).

**Qué cambiar.** En `test`, si el conector responde `ok` y la fuente está `inactive` por un `last_error` de conexión (no por
decisión del usuario: añadir `deactivated_by_user` o distinguir con `status_reason`), pasar a `active` y limpiar `last_error`;
registrar `datasource.reactivated` en auditoría. Prueba en `SqlSourceHostPolicyApiTest.php:52-61` o nueva.

## 8.3 El importador invierte el signo de un neto negativo

**Problema.** Una línea con descuento mayor que el subtotal (neto negativo) se guarda con neto e IVA positivos aunque el aviso
`net_amount_mismatch` dice que se conserva el valor del archivo.

**Código.** `SalesLineNormalizer.php:245-258` (`abs(valor)` en 251), impuesto en valor absoluto 193-202.

**Qué cambiar.** Conservar el signo del neto y del impuesto cuando la línea no es devolución; emitir el aviso `negative_net` y
excluir la línea del precio medio (precio no definido). Prueba en `SalesImportTest.php:117-138`.

## 8.4 Indicadores de «90 días» anclados a hoy

**Problema.** `units_90d`, `revenue_90d` y `last_sale_date` se calculan desde `now()`; con una copia de datos o una fuente detenida
salen en 0 o «—» y se leen como «no vende».

**Código.** `backend/app/Services/Catalog/ProductStatsService.php:15-39` (`windowStart = now − 90`), `SaleController.php:89-90`.

**Qué cambiar.** Anclar la ventana a `min(hoy, fecha de corte de datos de la empresa)` (= `complete_through` efectivo o última venta),
mostrar en la SPA «últimos 90 días hasta <fecha>» y, si la fecha está a más de 7 días de hoy, un aviso «datos hasta <fecha>». Lo
mismo para «riesgo de quiebre 0» y «compra sugerida $0»: distinguir «sin datos» de «0» (`null` + texto «sin inventario cargado»).

## 8.5 Driver de SQL Server en la imagen Docker del backend

**Problema.** La imagen `predictily/backend` no trae `pdo_sqlsrv` ni el ODBC Driver 18: la sincronización con SQL Server solo
funciona con el backend nativo.

**Qué cambiar.** `backend/Dockerfile`: instalar `msodbcsql18` + `unixodbc-dev` y `pecl install sqlsrv pdo_sqlsrv`; añadir `pdo_sqlsrv`
a la comprobación de extensiones (línea 39) y `SqlServerSqlConnector::status()` a `available` cuando la extensión existe. Probar
con `TEST_SQLSRV_*` (`tests/Unit/DataCore/SqlConnectorContractTest.php`).

## 8.6 Runbook: `ANALYZE` tras restaurar y copias de base

Añadir a `docs/operations/PILOT_RUNBOOK.md`: tras `pg_restore`, ejecutar `vacuumdb --analyze-only`; el dashboard tardaba > 15 s por
falta de estadísticas. Documentar también que una copia de datos congelada requiere declarar la cobertura a mano (8.1 lo
automatiza).

## Criterio de aceptación (conjunto)

- Con una fuente cuyo último dato es de hace meses, la cobertura efectiva termina en ese dato, el dashboard dice «datos hasta
  <fecha>» y la fuente muestra el aviso de fuente detenida.
- Una fuente con conexión recuperada se reactiva con la prueba, sin `PATCH` manual.
- La imagen Docker sincroniza contra SQL Server igual que el backend nativo.
