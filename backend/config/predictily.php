<?php

/*
|--------------------------------------------------------------------------
| Configuración de dominio de Predictily (backend-core)
|--------------------------------------------------------------------------
*/

return [
    'default_plan' => env('PREDICTILY_DEFAULT_PLAN', 'trial'),

    // Catálogo ÚNICO de horizontes (ADR-V25-02). Lo declara el motor igual (predictily_engine.platform.config.HORIZONS) y una prueba de cada
    // lado verifica que coinciden. El catálogo dice qué horizontes existen; qué puede usar cada empresa lo decide su plan (`limits.horizons`).
    //  - catalog:  todo lo que acepta la demanda.
    //  - customer: subconjunto de Customer (comportamiento de compra). 60 queda fuera: ninguna fuente lo pide para compra (V2.2: recompra
    //              7/14/30/90; V2.3: 7/15/30). Habilitarlo = agregarlo aquí y en el motor; la prueba de coincidencia lo exige.
    'horizons' => [
        'catalog' => [7, 14, 15, 30, 60, 90],
        'customer' => [7, 14, 15, 30, 90],
    ],

    // Conteo de series activas para `max_series` (PlanLimitService::activeSeries): DISTINCT sobre las ventas de 90 días, por eso se cachea por empresa y día y
    // corre con `statement_timeout` local. Aproximación documentada en docs/product/RETAIL_STARTER_GROWTH.md §3.
    'series_count' => [
        'cache_seconds' => (int) env('PREDICTILY_SERIES_CACHE_SECONDS', 600),
        'timeout_ms' => (int) env('PREDICTILY_SERIES_TIMEOUT_MS', 5000),
    ],

    // Claves permitidas en `plans.limits` y en `tenants.plan_overrides` (validación del superadministrador). «Se aplican» = hay código que las
    // exige hoy; «declaradas» = el contrato Enterprise las admite y las guarda, pero ningún módulo las consulta todavía (docs/product/RETAIL_STARTER_GROWTH.md §5).
    'plan_limit_keys' => [
        'enforced' => ['max_branches', 'max_series', 'max_active_products', 'max_import_rows_per_month', 'max_forecast_runs_per_day', 'max_users', 'horizons', 'features'],
        'declared' => ['modules', 'frequencies', 'retention_months', 'connectors', 'compute_budget', 'llm_budget'],
    ],

    // Límites por defecto de cada plan (el seeder los copia a la tabla plans). Los planes heredados declaran `customer => true` de forma explícita (antes se infería de su ausencia).
    // Un precio NUNCA es un límite: `monthly_price_usd` y `metadata.reference_price_usd` son informativos. Las claves ausentes en un plan heredan las del
    // plan por defecto (PlanLimitService::limits), por eso los planes nuevos declaran todas sus claves (null = sin tope del plan).
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
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => false, 'custom_models' => false, 'customer' => true],
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
                'features' => ['scenarios' => false, 'api_access' => false, 'external_indicators' => false, 'custom_models' => false, 'customer' => true],
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
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => true, 'custom_models' => false, 'customer' => true],
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
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => true, 'custom_models' => true, 'customer' => true],
            ],
        ],

        // ── Planes comerciales configurables (ADR-V25-18) ──────────────────────────────────────────────────────────────────────────────────
        // Starter y Growth son HIPÓTESIS HEREDADAS (V2.5 §Modelo comercial): capacidad opcional, no estrategia obligatoria. Los topes operativos que la
        // fuente no fija (filas por mes, ejecuciones por día, usuarios) son supuestos a aprobar en el piloto. Sin pasarela de pagos.
        'starter' => [
            'name' => 'Starter',
            'description' => 'Hipótesis heredada: un comercio de una sucursal que carga CSV/Excel, con demanda a 7 y 30 días. Configurable por empresa.',
            'monthly_price_usd' => 49,
            'metadata' => ['informational' => true, 'hypothesis' => true, 'reference_price_usd' => ['monthly' => 49], 'origin' => 'V2.5 master reconciliado, §Modelo comercial'],
            'limits' => [
                'max_branches' => 1,
                'max_series' => 750,
                'max_active_products' => 750,
                'max_import_rows_per_month' => 300_000,
                'max_forecast_runs_per_day' => 2,
                'max_users' => 2,
                'horizons' => [7, 30],
                'features' => ['scenarios' => false, 'api_access' => false, 'external_indicators' => false, 'custom_models' => false, 'customer' => false, 'roles' => false],
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'description' => 'Hipótesis heredada: hasta 5 sucursales y 5.000 series, demanda a 7/15/30 días, comportamiento de compra si los identificadores de comprador son confiables, API y roles.',
            'monthly_price_usd' => 119,
            'metadata' => ['informational' => true, 'hypothesis' => true, 'reference_price_usd' => ['monthly' => 119], 'origin' => 'V2.5 master reconciliado, §Modelo comercial'],
            'limits' => [
                'max_branches' => 5,
                'max_series' => 5000,
                'max_active_products' => null,
                'max_import_rows_per_month' => 3_000_000,
                'max_forecast_runs_per_day' => 10,
                'max_users' => 10,
                'horizons' => [7, 15, 30],
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => false, 'custom_models' => false, 'customer' => true, 'roles' => true],
            ],
        ],
        // Enterprise: los límites se fijan POR CONTRATO en `plans.limits` / `tenants.plan_overrides` (módulos, series, usuarios, sucursales, frecuencias,
        // retención, conectores, procesamiento privado, cómputo y LLM). Estos valores son techos operativos de partida, no una oferta; los rangos de
        // precio son orientativos. Horizontes por defecto 7/15/30; el catálogo completo se habilita con `plan_overrides.horizons`.
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Contrato personalizado: módulos, series, usuarios, sucursales, frecuencias, retención, conectores y procesamiento privado se acuerdan por empresa.',
            'monthly_price_usd' => null,
            'metadata' => [
                'informational' => true, 'contract' => true, 'origin' => 'V2.5 master reconciliado, §Modelo comercial',
                'reference_price_usd' => ['implementation_one_time' => ['min' => 1500, 'max' => 8000], 'subscription_monthly' => ['min' => 1000, 'max' => 3000]],
            ],
            'limits' => [
                'max_branches' => 200,
                'max_series' => 100_000,
                'max_active_products' => 200_000,
                'max_import_rows_per_month' => 30_000_000,
                'max_forecast_runs_per_day' => 50,
                'max_users' => 200,
                'horizons' => [7, 15, 30],
                'features' => ['scenarios' => true, 'api_access' => true, 'external_indicators' => true, 'custom_models' => true, 'customer' => true, 'roles' => true],
            ],
        ],
    ],

    'imports' => [
        'max_file_size_kb' => (int) env('PREDICTILY_IMPORT_MAX_KB', 20480),
        'disk' => env('PREDICTILY_PRIVATE_DISK', 'local'),
        'preview_rows' => 20,
        'max_stored_errors' => 10_000,
        'batch_documents' => 1000,
        // Tolerancia (minutos) de `source_recorded_at` respecto de la hora actual (desfase de reloj de la fuente). Una marca posterior a
        // ahora + tolerancia no se guarda como orden de versión (queda NULL) y se cuenta en `future_recorded_at` (importaciones,
        // /sales/batch y sincronizaciones SQL: SalesLineNormalizer::recordedAtBound).
        'source_recorded_at_tolerance_minutes' => (int) env('PREDICTILY_RECORDED_AT_TOLERANCE_MINUTES', 5),
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
