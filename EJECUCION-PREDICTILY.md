# Predictily — Orden de ejecución y capacidades necesarias

Fecha de revisión: 28 de octubre de 2026.

**El proyecto actual puede ejecutarse para demostrar pronóstico agregado y sus flujos existentes. Completar los cuatro cores requiere implementar la etapa 2. Levantar la aplicación, implementar el core pendiente y validar precisión comercial son tres resultados distintos.**

Repositorio revisado: `C:\Users\Luis.perez\Documents\prueba expalsa\backend\directily`.

## 1. Qué leer y en qué orden

| Orden | Documento | Para qué sirve |
|---|---|---|
| 1 | [Esta guía](EJECUCION-PREDICTILY.md) | Preparar el entorno, arrancar y entender dependencias |
| 2 | [Validación de etapa 1](VALIDACION-PREDICTILY-2026-10-28.md) | Conocer lo comprobado y los siete hallazgos F1–F7 |
| 3 | [instrucctions.md](instrucctions.md), secciones 1–5 | Entender alcance, decisiones y frontera entre core y personalización |
| 4 | [instrucctions.md](instrucctions.md), sección 6 completa | Usar el prompt vigente de implementación de etapa 2 |
| Referencia | [Prompt V2 original recuperado](PROMPT-ETAPA-2-ORIGINAL-RECUPERADO.md) | Consultar el antecedente histórico, no ejecutarlo como un segundo encargo independiente |
| Evidencia | [Validación reproducible](docs/validation/2026-10-28/README.md) | Resultados históricos, scripts y cómo volver a comprobarlos |

El anexo original contiene las secciones 1–10 completas; la lectura disponible truncó la continuación. La instrucción actual prioriza el core común y separa los módulos por rubro o empresa.

## 2. Orden de implementación de la etapa 2

Entregar al agente o equipo la **sección 6 completa** de `instrucctions.md` una vez. Ejecutar después por bloques, manteniendo sus invariantes en todos ellos. Esta tabla detalla las dependencias; no son siete prompts independientes que deban reconstruir el proyecto.

| Bloque | Trabajo | Dependencias | Evidencia para avanzar |
|---|---|---|---|
| 0. Preparación | Revisar instrucciones/código y crear el análisis de brechas; preparar entorno y base de pruebas | Acceso al repositorio y dependencias | Estado actual documentado; recorrido de etapa 1 reproducible; pruebas omitidas identificadas |
| 1. Estabilización | F1–F3: recepciones, horizonte e incertidumbre; F7: plan Básico; F4–F6: fugas temporales y evaluación | Bloque 0 | Casos reproducidos convertidos en pruebas de comportamiento correcto |
| 2. Datos y contratos | Identidades, unidades, cobertura, historial por comprador, disponibilidad temporal, snapshots, pedidos y configuración | Bloque 1; contratos temporales pueden construirse junto con las correcciones | Reimportación idempotente; reconstrucción por corte; aislamiento entre empresas; al menos un recorrido SQL comprobado |
| 3. Demanda | Evaluación cronológica independiente, calibración, respaldo, jerarquías y horizontes | Bloques 1–2 | Métricas recalculadas; período final reservado; intervalos y modelo realmente servido trazables |
| 4. Comportamiento | Compra 7/30/90, cantidad, monto, frecuencia, ranking e inactividad; reconciliación | Bloques 2–3 | Etiquetas maduras; probabilidades calibradas; resultados por comprador sin duplicar demanda ni pedidos |
| 5. Abastecimiento y decisiones | Balance fechado, costos, proveedores, optimización de reposición, transformación genérica y escenarios | Bloques 1–3; conectar bloque 4 al reconciliar demanda | Plan factible con proveedor, cantidad y fechas; límites de presupuesto/capacidad; incertidumbre explícita |
| 6. Integración y validación | API/UI, jobs, registro de modelos, seguimiento, pruebas E2E y datos reales autorizados | Bloques anteriores | Matriz E2-01–E2-26 con evidencia; distinguir implementación, prueba sintética y validación comercial |

Integrar y probar cada bloque al entregarlo; no esperar al bloque 6 para conectar componentes. Preparar datos reales desde el bloque 0 en paralelo, sin impedir el avance con sintéticos. Costos y abastecimiento pueden avanzar mientras se desarrolla comportamiento, después de acordar los contratos y la reconciliación.

**Después del core:** perfiles por rubro o empresa, conectores particulares y modelos adicionales que demuestren valor. La causalidad del precio requiere evidencia y evaluación propias; la simulación con supuestos puede funcionar antes. Un chat con LLM es opcional.

## 3. Capacidades funcionales y técnicas necesarias

| Capacidad | Para ejecutar hoy | Para completar la etapa 2 |
|---|---|---|
| Datos | Ventas normalizadas, empresa/producto/sucursal, fechas, unidades y calidad de importación | Comprador, cobertura temporal, precios/costos, pedidos, recepciones y revisiones reconstruibles al corte |
| Demanda | StatsForecast y LightGBM + MLForecast, con modelos de referencia | Corregir evaluación temporal; comparar por horizonte y calibrar incertidumbre sin información futura |
| Comportamiento | Aún no existe el core individual requerido | Clasificación de compra, cantidad condicionada, monto, ranking, recurrencia y calibración |
| Abastecimiento | Recomendaciones presentes, con defectos documentados | Balance por fecha, incertidumbre, horizonte físico completo y optimización bajo restricciones |
| Operación | API, base de datos, colas, motor y web | Idempotencia, concurrencia, modelos versionados, seguimiento y recuperación ante fallos |
| Personalización | Parámetros de la empresa | Interfaces de extensión; reglas particulares fuera del algoritmo común |

Para implementar hacen falta capacidades de ingeniería de datos/SQL; Python y evaluación de modelos temporales; Laravel y jobs; Vue/TypeScript para integrar resultados; optimización matemática; y operación/pruebas de servicios. Una misma persona o agente puede cubrir varias, pero debe poder ejecutar pruebas reales y revisar sus resultados.

El entorno de trabajo debe permitir leer y editar el repositorio, instalar las dependencias fijadas, ejecutar procesos y pruebas, utilizar una base aislada y acceder a datos autorizados. Acceso a un ERP real solo es necesario para integrar esa fuente y validar comercialmente. No hace falta contratar un LLM para que el cálculo funcione.

## 4. Software necesario

### Ruta principal: Docker con contenedores Linux

| Elemento | Requisito del proyecto | Observación |
|---|---|---|
| Docker | README indica Engine 24+ y Compose 2.24+ o versión posterior compatible | Verificar que el motor esté iniciado; tener solo el comando instalado no basta |
| Windows | Docker Desktop con motor WSL 2 y virtualización habilitada | Comandos Docker disponibles en PowerShell; el Makefile requiere Bash |
| Backend | PHP 8.3, Composer 2 y extensiones incluidas en la imagen | No se necesitan instalados en Windows cuando se usa la imagen |
| Motor | Python **3.12.x** y dependencias de `engine/pyproject.toml` | El manifiesto exige >=3.12 y <3.13; no sustituir por otra versión sin validar |
| Frontend | Node **22.x, al menos 22.13**, para compilar y probar todo | La imagen utiliza Node 22; el servidor final sirve archivos estáticos con nginx |
| Datos | PostgreSQL 16 | El esquema lo administran las migraciones de Laravel |
| Colas/caché/sesiones | Redis 7 | Necesario para el flujo asíncrono configurado |
| Web | nginx; Caddy para HTTPS en producción | Definidos en Dockerfiles/Compose |
| Instalación | Acceso a registros de imágenes y repositorios de paquetes | Usar los archivos lock y las versiones fijadas; después, el cálculo básico es local |

El `frontend/package.json` declara Node >=20.19, pero el lock requiere versiones más altas para algunas herramientas: Vitest 5 exige, en la rama 22, al menos 22.12 y ESLint 10 al menos 22.13. Por eso Node 16/20 no es la elección para ejecutar la suite completa de este checkout.

Docker documenta WSL 2.1.5 o posterior y virtualización habilitada para esta ruta. Una distribución Ubuntu adicional es útil para `make`, pero no es obligatoria para ejecutar Docker desde PowerShell. Ver [requisitos de Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/) y [uso de WSL 2](https://docs.docker.com/desktop/features/wsl/).

### Ruta alternativa: desarrollo nativo en Linux/WSL

Instalar PHP 8.3 y Composer 2; extensiones `pdo_pgsql`, `redis`, `intl`, `zip`, `gd`, `mbstring`, `xml`, `bcmath`, `pcntl` y las dependencias base de la imagen; PostgreSQL 16; Redis 7; Python 3.12 con `venv`; `libgomp1`; y Node 22 >=22.13.

Instalar con `composer install`, `pip install -e '.[dev]'` dentro de un entorno del motor y `npm ci` en frontend. Configurar los archivos de entorno por componente antes de migrar. Las instrucciones nativas están en [README.md](README.md) y [engine/README.md](engine/README.md).

El Makefile usa Bash, herramientas Unix y `.venv/bin`; no copiarlo literalmente a PowerShell. Tampoco repetir `make native-setup` sobre una instalación existente sin revisar: ejecuta `key:generate` y cambiar APP_KEY puede impedir descifrar datos guardados.

## 5. Capacidad de cómputo

| Uso | Referencia | Qué significa |
|---|---|---|
| Instalación inicial/demo o servidor pequeño | **4 vCPU, 8–16 GB RAM, 40+ GB SSD**, según README/despliegue | Dimensionamiento inicial documentado, no garantía para un número fijo de empresas |
| Motor en Compose | Límite de memoria 4 GB, 2 workers y `ENGINE_N_JOBS=2` | Valores configurados; ajustar tras medir concurrencia y consumo |
| Crecimiento | Medir series activas, largo del histórico, horizontes y ejecuciones simultáneas | Aumentar recursos según mediciones; reservar disco para datos, importaciones, modelos y respaldos |
| GPU | No requerida | LightGBM y estadísticos actuales se ejecutan con CPU |
| LLM/Ollama/OpenAI | No requerido | Solo una capa conversacional opcional |

Los 5.000 de `ENGINE_MAX_SERIES_LGBM` son un límite configurado, no una capacidad de carga certificada. La demo previa de 183 series no permite deducir cuántos clientes productivos pueden trabajar simultáneamente. Los 8–16 GB se refieren a recursos para la instalación; en Windows debe quedar memoria para el sistema y otras aplicaciones.

### Estado comprobado de este equipo

| Elemento | Observado el 28-10-2026 |
|---|---|
| CPU | Intel Core Ultra 5 225; 10 núcleos y 10 procesadores lógicos informados por Windows |
| RAM | 31,4 GiB aproximadamente, equivalente al equipo nominal de 32 GB |
| Disco C: | Aproximadamente 226 GiB libres en la consulta |
| Docker CLI / Compose | 29.7.2 / 5.4.0 |
| Motor Docker | No disponible en la consulta: falta la conexión a `dockerDesktopLinuxEngine` |
| Node / npm activos | 16.20.2 / 8.19.4; insuficientes para la ruta nativa de este frontend |
| PHP / Composer en PATH | No encontrados; esto no comprueba que no existan instalados en otra ubicación |
| Python usado en la validación | 3.12.14 en un entorno separado del chat |
| WSL, virtualización y recursos asignados a Docker | Pendientes de comprobar |

El equipo supera la referencia inicial de CPU/RAM y espacio libre. Falta verificar la configuración y arrancar el entorno; no se ha hecho una prueba de carga del conjunto. El Python de validación no se copia como dependencia al repositorio: se recrea desde el manifiesto.

## 6. Orden para arrancar la aplicación actual

Los pasos de esta sección son una guía; no se ejecutó el arranque completo al redactarla. Aplicar primero a una instalación local/de pruebas.

### 6.1 Comprobar Docker y preparar la configuración

Iniciar Docker Desktop y seleccionar contenedores Linux. En PowerShell:

```powershell
Set-Location -LiteralPath 'C:\Users\Luis.perez\Documents\prueba expalsa\backend\directily'
docker version
docker compose version
```

`docker version` debe mostrar cliente **y servidor**. Si falta el servidor, resolver Docker/WSL antes de construir.

Para una instalación nueva, en una terminal Bash/WSL con Docker integrado y `make`/`openssl` disponibles:

```bash
cd "/mnt/c/Users/Luis.perez/Documents/prueba expalsa/backend/directily"
make setup
```

Esto prepara `.env` y genera los secretos iniciales. Si ya existe una instalación, conservar APP_KEY, contraseñas y token; no regenerarlos sin un procedimiento de rotación.

La alternativa en PowerShell es copiar `.env.example` a `.env` **solo si no existe** y completar los valores con secretos generados de forma segura. No arrancar con los textos `cambiar_...`. APP_KEY debe ser una clave Laravel válida; DB_PASSWORD debe respetar el formato indicado en el ejemplo porque Compose la incorpora a una URL.

Revisar URL pública `http://localhost:8080`, puerto, conexión de datos, token interno y dominios de sesión. Con Compose el mismo `ENGINE_API_TOKEN` se pasa a backend y motor. Evitar compartir o registrar los valores de `.env`.

### 6.2 Validar, construir y levantar

Desde la raíz, en PowerShell o Bash, ejecutar por pasos y comprobar cada salida:

```text
docker compose config --quiet
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 backend engine queue queue-forecasting
```

Orden lógico: PostgreSQL/Redis saludables → migraciones del backend → motor y API → workers → web y scheduler. Compose expresa las dependencias principales. Antes de cargar datos, comprobar que las migraciones finalizaron; `service_started` no acredita que el esquema esté listo.

### 6.3 Cargar datos base y comprobar el recorrido

Cuando el backend esté preparado:

```text
docker compose exec backend php artisan migrate:status
docker compose exec backend php artisan db:seed --force
```

En la instalación local/de pruebas, cargar opcionalmente la demo ficticia:

```text
docker compose exec backend php artisan predictily:demo --forecast
```

Abrir **http://localhost:8080**. Confirmar que se creó/importó la demo, el job pasó de pendiente a completado y aparecen resultados de pronóstico. Los workers `queue` y `queue-forecasting` son necesarios para importar y calcular; la pantalla por sí sola no demuestra que el flujo funcione.

Comprobar también `http://localhost:8080/up` y `docker compose ps`. El motor no publica 8100 hacia Windows. Su estado se puede consultar desde el propio contenedor:

```text
docker compose exec engine python -c "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8100/health').read().decode())"
```

Revisar el cuerpo: `status=ok` y `db=ok`. El endpoint actual puede responder HTTP 200 con estado degradado; el código HTTP o el healthcheck del contenedor por sí solos no certifican conexión a la base.

Recorrido mínimo: iniciar sesión → importar → validar mapeo → confirmar carga → generar pronóstico → ver resultado → reimportar sin duplicados. Registrar cualquier fallo y aplicar las correcciones de etapa 2; no usar el resultado de la demo como validación comercial.

## 7. Datos necesarios para obtener resultados útiles

| Capacidad | Datos indispensables |
|---|---|
| Demanda agregada | Fecha, empresa, producto, ubicación, cantidad/unidad, estado de la transacción y cobertura del histórico |
| Venta e ingreso | Precio, descuentos, moneda, impuestos según definición y tratamiento de anulaciones/devoluciones |
| Comprador | Identidad estable de comprador, líneas de compra, fechas, cantidades y cobertura de observación |
| Abastecimiento | Stock por fecha, reservas, movimientos, pedidos pendientes, recepciones esperadas/reales, plazos y proveedor |
| Optimización | Presupuesto, costos comparables, lotes/mínimos, capacidad, almacenamiento y prioridades |
| Costos/escenarios | Históricos de costo y trayectorias conocidas o supuestas, con fuente y fecha de disponibilidad |
| Producción opcional | Recursos, transformación de insumos, rendimientos, tiempos y capacidad, aportados por contrato |

El umbral actual de perfilado de 28 días de historia y cuatro días con ventas, o los mínimos de LightGBM de cinco series/1.500 filas, son condiciones del código, no garantías de precisión. Para estacionalidad y horizontes largos debe existir historia suficiente para entrenar y comprobar períodos posteriores completos. No hay un número universal de meses que asegure exactitud.

Soyoda es una fuente posible; no se requiere para arrancar con demos. Para validar comercialmente sí se necesita un histórico real autorizado y bien mapeado, reservas temporales de evaluación y observaciones posteriores. Si un comprador no es identificable, no se puede atribuirle una probabilidad individual fiable.

## 8. Pruebas, producción y criterio de terminado

En desarrollo, ejecutar las suites afectadas y los recorridos de integración. Las imágenes actuales de ejecución no incluyen necesariamente herramientas de pruebas: el backend instala sin dependencias de desarrollo y elimina tests. Usar un entorno de desarrollo/CI separado con dependencias dev y bases de pruebas.

Las pruebas de integración del motor vacían tablas de su base de pruebas. Comprobar siempre `ENGINE_TEST_DATABASE_URL` y las bases dedicadas `predictily_test`, `predictily_test_b2` y `predictily_engine_test`; nunca apuntarlas a datos operativos. Un resultado con integración omitida no equivale a integración aprobada.

La evidencia guardada acredita 34 pruebas unitarias del motor, cinco reproducciones y una demo en memoria. No acredita arranque completo, suites backend/frontend/E2E, TLS ni operación con Soyoda.

Para producción: completar la validación integral, configurar dominio/DNS y HTTPS, persistencia y respaldos con restauración probada, credenciales de producción, seguimiento de jobs/recursos y correo si se requiere. Usar [la guía de despliegue](infra/scripts/deploy.md). Conservar APP_KEY fuera del servidor junto con su recuperación. Los componentes de datos y motor permanecen en red interna.

Criterios separados:

- **Aplicación arrancada:** servicios disponibles, migraciones listas y recorrido funcional completado.
- **Etapa 2 implementada:** capacidades del core y matriz E2-01–E2-26 con evidencia, estados pendientes claramente identificados.
- **Validación comercial:** evaluación temporal independiente y operativa con datos reales; no se deduce de pruebas sintéticas.
- **Personalización:** extensiones por rubro/empresa verificadas contra el mismo core, después o en paralelo sin bloquear sus contratos.

## 9. Qué queda guardado en el repositorio

Se incluyen esta guía, las instrucciones, el anexo histórico, el informe, resultados XML/JSON y scripts de reproducción portables en `docs/validation/2026-10-28/`. Los scripts permiten elegir repositorio y carpeta de salida; no sobrescriben los resultados históricos por defecto. Ver su README.

Los entornos virtuales, dependencias instaladas, cachés y secretos son archivos locales generados; se recrean desde manifiestos y no forman parte del paquete documental. Guardar estos archivos no equivale a haber implementado el prompt ni a haber desplegado el producto.
