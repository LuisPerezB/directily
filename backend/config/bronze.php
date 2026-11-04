<?php

/**
 * Almacén Bronze (ADR-V25-04, ADR-V25-08, ADR-V25-24). El contenido original autorizado se guarda íntegro e
 * inmutable antes de normalizar.
 *
 * - `disk`: disco de config/filesystems.php. `local` en desarrollo y pruebas; `bronze_s3` en AWS (o contra un emulador
 *   S3 en integración: bucket, región, endpoint, path-style y cifrado del lado del servidor se definen por entorno en
 *   config/filesystems.php). La selección es solo configuración: la interfaz BronzeStore no cambia.
 * - `retention_days`: retención por defecto de la plataforma (0 = sin vencimiento). Cada empresa puede fijar la suya en
 *   `tenants.settings` → `data.bronze_retention_days`, que tiene precedencia sobre este valor (V2.5 §3: retención y
 *   borrado por tenant tras evaluar obligaciones contractuales y legales).
 * - `max_object_mb`: tamaño máximo de un objeto; se aplica al guardar.
 * - `retention_max_days`: tope de la política de empresa (validación de la API y de la lectura de la política).
 * - `reconcile_max_window_days`: ventana máxima de una reconciliación por ventana.
 */
return [
    'disk' => env('PREDICTILY_BRONZE_DISK', 'local'),
    'retention_days' => (int) env('PREDICTILY_BRONZE_RETENTION_DAYS', 0),
    'retention_max_days' => 3650,
    'max_object_mb' => (int) env('PREDICTILY_BRONZE_MAX_OBJECT_MB', 64),
    'reconcile_max_window_days' => 366,
];
