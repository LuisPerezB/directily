<?php

/**
 * Seguridad Enterprise (V2.5 · W6-A; ADR-V25-15; §15). Parámetros de partida: ninguno es un umbral universal; los valores de bloqueo y de ventana son hipótesis de
 * endurecimiento razonables y se aprueban (o se cambian) con el propietario de la plataforma antes de producción. Detalle y estados honestos:
 * docs/security/ENTERPRISE_SECURITY.md y docs/architecture/TENANT_ISOLATION.md.
 *
 * MFA TOTP (RFC 6238; HMAC-SHA1, 6 dígitos, paso de 30 s: lo que entienden las aplicaciones autenticadoras corrientes):
 * - `window`: pasos de tolerancia hacia cada lado (±1 paso = ±30 s de desfase de reloj). Más ventana = más códigos válidos a la vez; 1 es el máximo que recomienda RFC 6238 §5.2.
 * - `step_up_ttl_seconds`: cuánto dura «fresco» un segundo factor reciente para las acciones sensibles (aprobaciones, ajustes, modelos, exportaciones, tokens). 10 minutos.
 * - `challenge_ttl_seconds`: validez del desafío que sigue a una contraseña correcta (el inicio de sesión se completa con el código).
 * - Fuerza bruta en TRES niveles (de más rápido a más lento; ver MfaService): `max_failed_attempts_per_ip_user` (5) fallos seguidos desde una IP bloquean esa combinación persona + IP;
 *   `max_failed_attempts_per_ip` (30) fallos de cualquier persona desde una IP bloquean la IP; `max_failed_attempts` (15) fallos seguidos de la persona desde VARIAS IP la bloquean.
 *   `lockout_seconds` (15 minutos) es la duración de cada bloqueo y la ventana de los contadores por IP. Quien conoce la contraseña no puede bloquear a la víctima desde una sola
 *   IP (el nivel 1 solo cierra su propia IP); con 10^6 códigos y 3 válidos por ventana, 5 intentos por IP hacen inviable adivinar el código (< 2 × 10^-5 por bloqueo) y rotar IP
 *   está limitado a 15 intentos por persona en total hasta que el bloqueo de la persona entra. Los valores son hipótesis: a aprobar con el propietario.
 * - `recovery_codes`: cantidad de códigos de recuperación de un solo uso (se guardan solo como hash).
 * - `superadmin_required`: el superadministrador de plataforma debe tener MFA. `null` = obligatorio solo en producción (en desarrollo y pruebas no estorba).
 * - `sensitive_abilities`: habilidades cuya ruta exige un segundo factor FRESCO a quien tiene MFA activo (siempre además de la habilidad). La aprobación de decisiones,
 *   que viaja por la ruta de transición, se protege aparte con `mfa.step_up:approval`.
 *
 * Tokens (Sanctum): `default_ttl_days` para los de inicio de sesión de clientes API; `max_ttl_days` tope de los que emite una persona; `connector_default_ttl_days` para los de conector.
 *
 * RLS de PostgreSQL (defensa adicional; EXPERIMENTAL y DESACTIVADA por defecto: NO activar en producción, ver los bloqueos en docs/architecture/TENANT_ISOLATION.md §4): con `enabled` la aplicación fija `app.tenant_id` en la conexión al fijar la empresa y el comando
 * `predictily:rls enable` crea las políticas; ver docs/architecture/TENANT_ISOLATION.md §RLS.
 */
return [
    'mfa' => [
        'issuer' => env('PREDICTILY_MFA_ISSUER', 'Predictily'),
        'digits' => 6,
        'period' => 30,
        'window' => 1,
        'step_up_ttl_seconds' => (int) env('PREDICTILY_MFA_STEP_UP_TTL', 600),
        'challenge_ttl_seconds' => 300,
        'max_failed_attempts' => 15,
        'max_failed_attempts_per_ip_user' => 5,
        'max_failed_attempts_per_ip' => 30,
        'lockout_seconds' => 900,
        'recovery_codes' => 10,
        'superadmin_required' => env('PREDICTILY_MFA_SUPERADMIN_REQUIRED') === null ? null : filter_var(env('PREDICTILY_MFA_SUPERADMIN_REQUIRED'), FILTER_VALIDATE_BOOLEAN),
        'sensitive_abilities' => [
            'settings.manage', 'users.manage', 'datasources.manage', 'models.approve', 'decisions.approve',
            'data.export', 'tenant.export_all', 'tenant.delete', 'tenant.consent', 'owners.manage',
        ],
    ],

    'tokens' => [
        'default_ttl_days' => (int) env('PREDICTILY_TOKEN_DEFAULT_TTL_DAYS', 30),
        'max_ttl_days' => (int) env('PREDICTILY_TOKEN_MAX_TTL_DAYS', 365),
        'connector_default_ttl_days' => (int) env('PREDICTILY_CONNECTOR_TOKEN_TTL_DAYS', 365),
    ],

    'rls' => [
        'enabled' => filter_var(env('PREDICTILY_RLS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Tablas principales de ventas (piloto). Todas tienen `tenant_id`.
        'tables' => ['sales', 'sale_items', 'sales_document_revisions', 'sale_item_revisions'],
    ],
];
