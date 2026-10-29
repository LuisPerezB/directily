<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Models\DataSource;

/**
 * Conector de origen de datos (ERP/POS/facturación). Predictily no depende de que el
 * sistema origen tenga API pública: los conectores pueden ser de archivo, de API REST
 * entrante (el cliente envía datos) o salientes (Predictily consulta al sistema).
 *
 * Estado:
 * - available: funciona sin configuración externa.
 * - requires_credentials: implementación desacoplada que necesita credenciales del cliente
 *   y un entorno con acceso al servicio externo; NO se activa sin ellas.
 * - implemented_untested: implementado pero SIN comprobar contra un servidor real (p. ej. MySQL/SQL Server en
 *   el entorno de desarrollo). Nunca se presenta como disponible.
 * - planned: definido en el catálogo, aún sin implementación de sincronización.
 */
interface ConnectorInterface
{
    public function key(): string;

    public function name(): string;

    public function description(): string;

    /** available | requires_credentials | implemented_untested | planned */
    public function status(): string;

    /** @return list<string> tipos de entidad soportados (sales, products, inventory, ...) */
    public function entityTypes(): array;

    /** @return list<string> claves de credenciales requeridas (p. ej. api_key) */
    public function credentialKeys(): array;

    /** Verifica la configuración sin efectos secundarios. */
    public function test(DataSource $source): ConnectorTestResult;
}
