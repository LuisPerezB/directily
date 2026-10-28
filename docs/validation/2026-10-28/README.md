# Evidencia de validación del 28 de octubre de 2026

Estos resultados corresponden al estado local examinado durante la revisión de etapa 1. HEAD identificado: `ebd0d5a361d1ee8fba093efb88e08727511c5db3`; no se certificó coincidencia íntegra del árbol con ese commit. No representan pruebas de la etapa 2 implementada.

## Contenido

| Archivo | Procedencia y alcance |
|---|---|
| [unit-results.xml](unit-results.xml) | Resultado histórico sin modificaciones: 34 pruebas unitarias del motor aprobadas |
| [probe-results.json](probe-results.json) | Resultado histórico sin modificaciones: cinco defectos reproducidos con entradas controladas |
| [benchmark-results.json](benchmark-results.json) | Resultado histórico sin modificaciones: demo sintética en memoria, 183 series pronosticadas |
| [environment-review.json](environment-review.json) | Entorno observado al preparar la guía; no es una prueba de carga |
| [run_unit_suite.py](run_unit_suite.py) | Ejecuta únicamente la suite unitaria original del motor |
| [probe_findings.py](probe_findings.py) | Reproduce las condiciones defectuosas de F1–F5 |
| [benchmark_in_memory.py](benchmark_in_memory.py) | Genera Ferretería Andina con semilla 42 y ejecuta el pipeline sin BD |
| [_validation_context.py](_validation_context.py) | Resuelve rutas, salida temporal y cachés de cada ejecución |

Los tres scripts conservan la lógica de las comprobaciones originales y adaptan las rutas y la salida para poder ejecutarse desde otra copia del repositorio. Los JSON/XML históricos se conservaron byte por byte. El XML puede contener rutas de la máquina donde se ejecutó originalmente.

## Repetir las comprobaciones

Requiere Python 3.12 y las dependencias de `engine/pyproject.toml`, incluido el extra `dev`. Desde la raíz y con un entorno creado exclusivamente para el proyecto:

```bash
python3.12 -m venv engine/.venv
engine/.venv/bin/python -m pip install -e './engine[dev]'
engine/.venv/bin/python -B docs/validation/2026-10-28/run_unit_suite.py
engine/.venv/bin/python -B docs/validation/2026-10-28/probe_findings.py
engine/.venv/bin/python -B docs/validation/2026-10-28/benchmark_in_memory.py
```

En Windows con Python 3.12 nativo, el ejecutable del entorno está en `engine\.venv\Scripts\python.exe`. La guía principal recomienda contenedores Linux para la aplicación completa. No ejecutar estas instrucciones dentro de una base productiva; estos tres scripts trabajan en memoria y no requieren conexión a una base.

Cada comando crea por defecto una **carpeta temporal nueva** e imprime su ubicación. Opcionalmente acepta `--repo-root RUTA` y `--output-dir RUTA_NUEVA`. Para conservar una repetición, elegir una salida persistente nueva. No sustituir los resultados fechados por los de otra versión del código.

**El script de reproducciones afirma los defectos históricos.** Después de corregirlos puede fallar una de esas aserciones: eso no demuestra una regresión. Convertir cada caso en una prueba del comportamiento correcto durante el bloque de estabilización; no modificar el producto para que vuelva a cumplir la aserción defectuosa.

Los tiempos varían por equipo, concurrencia y cachés. Los números de la demo son sintéticos; no certifican precisión comercial. Las suites de backend/frontend, integración PostgreSQL y E2E no están incluidas en estas 34 pruebas. Consultar [el informe](../../../VALIDACION-PREDICTILY-2026-10-28.md) y [la guía](../../../EJECUCION-PREDICTILY.md).

## Integridad y conservación

`FILES-SHA256.json` lista las huellas del paquete documental guardado. Se usa para comprobar la copia; no es una firma del código ni prueba de que se ejecutó la etapa 2. No se archivan secretos, entornos virtuales ni cachés.
