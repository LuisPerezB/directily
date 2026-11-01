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
        // Política de destino (SSRF; SqlHostPolicy, ADR-V25-15). Seguro por defecto, sin interruptor que la abra:
        // - Siempre denegados (ninguna lista los autoriza): metadatos de nube (169.254.0.0/16, fd00:ec2::254…), link-local,
        //   0.0.0.0/8, multicast, broadcast y formas de transición IPv6 (6to4, Teredo, NAT64 local).
        // - Denegados por defecto: loopback y redes privadas (RFC 1918, 100.64.0.0/10, ULA fc00::/7). Se autorizan SOLO con las
        //   listas de abajo (ERP detrás de VPN). El loopback solo con una IP literal o un CIDR explícitos y nunca en producción.
        // - Solo se aceptan nombres RFC 1123 o IP literales canónicas; se resuelven A y AAAA y la conexión usa la IP validada.
        // Destinos autorizados explícitamente (nombres exactos o IP, separados por comas). Si no está vacío, SOLO se permiten estos
        // destinos y pueden resolver a redes privadas. En un SaaS multiempresa defínalo siempre (alta de cliente = alta del host).
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PREDICTILY_SQL_ALLOWED_HOSTS', ''))))),
        // Redes privadas autorizadas (CIDR o IP, separadas por comas; p. ej. 10.20.0.0/16 de la VPN del cliente). Una dirección
        // privada se acepta si cae en alguna; sin CIDR ni hosts, toda red privada queda denegada.
        'allowed_cidrs' => array_values(array_filter(array_map('trim', explode(',', (string) env('PREDICTILY_SQL_ALLOWED_CIDRS', ''))))),
        'default_page_size' => 5000,
        'max_page_size' => 20_000,
        // Tope de filas por sincronización (protege memoria y disco).
        'max_rows_per_sync' => (int) env('PREDICTILY_SQL_MAX_ROWS', 2_000_000),
        'preview_rows' => 20,
        // Margen de solape de la ventana incremental (minutos): relee un poco para no perder cambios en curso.
        'default_overlap_minutes' => 120,
    ],
];
