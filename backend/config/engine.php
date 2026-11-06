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
    // Una ejecución EN CURSO (running) se considera vencida pasados estos minutos desde que empezó (no bloquea nuevas).
    'stale_run_minutes' => (int) env('ENGINE_STALE_RUN_MINUTES', 120),
    // Una ejecución EN COLA (queued) se considera vencida pasados estos minutos desde que se pidió: tope de seguridad por si el
    // trabajo se perdió. Mayor que el anterior porque esperar en cola es normal (App\Services\Forecasting\Support\ActiveRuns).
    'stale_queued_minutes' => (int) env('ENGINE_STALE_QUEUED_MINUTES', 240),
    // Cola de los trabajos largos del motor: entrenamiento y pronóstico.
    'queue' => env('ENGINE_QUEUE', 'forecasting'),
    // Cola de los trabajos cortos del motor (predicción diaria de clientes, evaluación contra reales, preparación de datos).
    // Vacío = la misma que `queue`. Definirla exige un worker que la atienda (App\Services\Forecasting\Support\EngineQueue).
    'light_queue' => env('ENGINE_LIGHT_QUEUE'),
    // Cortacircuitos hacia el motor (App\Services\Forecasting\Support\EngineCircuit; ADR-V26-01 · F6).
    'circuit' => [
        'enabled' => filter_var(env('ENGINE_CIRCUIT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Llamadas seguidas sin lograr conectar tras las que se abre (cada llamada ya reintenta la conexión `connection_retries` veces).
        'failure_threshold' => (int) env('ENGINE_CIRCUIT_FAILURES', 3),
        // Segundos que permanece abierto antes de dejar pasar llamadas de prueba; también lo que se aplaza cada trabajo.
        'open_seconds' => (int) env('ENGINE_CIRCUIT_OPEN_SECONDS', 60),
        // Veces que un trabajo se aplaza esperando al motor antes de intentarlo de todos modos y fallar por la vía normal
        // (60 × 60 s ≈ 1 hora; debe quedar por debajo de `stale_queued_minutes`).
        'max_deferrals' => (int) env('ENGINE_CIRCUIT_MAX_DEFERRALS', 60),
    ],
    // Directorio de artefactos de modelos del motor (la MISMA variable ENGINE_ARTIFACTS_DIR, apuntando al mismo volumen compartido).
    // El motor guarda `model_registry.artifact_path` relativo a este directorio; con él el backend verifica el SHA-256 al revertir
    // modelos. Sin configurar (o sin acceso al volumen) no verifica y lo informa explícitamente: lo verifica el motor al cargar.
    'artifacts_dir' => env('ENGINE_ARTIFACTS_DIR'),
    // Firma Ed25519 de los artefactos (ADR-V25-20). El backend NUNCA firma: solo verifica con las claves PÚBLICAS de confianza
    // (las mismas variables que el motor). Al revertir un modelo con artefacto visible se exige una firma válida además del SHA-256.
    'artifact_signing' => [
        // Lista `kid:clave` (clave pública Ed25519 de 32 bytes en base64) separada por comas, punto y coma o espacios.
        'trusted_public_keys' => env('ENGINE_TRUSTED_PUBLIC_KEYS'),
        // Artefactos legados SIN firma: solo con true explícito (entorno local). Nunca acepta firmas inválidas ni claves desconocidas.
        'allow_unsigned' => filter_var(env('ENGINE_ALLOW_UNSIGNED_ARTIFACTS', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
    ],
    // Los horizontes del dominio ya no se declaran aquí: el catálogo ÚNICO es `predictily.horizons.catalog` (ADR-V25-02; App\Support\Horizons\HorizonCatalog).
    // Que un plan los autorice es otra cosa (`plans.limits.horizons`).
];
