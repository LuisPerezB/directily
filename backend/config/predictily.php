<?php

/*
|--------------------------------------------------------------------------
| Configuración de dominio de Predictily (backend-core)
|--------------------------------------------------------------------------
*/

return [
    'default_plan' => env('PREDICTILY_DEFAULT_PLAN', 'trial'),

    // Límites por defecto de cada plan (el seeder los copia a la tabla plans).
    'plans' => [
        'trial' => [
            'name' => 'Prueba',
            'description' => 'Plan de prueba con todas las capacidades del MVP y límites moderados.',
            'monthly_price_usd' => 0,
            'limits' => [
                'max_branches' => 5,
                'max_active_products' => 1000,
                'max_import_rows_per_month' => 1_000_000,
                'max_forecast_runs_per_day' => 10,
                'max_users' => 5,
                'horizons' => [7, 14, 30, 60, 90],
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => false, 'custom_models' => false],
            ],
        ],
        'basico' => [
            'name' => 'Básico',
            'description' => 'Pequeños comercios con una o dos sucursales.',
            'monthly_price_usd' => 39,
            'limits' => [
                'max_branches' => 2,
                'max_active_products' => 1500,
                'max_import_rows_per_month' => 300_000,
                'max_forecast_runs_per_day' => 2,
                'max_users' => 3,
                'horizons' => [7, 14, 30],
                'features' => ['scenarios' => false, 'api_access' => false, 'external_indicators' => false, 'custom_models' => false],
            ],
        ],
        'profesional' => [
            'name' => 'Profesional',
            'description' => 'Distribuidoras y comercios con varias sucursales.',
            'monthly_price_usd' => 119,
            'limits' => [
                'max_branches' => 15,
                'max_active_products' => 10_000,
                'max_import_rows_per_month' => 3_000_000,
                'max_forecast_runs_per_day' => 10,
                'max_users' => 15,
                'horizons' => [7, 14, 30, 60, 90],
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => true, 'custom_models' => false],
            ],
        ],
        'empresarial' => [
            'name' => 'Empresarial',
            'description' => 'Importadoras y cadenas con alto volumen.',
            'monthly_price_usd' => null,
            'limits' => [
                'max_branches' => 200,
                'max_active_products' => 200_000,
                'max_import_rows_per_month' => 30_000_000,
                'max_forecast_runs_per_day' => 50,
                'max_users' => 200,
                'horizons' => [7, 14, 30, 60, 90],
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => true, 'custom_models' => true],
            ],
        ],
    ],

    'imports' => [
        'max_file_size_kb' => (int) env('PREDICTILY_IMPORT_MAX_KB', 20480),
        'disk' => env('PREDICTILY_PRIVATE_DISK', 'local'),
        'preview_rows' => 20,
        'max_stored_errors' => 10_000,
        'batch_documents' => 1000,
    ],

    'privacy' => [
        'deletion_grace_days' => 30,
        'audit_retention_days' => (int) env('PREDICTILY_AUDIT_RETENTION_DAYS', 730),
        'export_link_minutes' => 60,
        'export_retention_days' => 7,
    ],

    'superadmin' => [
        'email' => env('SUPERADMIN_EMAIL'),
        'password' => env('SUPERADMIN_PASSWORD'),
    ],

    'demo' => [
        'path' => env('PREDICTILY_DEMO_PATH', base_path('../data/demo')),
        'password' => 'Predictily2026',
        'tenants' => [
            'ferreteria_andina' => [
                'name' => 'Ferretería Andina (Demo ficticia)',
                'sector' => 'ferreteria',
                'email' => 'demo.ferreteria@predictily.test',
                'user_name' => 'Usuario Demo Ferretería',
            ],
            'distribuidora_costa' => [
                'name' => 'Distribuidora Costa (Demo ficticia)',
                'sector' => 'distribuidora',
                'email' => 'demo.distribuidora@predictily.test',
                'user_name' => 'Usuario Demo Distribuidora',
            ],
        ],
    ],
];
