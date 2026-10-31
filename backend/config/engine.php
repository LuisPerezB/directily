<?php

/*
|--------------------------------------------------------------------------
| Motor predictivo (servicio interno FastAPI)
|--------------------------------------------------------------------------
| Contrato: docs/06-contratos-integracion.md §4.
*/

return [
    'url' => rtrim((string) env('ENGINE_URL', 'http://127.0.0.1:8100'), '/'),
    'token' => env('ENGINE_API_TOKEN'),
    // Segundos que el Job de Laravel espera a que el motor ejecute el pipeline completo.
    'timeout' => (int) env('ENGINE_TIMEOUT', 900),
    // Timeout de llamadas cortas (health, readiness, capabilities).
    'short_timeout' => (int) env('ENGINE_SHORT_TIMEOUT', 60),
    'connect_timeout' => (int) env('ENGINE_CONNECT_TIMEOUT', 10),
    // Reintentos SOLO ante errores de conexión (no ante respuestas HTTP del motor).
    'connection_retries' => (int) env('ENGINE_CONNECTION_RETRIES', 2),
    'retry_sleep_ms' => (int) env('ENGINE_RETRY_SLEEP_MS', 500),
    // Una ejecución queued/running más antigua que esto se considera vencida (no bloquea nuevas).
    'stale_run_minutes' => (int) env('ENGINE_STALE_RUN_MINUTES', 120),
    'queue' => env('ENGINE_QUEUE', 'forecasting'),
    // Directorio de artefactos de modelos del motor (la MISMA variable ENGINE_ARTIFACTS_DIR, apuntando al mismo volumen compartido).
    // El motor guarda `model_registry.artifact_path` relativo a este directorio; con él el backend verifica el SHA-256 al revertir
    // modelos. Sin configurar (o sin acceso al volumen) no verifica y lo informa explícitamente: lo verifica el motor al cargar.
    'artifacts_dir' => env('ENGINE_ARTIFACTS_DIR'),
    // Horizontes de demanda del dominio (ADR-V2-10). Que un plan los autorice es otra cosa (config/predictily.php).
    'horizons' => [7, 14, 30, 60, 90],
];
