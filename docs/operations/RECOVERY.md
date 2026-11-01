# Predictily · Respaldo y recuperación (V2.5 · W4-C)

> **Estado**
> - Respaldo y restauración de **PostgreSQL en local/VPS**: **IMPLEMENTADO y PROBADO** el 2026-11-01 (§4, con conteos).
> - Recuperación **en AWS** (snapshots y PITR de RDS, versiones de S3, copias de EFS): **DISEÑADA, NO PROBADA** (BLOQUEADO: no hay
>   cuenta AWS). Los objetivos RTO/RPO de AWS son **propuestas a aprobar**, no mediciones.
> - Una restauración que nadie ha probado no es un respaldo: la prueba de §4 se repite **mensualmente** y tras cada cambio de
>   esquema relevante (§7).

Ámbito: este documento cubre la base de datos y los datos que la acompañan. Arquitectura: `docs/architecture/AWS_ARCHITECTURE.md`
(§6: Single-AZ y criterio Multi-AZ). Despliegue en VPS: `infra/scripts/deploy.md` §4. Despliegue en AWS: `docs/operations/AWS_DEPLOYMENT.md`.

## 1. Qué hay que poder recuperar

| Dato | Dónde vive (local/VPS) | Dónde vive (AWS, diseñado) | Cómo se recupera |
|---|---|---|---|
| PostgreSQL (empresas, ventas, pronósticos, registro de modelos, auditoría) | volumen `pgdata` | RDS PostgreSQL 16 | `pg_dump`/`pg_restore` (§3–§4) · snapshots y PITR de RDS (§6) |
| Archivos de importación y exportaciones (disco `local` de Laravel) | volumen `backend-storage` | EFS (punto de acceso `/backend-storage`) | copia del volumen · copias de AWS Backup de EFS |
| Artefactos de modelos del motor | volumen `engine-artifacts` | EFS (`/engine-artifacts`) + S3 `artifacts` | pueden regenerarse con una nueva ejecución, pero **las versiones aprobadas y su firma se conservan** de S3 |
| Bronze (original inmutable) | disco local (`bronze` local) | S3 `bronze` (versionado, SSE‑KMS) | versiones de objeto de S3 |
| Silver / Gold | PostgreSQL | S3 `silver` / `gold` (sin consumidores aún) + RDS | reconstruibles desde Bronze |
| `APP_KEY` de Laravel | `.env` | Secrets Manager (`predictily/<entorno>/app-key`, **RETAIN**) | **copia fuera del sistema obligatoria**: sin ella no se descifran nombres de clientes ni credenciales de conectores |
| Claves de firma Ed25519 | archivo 0600 del servidor | secreto creado por el propietario | la privada se respalda **cifrada y aparte**; la pública vive en `ENGINE_TRUSTED_PUBLIC_KEYS` |

Los respaldos contienen **datos de clientes**: se cifran (GPG o KMS), se guardan fuera del servidor, se rotan y no se copian a
staging. Un respaldo restaurado en un entorno de pruebas debe ser **sintético o anonimizado**.

## 2. Objetivos

| | Local / VPS (vigente) | AWS (propuesto) |
|---|---|---|
| RPO | ≤ 24 h (respaldo diario) | ≤ 15 min con PITR de RDS — **a aprobar**; la cifra se mide en el primer simulacro |
| RTO | ≤ 4 h | ≤ 4 h — **a aprobar**; Single‑AZ implica restaurar en una instancia nueva |
| Retención | 14 días (`BACKUP_RETENTION_DAYS`) | production 14 d · staging 7 d (`BackupRetentionPeriod`) |
| Estado | probado en local (§4) | DISEÑADO; BLOQUEADO |

Ninguna cifra es universal: se confirman o se corrigen con el simulacro y con los compromisos contractuales. El criterio para
pasar a Multi‑AZ está en `AWS_ARCHITECTURE.md` §6.

## 3. Respaldo (`infra/scripts/backup.sh`)

```bash
infra/scripts/backup.sh                                  # diario; autodetecta docker compose o pg_dump local
BACKUP_MODE=native BACKUP_DIR=/ruta infra/scripts/backup.sh
```

Genera `<base>_<UTC>.dump` (formato custom, comprimido, sin propietarios ni privilegios), `<archivo>.sha256` y, **nuevo**,
`<archivo>.counts`: un manifiesto con el número de tablas, índices, restricciones y secuencias, una huella del esquema, **el valor
actual de cada secuencia** y las filas de cada tabla. Opcional: cifrado GPG (`BACKUP_GPG_RECIPIENT`) y rotación por antigüedad.

**Sensibilidad del manifiesto.** No contiene datos de clientes, pero sí nombres de tablas y volumen de filas (información de negocio y de
esquema). Se guarda con permisos 0600 y se trata como el propio respaldo: con `BACKUP_GPG_RECIPIENT` se **cifra igual**
(`<archivo>.dump.gpg.counts.gpg`) y no queda copia en claro; `recovery-check.sh verify` lo descifra solo. Cópielo fuera del servidor
junto con el respaldo, con la misma política de acceso.

Cambios de este paquete en `backup.sh` y `restore.sh` (mínimos y compatibles): (1) **las variables ya definidas en el entorno tienen
precedencia sobre las del `.env`** (antes el `.env` las pisaba; así una ejecución de prueba nunca respalda ni restaura por accidente la
base del `.env`; la misma regla vale ahora en `restore.sh` y `recovery-check.sh`); `BACKUP_IGNORE_ENV_FILE=true` omite el `.env`; el
lector del `.env` recorta espacios finales, `\r` y comentarios, y quita comillas; (2) la suma SHA‑256 usa `sha256sum` o, si falta (macOS), `shasum -a 256` (también en `restore.sh`), y se escribe
con nombre relativo; (3) `BACKUP_COUNTS` (por defecto `true`) escribe el manifiesto.

Límite honesto del manifiesto: se calcula **después** del volcado y no en la misma instantánea. En una base con escrituras
concurrentes las filas pueden diferir entre ambos instantes; la comparación es exacta en una base quiescente (pruebas, ventana de
mantenimiento). Para la prueba mensual en producción, ejecutar el respaldo en una ventana sin escrituras o interpretar las
diferencias pequeñas de tablas de alta escritura (`audit_logs`, `tenant_usage_records`). Una instantánea consistente
(`pg_dump --snapshot`) es DISEÑADA.

## 4. Prueba de restauración (`infra/scripts/recovery-check.sh`) — IMPLEMENTADA Y PROBADA

```bash
# Ciclo completo sobre una base de PRUEBAS: respalda, restaura en una base vacía temporal y compara
infra/scripts/recovery-check.sh cycle predictily_test_w4c

# Verificar un respaldo ya generado por backup.sh (la prueba mensual real)
infra/scripts/recovery-check.sh verify backups/predictily_20261101T031500Z.dump       # lee <archivo>.counts

# Solo el manifiesto de una base
infra/scripts/recovery-check.sh manifest predictily_test_w4c
```

Qué hace `verify`: comprueba la suma SHA‑256; (si es `.gpg`, descifra); crea `predictily_recovery_check_<pid>`; restaura con
`pg_restore --exit-on-error`; calcula el manifiesto de lo restaurado y lo **compara línea a línea** con el del respaldo (tablas,
índices, restricciones, secuencias **y su valor actual**, huella de columnas y filas de **cada** tabla); elimina la base temporal y los archivos temporales
(también si falla). Se niega a usar la base `predictily` en `cycle` (la de desarrollo/producción) salvo `--allow-live-source`.

| Código de salida | Significado |
|---|---|
| 0 | restauración idéntica |
| 1 | hay diferencias: **la recuperación no es fiable** (se imprimen las líneas distintas) |
| 2 | uso o configuración incorrectos |
| 3 | fallo al respaldar o restaurar, o suma SHA‑256 que no coincide |

Variables: `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST`, `DB_PORT`, `BACKUP_MODE`, `BACKUP_IGNORE_ENV_FILE` (como `backup.sh`).

### Evidencia de la ejecución (2026-11-01)

Entorno: PostgreSQL 15.17 (Homebrew), cliente `pg_dump`/`pg_restore` 15.17, macOS, bash 3.2. Base de origen:
`predictily_test_w4c`, migrada con **todas** las migraciones vigentes (incluida la de W4) y cargada con la empresa demo FICTICIA
«Ferretería Andina» (62 productos, 44 269 ventas, 99 334 líneas; 126 MB). Nunca se tocó la base de desarrollo `predictily`.

```
[recovery-check] respaldando predictily_test_w4c con backup.sh → <directorio temporal>
[backup] ... modo=native base=predictily_test_w4c destino=<...>/predictily_test_w4c_20261101T141738Z.dump
[backup] OK 8.0M ed3c67bc9e27...
[backup] manifiesto de conteos: <...>.dump.counts
[recovery-check] suma SHA-256 verificada
[recovery-check] restauración terminada en 4 s (base temporal predictily_recovery_check_85448)
[recovery-check] IDÉNTICO: 100 tablas, 277965 filas; coinciden el esquema (huella de columnas), el número de índices, restricciones y secuencias, el valor actual de cada secuencia y las filas de cada tabla
```

Manifiesto (cabecera): `tables=100`, `indexes=297`, `constraints=490`, `sequences=95`, `schema_md5=22b9df17...`; 95 líneas `seq|...` (p. ej.
`audit_logs_id_seq` = 34) y 100 líneas `count|...` (p. ej. `audit_logs` 21, `branches` 3, `sales` 44 269). Antes el mensaje decía «secuencias coinciden» pero
solo se comparaba su número; ahora también se compara el valor de cada una (una restauración que dejara una secuencia en 1 haría que el
siguiente INSERT chocara aunque las filas coincidan).

Pruebas negativas ejecutadas (resultado literal resumido):

| Caso | Resultado |
|---|---|
| manifiesto alterado (`sales` 44 268 en vez de 44 269) | `DIFERENCIAS (2 líneas)` + las dos líneas distintas · **salida 1** |
| respaldo con un byte alterado (con su `.sha256` reescrito para el nombre nuevo) | `la suma SHA-256 del respaldo no coincide` · **salida 3**, sin restaurar |
| `cycle predictily` | `«predictily» es la base de desarrollo/producción...` · **salida 2** |
| sin argumentos | uso · **salida 2** |
| secuencia restaurada con otro valor (manifiesto alterado) | `DIFERENCIAS` con la línea `seq|...` · **salida 1** |
| bases `predictily_recovery_check_%` restantes tras el ciclo | **0** (limpieza verificada) |

Automatizado en `infra/aws/test/recovery.test.ts` (9 pruebas, **se omiten con mensaje explícito** si no se define
`RECOVERY_CHECK_DB`; ejecutadas el 2026-11-01: 9 aprobadas). Incluye el flujo de cifrado con un **`gpg` simulado (stub con base64)**:
prueba nombres de archivo, que no queden volcado ni manifiesto en claro y que `verify` descifre; **no prueba la criptografía real** (en esta
máquina no hay `gpg` instalado) y el lector del `.env` (espacios finales, CR, comentarios, precedencia del entorno, `restore.sh --verify`):

```bash
cd infra/aws
export PATH=/opt/homebrew/opt/postgresql@15/bin:$PATH
RECOVERY_CHECK_DB=predictily_test_w4c DB_PASSWORD=<clave local> npm test -- recovery
```

No probado: modo `docker` de `recovery-check.sh` (código escrito con la misma lógica que `restore.sh`, **implemented_untested**),
respaldos cifrados con GPG **real** (solo el flujo con un stub) y bases de varios GB (el tiempo de restauración crece con el tamaño: el RTO real se mide).

## 5. Restauración en el VPS (procedimiento vigente)

```bash
docker compose stop backend queue queue-forecasting scheduler engine
infra/scripts/restore.sh backups/predictily_AAAAMMDDTHHMMSSZ.dump        # pide confirmar escribiendo el nombre de la base
docker compose start backend queue queue-forecasting scheduler engine
```

`restore.sh --verify <archivo>` hace una restauración de humo (6 tablas); la comprobación **completa** es `recovery-check.sh verify`.
Después de restaurar: `curl -I https://<dominio>/up`, iniciar sesión, abrir un pronóstico reciente y verificar que `php artisan
migrate:status` no tiene pendientes.

## 6. Recuperación en AWS — DISEÑADA, NO PROBADA (BLOQUEADO)

| Escenario | Procedimiento (resumen) | Fuente de datos |
|---|---|---|
| Borrado o corrupción lógica en la base | restaurar a un punto en el tiempo **en una instancia nueva** (`restore-db-instance-to-point-in-time`), validar con `recovery-check`-equivalente (conteos), y conmutar `DB_HOST` actualizando la pila (o el secreto) y redesplegando | respaldos automáticos de RDS (14 d en producción) |
| Fallo de la instancia o de la zona (Single‑AZ) | crear instancia desde el último snapshot automático o manual en la otra zona; los servicios ECS se reinician solos | snapshot de RDS |
| Borrado accidental de objetos | recuperar la versión anterior del objeto en el bucket versionado (`aws s3api list-object-versions` / `get-object --version-id`) | versionado de S3 (versiones no vigentes 30 d en Bronze/Silver/Gold, 90/365 d en artefactos) |
| Pérdida de EFS | restaurar el último punto de recuperación de AWS Backup (respaldo automático activado) en un sistema de archivos nuevo y apuntar los puntos de acceso | AWS Backup (EFS) |
| Pérdida de la pila | `cdk deploy` de `Platform` y `App` recrea la infraestructura; los recursos con `RETAIN` (claves KMS, buckets, EFS, secretos de la app, registros) sobreviven | IaC + recursos retenidos |
| Secretos | `app-key` y la clave de firma se restauran desde la copia externa; el resto se regenera y se redesplega | copia fuera de línea |
| Región completa no disponible | **fuera de alcance** de esta versión (sin réplica entre regiones): DISEÑADO | — |

Reglas: la restauración de RDS nunca sobrescribe la instancia existente (se crea una nueva y se conmuta); toda restauración en AWS
se ejecuta primero en **staging** con datos sintéticos; el acceso de emergencia queda registrado (CloudTrail del propietario).

**Simulacro (trimestral, desde el primer despliegue)**: restaurar el snapshot más reciente en una instancia temporal, correr los
conteos, registrar el tiempo real y compararlo con el RTO/RPO objetivo; el resultado aprueba o corrige los objetivos de §2.

## 7. Calendario y responsables

| Frecuencia | Acción | Responsable | Estado |
|---|---|---|---|
| Diaria | `backup.sh` (cron) con copia externa cifrada | propietario/operaciones | IMPLEMENTADO (VPS) |
| Mensual | `recovery-check.sh verify` sobre el último respaldo | propietario/operaciones | IMPLEMENTADO |
| Tras cada migración destructiva | respaldo previo y `verify` | quien despliega | IMPLEMENTADO (procedimiento) |
| Trimestral | simulacro de recuperación en AWS | propietario | DISEÑADO · BLOQUEADO |
| Anual | revisión de objetivos RTO/RPO y del criterio Multi‑AZ | propietario | DISEÑADO |

## 8. Riesgos abiertos

1. El manifiesto de conteos no es transaccional con el volcado (§3).
2. Sin restauración real en AWS no hay RTO medido; Single‑AZ puede superar el objetivo de 4 h con bases grandes.
3. Los archivos de importación/exportación en EFS no tienen aún un procedimiento de restauración probado.
4. La copia de `APP_KEY` y de las claves de firma depende de un proceso humano: sin ellas, los datos cifrados o los artefactos firmados
   no se recuperan.
5. Un respaldo de producción restaurado en staging contendría datos reales: prohibido salvo anonimizado.
