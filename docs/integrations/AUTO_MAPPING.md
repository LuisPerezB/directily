# Schema discovery y Auto Mapper (descubrimiento de esquema, propuesta de mapeo y plantillas)

Paquete W6-C · V2.5 §4 («Auto Mapper»), §17 (onboarding repetible) y §22 (checklist del propietario) · ADR-V25-10 (el Bridge y los conectores solo leen).
Código: `backend/app/Services/DataCore/Mapping/` (`Discovery/`, `Heuristics/`, `Templates/`, `AutoMapperService.php`), modelo `backend/app/Models/MappingTemplate.php`,
controladores `SchemaDiscoveryController`, `MappingTemplateController` y `PlatformMappingTemplateController`, rutas `backend/routes/api/mapping.php`, configuración `backend/config/mapping.php`.
Tablas: `mapping_templates` (contrato del arquitecto `2026_03_14_000100`, sin modificar) más la migración aditiva `2026_03_14_000400` de este paquete (§6.1).
API: bloque «W6-C» de `docs/openapi/v2.yaml`. Onboarding paso a paso: [`ENTERPRISE_ONBOARDING.md`](ENTERPRISE_ONBOARDING.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba que lo demuestra) · **IMPLEMENTADO / SIN SERVIDOR** (código y pruebas con catálogos de ejemplo; sin servidor real del motor) ·
**DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** (no se puede construir o verificar en este entorno) · **NO APLICA**.

## 1. Qué es y qué garantiza

Una forma repetible de pasar de «tenemos acceso de solo lectura a la base del ERP del cliente» a «hay un mapeo revisado y aprobado que el importador de siempre ejecuta», sin escribir
una consulta a mano por cliente:

```
descubrir esquema ──► proponer (determinista) ──► corregir (humano) ──► aprobar (humano) ──► aplicar ──► sincronizar (acción aparte)
  metadatos            puntaje heurístico            PATCH                  settings.manage      crea el mapeo SQL     el pipeline de siempre
```

Garantías (cada una con su prueba en §11):

1. **Solo lee metadatos del catálogo**, con consultas fijas por motor que no reciben texto del usuario ni del origen y no pasan por `SqlQueryGuard` (ver §3).
2. **Determinista y sin LLM**: el mismo esquema produce la misma propuesta, también si el catálogo llega en otro orden.
3. **La confianza es un puntaje heurístico, no una probabilidad** (§4.2). Lo ambiguo o de baja confianza queda **sin mapear** y marcado para revisión.
4. **Nunca aprueba, aplica ni sincroniza por sí solo.** Aprobar es una acción humana con habilidad (`settings.manage`) y queda auditada.
5. **No hay un segundo importador**: una plantilla aprobada produce un mapeo SQL (`source_mappings`) que consume `SourceSyncService` → `CanonicalImportRunner` (§8).
6. **Muestras mínimas, con garantías acotadas** (§5): las columnas con nombre de dato personal no se mapean ni se leen; de las columnas de vocabulario solo salen valores que pasan una lista permitida de forma de código, que se repiten en 5+ filas y que no son datos sueltos; los registros y la auditoría llevan solo conteos. **No garantiza** que una columna de vocabulario cuyo nombre no delata nada nunca contenga un dato personal con forma de código corto repetido 5 o más veces (§5, límites).
7. **Versionado y deriva**: cada plantilla lleva la huella de las columnas que usa; si el esquema cambia, no se aplica y vuelve a revisión (§7, §9).

## 2. Estado por capacidad

| Capacidad | Estado | Prueba que lo demuestra |
|---|---|---|
| Descubrimiento PostgreSQL (tablas, vistas, columnas, PK, FK, filas estimadas) | **IMPLEMENTADO / VERIFICADO** contra una base PostgreSQL de pruebas | `AutoMapperEndToEndTest::test_discovery_returns_metadata_only` |
| Descubrimiento MySQL/MariaDB y SQL Server | **IMPLEMENTADO / SIN SERVIDOR**: consultas de catálogo y clasificación de tipos verificadas con catálogos de ejemplo; sin servidor real ni driver en este entorno | `CatalogContractTest` (consultas fijas, `READ UNCOMMITTED`, tipos nativos), `SourceQueryAndFingerprintTest` |
| Consultas de catálogo fijas, solo SELECT, aisladas de `SqlQueryGuard` | **IMPLEMENTADO / VERIFICADO** | `CatalogContractTest::test_catalog_queries_are_fixed_select_only_and_take_no_input`, `::test_catalog_queries_are_isolated_from_the_user_query_guard` |
| Propuesta determinista por entidad (ventas, productos, inventario, proveedores, plazos, sucursales) | **IMPLEMENTADO / VERIFICADO** sobre esquemas sintéticos variados (español, inglés, abreviaturas heredadas, vista plana, nombres hostiles) | `EntityProposerTest` |
| Clientes | **NO APLICA como entidad propia** (no hay importador `customers`; los clientes nacen de las ventas). Los atributos del cliente (código, segmento, provincia) se proponen dentro de ventas, unidos por clave foránea | `EntityProposerTest::test_spanish_erp_with_header_detail_product_and_customer_joins` |
| Compras | Proveedores y plazos de abastecimiento (`suppliers`, `lead_times`): **IMPLEMENTADO / VERIFICADO**. Órdenes de compra (`PurchaseOrder`): **DISEÑADO / NO IMPLEMENTADO** (sin importador) | `AutoMapperEndToEndTest::test_the_full_flow_imports_the_expected_canonical_rows` |
| Puntaje heurístico declarado como tal, nunca 1,00 | **IMPLEMENTADO / VERIFICADO** | `EntityProposerTest::test_confidence_is_declared_as_a_heuristic_score_and_never_certainty` |
| Mapeos de valores de tipo y estado de documento | **IMPLEMENTADO / VERIFICADO** | `ValueMapProposerTest`, `AutoMapperEndToEndTest` |
| Muestras acotadas con lista permitida de forma, frecuencia mínima y límite de lo guardado; columnas con nombre personal ni mapeadas ni leídas | **IMPLEMENTADO / VERIFICADO** (con los límites de §5) | `MappingPrivacyTest`, `SampleMaskingTest`, `EntityProposerTest::test_the_profiler_is_never_asked_to_read_personal_data_columns` |
| Aprobación humana con habilidad, auditada; nada sincroniza sin aprobar | **IMPLEMENTADO / VERIFICADO** | `MappingTemplateAccessTest` |
| Plantillas públicas (solo plataforma) y de empresa; bifurcar | **IMPLEMENTADO / VERIFICADO** | `PublicMappingTemplatesTest` |
| Versionado por clave, huella del esquema y deriva que invalida la plantilla | **IMPLEMENTADO / VERIFICADO** | `MappingSchemaDriftTest`, `SourceQueryAndFingerprintTest`, `MappingTemplateAccessTest::test_database_constraints_keep_versions_unique_and_one_approved_per_key` |
| Aislamiento entre empresas | **IMPLEMENTADO / VERIFICADO** | `MappingTemplateAccessTest::test_other_companies_cannot_see_or_touch_templates_sources_or_proposals` |
| Descubrimiento → propuesta → aprobación → importación de filas canónicas (ERP ficticio PostgreSQL) | **IMPLEMENTADO / VERIFICADO** | `AutoMapperEndToEndTest::test_the_full_flow_imports_the_expected_canonical_rows` |
| Ajuste de umbrales y pesos con mapeos reales de clientes | **DISEÑADO / NO IMPLEMENTADO**: sin mapeos reales para medir; los valores son provisionales, **a aprobar en el piloto** (§4.2) | — |
| LLM opcional para sugerir equivalencias (§4: «solo con metadatos y herramientas permitidas») | **DISEÑADO / NO IMPLEMENTADO**: el Auto Mapper no usa ningún LLM; sin clave de proveedor nada cambia | — |
| Bridge Windows como origen del descubrimiento | **BLOQUEADO** (instalador y firma de código: sin host Windows ni certificado; ver `BRIDGE_WINDOWS.md` de W6-B) | — |
| Fuentes no SQL (CSV, Excel, REST, ODBC) | **NO APLICA**: el asistente de importación ya sugiere columnas de un archivo (`ColumnMatcher`); el descubrimiento de catálogo solo existe para conectores SQL | — |

## 3. Descubrimiento de esquema (solo lectura)

`SchemaDiscoveryService` abre la conexión con el conector de la fuente (`SqlConnectorInterface::open`), de modo que se aplican **las mismas reglas de siempre**: política SSRF
(`SqlHostPolicy`: lista blanca `PREDICTILY_SQL_ALLOWED_HOSTS`/`_CIDRS`, IP fijada, sin redes privadas por defecto), credenciales cifradas que nunca se devuelven, tiempo máximo de
sentencia y transacción de solo lectura (PostgreSQL `REPEATABLE READ READ ONLY`, MySQL `START TRANSACTION READ ONLY`). En SQL Server, donde no existe transacción de solo lectura, la
conexión usa `ApplicationIntent=ReadOnly` y `LOCK_TIMEOUT 5000`, y el descubrimiento añade `SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED` antes de leer el catálogo
(`SqlServerCatalog::sessionStatements()`), para no bloquear a los escritores del ERP: son lecturas de **metadatos**, no de datos de negocio.

Las consultas (`PostgresCatalog`, `MySqlCatalog`, `SqlServerCatalog`; la de SQL Server parte de `infra/scripts/pilot/sql_source_discovery.php`) son **constantes del código**:

- no reciben parámetros (la prueba lo verifica por reflexión), no contienen `;`, `$` ni marcadores, y empiezan por `SELECT` sin palabras de escritura;
- parten de `pg_catalog`/`information_schema`/`sys`: **`SqlQueryGuard` las rechazaría** (rechaza el catálogo del sistema en consultas escritas por personas) y por eso el descubrimiento
  no pasa por él. El aislamiento es por construcción: nada que escriba una persona o que venga del origen se concatena en ellas;
- devuelven tablas y vistas (con filas **estimadas** por la estadística del catálogo, nunca `COUNT(*)`), columnas con su tipo nativo, claves primarias y claves foráneas. PostgreSQL
  solo lista tablas con `SELECT` concedido (`has_table_privilege`).

El tipo nativo se reduce a una **clase de tipo** común (`TypeClass`: texto, entero, decimal, fecha, fecha-hora, booleano, binario, otro; p. ej. `tinyint(1)` de MySQL es booleano y `timestamp` de
SQL Server es `rowversion`, binario). Límites: 2000 tablas y 60 000 columnas por descubrimiento (`mapping.discovery`); si se superan, `truncated` y las operaciones que necesitan el esquema
completo (proponer, comparar la deriva) responden 422 `schema_too_large` en vez de decidir con medio catálogo. `POST /data-sources/{id}/schema/discover` devuelve solo metadatos
(con `include_columns` también las columnas de las primeras 300 tablas por filas estimadas). Una fuente que no es SQL responde 422; una que no se alcanza, 422 `source_unavailable` (mensaje
genérico, sin filtrar la red interna).

## 4. Propuesta determinista

### 4.1 Pasos

`EntityProposer::propose(entidad, esquema, perfilador?)`:

1. **Tokeniza** los nombres (`NameTokens`): camelCase/PascalCase, tildes y mayúsculas, separadores, artículos fuera, plural simple, abreviaturas de ERP en español (`cant`, `nro`,
   `fec`, `dscto`, `cod`, `suc`, `exist`…) y nombres pegados (`codproducto`, `fechaemision`) si el vocabulario conocido los cubre completos.
2. **Puntúa** cada columna contra cada campo canónico del importador (`ImportFieldRegistry`, el mismo léxico del asistente de importación más variantes de ERP) según §4.2.
3. **Descarta** las columnas con nombre de dato personal (§5) y las de nombre no seguro para una consulta (vacío, caracteres de control, barra invertida, más de 128 caracteres).
4. **Elige la tabla principal** (en ventas, la de líneas; es elegible si cubre TODOS los campos que identifican la entidad —ventas: SKU, cantidad y precio—, o si su nombre indica el rol y cubre
   al menos la mitad: una tabla de parámetros con una sola coincidencia suelta no se propone) y, **solo en ventas**, las tablas padre alcanzables hasta 3 saltos y 6 tablas por clave foránea
   declarada o, si no hay, **inferida** por el nombre de la clave primaria de una sola columna (se advierte «unión inferida: verifíquela»). Solo hacia el lado «uno» (línea → cabecera → cliente;
   línea → artículo): nunca se une una tabla hija, así que la consulta no multiplica filas. Dos claves hacia la misma tabla (bodega de origen y de destino) → no se une a ciegas.
5. **Asigna** columnas a campos (§4.3). Si hay perfilador, lee muestras acotadas (§5) y reasigna.
6. **Arma** el `source_spec` (tablas con alias `t0…`, uniones INNER si la tabla aporta un campo obligatorio y LEFT si no, columna de marca de agua) y la huella (§7).

### 4.2 El puntaje es heurístico, NO una probabilidad

`confidence.fields.<campo>.score` ∈ [0; 0,99] combina cuatro señales con pesos fijos; si no hubo muestra, esa señal se omite y los demás pesos se reescalan (la evidencia dice `sample:no_leida`):

| Señal | Peso | Cómo se calcula |
|---|---|---|
| Nombre | 0,55 | Coincidencia de tokens con un patrón del campo: exacta 1,00; exacta con una palabra genérica (`codigo`, `fecha`, `estado`…) 0,70; parcial con palabras de más 0,88 − 0,12 por cada palabra extra desde la segunda (mínimo 0,45); parcial con palabra genérica 0,40 − 0,08 por palabra extra (mínimo 0,15). Penalizaciones: ×0,35 si el nombre trae solo el concepto de otro campo (`codigo_cliente` como SKU); ×0,40 si trae un token que lo desmiente (`costo` en un precio, `vencimiento` en la fecha de emisión, `entregada` en la cantidad) |
| Tipo | 0,25 | Compatibilidad de la clase de tipo con el tipo del campo (p. ej. fecha: fecha 1,0 / fecha-hora 0,9–0,95 / texto 0,45 / entero 0,15; número: decimal o entero 1,0 / texto 0,4); incompatible = el candidato se descarta |
| Tabla | 0,10 | 1,0 si el nombre de la tabla indica el rol esperado (línea de ventas, cabecera, producto, inventario, proveedor, plazo, sucursal); 0,5 si no indica ninguno; si indica **otro** rol, la columna no es candidata |
| Muestra | 0,10 | Solo si se leyó: proporción de fechas, números o booleanos en una columna de texto; vocabulario corto en un campo categórico |

Se resta 0,02 por cada salto de unión (desempate hacia la tabla principal) y nunca se informa 1,00. **No hay calibración**: los pesos y los umbrales son juicio de ingeniería sin medir contra mapeos reales
de clientes; el puntaje ordena candidatos y explica (`evidence`), no estima la probabilidad de acertar.

Umbrales provisionales (`config/mapping.php`; **justificación y «a aprobar en el piloto»**, nunca universales):

| Umbral | Valor | Justificación |
|---|---|---|
| `min_confidence` | 0,60 | deja pasar una coincidencia exacta de sinónimo con tipo compatible (≥ 0,80) y una parcial con una palabra de más (≈ 0,66); deja fuera las que solo coinciden por una palabra genérica sin más evidencia |
| `ambiguity_margin` | 0,10 | si el segundo candidato queda a menos de este margen del primero, ninguno se elige |
| `high_band` | 0,80 | solo etiqueta informativa (`high`/`medium`) |

### 4.3 Lo que queda sin mapear, y por qué

Cada campo sin mapear aparece en `confidence.unmapped` con `reason`, si es obligatorio y los candidatos con su puntaje:

| `reason` | Significado | Ejemplo probado |
|---|---|---|
| `not_found` | ninguna columna lo cubre | `currency` en el ERP ficticio |
| `low_confidence` | el mejor candidato queda bajo el mínimo | columnas genéricas (`codigo`, `fecha`, `valor`) en una tabla sin rol |
| `ambiguous` | dos candidatos dentro del margen, o una columna disputada por dos campos | `precio1`/`precio2`; `Cantidad`/`cantidad` |
| `removed_by_user` | la persona lo quitó al corregir | `PATCH` |

Un campo de identidad con nombre vago (`cliente_ciudad`) tampoco se propone (§5). Una propuesta con campos obligatorios sin mapear es `complete: false` y **no se puede aprobar** (422 `template_incomplete` con `context.missing_required`). Ejemplos que el mapeador **no** inventa:
una tabla de inventario sin columna de fecha (queda `snapshot_date` pendiente), un `id_factura` interno como número de documento, el `total_venta` que incluye impuestos como importe neto.

**Datos históricos, no actuales (disponibilidad temporal):** en ventas, el precio y el costo unitarios solo pueden venir de la tabla de **líneas**; nunca de la tabla de productos (traería el precio o
costo de hoy a una venta pasada). La tabla de producto aporta solo nombre y categoría; la de cliente, solo segmento y provincia.

### 4.4 Qué se propone por entidad

| Entidad (importador) | Tabla principal | Uniones | Obligatorios |
|---|---|---|---|
| `sales` | líneas del documento (o vista plana) | cabecera, artículo y cliente (hasta 3 saltos) | `document_number`, `issued_at`, `product_sku`, `quantity`, `unit_price` |
| `products` | tabla de artículos | no | `sku`, `name` |
| `inventory` | existencias por fecha | no | `snapshot_date`, `branch_code`, `product_sku`, `on_hand` |
| `suppliers` | proveedores | no | `code`, `name` |
| `lead_times` | plazos de entrega | no | `lead_time_days` |
| `branches` | sucursales (por el nombre de la tabla) | no | `code`, `name` |

Limitaciones honestas: una propuesta por entidad y fuente (si las notas de crédito viven en otra tabla que las facturas, se mapea esa otra tabla con una vista o un mapeo SQL a mano); sin uniones
de uno a muchos; sin conversión de monedas ni de unidades; una columna de texto que mezcla formatos de fecha se confirma solo por proporción.

## 5. Muestras mínimas y enmascaramiento

Qué se lee y qué no (`ColumnSampler`, `EntityProposer::profile`, `SampleMasker`, `PiiColumns`):

- **Metadatos por defecto.** Con `sample: false` no se lee ni una fila (la prueba lo verifica); el tipo, el nombre y las claves bastan para una propuesta completa.
- **Columnas con nombre de dato personal NUNCA se mapean ni se leen**: correo, teléfono (también abreviado: `tlf`, `celu`, `whatsapp`), dirección de una persona, nombre o razón social de un cliente, fecha de
  nacimiento y edad (`fnac`, `fecha_nac`, `edad`), género/salud, tarjeta, IBAN, GPS, claves y tokens, y los textos libres sobre una persona (`observaciones`, `comentarios`). Se listan **solo por nombre y tipo** en
  `confidence.pii_columns_excluded`. Esto es una decisión **por nombre**: no depende de leer valores.
- **Identificación nacional (cédula, RUC de una persona)**: el Auto Mapper NO la propone como código de cliente (queda sin mapear con una advertencia que nombra la columna); una persona puede asignarla a mano
  (`PATCH`) y el importador la guarda solo como huella HMAC. Se propone el identificador interno (`id_cliente`). Sí se propone el RUC de un **proveedor** (persona jurídica), sin leerlo.
- **Un campo que identifica registros no se propone por un nombre vago**: código de cliente, SKU, sucursal, proveedor y número de documento exigen en el nombre un token de código o identificador (`codigo`, `id`,
  `numero`, `ruc`, `sku`…); `cliente_ciudad` o `producto_color` no lo son. El nombre exacto del concepto sin ese token (`cliente`, `sucursal`) se acepta con el puntaje **topado** en 0,70 (evidencia `tope:nombre_sin_codigo`).
  Con muestra, además, se descarta si la forma de los valores es la de correos, teléfonos con separadores, fechas o frases (proporciones; no sale ningún valor).
- **Qué se lee**: (a) de las columnas de texto candidatas a fecha/número/booleano, y de los campos que identifican registros, las primeras `pattern_rows` (200) filas; se calculan proporciones **en memoria** y los
  valores se descartan; (b) de las columnas de **tipo y estado de documento**, los valores distintos con su conteo (`GROUP BY`), solo si hay a lo sumo 25 (`distinct_cap`).
- **Qué sale** (`SampleMasker`, lista permitida): únicamente esos valores distintos, y solo si (1) están hechos de letras latinas (con tildes del español), dígitos y `_ . / -`, hasta 20 caracteres por palabra y
  a lo sumo 2 palabras (hasta 4 solo si el importador ya las reconoce como tipo o estado de documento, p. ej. `NOTA DE CREDITO`); (2) no tienen dos palabras en Mayúscula inicial (forma de nombre propio);
  (3) tienen menos de 7 dígitos en total (tarjetas, teléfonos y cédulas, con o sin separadores, quedan fuera); (4) no traen `@`, arroba de ancho completo, letras de otros alfabetos ni símbolos; y (5) aparecen
  en **al menos 5 filas** (`min_value_count`, k-anonimato simple: un valor único puede ser un dato suelto; se informa cuántos se omitieron en `suppressed_rare_values`). **Si un solo valor incumple (1)–(4),
  la columna deja de tratarse como vocabulario y no se devuelve NINGÚN valor** (`sin_vocabulario`). Más de 25 valores distintos: tampoco es un vocabulario. Lo que se guarda en la plantilla
  (`confidence.value_maps`: entradas, sin resolver, reconocidos) se limita a 25 valores por campo.
- **Límites que quedan** (honestos): un nombre de una sola palabra, un apodo o un código corto de otro tipo que se repita 5 o más veces dentro de una columna cuyo nombre sugiere «tipo» o «estado» pasa estos
  filtros (la forma de un código no distingue su significado); la frecuencia mínima dejará fuera estados reales poco frecuentes (los resuelve la persona al revisar: el importador los marca para revisión);
  el umbral de 5 filas es provisional, **a aprobar en el piloto**.
- **Tablas grandes y tiempo**: por encima de `distinct_scan_max_rows` (500 000 filas estimadas; antes 5 000 000) los valores distintos se leen solo del inicio de la tabla (`prefix_rows` = 50 000) y el perfil se marca `parcial`:
  pueden existir valores que no aparecen. Cada sentencia de muestra corre con un tiempo máximo propio (`statement_timeout_seconds`, 5 s; en PostgreSQL nunca se sube el del conector; MySQL y SQL Server: sentencias de sesión
  implementadas sin servidor de prueba): una muestra lenta se omite y la propuesta sigue. Estos topes son provisionales, **a aprobar en el piloto**.
- **Cómo se lee**: cada consulta de muestra la genera Predictily con identificadores del catálogo entrecomillados (`IdentifierQuoter`; un identificador con caracteres de control, barra invertida o más de 128
  caracteres se rechaza, no se «sanea»), **pasa por `SqlQueryGuard`** como cualquier consulta de solo lectura (un nombre que el guard rechaza, como `sp_estado`, no se muestrea) y corre en la transacción de solo
  lectura de la conexión. En PostgreSQL cada muestra va en un `SAVEPOINT` para que un error (permiso, tiempo) no aborte la transacción; la columna sin muestra queda sin perfil y la propuesta sigue.
- **Registros y auditoría**: solo conteos (tablas, columnas, entidades, milisegundos). Las huellas de esquema se auditan por prefijo de 16 caracteres (la bitácora enmascara cadenas hexadecimales largas).

## 6. Plantillas

### 6.1 Modelo de datos

`mapping_templates` (contrato `2026_03_14_000100`): `tenant_id` (NULL = plantilla **pública** de la plataforma), `system_kind`, `vendor`, `entity`, `version`, `schema_fingerprint`, `field_map`, `value_maps`,
`confidence`, `status` (`proposed`, `approved`, `retired`), `approved_by`, `approved_at`. La migración aditiva **`2026_03_14_000400`** (prefijo asignado a este paquete) agrega, con justificación:

| Columna / restricción | Para qué |
|---|---|
| `source_spec` (jsonb) | la estructura a la que apunta `field_map`: dialecto, tablas con alias y columnas (nombre → clase de tipo), uniones y columna de marca de agua. `field_map` guarda `campo → alias.columna` o `{"const": valor}` (el contrato dejó «expresión de origen») |
| `data_source_id` (FK compuesta con `tenant_id`) | fuente de la que nació una plantilla de empresa (NULL en las públicas); `CHECK` impide una pública con fuente |
| `forked_from_id` | la pública de la que se bifurcó |
| `created_by`, `drift`, `invalidated_at` | quién la propuso; informe de la deriva que la invalidó |
| `CHECK` de `status` | vocabulario cerrado |
| Índices únicos parciales de versión (empresa / pública) con `COALESCE(vendor, '')` | el `UNIQUE` del contrato no protege cuando `tenant_id` o `vendor` son NULL (en PostgreSQL los NULL son distintos): permitía versiones duplicadas |
| Índice único parcial «una aprobada» por (empresa o plataforma, sistema, proveedor, entidad, fuente) | aprobar una versión nueva retira la anterior sin carreras |

Se actualizó la lista de columnas de `mapping_templates` en `TenantDataExporter` (las plantillas de la empresa se exportan; las públicas no, porque su `tenant_id` es NULL).

### 6.2 Versionado y ciclo de vida

La clave es (empresa o plataforma, `system_kind`, `vendor`, `entity`); `version` sube por clave (incluye las retiradas). Proponer de nuevo sobre el mismo esquema **no crea otra versión** (devuelve la
existente; si aún no está aprobada, refresca su evidencia); un esquema que cambió sí crea la siguiente.

```
proposed ──approve──► approved ──(otra versión aprobada)──► retired
   │  ▲                  │
   │  └──── deriva ──────┘   (la plantilla de la empresa vuelve a «proposed»; ver §9)
   └──retire──► retired
```

- **Corregir** (`PATCH`) solo mientras está `proposed`: `field_map` (columna, constante o `null` para quitar) y `value_maps`. Se rechaza un campo desconocido, una columna fuera de `source_spec`, un tipo
  incompatible, un destino fuera del vocabulario canónico, **cualquier columna con nombre de dato personal** (salvo la identificación nacional como código de cliente, que solo una persona puede asignar), y una
  **constante nula o vacía**; en los campos que identifican registros (`document_number`, `product_sku`, `sku`, `code`, `snapshot_date`, `external_id`, `customer_code`, `supplier_code`) **no se admite ninguna constante**
  (una constante en una clave de registro colapsaría todos los registros en uno; en `branch_code`, la sucursal única de un ERP, sí se admite). Un mapa que ya trae una constante nula en un campo obligatorio cuenta
  ese campo como sin mapear. Lo corregido queda con `band: human`.
- **Revisar** (`revise`): otra versión (`proposed`) con las correcciones; la anterior no cambia hasta que se aprueba la nueva.
- **Retirar** (`retire`): terminal; no deshace los mapeos SQL ya aplicados.

### 6.3 Públicas y de empresa

| | Plataforma (`tenant_id` NULL) | Empresa |
|---|---|---|
| Quién la crea, edita, aprueba y retira | solo el superadministrador (`/admin/mapping-templates`, sin empresa activa) | quien tiene `datasources.manage` (crear/corregir) y `settings.manage` (aprobar/retirar) |
| Quién la ve | todas las empresas, solo lectura (`read_only: true`) | solo su empresa |
| Qué puede hacer una empresa con una pública | listarla, aplicarla a una fuente cuyo esquema coincida con su huella, **bifurcarla** (copia propia `proposed` con `forked_from_id`, que hay que aprobar) | — |
| Modificarla desde una empresa | 403 `public_template_read_only` | — |

Una pública exige `vendor` (sin proveedor no es reutilizable), campos obligatorios mapeados para aprobarse, y las mismas validaciones que una corregida (estructura, campos del importador, vocabulario,
sin columnas con nombre de dato personal). La plataforma no accede a las plantillas de una empresa (404) ni a sus datos.

## 7. Huella del esquema

`schema_fingerprint` = SHA-256 del dialecto y de **las columnas que la plantilla usa** (campos mapeados, columnas de unión y de marca de agua) como (tabla, columna, clase de tipo). Cambiar el nombre de una
columna usada, quitarla o cambiar su **clase** de tipo (`numeric` → `varchar`) cambia la huella; agregar columnas nuevas o cambiar dentro de la misma clase (`int` → `bigint`, `numeric` → `double`) **no** la
cambia: no rompe nada de lo aprobado. Decisión de diseño: una huella de todo el esquema invalidaría la plantilla por cualquier cambio ajeno a ella en un ERP grande; la huella completa se devuelve como
dato informativo en el descubrimiento (`schema_fingerprint`).

## 8. De la plantilla aprobada al pipeline de importación

`TemplateApplier::apply` (habilidades `datasources.manage` **y** `settings.manage`):

1. exige la plantilla **aprobada** (409 `template_not_approved`) y, si nació de una fuente, que sea esa (409 `template_source_mismatch`);
2. descubre el esquema actual y compara la huella (§9);
3. genera la consulta con `SourceQueryBuilder` y la valida con `SqlQueryGuard` (422 `sql_query_rejected` si no cumple);
4. entrega a `SourceMappingService::create` la consulta, el `field_map` y `options.value_maps`; el servicio la versiona y la deja **sin aprobar**; luego `approve` la deja aprobada y activa **como la persona
   que aplica** (la plantilla aprobada es la autorización; la bitácora conserva ambas aprobaciones: `mapping.template_approved` y `datasource.mapping_approved`);
5. si el mapeo activo ya es idéntico, lo reutiliza (200 `reused`).

La consulta generada tiene esta forma (PostgreSQL; con comillas `` ` `` en MySQL y `[ ]` en SQL Server):

```sql
SELECT t0."cod_articulo" AS "product_sku", t0."cantidad" AS "quantity", t0."precio_unitario" AS "unit_price", t2."numero_factura" AS "document_number", t2."fecha_emision" AS "issued_at", …
FROM "comercial"."det_venta" t0
LEFT JOIN "inventario"."articulo" t1 ON t0."cod_articulo" = t1."cod_articulo"
INNER JOIN "comercial"."cab_venta" t2 ON t0."id_venta" = t2."id_venta"
LEFT JOIN "comercial"."cliente" t3 ON t2."id_cliente" = t3."id_cliente"
WHERE t2."fecha_modificacion" >= :since
```

Se construye **solo** con palabras clave fijas, identificadores del catálogo entrecomillados y los nombres canónicos de los campos como alias (`AS "quantity"`), de modo que el `field_map` del mapeo SQL es la
identidad (campo → campo) y no depende de nombres del origen; no lleva `ORDER BY` (SQL Server no lo admite en la vista previa). `:since` solo se usa si hay una columna de modificación de tipo fecha;
sin ella cada sincronización lee todas las filas de la consulta (el importador es idempotente por clave natural y huella, pero no incremental). **Aplicar no inicia ninguna sincronización**:
`POST /data-sources/{id}/sync` sigue siendo una acción aparte; el modo de ingestión (backfill/incremental, ADR-V25-23), la ventana, la cuarentena (W2-A), Bronze y la reconciliación son los de siempre.

## 9. Deriva de esquema

Al **aprobar** una plantilla de una fuente, antes de **aplicarla** y a demanda (`POST /data-sources/{id}/mapping-templates/{template}/drift`, que no modifica nada) se compara la huella con el catálogo **actual** de la fuente. El informe usa el formato de la
deriva de W2 (`SchemaDrift`: columnas **agregadas**, **quitadas** y con **tipo cambiado**, más tablas quitadas y el desajuste de dialecto). Si hay columnas quitadas, con otra clase de tipo o el dialecto no
coincide:

- **no se aprueba ni se aplica**: 409 `schema_drift_review_required` con el informe en `context.drift` (al aprobar, la plantilla sigue `proposed`: se propone de nuevo sobre el esquema actual; una fuente inalcanzable
  responde 422 `source_unavailable`; una plantilla sin fuente asociada, como una copia de una pública sin destino, o una pública se compara al aplicarla);
- una plantilla **de la empresa** vuelve a `proposed` (`approved_by`/`approved_at` en NULL, `invalidated_at` y `drift` guardados): hay que revisarla y aprobarla otra vez, normalmente proponiendo de nuevo
  sobre el esquema actual (versión siguiente) y comparando con la anterior; queda `mapping.template_invalidated` en la bitácora;
- una plantilla **pública** no se modifica (es de solo lectura); queda `mapping.template_drift_detected` en la bitácora de la empresa que intentó aplicarla;
- las columnas agregadas se informan pero **no** bloquean.

Reutilización de W2: el informe de deriva incluye, si existe, el hallazgo `w2_sync_drift` de la última sincronización de la fuente y entidad (`source_syncs.stats.schema.drift`, el que registra
`SchemaDrift`/`IngestionQuality` como WARNING `schema_drift`). Limitación honesta: como la consulta generada proyecta alias canónicos, la deriva de columnas del *resultado* de W2 casi no se activa para estos
mapeos (si una columna del origen desaparece, la consulta falla antes); la detección que protege la plantilla es la del catálogo de esta sección. **DISEÑADO / NO IMPLEMENTADO**: comprobar la deriva de forma
programada y **al sincronizar**: hoy la deriva se comprueba al aprobar, al aplicar y a demanda, NO en cada sincronización (la sincronización es de W2-A; si una columna usada desaparece, la consulta falla y la
sincronización queda `failed` sin importar nada).

Puntos ciegos conocidos (cubiertos por `MappingSchemaDriftTest::test_drift_blind_spots_are_the_documented_ones`):

1. **Renombrar una columna y crear otra con el nombre antiguo y la misma clase de tipo** deja la misma huella: la plantilla sigue «válida» aunque la columna ahora signifique otra cosa. Una huella de nombres y
   tipos no detecta un cambio de significado.
2. **La comparación es estricta con la clase de tipo**: `integer` ↔ `decimal` (p. ej. `int` → `numeric`) cuenta como deriva aunque sean compatibles, y obliga a proponer de nuevo; dentro de la misma clase
   (`int` → `bigint`, `numeric` → `double`, `varchar(50)` → `varchar(200)`) no.
3. Las columnas **agregadas** se informan pero no bloquean, aunque a veces sean la nueva columna correcta.

## 10. Autorización y auditoría

| Acción | Habilidad (`TenantRole`) | Roles hoy |
|---|---|---|
| Descubrir, proponer, listar, ver, corregir, revisar, bifurcar, comprobar la deriva | `datasources.manage` | owner, admin |
| Aprobar y retirar una plantilla | `settings.manage` | owner, admin |
| Aplicar una plantilla a una fuente | `datasources.manage` **y** `settings.manage` | owner, admin |
| Plantillas públicas (`/admin/mapping-templates`) | superadministrador (sin empresa activa) | — |

Decisión: se usa `settings.manage` (opción prevista por el arquitecto) y no una habilidad nueva, porque `TenantRole` pertenece a W6-A; con ella la aprobación ya es una acción **distinta** de la que propone. Un
token con alcance `datasources.manage` pero sin `settings.manage` puede proponer y no aprobar ni aplicar (prueba). Ambas habilidades son sensibles para el MFA de W6-A (`security.mfa.sensitive_abilities`): quien
tiene MFA activo necesita un segundo factor fresco (403 `mfa_step_up_required`; prueba). **Segregación de funciones (IMPLEMENTADA / VERIFICADA)**: quien propuso la plantilla (`created_by`) no la aprueba si en la
empresa hay OTRA persona con la habilidad de ajustes (propietario o administrador): 403 `approval_segregation_of_duties`; analistas y usuarios de consulta no cuentan. Con una sola persona que pueda aprobar se permite
(caso de una empresa con un solo administrador; la bitácora registra `created_by` y `approved_by`). En las plantillas públicas aplica entre superadministradores con la misma regla. Quien corrige o revisa una
propuesta ajena no se convierte en su autor: la segregación se mide contra quien la creó. Pruebas: `MappingTemplateAccessTest::test_the_person_who_proposed_cannot_approve_when_someone_else_can_but_a_single_administrator_can`,
`PublicMappingTemplatesTest::test_a_public_template_is_approved_by_another_platform_admin_when_there_is_one`.

**Identidad del personal de la plataforma**: en una plantilla pública, `created_by` y `approved_by` son `null` para las empresas (se muestra la fecha de aprobación); solo el superadministrador los ve en `/admin`.

Bitácora (`audit_logs`, sin PII): `mapping.discovery_run`, `mapping.proposals_generated`, `mapping.template_proposed`, `mapping.template_edited` (campos modificados), `mapping.template_approved`
(quién, versión, alcance, prefijo de huella), `mapping.template_retired`, `mapping.template_revised`, `mapping.template_forked`, `mapping.template_applied`, `mapping.template_invalidated`,
`mapping.template_drift_detected` y `mapping.public_template_created` (sin empresa).

## 11. API y verificación

| Método y ruta | Habilidad | Para qué |
|---|---|---|
| `POST /data-sources/{id}/schema/discover` | `datasources.manage` | metadatos del catálogo |
| `POST /data-sources/{id}/mapping-proposals` | `datasources.manage` | proponer por entidad (guarda plantillas `proposed`) |
| `GET /mapping-templates` · `GET /mapping-templates/{template}` | `datasources.manage` | listar (empresa + públicas) · detalle con la consulta |
| `PATCH /mapping-templates/{template}` · `POST …/revise` · `POST …/fork` | `datasources.manage` | corregir · otra versión · copia propia de una pública |
| `POST /mapping-templates/{template}/approve` · `POST …/retire` | `settings.manage` | aprobar · retirar |
| `POST /data-sources/{id}/mapping-templates/{template}/drift` | `datasources.manage` | informe de deriva (sin aplicar) |
| `POST /data-sources/{id}/mapping-templates/{template}/apply` | `datasources.manage` + `settings.manage` | crear el mapeo SQL activo |
| `GET/POST /admin/mapping-templates` · `GET/PATCH …/{id}` · `POST …/{id}/approve` · `POST …/{id}/retire` | superadministrador | plantillas públicas |

Códigos de error estables: `template_incomplete`, `template_not_proposed`, `template_not_editable`, `template_not_approved`, `template_not_public`, `template_source_mismatch`,
`schema_drift_review_required`, `schema_too_large`, `public_template_read_only`, `sql_query_rejected`, `source_unavailable`.

Pruebas (base de pruebas del ERP ficticio `predictily_erp_source_w6`, con esquemas en español `comercial`, `inventario` y `compras`; sin esa base las pruebas de PostgreSQL se **omiten con ruido**, no fallan):

```bash
cd backend
PHP_INI_SCAN_DIR="/opt/homebrew/etc/php/8.3/conf.d:$HOME/.predictily/php/conf.d" \
  DB_DATABASE=<bd_pruebas> TEST_ERP_DATABASE=predictily_erp_source_w6 php artisan test tests/Unit/Mapping tests/Feature/Mapping
```

Cobertura por archivo: `tests/Unit/Mapping/` (`NameTokensTest`, `EntityProposerTest` —heurísticas sobre esquemas variados y nombres adversariales—, `ValueMapProposerTest`, `SampleMaskingTest`,
`CatalogContractTest` —consultas fijas, aislamiento, `READ UNCOMMITTED`, catálogos de MySQL y SQL Server—, `SourceQueryAndFingerprintTest`) y `tests/Feature/Mapping/` (`AutoMapperEndToEndTest`,
`MappingTemplateAccessTest`, `PublicMappingTemplatesTest`, `MappingSchemaDriftTest`, `MappingPrivacyTest`). Última ejecución del paquete: `tests/Unit/Mapping` 106 pruebas y `tests/Feature/Mapping` 57 pruebas (163 en total), todas verdes; con `OpenApiRoutesTest` y `tests/Feature/Privacy` 175/175. La suite completa del backend quedó en 2321 pruebas con 2312 verdes, 8 omitidas y una falla ajena a este paquete (`TenantIsolationMatrixTest`, lista de usos de caché de W6-A/W6-B). Mutación (57 mutantes de las reglas de este paquete —máscara de muestras, PII, aprobación, segregación, deriva, consulta de solo lectura, comillas de identificadores, plantillas públicas, aislamiento—): los 57 detectados.

## 12. Pendiente, bloqueado y no aplica

- **A aprobar en el piloto**: umbrales (0,60 / 0,10), pesos del puntaje y topes de muestra, con los primeros mapeos reales de clientes (hoy solo hay esquemas sintéticos).
- **DISEÑADO / NO IMPLEMENTADO**: comprobación de deriva programada y en cada sincronización (hoy: al aprobar, al aplicar y a demanda); propuesta con LLM opcional (metadatos y herramientas permitidas, §4); entidad
  `customers` e importador de órdenes de compra; propuestas con varias tablas de documentos (facturas y notas de crédito en tablas separadas); descubrimiento por Bridge.
- **BLOQUEADO**: verificación contra servidores reales de MySQL/MariaDB y SQL Server (no hay servidor ni driver en este entorno: `implemented_untested`); instalador y firma del Bridge Windows.
- **NO APLICA**: fuentes que no son SQL.
