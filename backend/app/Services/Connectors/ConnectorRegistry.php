<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Services\Connectors\Connectors\AbstractConnector;
use App\Services\Connectors\Connectors\ContificoConnector;
use App\Services\Connectors\Connectors\GenericCsvConnector;
use App\Services\Connectors\Connectors\GenericExcelConnector;
use App\Services\Connectors\Connectors\GenericOdbcConnector;
use App\Services\Connectors\Connectors\GenericRestConnector;

/** Catálogo de conectores. Para agregar uno: implementar ConnectorInterface y registrarlo aquí. */
final class ConnectorRegistry
{
    /** @var array<string, AbstractConnector> */
    private array $connectors = [];

    public function __construct()
    {
        foreach ([new GenericCsvConnector, new GenericExcelConnector, new GenericRestConnector, new ContificoConnector, new GenericOdbcConnector] as $connector) {
            $this->connectors[$connector->key()] = $connector;
        }
    }

    /** @return list<AbstractConnector> */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    public function get(string $key): ?AbstractConnector
    {
        return $this->connectors[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->connectors);
    }
}
