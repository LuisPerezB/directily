# Fuentes externas de Factor Intelligence: estado legal y técnico (V2.5 §24.4, §24.6, §24.8, §24.9)

> **Alcance y estado.** Registro honesto de qué fuentes externas existen realmente hoy para alimentar los factores del catálogo. Decisión: `docs/architecture/adr/V25-ADRS.md` (ADR-V25-16 y ADR-V25-25).
> Documentos hermanos: `FACTOR_INTELLIGENCE.md`, `PRODUCT_TAXONOMY.md`, `SECTOR_PROFILES.md`.
>
> Cada afirmación lleva una de estas marcas: **IMPLEMENTADO / VERIFICADO** (con la prueba que lo demuestra), **DISEÑADO / NO IMPLEMENTADO**, **BLOQUEADO** o **NO APLICA**.
>
> ## Estado global
>
> **Hoy NO HAY NINGUNA INTEGRACIÓN EXTERNA ACTIVA.** No existe ningún conector, ninguna tarea que descargue datos de terceros, ningún proveedor de LLM configurado ni ninguna fuente aprobada en producción. El
> catálogo de factores (`factor_definitions`) define el contrato de cada familia, pero todas sus definiciones tienen `source_key`, licencia de fuente e indicador externo en blanco y `is_active = false`.
> Este documento **no nombra ningún proveedor como si estuviera integrado**: lo que describe son requisitos para poder activar una fuente cuando se decida (P2).
>
> Prueba de ausencia: `FactorCatalogTest::test_seeder_catalogs_the_17_families_all_inactive_with_complete_metadata` (ninguna definición declara fuente ni indicador) y
> `ExternalEventsTest::test_this_package_has_no_external_integration_or_llm_extraction` (sin `source_versions` ni `external_indicators`, eventos solo manuales).

## 1. Lo que existe hoy (y lo que no)

| Elemento | Estado |
|---|---|
| Tabla `external_indicators` / `external_indicator_observations` (etapa 1, ampliada por el contrato de W2 con `observed_at`, `available_at`, vigencia, `issued_at`, `source_version_id`, alcance y banderas de calidad) | existe el **contrato**; **no hay ninguna ruta, importador ni tarea que cargue observaciones** y el catálogo no apunta a ningún indicador. El motor tiene un lector (W2-D, `db/factors_repository.py`) sin datos que leer |
| Tabla `source_versions` (versión de una fuente, licencia, huella, términos) | existe el contrato y su modelo; **0 filas** |
| Eventos externos manuales con procedencia (`external_events`) | **IMPLEMENTADO / VERIFICADO** (`FACTOR_INTELLIGENCE.md` §6): una persona registra el evento y otra lo verifica |
| Conectores de `backend/app/Services/Connectors` (Contífico, CSV, Excel, ODBC, REST genérico, SQL) | **NO APLICA**: son conectores de los **datos propios** de la empresa (ERP/archivos), no fuentes externas de factores |
| Proveedor de LLM para clasificar productos o extraer eventos | **DISEÑADO / NO IMPLEMENTADO**: ver §4 |
| Pipeline de factores en el motor | desactivable y apagado por defecto (W2-D); la activación de un factor por la empresa no tiene efecto con el pipeline apagado |

## 2. Estado por familia

Leyenda de **estado técnico**: `NATIVO` = el dato ya vive en Predictily y no requiere fuente externa; `SIN INTEGRACIÓN` = contrato definido, ninguna fuente ni dato; `MANUAL` = solo eventos registrados a mano;
`CONTRATO DESACTIVADO` = diseñado y apagado por bandera. **Estado legal:** `NO EVALUADO` significa que no se ha revisado licencia, términos de uso, permisos de acceso ni condiciones de redistribución de ninguna
fuente concreta; hasta entonces no se puede activar. «Qué se necesita» son requisitos, no promesas.

| # | Familia | Estado técnico | Estado legal | Qué se necesita para activarla (P2) |
|---|---|---|---|---|
| 1 | Ventas históricas | NATIVO | propio de la empresa | — |
| 2 | Precios y descuentos | NATIVO (precios de venta y promociones propias) | propio de la empresa | — |
| 3 | Competencia | SIN INTEGRACIÓN | NO EVALUADO | Fuente autorizada (acuerdo o API con permiso de uso; **sin scraping o extracción prohibida**, V2.1 §24.8); regla de equivalencia explicable entre productos; normalización por unidad, impuesto, descuento, moneda, región y canal; vigencia y calidad |
| 4 | Tendencias de búsqueda | SIN INTEGRACIÓN | NO EVALUADO | Fuente con términos que permitan su uso comercial; política de cuotas; fecha de publicación de cada valor |
| 5 | Preferencias del consumidor | SIN INTEGRACIÓN | NO EVALUADO | Encuestas o estudios con licencia; rezago de publicación; cobertura geográfica |
| 6 | Clima observado y pronosticado | SIN INTEGRACIÓN | NO EVALUADO | Fuente oficial o comercial con licencia; **para el pronóstico, historial archivado de emisiones (`issued_at`)**: sin él el backtest con este factor es INVALID y se prohíbe (ADR-V25-06) |
| 7 | Epidemiología | MANUAL | NO EVALUADO | Fuente oficial con licencia de reutilización; corroboración; verificación humana (sensible) |
| 8 | Sanidad animal/vegetal | MANUAL | NO EVALUADO | ídem; jurisdicción y vigencia |
| 9 | Alertas de inocuidad | MANUAL | NO EVALUADO | ídem; el documento publicado después del corte no existía para el modelo |
| 10 | Restricciones comerciales/regulatorias | MANUAL | NO EVALUADO | ídem; fecha de publicación y de entrada en vigor |
| 11 | Estadísticas de exportación | SIN INTEGRACIÓN | NO EVALUADO | Serie oficial con licencia; registro de **revisiones** (el valor visible al corte es el de esa fecha, no la revisión actual) |
| 12 | Pedidos y contratos | NATIVO (compromisos comerciales de la empresa) | propio de la empresa | — |
| 13 | Proyecciones compartidas con autorización | CONTRATO DESACTIVADO | requiere acuerdo entre empresas | Habilitar la red colaborativa (hoy desactivada por decisión de P0); consentimiento y contrato entre las partes; reconciliación sin dobles |
| 14 | Estimaciones de producción | SIN INTEGRACIÓN | NO EVALUADO | Estimaciones de organismos o gremios con licencia; fecha de emisión archivada (son pronósticos de terceros) |
| 15 | Reseñas y satisfacción | SIN INTEGRACIÓN | NO EVALUADO | Permiso de acceso a la fuente (sin scraping prohibido); agrupación por tema/sentimiento con incertidumbre; sin atribución causal automática |
| 16 | Capacidad e inventario | NATIVO (inventario y capacidades de la empresa) | propio de la empresa | — |
| 17 | Calendarios, eventos y feriados | NATIVO (calendario con alcance, ya existente) | propio / público compartido | — |

## 3. Requisitos transversales para activar cualquier fuente externa

Una fuente solo puede declararse activa cuando se cumpla **todo** lo siguiente; el catálogo lo refuerza (el propietario de la plataforma no puede activar una familia externa sin `source_key`:
`FactorCatalogTest::test_the_platform_owner_cannot_declare_active_an_external_factor_without_source`).

1. **Permiso y licencia** revisados (uso comercial, redistribución, retención) y registrados en `source_versions` (`license`, `terms_url`, `checksum`, `coverage`). *NO EVALUADO.*
2. **Disponibilidad temporal real:** cada valor con fechas de evento/observación, publicación, disponibilidad para el sistema, vigencia y, para pronósticos de terceros, emisión (`issued_at`) y revisión.
   Sin historial de emisiones se prohíbe el backtest con ese factor. *Contrato definido; validador en el motor (W2-D).*
3. **Resiliencia (V2.1 §24.9):** timeouts, circuit breaker y *backoff*; caché del último dato válido **con etiqueta de antigüedad** y expiración; respaldo a modelo sin el factor o resultado `UNAVAILABLE`;
   nunca imputar silenciosamente como medición actual; aviso y auditoría. *DISEÑADO / NO IMPLEMENTADO* (no hay llamadas externas que proteger).
4. **Aislamiento:** las fuentes públicas pueden compartir definiciones de adaptadores, pero **no datos privados entre empresas**; las consultas privadas, los eventos derivados y los artefactos se aíslan por empresa.
   El aislamiento de lo que ya existe (catálogos públicos de solo lectura, filas de empresa con *global scope*) está **IMPLEMENTADO / VERIFICADO**.
5. **Costos:** versionado de costos y tasas de llamadas, y presupuesto por empresa, para evitar facturas imprevistas. *DISEÑADO / NO IMPLEMENTADO.*
6. **SSRF y descargas:** cualquier conector que traiga contenido de una URL debe pasar por la política de salida del backend (lista blanca de hosts, bloqueo de redes internas; ADR-V25-20). En este paquete la
   URL de un evento solo se almacena; **nunca se descarga**. *IMPLEMENTADO / VERIFICADO:* `ExternalEventsTest::test_incomplete_provenance_blocks_verification_and_a_future_publication_is_rejected` (solo http/https).
7. **Comparación real:** ningún factor externo se promueve sin ablation con holdout intacto (V2.1 §24.7). Las alertas regulatorias, epidemiológicas, sanitarias y de seguridad se conservan como **alertas
   informativas verificadas** aunque no mejoren el error. *Alertas informativas: IMPLEMENTADO / VERIFICADO; ablation: DISEÑADO (P2).*

## 4. LLM para contexto y extracción

V2.1 §24.6 admite un LLM para clasificar productos, proponer factores, interpretar documentos y extraer eventos estructurados con JSON Schema, **jamás como fuente financiera**, sin PII a proveedores externos
sin autorización y con verificación humana de eventos sensibles. **Estado: DISEÑADO / NO IMPLEMENTADO.** No hay ningún proveedor configurado (ni Ollama Cloud ni otro) ni adaptador en este paquete; el servicio
conversacional de la etapa 2 no se amplió. Lo que sí existe para recibirlo sin cambiar contratos:

* `external_events.extracted_by` (`manual | connector | llm`), `extraction` (salida estructurada sin PII) y los estados `PROPOSED/VERIFIED/REJECTED/EXPIRED`: una extracción automática entraría como `PROPOSED` y
  exigiría verificación humana. Hoy `extracted_by` es siempre `manual`.
* `product_taxonomy_assignments.provenance = llm_proposed`: una clasificación propuesta nunca se usa como verdad hasta que un usuario la verifica (`PRODUCT_TAXONOMY.md`).

## 5. Registro de fuentes aprobadas

| Fuente | Familia | Licencia revisada | Aprobada | Activa |
|---|---|---|---|---|
| *(ninguna)* | — | — | — | — |

Cuando una fuente se apruebe, se agregará aquí con su licencia, términos, frecuencia, cobertura, historial de emisiones disponible, retención, responsable y fecha de revisión, y se reflejará en `source_versions` y
en la definición correspondiente del catálogo. Mientras esta tabla esté vacía, ningún documento ni pantalla puede presentar un factor externo como activo.
