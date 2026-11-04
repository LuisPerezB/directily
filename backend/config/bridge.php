<?php

/**
 * Predictily Bridge (ADR-V25-10; V2.5 · W6-B): agente de SOLO LECTURA en la red del cliente que sube lotes comprimidos al almacén Bronze.
 * Las credenciales de la fuente del cliente NUNCA llegan a Predictily: se quedan en el dispositivo. Nada aquí es un secreto.
 *
 * - `enrolment_ttl_minutes`: vigencia del código de registro de un solo uso (un administrador lo genera por fuente de datos).
 * - `upload_url_ttl_seconds`: vigencia de la URL prefirmada de subida de UN lote (ligada a empresa, dispositivo, entidad, SHA-256,
 *   tamaño y tipo de contenido).
 * - `max_batch_mb` / `max_batch_rows`: tope de un lote (no puede superar `bronze.max_object_mb`).
 * - `signature_skew_seconds` / `nonce_ttl_seconds`: tolerancia de reloj de la firma de petición del dispositivo y memoria anti-repetición.
 * - `offline_after_seconds`: sin latido durante este tiempo el dispositivo figura como «sin conexión» (informativo).
 * - `entities`: entidades que un dispositivo puede entregar (las del importador canónico). Solo `sales` está verificado de punta a punta.
 *
 * Límites de CONTENIDO de un lote (los aplica BridgeBatchReader, tanto al recibirlo —ANTES de guardar nada en Bronze— como al procesarlo):
 * - `max_decompressed_mb`: bytes descomprimidos máximos de un lote (defensa ante bombas de compresión; 512 MB por defecto, muy por encima de un lote normal).
 * - `max_decompression_ratio`: relación descomprimido/comprimido máxima (200×); se aplica cuando el lote ya descomprimió más de 8 MB, de modo que un lote pequeño
 *   y repetitivo no se rechaza. Un lote real de NDJSON comprime del orden de 5–30×.
 * - `max_line_bytes` / `max_value_length`: longitud máxima de una línea (256 KB) y de un valor de texto (2000 caracteres).
 * - `max_batch_rows`: filas máximas por lote; el número de filas debe ser IGUAL al declarado (row_count).
 *
 * Cuotas y tasas (valores iniciales, **a aprobar en el piloto**; no son umbrales universales):
 * - `tenant_daily_quota_mb`: bytes comprimidos que una empresa puede subir por día UTC con el Bridge (4096 MB; 0 = sin tope). Al agotarse: 429 `quota_exceeded`
 *   con `Retry-After`; el agente conserva los lotes en su búfer cifrado. Cuota por dispositivo: DISEÑADA, no implementada.
 * - `device_requests_per_minute`: por credencial (hash del token + IP); `ip_requests_per_minute`: por IP, ANTES de autenticar (rotar valores de Bearer no evade el límite).
 */
return [
    'enrolment_ttl_minutes' => (int) env('PREDICTILY_BRIDGE_ENROLMENT_TTL_MINUTES', 60),
    'enrolment_max_ttl_minutes' => 1440,
    'upload_url_ttl_seconds' => (int) env('PREDICTILY_BRIDGE_UPLOAD_URL_TTL', 600),
    'max_batch_mb' => (int) env('PREDICTILY_BRIDGE_MAX_BATCH_MB', 32),
    'max_batch_rows' => (int) env('PREDICTILY_BRIDGE_MAX_BATCH_ROWS', 50_000),
    'max_decompressed_mb' => (int) env('PREDICTILY_BRIDGE_MAX_DECOMPRESSED_MB', 512),
    'max_decompression_ratio' => (int) env('PREDICTILY_BRIDGE_MAX_DECOMPRESSION_RATIO', 200),
    'max_line_bytes' => 262_144,
    'max_value_length' => 2000,
    'tenant_daily_quota_mb' => (int) env('PREDICTILY_BRIDGE_TENANT_DAILY_QUOTA_MB', 4096),
    'signature_skew_seconds' => 300,
    'nonce_ttl_seconds' => 900,
    'offline_after_seconds' => 900,
    'enrol_attempts_per_minute' => 10,
    'device_requests_per_minute' => 240,
    'ip_requests_per_minute' => (int) env('PREDICTILY_BRIDGE_IP_REQUESTS_PER_MINUTE', 240),
    'entities' => ['sales', 'products', 'inventory', 'commitments', 'branches', 'suppliers', 'lead_times', 'promotions', 'calendar_events', 'unit_conversions', 'warehouses'],
];
