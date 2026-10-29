<?php

/*
|--------------------------------------------------------------------------
| Conectores de fuentes externas (etapa 2 · P3)
|--------------------------------------------------------------------------
| Contrato: docs/architecture/v2/P3-datos.md. Las credenciales NUNCA van aquí: se guardan cifradas por fuente
| (data_sources.credentials_encrypted).
*/

return [
    'sql' => [
        // Rechaza hosts en redes privadas/loopback (SSRF). Por defecto permitido: el ERP del cliente suele estar en
        // una red privada accesible por VPN. En un entorno SaaS multi-inquilino público actívelo y use allowed_hosts.
        'deny_private_networks' => (bool) env('PREDICTILY_SQL_DENY_PRIVATE', false),
        // Si no está vacío, solo se permiten estos hosts (coma-separados en la variable de entorno).
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PREDICTILY_SQL_ALLOWED_HOSTS', ''))))),
        'default_page_size' => 5000,
        'max_page_size' => 20_000,
        // Tope de filas por sincronización (protege memoria y disco).
        'max_rows_per_sync' => (int) env('PREDICTILY_SQL_MAX_ROWS', 2_000_000),
        'preview_rows' => 20,
        // Margen de solape de la ventana incremental (minutos): relee un poco para no perder cambios en curso.
        'default_overlap_minutes' => 120,
    ],
];
