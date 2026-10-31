<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/** Clase de una dirección de destino según la política SSRF (ver SqlNetworkClassifier). */
enum SqlAddressClass: string
{
    /** Dirección pública enrutable: permitida. */
    case Public = 'public';

    /** Red privada o reservada no pública (RFC 1918, ULA, 100.64.0.0/10, documentación…): denegada salvo autorización explícita. */
    case Private = 'private';

    /** Loopback: denegado siempre; solo una IP literal o CIDR explícitos de la lista blanca lo autorizan (y nunca en producción). */
    case Loopback = 'loopback';

    /** Metadatos de nube, link-local, 0.0.0.0/8, multicast, broadcast y formas de transición: ninguna lista blanca lo autoriza. */
    case Blocked = 'blocked';
}
