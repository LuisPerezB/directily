<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Bronze en S3 (ADR-V25-08 y ADR-V25-24; se activa con PREDICTILY_BRONZE_DISK=bronze_s3). Todo se define por
        | entorno: bucket, región, endpoint y path-style (emuladores como moto/MinIO) y cifrado del lado del servidor
        | (PREDICTILY_BRONZE_S3_SSE = AES256 | aws:kms, con PREDICTILY_BRONZE_S3_KMS_KEY_ID opcional). Sin clave/secreto
        | en el entorno, el SDK usa la cadena de credenciales por defecto (rol IAM). `throw` = true: un fallo del
        | almacén nunca se confunde con «el objeto no existe».
        */
        'bronze_s3' => [
            'driver' => 's3',
            'key' => env('PREDICTILY_BRONZE_S3_KEY'),
            'secret' => env('PREDICTILY_BRONZE_S3_SECRET'),
            'region' => env('PREDICTILY_BRONZE_S3_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            'bucket' => env('PREDICTILY_BRONZE_S3_BUCKET'),
            'endpoint' => env('PREDICTILY_BRONZE_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('PREDICTILY_BRONZE_S3_PATH_STYLE', false),
            'options' => array_filter([
                'ServerSideEncryption' => env('PREDICTILY_BRONZE_S3_SSE'),
                'SSEKMSKeyId' => env('PREDICTILY_BRONZE_S3_KMS_KEY_ID'),
            ]),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
