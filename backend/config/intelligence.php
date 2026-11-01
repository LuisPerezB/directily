<?php

/**
 * Factor Intelligence y taxonomía (V2.5 §24; ADR-V25-16 y ADR-V25-25). Todo es P0: contratos, disponibilidad temporal y aislamiento.
 *
 * - `shared_forecast_grants.enabled`: bandera del contrato entre empresas autorizadas (V2.1 §10). DESACTIVADA por defecto: con ella apagada
 *   ninguna ruta de pronósticos compartidos existe (404), ni siquiera para quien no tiene sesión, y el servicio se niega a operar.
 *   La red colaborativa no se habilita en P0 (V2.5 §24.10); el contrato y sus pruebas negativas de aislamiento sí existen.
 * - Los límites numéricos son técnicos (protegen la API), no umbrales de negocio; se revisan en el piloto.
 */
return [
    'shared_forecast_grants' => [
        // Fail-closed: solo un booleano reconocible (true/1/on/yes) la enciende. «no», «off», «0», vacío o un valor irreconocible la dejan APAGADA
        // (un `(bool) 'no'` sería true). Misma regla en SharedForecastGrantService::isEnabled().
        'enabled' => filter_var(env('PREDICTILY_SHARED_FORECAST_GRANTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
        // Contenido que un emisor puede conceder. Ventas, clientes, precios, ingresos o costos NO están en la lista y nunca se podrán conceder.
        'allowed_content' => ['point_forecast', 'interval_80', 'interval_95'],
        'max_subjects' => 200,
    ],
];
