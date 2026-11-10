<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alertas operativas (ADR-V26-01 · F5)
    |--------------------------------------------------------------------------
    | Una tarea programada que termina con error o un trabajo de cola que queda fallido genera una alerta. Siempre se escribe en el
    | registro de la aplicación con nivel CRITICAL y un código estable en `ops_alert` (sirve para cualquier alarma basada en logs).
    |
    | log_channel       Canal adicional de config/logging.php al que también se envía (p. ej. `slack`, con LOG_SLACK_WEBHOOK_URL).
    |                   Vacío = solo el registro de la aplicación.
    | throttle_seconds  En el canal adicional, la misma alerta (mismo código y mismo origen) no se repite antes de este tiempo: con el
    |                   motor caído fallan muchos trabajos seguidos. El registro de la aplicación recibe siempre todas. 0 = sin límite.
    |
    | La alerta nunca lleva el texto de la excepción (puede traer SQL o datos): solo su clase. El detalle queda en el registro.
    */
    'alerts' => [
        'log_channel' => env('OPS_ALERT_LOG_CHANNEL'),
        'throttle_seconds' => (int) env('OPS_ALERT_THROTTLE_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ciclo diario por empresa (ADR-V26-01 · F1 y F4)
    |--------------------------------------------------------------------------
    | queue  Cola de los trabajos por empresa del ciclo diario (radar, decisiones, medición de resultados). Vacío = `default`, que
    |        existe en todos los despliegues. docker-compose.yml la define como `batch`, con su propio worker (`queue-batch`), para que
    |        no esperen detrás de una importación. Definirla exige un worker que la atienda.
    */
    'pipeline' => [
        'queue' => env('PIPELINE_QUEUE'),
    ],

];
