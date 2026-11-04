<?php

/*
|--------------------------------------------------------------------------
| MLOps: registro de modelos y publicación (V2.5 · W4; ADR-V25-07, ADR-V25-08, ADR-V25-19)
|--------------------------------------------------------------------------
| - published_disk: disco de config/filesystems.php donde la CLI de entrenamiento privado publica los artefactos firmados con clave
|   generada por el servidor (`tenants/<uuid>/models/<core>/<versión>/...`). El backend lo lee SOLO para verificar la firma antes de aprobar
|   (no sirve modelos). `local` en desarrollo y pruebas (el motor y el backend deben ver el mismo directorio); `bronze_s3` u otro disco S3 en
|   AWS (el despliegue real está BLOQUEADO: sin cuenta del propietario). Por defecto el mismo disco que Bronze.
*/

return [
    'published_disk' => env('PREDICTILY_MODELS_DISK', env('PREDICTILY_BRONZE_DISK', 'local')),
];
