"""Core de Demanda (etapa 2, paquete P1): horizontes, evaluación, casos especiales, cobertura y trazabilidad.

El pipeline puro vive en `predictily_engine.pipeline.core`; aquí están las piezas que lo completan:

- `horizons`: horizontes 7/14/30/60/90 y abstención explícita cuando la historia no los respalda.
- `evaluation`: métricas (WAPE/MAE/RMSE/sesgo, MASE/RMSSE, pinball P10/P50/P90, amplitud, dinero).
- `lifecycle`: productos nuevos, descontinuados, aperturas/cierres de sucursal y eventos extraordinarios.
- `coverage`: completitud declarada de la fuente de ventas (`data_coverage`).
- `manifest`: manifiesto del protocolo de evaluación (`forecast_runs.evaluation_manifest`).
- `snapshot`: huella reproducible de todos los insumos de la ejecución.
"""
