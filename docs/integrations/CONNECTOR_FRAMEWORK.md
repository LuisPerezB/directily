# Marco de conectores de Predictily

> V2.5 · W6-B (ADR-V2-13, ADR-V25-04, ADR-V25-10, ADR-V25-24). Marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba), **IMPLEMENTADO / SIN VERIFICAR**, **DISEÑADO / NO IMPLEMENTADO**,
> **BLOQUEADO**, **NO APLICA**. Ejemplos y datos de prueba **ficticios**; ningún conector se ha probado contra el ERP de un cliente. La matriz por motor y ERP está en `ERP_COMPATIBILITY.md`.

## 1. Idea central

Predictily **no depende** de que el sistema de origen tenga una API pública. Hay tres formas de traer datos, y las tres terminan en el **mismo camino**:

```
origen ──► (Bronze: original íntegro e inmutable, SHA-256, huella de esquema, procedencia) ──► normalización ──► importador canónico ──► Silver (ventas, productos, inventario…)
```

Ninguna ruta escribe datos de negocio saltándose el importador (validación, idempotencia por clave natural + `source_hash`, procedencia `data_source_id`, linaje `bronze_object_id`). Ningún conector
**escribe en el origen** (Predictily no escribe en el ERP ni en P0/P1 ni después) y ninguno activa CDC.

| Patrón | Quién inicia | Conectores | Original en Bronze (`bronze_objects.kind`) |
|---|---|---|---|
| **Archivo** | la persona sube un CSV/XLSX | `generic_csv`, `generic_excel` | `file_upload` |
| **Empuje por API** | el sistema del cliente envía lotes (`POST /sales/batch` con token de fuente) | `generic_rest` | `api_batch` |
| **Lectura programada por el servidor** | Predictily consulta la base del cliente (SELECT autorizado, solo lectura) | `sql_postgresql`, `sql_mysql`, `sql_sqlserver` | `sql_extract` |
| **Agente en la red del cliente** | el agente envía (HTTPS saliente, sin puertos entrantes) | `predictily_bridge` | `bridge_batch` |

## 2. Catálogo y estado real

`GET /api/v1/connectors` devuelve, por conector, `status` (`available`, `requires_credentials`, `implemented_untested`, `planned`) y `verification` (nivel de comprobación real; **nunca se infiere de `status`**).

| Clave | Estado (`status`) | Comprobación (`verification`) | Evidencia |
|---|---|---|---|
| `generic_csv`, `generic_excel` | available | tested | pruebas de importación |
| `generic_rest` | available | tested | pruebas de `POST /sales/batch` |
| `sql_postgresql` | requires_credentials | **tested_with_test_database** | `SqlSourceSyncTest` (base ficticia, no ERP de cliente) |
| `sql_mysql`, `sql_sqlserver` | **implemented_untested** | implemented_untested | `SqlConnectorContractTest` (contrato; sin servidor) |
| **`predictily_bridge`** (W6-B) | requires_credentials (la fuente nace `inactive` hasta registrar su primer dispositivo) | **tested_with_test_database** | `BridgeAgentEndToEndTest` (agente real + PostgreSQL ficticio); PostgreSQL, SQLite y archivos probados; MySQL y SQL Server del agente sin servidor |
| `contifico` | requires_credentials | not_verified | sin credenciales del proveedor |
| `generic_odbc` | planned | planned | no implementado (la lectura de ERP locales la cubre el Bridge, no este conector) |

## 3. Contrato de un conector del servidor

`App\Services\Connectors\ConnectorInterface` (registro: `ConnectorRegistry`): `key()`, `name()`, `description()`, `status()`, `verification()`, `entityTypes()`, `credentialKeys()` y `test(DataSource)` (sin efectos
secundarios). Para agregar uno: implementar la interfaz (extender `AbstractConnector`), registrarlo en `ConnectorRegistry`, declarar su `status` y `verification` **honestos**, y entregar los datos al importador
(`CanonicalImportRunner`) con el original en Bronze (`BronzeStore`). Reglas:

- **Credenciales** cifradas (`data_sources.credentials_encrypted`), nunca devueltas ni registradas; los mensajes de error pasan por `SqlSourceConnection::safeMessage`.
- **Fuentes SQL leídas por el servidor** (`AbstractSqlConnector`): política de destino anti-SSRF (`SqlHostPolicy`: resuelve A/AAAA, valida todas las respuestas y **fija la IP**; metadatos de nube, link-local y multicast
  siempre denegados; redes privadas solo con lista blanca), consulta autorizada por `SqlQueryGuard` (una sola sentencia SELECT, sin escritura ni objetos de sistema), transacción de solo lectura donde el motor la ofrece
  y **mapeo aprobado antes de sincronizar** (`source_mappings`: una versión nueva nace sin aprobar). Marca de agua **acotada al inicio de la sincronización** (`SourceSyncService`), solape configurable.
- **Un lector de fuentes SQL no puede alcanzar un ERP en la red privada del cliente** (la política SSRF lo impide por diseño). Para esos casos existe el **Bridge**.

## 4. Predictily Bridge como conector

`BridgeConnector` (`predictily_bridge`) representa en el servidor a los agentes de una fuente. No guarda host ni credenciales del ERP. Ciclo de vida de la fuente: nace `inactive` con el mensaje «genere un código de
registro»; el primer dispositivo registrado la activa; al revocar el último dispositivo vuelve a `inactive`. `test()` informa dispositivos activos y en línea.

### 4.1 Contrato canónico del lote
Un lote es **NDJSON UTF-8 comprimido con gzip** (`application/gzip`, extensión `ndjson.gz`); cada línea es un objeto JSON cuyas claves son **campos canónicos** del importador de la entidad (`ImportFieldRegistry`;
p. ej. ventas: `document_number`, `issued_at`, `product_sku`, `quantity`, `unit_price`, … `source_recorded_at`). El agente renombra con alias de columna en su plantilla SELECT; el servidor rechaza columnas desconocidas
(`unknown_columns`) y la falta de campos obligatorios (`missing_required_columns`) **al declarar el lote**. Tipos: números como número JSON o texto exacto (decimales como texto: `"8.5000"`), fechas-hora sin zona como
`AAAA-MM-DD HH:MM:SS` en la hora local del origen (el servidor las interpreta con `source_timezone`: del lote, de `data_sources.config.bridge` o de la empresa), fechas-hora con zona con su desplazamiento, `null` = sin valor.
El gzip es **determinista** (mtime 0): el mismo contenido tiene siempre el mismo SHA-256, base de la idempotencia.

Entidades que un dispositivo puede entregar (`config/bridge.php`): `sales`, `products`, `inventory`, `commitments`, `branches`, `suppliers`, `lead_times`, `promotions`, `calendar_events`, `unit_conversions`, `warehouses`.
**Solo `sales` está verificada de punta a punta** (E2E); `products` está verificada por `BridgeUploadTest`; las demás pasan por el mismo importador pero **no tienen prueba propia del Bridge** (IMPLEMENTADO / SIN VERIFICAR por entidad).

### 4.2 Contrato de un adaptador del agente
`bridge/src/predictily_bridge/adapters/base.py`: `open()`, `close()`, `describe()` (versión, modo de solo lectura, cuenta con privilegios de escritura, advertencias), `start_run(plantilla, conocidos)` y
`fetch_page(plantilla, since, after, limit, contexto) → Page(rows, cursor_after, exhausted)`. Las filas llegan en el **orden total** de la plantilla (`order_by`), lo que permite el cursor por llave (reanudar sin repetir ni
dejar huecos). Un adaptador SQL hereda de `SqlAdapter` y solo aporta conexión, citado de identificadores, límite de filas, tipo del parámetro `since` y su modo de solo lectura; **no** tiene ruta para ejecutar texto SQL
que no haya pasado por el guardia (`ReadOnlyViolation`). Adaptadores: PostgreSQL, SQLite, CSV, Excel (verificados); MySQL, SQL Server (sin verificar).

### 4.3 Reglas de extracción incremental (iguales a `SourceSyncService` donde aplican)
- Marca de agua = `min(máx. fecha de registro legible, inicio de la extracción)`; una fecha de registro **más de 5 minutos futura** no la adelanta (se informa como anomalía); **nunca retrocede** (el servidor lo comprueba: 409 `watermark_regression`) ni queda en el futuro (422 `watermark_in_future`).
- Cada extracción relee `overlap_minutes` antes de la marca (cambios en curso); una extracción sin filas con fecha conserva la marca previa; sin `recorded_at_column` cada corrida es una foto completa y la marca es su inicio.
- Cada commit lleva `run_id`, la posición `run_batch_index` del lote y, al completar, `run_batches`: el servidor lleva un **libro por extracción** (`bridge_run_batches`) y solo mueve la marca de agua si los lotes son exactamente 1…N, sin huecos ni fallidos; rechaza el checkpoint de un lote fallido o en cuarentena (409 `batch_failed`). Si el libro no coincide con el del agente, este descarta la corrida y vuelve a extraer desde lo confirmado.
- Un checkpoint intermedio guarda el cursor (≤ 200 caracteres; si las llaves de orden lo exceden, el agente falla con un mensaje claro). El servidor es la **verdad de lo confirmado**: si el agente pierde su estado, reanuda desde el cursor del servidor; si un
  administrador reinicia la entidad, vuelve a extraer completo (los lotes iguales ya están en Bronze: no se duplican).
- Modo de ingestión de las ventas (ADR-V25-23): la primera extracción completa de la fuente se procesa como `backfill`; las demás, `incremental`.

### 4.4 Procesamiento en el servidor y estados
`bronze_objects.metadata.bridge.processing.status`: `queued` → `running` → `completed` | `needs_review` | `failed` (un lote cuyo contenido incumple el contrato queda en `quarantined`; el trabajo `ProcessBridgeBatch` es único por lote). `completed` marca el objeto `processed`. `needs_review` (hay filas con error y la fuente no tiene `skip_invalid_rows`) deja el
original a salvo y espera a una persona (`/imports/{id}/errors`). `failed` registra `error_code` y se reintenta cuando el agente reenvía el mismo lote. El reprocesamiento determinista desde Bronze (`BronzeReprocessor`) cubre `bridge_batch`
y se detiene con 409 `reprocess_would_overwrite_newer` si sobrescribiría una versión más nueva. **No se infiere cobertura de fuente** desde el Bridge (se declara con la API de cobertura).

## 5. Seguridad transversal

| Riesgo | Control | Estado |
|---|---|---|
| Escritura accidental o maliciosa en el origen | guardia SELECT (puerto de `SqlQueryGuard`) + sesión de solo lectura del motor + cuenta `GRANT SELECT` + conjunto cerrado de sentencias de infraestructura | VERIFICADO (PostgreSQL, SQLite); SIN VERIFICAR (MySQL, SQL Server) |
| Credenciales del cliente en Predictily | el Bridge no las recibe; los conectores SQL del servidor las guardan cifradas | VERIFICADO |
| SSRF desde el servidor | `SqlHostPolicy` (conectores SQL); el Bridge no hace conexiones salientes del servidor | VERIFICADO (`SqlSourceHostPolicyApiTest`); NO APLICA al Bridge |
| Dispositivo robado o clonado | token con hash + firma Ed25519 + revocación inmediata + borrado del búfer | VERIFICADO |
| Repetición de peticiones | marca de tiempo ±5 min + nonce de un solo uso guardado en la **base de datos** (`bridge_request_nonces`; no depende de la caché ni de su durabilidad) | VERIFICADO (`test_replay_protection_survives_a_cache_flush`) |
| Contenido malicioso en un lote (columnas o PII ocultas tras la primera línea, valores anidados, bombas de compresión) | el lote se valida COMPLETO en flujo antes de guardar nada en Bronze: gzip real, claves ⊆ columnas declaradas, valores escalares acotados, filas = declaradas, filas/bytes/relación de descompresión; cuarentena si algo inválido llegara a Bronze | VERIFICADO (`BridgeHardeningTest`) |
| Checkpoint sin prueba | el servidor exige lote presente y no fallido, libro de lotes 1…N sin huecos por extracción y marca de agua acotada | VERIFICADO (`BridgeCheckpointTest`) |
| Abuso de tasa y de volumen | límite por IP antes de autenticar (rotar tokens no lo evade) + por credencial + cuota diaria por empresa | VERIFICADO (`BridgeHardeningTest`); cuota por dispositivo: DISEÑADA |
| Fuga entre empresas | empresa y fuente salen del dispositivo; Bronze/checkpoints por empresa; FK compuestas | VERIFICADO |
| Secretos en registros | filtro de registro del agente; mensajes de error acotados | VERIFICADO |

## 6. Lo que falta (honesto)

Sin servidor de pruebas para MySQL ni SQL Server; sin ERP real de ningún cliente; instalador y servicio de Windows **BLOQUEADOS** (`BRIDGE_WINDOWS.md`); descarga de mapeos aprobados / vínculo con `mapping_templates` (W6-C) **DISEÑADO**;
umbrales (tamaños de lote, solape, vigencias, límites de tasa) son valores iniciales **a aprobar en el piloto**, no universales.
