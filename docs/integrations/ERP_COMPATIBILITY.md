# Compatibilidad con motores de base de datos y ERP

> V2.5 · W6-B (ADR-V25-10; especificación §4). Estados usados, sin excepciones:
> **probado** = lo demuestra una prueba automatizada del repositorio **contra una base de pruebas ficticia** (no contra el ERP de un cliente);
> **implemented_untested** = implementado detrás de la misma interfaz pero **sin servidor ni controlador reales para probarlo** (solo pruebas con un cursor falso o de contrato);
> **BLOQUEADO** = falta un recurso externo (host, servidor, credenciales o certificado) que debe aportar el propietario;
> **NO IMPLEMENTADO** / **NO APLICA**.
> Compatibilidad es **por motor de base de datos y por formato de archivo**, no por producto ERP: **ningún ERP real (Soyoda, Contífico, SAP Business One, Odoo, Dynamics, …) se ha probado**.
> Un ERP «compatible» significa que su base se puede leer con una consulta SELECT que asigna alias de campos canónicos a las columnas a campos canónicos; **eso lo valida el piloto con el cliente**, ERP por ERP.

## 1. Matriz por motor y formato

| Origen | Servidor lee directo (`sql_*`) | Agente Bridge (`predictily_bridge`) | Qué lo demuestra | Qué falta para declararlo «probado con un cliente» |
|---|---|---|---|---|
| **PostgreSQL** 12+ | **probado** (`SqlSourceSyncTest`) — solo si el servidor de Predictily alcanza la base (política anti-SSRF: lista blanca o VPN) | **probado** (`test_postgres_integration.py`, `BridgeAgentEndToEndTest`) | base PostgreSQL ficticia local; solo lectura demostrada por el servidor de BD | un ERP PostgreSQL real, cuenta `GRANT SELECT`, volumen real |
| **SQLite** | NO APLICA (archivo local) | **probado** (`test_readonly_enforcement.py`, `test_agent_resume.py`) | archivos SQLite ficticios; `mode=ro` + autorizador | un sistema real que use SQLite (POS de escritorio) |
| **CSV** | archivo subido: **probado** (`generic_csv`) | **probado** (`test_files_adapter.py`) — carpeta con `*`, reanudación por archivo y fila, omite archivos sin cambios | archivos ficticios | exportaciones reales del cliente (separadores, codificaciones) |
| **Excel (.xlsx)** | archivo subido: **probado** (`generic_excel`) | **probado** (`test_files_adapter.py`, requiere `openpyxl`) | libro ficticio | libros reales (hojas, fórmulas, filas combinadas) |
| **MySQL / MariaDB** | **implemented_untested** | **implemented_untested** (PyMySQL; cursor falso) | contrato y cursor falso; **no hay servidor MySQL** | un servidor MySQL/MariaDB de pruebas y una pasada de la suite de integración (**BLOQUEADO** hasta tenerlo) |
| **SQL Server** 2016+ | **implemented_untested** | **implemented_untested** (pyodbc + ODBC Driver 18; cursor falso) | contrato y cursor falso; **no hay SQL Server ni `pdo_sqlsrv`** | un SQL Server de pruebas (**BLOQUEADO**); en Windows además el empaquetado (`BRIDGE_WINDOWS.md`) |
| **Oracle, DB2, Firebird, Informix** | NO IMPLEMENTADO | NO IMPLEMENTADO | — | decidir con el piloto si hace falta; el contrato de adaptador permite agregarlos |
| **ODBC genérico, Access (.mdb/.accdb), DBF** | `generic_odbc`: **planned** | NO IMPLEMENTADO (ADR-V25-10 los prevé detrás de la misma interfaz) | — | un caso real y un controlador probado |
| **API REST de un proveedor** | `generic_rest` (empuje del cliente): **probado**; `contifico`: requires_credentials, **no probado** | NO APLICA | pruebas de `POST /sales/batch` | credenciales del proveedor |

Una **cuenta del cliente con `GRANT SELECT` únicamente** es la recomendación de producción en todos los motores; los controles de solo lectura de la sesión (PostgreSQL, MySQL) y el autorizador (SQLite) son defensa en profundidad, no sustituto. En
SQL Server **no existe** transacción de solo lectura: la garantía descansa en la cuenta `db_datareader` y en el guardia SELECT; el agente consulta los permisos y, como `require_readonly_account` es `true` **por defecto en SQL Server y MySQL**, se
niega a extraer si la cuenta puede escribir (en el resto de motores el valor por defecto es `false` y se recomienda `true` en producción). En PostgreSQL los privilegios de escritura se detectan como **efectivos**, también los heredados por roles. **PostgreSQL exige TLS por
defecto** (`sslmode = "require"`): un PostgreSQL local sin TLS (solo pruebas) se relaja de forma explícita con `sslmode = "prefer"`.

## 2. Requisitos y particularidades por motor

| Motor | Permisos mínimos | Particularidades de las plantillas | Fechas y tipos |
|---|---|---|---|
| PostgreSQL | `CONNECT` + `USAGE` en el esquema + `SELECT` en las tablas/vistas de la plantilla | las CTE se admiten (se envuelven como subconsulta) | `timestamptz` se envía con su desplazamiento; `timestamp` sin zona va en la hora local del origen (`source_timezone`); `numeric` llega como texto exacto (`"8.5000"`) |
| MySQL / MariaDB | `SELECT` | los comentarios `#` solo se reconocen en MySQL; `/*! … */` se rechaza; el `%` literal se maneja (se duplica al ligar parámetros) | las fechas son ingenuas: se interpretan en `source_timezone` |
| SQL Server | `db_datareader` (no `db_owner`) | **SELECT simple**: sin `WITH` ni `ORDER BY` internos (se envuelve como subconsulta); use vistas; paginación con `TOP` | `datetime2` se liga tipado (sin ambigüedad regional); `ApplicationIntent=ReadOnly` |
| SQLite | lectura del archivo | `sqlite_*`, `PRAGMA`, `ATTACH`, `load_extension` rechazados | fechas como texto ISO |
| CSV / Excel | lectura de la carpeta | `columns` mapea encabezado → campo canónico; los archivos se leen en orden de nombre | valores como texto; el importador interpreta fechas y números |

Toda plantilla debe: ser **un solo SELECT**; devolver como alias los **campos canónicos** de la entidad; declarar `order_by` con columnas **únicas y no nulas** (el orden total permite reanudar sin duplicar; un `NULL` en una llave detiene la extracción
con un mensaje claro); si hay fecha de modificación en el origen, declararla como `recorded_at_column` (marca de agua incremental). Sin `recorded_at_column` cada corrida es una foto completa (idempotente).

## 3. Límites conocidos (honestos)

- **Sin ERP real probado.** Los resultados de este documento vienen de bases y archivos ficticios; el comportamiento con volúmenes, codificaciones, zonas horarias y datos sucios reales del cliente **no está medido**.
- **Una llave de orden por plantilla.** Si el ERP no tiene una llave única y estable por fila (p. ej. líneas sin número), la plantilla debe construirla (p. ej. `ROW_NUMBER()` en una vista) o no podrá reanudar sin repetir.
- **Cursor ≤ 200 caracteres** (límite del contrato `bridge_checkpoints.checkpoint`): llaves de orden muy largas no caben (el agente lo informa).
- **Cambios de esquema.** Si el ERP renombra o quita una columna que usa la plantilla, la extracción falla con un error legible; si cambia el conjunto de columnas del lote, el servidor registra la **deriva de esquema** (`schema_fingerprint`, W2-A) y la fuente lo refleja.
- **Borrados en el ERP.** El Bridge no activa CDC: un documento borrado físicamente en el origen **no se detecta** (un documento anulado con su estado sí). Documentado; decisión del piloto.
- **Documentos modificados con fecha de modificación que no avanza.** Si el ERP no actualiza `modificado_en` al corregir, la corrección no se vería en la ventana incremental; use una carga completa (reinicio de checkpoint) periódica.
- **Instalador de Windows y servicio: BLOQUEADO** (`BRIDGE_WINDOWS.md`). Hasta entonces el agente se ejecuta con Python 3.12 en cualquier sistema operativo.
- Los valores por defecto (lote de 5 000 filas, solape de 120 minutos, vigencias, límites de tasa) son iniciales y **se aprueban en el piloto**.

## 4. Cómo se declara un ERP «probado» (criterio de aceptación del piloto)

Para cada ERP/motor del cliente, con su autorización: (1) cuenta de solo lectura creada por el cliente; (2) plantillas revisadas y fijadas (`check-config --pin`); (3) `test-source` sin advertencias de escritura; (4) una carga completa
conciliada contra el ERP (conteo de documentos y totales; informe de reconciliación W2-B); (5) una corrección en el origen reflejada en la siguiente corrida incremental; (6) interrupción y reanudación sin duplicados; (7) revocación
verificada. Solo entonces se actualiza esta matriz con el nombre del ERP, la versión y la fecha, y se cita el informe de la prueba.
