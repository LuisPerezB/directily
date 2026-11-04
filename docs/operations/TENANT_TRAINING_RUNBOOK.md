# Runbook del entrenamiento privado por empresa (pasos exactos del propietario)

Especificación: V2.5 §9 · ADR-V25-19 (CLI), ADR-V25-08 (almacén de objetos), ADR-V25-07 (aprobación separada), ADR-V25-20 (firma). Contexto, garantías y estado de cada capacidad:
[`docs/ml/PRIVATE_TENANT_TRAINING.md`](../ml/PRIVATE_TENANT_TRAINING.md). Entrenamiento automático (solo diseño): [`docs/ml/AUTOMATED_TRAINING.md`](../ml/AUTOMATED_TRAINING.md).

Estado de este runbook: los comandos y su comportamiento están **IMPLEMENTADOS / VERIFICADOS** contra el motor, una base PostgreSQL local migrada y el almacén de objetos **local**; el controlador S3 está verificado
solo contra un **emulador** y el almacén en AWS real está **BLOQUEADO** (sin cuenta del propietario). El flujo con datos reales de una empresa **no se ha ejecutado** (se hace en el equipo del propietario).

## 0. Reglas que no se negocian

1. **Autorización escrita antes de exportar.** Sin referencia de autorización, `export-local` no toca nada. La autorización la da quien tiene la base legal sobre esos datos; el motor solo registra la referencia.
2. **Nada en claro fuera del servidor.** La exportación sale cifrada (AES-256-GCM) y vence. La clave de la exportación viaja por un canal distinto del de la exportación.
3. **Nada de credenciales en documentos, logs ni mensajes.** Ni la clave privada de firma, ni la clave de la exportación, ni la cadena de conexión con contraseña, ni claves de acceso del almacén. Use archivos 0600, secretos del orquestador o el `.env` local no versionado.
4. **Quien publica no aprueba.** El usuario que usted indica en `--published-by-user-id` queda como `published_by` y no podrá aprobar ese modelo. Otra persona con la habilidad `models.approve` valida, aprueba y promueve en el backend. Ese usuario es una **declaración** de quien ejecuta la CLI: debe ser de la empresa y tener un rol con `forecast.run` (analista, administrador o propietario), y el evento de auditoría deja el usuario del sistema operativo y el equipo desde donde se ejecutó. **Es un control organizativo, no criptográfico**: quien tenga acceso a la CLI y a la base puede declarar a otro usuario; por eso conviene que la persona que aprueba compruebe en el evento (`cli_operator`) que el publicador declarado es quien dice ser, y que el acceso a la CLI esté restringido a personas identificadas.
5. **Un modelo publicado es un candidato.** Hasta su aprobación separada el motor no lo carga. No hay atajo.
6. **Repositorio público.** Ningún dato real, exportación, clave, métrica identificable ni nombre de cliente se versiona (usar `data/private/` y `reports/private/`, ignorados por git).

## 1. Preparación (una vez por máquina de entrenamiento)

| Paso | Comando o acción |
|---|---|
| 1.1 Código | `git checkout main && git pull` (o la rama acordada); `cd engine && python3.12 -m venv .venv && .venv/bin/pip install -e .` |
| 1.2 Extras opcionales | `.venv/bin/pip install -e '.[mlops]'` (exportación; ya suele estar). Solo si va a publicar a S3: `.venv/bin/pip install -e '.[s3]'` |
| 1.3 Par de claves de firma | `.venv/bin/python -m predictily_engine.cli keygen --key-id k-AAAA-MM --private-out /ruta/segura/firma.key` → imprime la clave pública y su huella (la privada no se imprime; queda 0600). Confirme la huella por otro canal |
| 1.4 Variables del entrenamiento | `ENGINE_SIGNING_KEY_ID=k-AAAA-MM`, `ENGINE_SIGNING_PRIVATE_KEY=file:/ruta/segura/firma.key`, `ENGINE_TRUSTED_PUBLIC_KEYS=k-AAAA-MM:<clave pública>`, `ENGINE_DATABASE_URL` (la base **de destino** del registro), y el almacén (§1.5) |
| 1.5 Almacén de objetos | **Local**: `ENGINE_OBJECT_STORE_DRIVER=local` y `ENGINE_OBJECT_STORE_ROOT=<raíz del disco local del backend>` (en el monorepo, `backend/storage/app/private`; el backend lo lee con `PREDICTILY_MODELS_DISK=local`). **S3**: `ENGINE_OBJECT_STORE_DRIVER=s3`, `_BUCKET`, `_REGION`, `_SSE=aws:kms`, `_KMS_KEY_ID`; credenciales por la cadena de boto3 (perfil o rol), nunca en variables del motor |
| 1.6 Lado del backend | `ENGINE_TRUSTED_PUBLIC_KEYS` con la **misma** clave pública (sin la privada) y `PREDICTILY_MODELS_DISK` apuntando al **mismo** almacén. Sin esto el backend no podrá verificar la firma ni aprobar |
| 1.7 Diagnóstico | `.venv/bin/python -m predictily_engine.cli diagnose --require db,object-store,signing` → debe salir `0`. Si sale `1`, lea `checks` (fallos: base inalcanzable o sin migrar, almacén sin escritura, clave de confianza distinta de la privada) |

`diagnose` imprime identificadores y huellas de claves, nunca material de clave ni contraseñas; es seguro pegar su salida en un ticket.

## 2. Exportar los datos de la empresa (en el servidor, con autorización)

Abreviaturas: `CLI = python -m predictily_engine.cli`, `T` = id de la empresa, `C` = `demand` o `customer`.

```
CLI export-local --tenant-id T --core C \
  --authorized-export AUT-2026-0042 --authorized-by "Nombre de quien autoriza" --purpose "Entrenar el core C de la empresa T en local" \
  --out /ruta/exportaciones --key-file /ruta/segura/export.key --generate-key --ttl-hours 72
```

- Salida 4 si falta la referencia, quién autoriza o la referencia es inválida (no se toca la base). Salida 2 si el propósito es demasiado corto o `--ttl-hours` está fuera de 1–720.
- Resultado: `/ruta/exportaciones/export-<id>/` con `data.enc` (cifrado), `export.manifest.json` (referencia de autorización, tablas, columnas descartadas, huella de la clave, vencimiento) y `.predictily-export`. El manifiesto está **autenticado** por el cifrado: si alguien lo edita (empresa, vencimiento, autorización), `train --from-export` sale con 4. Las devoluciones y las compras de los compradores usan **un solo mapa de seudónimos**, así que siguen emparejadas.
- Compruebe en el JSON: `encrypted: true`, `dropped_columns` (lo que se descartó por minimización) y `asof_fidelity: approximate_no_versions` (§ «Fidelidad» de `PRIVATE_TENANT_TRAINING.md`: la exportación no trae el historial de versiones con tiempo de conocimiento).
- Transfiera `export-<id>/` a la máquina de entrenamiento y la **clave** (`export.key`) por **otro canal**. Mientras no la use, no la deje junto a la exportación.

Si el entrenamiento se hace en el propio servidor con acceso a la base, puede omitir este paso y usar la base directamente (`--from-export` no se indica).

## 3. Entrenar y evaluar en local

Con la exportación (`X = /ruta/exportaciones/export-<id>`, `K = /ruta/segura/export.key`). Sin exportación, omita `--from-export` y `--key-file`.

| Paso | Comando | Qué mirar |
|---|---|---|
| 3.1 Calidad de datos | `CLI validate-data --tenant-id T --core C --from-export X --key-file K` | `outcome`. `BLOCK` (salida 1) detiene todo: corrija los datos. `DEGRADE` deshabilita variables (`disabled_features`) y queda declarado |
| 3.2 Variables | `CLI build-features --tenant-id T --core C --from-export X --key-file K --out features.json` | `feature_schema_version`, `data_fingerprint`, series o cortes |
| 3.3 Backtest (opcional) | `CLI backtest --tenant-id T --core C --from-export X --key-file K --out backtest.json` | `metrics_summary` por horizonte y segmento; no guarda artefactos |
| 3.4 Ajuste (opcional, demanda) | `CLI tune --tenant-id T --core demand --from-export X --key-file K --trials 20 --timeout-seconds 120 --out /ruta/tuned` | `tuning.holdout_used` debe ser `false`; salida 3 si Optuna no está instalado |
| 3.5 Entrenar | `CLI train --tenant-id T --core C --from-export X --key-file K --out /ruta/entrenamiento` | `signed: true`, `models`, `headline.data_gate`. `--out` debe ser un directorio **nuevo** (salida 6 si ya hay un entrenamiento) |

Entrenar **no escribe nada en la base de datos**. Con `--from-export` el motor descifra en memoria; no deja datos en claro en disco.

## 4. Empaquetar y verificar

```
CLI package --dir /ruta/entrenamiento --sign
CLI verify  --dir /ruta/entrenamiento --require-package --tenant-id T
```

- `package` crea `package.manifest.json` firmado (huellas, versiones de librerías, huella de datos, métricas y resultado del gate). Salida 1 si algún artefacto no verifica como firmado; salida 3 sin clave privada.
- `verify` debe dar `ok: true` y `package: verified`; cualquier manipulación (artefacto, manifiesto o paquete) da salida 1 con el motivo (`artifact_sha256_mismatch`, `package_signature_invalid`, `package_untrusted_key`, `package_tenant_mismatch`…).
- Para comparar contra lo que ya sirve: `CLI compare --tenant-id T --core C --a <versión del registro> --b bundle:/ruta/entrenamiento` (veredicto descriptivo; no recomienda promover).

## 5. Publicar como candidato

Primero un ensayo (no escribe en el almacén ni en la base):

```
CLI publish --dir /ruta/entrenamiento --env staging --published-by-user-id U --tenant-id T --dry-run
```

Luego la publicación real:

```
CLI publish --dir /ruta/entrenamiento --env staging --published-by-user-id U --tenant-id T
```

- `U` es un usuario **de la empresa T**, no eliminado y con un rol con `forecast.run` (si no, salida 4). Quedará como `published_by` y no podrá aprobar. Es una declaración suya: el evento deja además `cli_operator` (usuario del sistema operativo y equipo) y el rol declarado.
- `--env production` exige además `--confirm-production`. Sigue siendo solo un candidato.
- El resultado lista, por modelo, `version`, `object_key` (`tenants/<uuid>/models/<core>/<versión>/<archivo>`, generada por el servidor) y `action` (`published` | `already_published`). Repetir el MISMO comando es seguro (idempotente). Repetirlo con otro `--published-by-user-id` o con otro `--env` es un conflicto (salida 6): una versión ya publicada no se republica a nombre de otra persona. Dos publicaciones simultáneas del mismo paquete se serializan; la segunda responde `already_published` y no borra nada de la primera.
- Si algo falla a mitad, `publish` borra del almacén lo que esa llamada creó (también un artefacto ya subido cuyo manifiesto chocó con otro objeto) y no deja filas a medias; lo que ya existía antes no se toca.
- Compruebe: `CLI list-models --tenant-id T --core C` → los nuevos aparecen con `status: candidate`, `serving_basis: not_approved`, `servable: false`.

## 6. Aprobación separada (en el backend, por otra persona)

Quien aprueba debe tener la habilidad `models.approve` (propietario o administrador) y **no** ser `U` ni quien disparó el entrenamiento. Por modelo (`ID` = su id en `list-models`):

| Paso | Llamada | Quién |
|---|---|---|
| 6.1 Validar | `POST /api/v1/models/ID/validate` | `forecast.run` (analista o superior). Ejecuta las compuertas: firma verificada **desde el almacén**, gate de datos ≠ BLOCK (el declarado por la CLI), política y compatibilidad. No vuelve servible al modelo |
| 6.2 Aprobar | `POST /api/v1/models/ID/approve` | `models.approve`, ≠ publicador/entrenador (403 `approval_segregation_of_duties` si no) |
| 6.3 Promover | `POST /api/v1/models/ID/promote` | `models.approve`. Retira al que servía en el mismo alcance (para Customer, el mismo `slot`) |

Rechazar: `POST /api/v1/models/ID/reject` con motivo. Errores frecuentes: `approval_artifact_not_verifiable` (el backend no ve el almacén: revise `PREDICTILY_MODELS_DISK`), `approval_artifact_signature_invalid` (falta la clave pública en el
backend), `approval_data_gate_missing` (candidato sin gate declarado: no debería ocurrir con esta CLI).

A partir de la promoción, el motor carga el modelo desde el almacén en la siguiente predicción del Customer Core, verificando firma y empresa. Nota: el motor de **demanda** reajusta sus modelos en cada corrida y no carga
artefactos (NO APLICA hoy); lo publicado queda como evidencia auditable y candidato.

## 7. Reversión

```
CLI rollback --tenant-id T --core C --to-version V
```

No cambia nada. Si la versión es servible, imprime la llamada exacta: `POST /api/v1/models/customer/rollback {"model_registry_id": N}` (Customer), `POST /api/v1/models/demand/rollback {"run_id": R}` (demanda, por corrida) o
`POST /api/v1/models/N/promote` (aprobado que nunca sirvió). Si no es servible (no aprobado), sale 1 explicando el camino de aprobación.

## 8. Limpieza (al terminar y siempre al vencer)

1. `CLI cleanup-exports --root /ruta/exportaciones` borra las exportaciones vencidas (`--all` borra también las vigentes; `--dry-run` solo lista). Sobrescribe con ceros antes de borrar (mejor esfuerzo).
2. Borre el archivo de clave de la exportación (`export.key`) y el directorio de entrenamiento (`/ruta/entrenamiento`: contiene artefactos y borradores, no datos de la empresa).
3. La clave privada de firma NO se borra: se custodia (§1.3); rótela según `MODEL_REGISTRY.md` §2.3.

## 9. Códigos de salida y qué hacer

| Salida | Significado | Qué hacer |
|---|---|---|
| 0 | ok | — |
| 1 | comprobación negativa | Lea el JSON/stderr: gate BLOCK, firma que no verifica, comparación sin cifras comunes, destino no servible, diagnóstico con fallos |
| 2 | uso inválido | Revise la ayuda en español: `CLI <comando> --help` (las opciones numéricas tienen rango: `--trials` 1–200, `--max-windows` 1–6, `--ttl-hours` 1–720…; un horizonte fuera del catálogo del core es un error) |
| 3 | entorno o configuración | `CLI diagnose --require db,object-store,signing`; claves, base, almacén o biblioteca opcional; o una variable mal escrita (`ENGINE_LOG_LEVEL`, `ENGINE_ARTIFACT_CACHE_ENTRIES`). Si `ENGINE_DATABASE_URL` tiene caracteres especiales en la clave (`@`, `/`, `?`, `#`), codifíquelos en porcentaje (`@` → `%40`): `diagnose` no imprime esa URL ni se conecta con ella |
| 4 | sin autorización | Falta la referencia/persona/propósito de la exportación, el usuario no es de la empresa o su rol no tiene `forecast.run`, exportación vencida, alterada o de otra empresa, `compare` con el paquete de otra empresa, producción sin `--confirm-production` |
| 5 | no encontrado | Empresa, versión, paquete o exportación inexistentes (o de otra empresa) |
| 6 | conflicto | Se negó a sobrescribir (directorio de salida, versión publicada con otro contenido, por otro usuario o en otro entorno) |
| 7 | error interno | Repita con `ENGINE_LOG_LEVEL=DEBUG`; no pegue el detalle sin revisarlo (puede contener rutas) |

## 10. Checklist del propietario antes de la primera publicación real

- [ ] Autorización escrita y referencia registrada para la empresa y la finalidad.
- [ ] Par de claves de firma generado en la máquina de entrenamiento; huella confirmada por otro canal; pública instalada en el motor **y** en el backend (nunca la privada).
- [ ] `diagnose --require db,object-store,signing` en `0` en la máquina de entrenamiento.
- [ ] El backend y la CLI apuntan al **mismo** almacén (`PREDICTILY_MODELS_DISK` ↔ `ENGINE_OBJECT_STORE_*`).
- [ ] Persona aprobadora distinta de quien publica, con `models.approve`; el acceso a la CLI está restringido a personas identificadas (el publicador es una declaración, control organizativo) y quien aprueba revisa `cli_operator` en el evento.
- [ ] Umbrales de calidad (`quality_gates`) y de promoción (`promotion`) revisados: son valores iniciales **a aprobar en el piloto**.
- [ ] Plan de limpieza: quién ejecuta `cleanup-exports` y cuándo.
- [ ] (AWS) Cuenta, presupuesto y rol de mínimo privilegio listos: **BLOQUEADO** hasta entonces; ver `docs/operations/AWS_DEPLOYMENT.md`.
