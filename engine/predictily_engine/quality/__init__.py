"""Métricas de dataset y gates de calidad por módulo (ADR-V25-05 y ADR-V25-24, V2.5 §5).

Antes de entrenar y de inferir, cada módulo (demanda, Customer, abastecimiento) consulta un gate que devuelve PASS, WARN, DEGRADE o
BLOCK con motivos de código estable:

- `metrics`     : métricas de dataset AL CORTE (cobertura, % inválido, integridad de claves, historia, ceros, variabilidad, estabilidad
                  de IDs, presencia de costo/inventario/precio, cierres y quiebres, frescura). Usan la lectura as-of y la clasificación de
                  días de W1: `missing_source` y `closed` nunca cuentan como ceros.
- `signals`     : lo que no está en memoria y se lee de la base con el mismo corte (informes de ingestión, alias, desfase de ingestión).
- `rules`       : tabla declarativa de reglas (código → métrica → umbral → severidad) y de datos requeridos.
- `features`    : mapa declarativo feature → datos requeridos y su efecto cuando el gate DEGRADE la deshabilita.
- `gates`       : evaluación de las reglas.
- `assessment`  : orquestación por módulo (`assess_demand`, `assess_customer`, `assess_supply`) y el resultado `QualityAssessment`.
- `persistence` : fila `data_quality_reports` (scope `run`) por ejecución.
- `thresholds`  : justificación escrita de cada umbral (los valores viven en `platform/config.py`, bloque `quality_gates`).

Este `__init__` no importa nada: `platform/config.py` es independiente de este paquete y evita ciclos de importación.
"""
