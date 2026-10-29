<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use App\Models\DataSource;
use App\Services\Connectors\ConnectorInterface;

/** Conector SQL de solo lectura: lee únicamente consultas autorizadas (source_mappings) y entrega filas crudas. */
interface SqlConnectorInterface extends ConnectorInterface
{
    public function dialect(): SqlDialect;

    /** tested_with_test_database | implemented_untested */
    public function verification(): string;

    /** @throws SqlSourceException */
    public function open(DataSource $source): SqlSourceConnection;

    /**
     * Valida host/puerto/base/opciones (no secretas) y credenciales.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $credentials
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateInput(array $config, ?array $credentials): void;
}
