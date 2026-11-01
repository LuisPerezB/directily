<?php

/**
 * Almacén Bronze (ADR-V25-04, ADR-V25-08, ADR-V25-24). El contenido original autorizado se guarda íntegro e
 * inmutable antes de normalizar. `disk` = disco de config/filesystems.php (`local` en desarrollo; `s3` en AWS cuando
 * exista el adaptador y la cuenta). `retention_days` = 0 conserva sin vencimiento hasta que la empresa defina su
 * política (V2.5 §3: retención y borrado por tenant tras evaluar obligaciones contractuales y legales).
 */
return [
    'disk' => env('PREDICTILY_BRONZE_DISK', 'local'),
    'retention_days' => (int) env('PREDICTILY_BRONZE_RETENTION_DAYS', 0),
    'max_object_mb' => (int) env('PREDICTILY_BRONZE_MAX_OBJECT_MB', 64),
];
