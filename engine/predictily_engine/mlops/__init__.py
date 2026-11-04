"""MLOps del motor (V2.5 · W4-B; ADR-V25-07, -08, -19, -20): CLI de entrenamiento privado por empresa, publicación de artefactos firmados
al almacén de objetos y serving solo de lo aprobado.

Módulos:
- `errors`: códigos de salida estables de la CLI y `CliError`.
- `policy`: espejo en Python de `ModelServingPolicy::basis()` del backend (qué modelo puede servirse).
- `serving`: filtro de serving, carga verificada de artefactos (directorio local o almacén de objetos) y caché con empresa + versión + esquema.
- `training`: entradas (base de datos o exportación autorizada) y entrenamiento/backtest/ajuste SIN escribir en la base de datos.
- `bundle`: paquete (artefactos + manifiestos firmados + metadatos), firma, verificación y publicación como `candidate`.
- `exports`: exportación local autorizada, minimizada, cifrada y su limpieza.
- `compare`, `diagnose`: comparación de versiones y diagnóstico sin secretos.
- `commands`: subcomandos de `python -m predictily_engine.cli`.
"""
