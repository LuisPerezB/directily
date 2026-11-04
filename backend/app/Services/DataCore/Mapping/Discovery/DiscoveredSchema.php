<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/**
 * Esquema descubierto de una fuente SQL (solo metadatos). `fingerprint()` resume TODO el esquema visible (informativo: «cambió algo en la base»);
 * la huella que decide si una plantilla sigue siendo válida es la de las columnas que ESA plantilla usa (Templates\TemplateFingerprint).
 */
final class DiscoveredSchema
{
    /** @var array<string, DiscoveredTable> */
    private array $tables = [];

    public function __construct(
        public readonly string $dialect,
        /** @param list<DiscoveredTable> $tables */
        array $tables = [],
        public readonly bool $truncated = false,
        public readonly ?string $database = null,
    ) {
        foreach ($tables as $table) {
            $this->tables[$table->key()] = $table;
        }
    }

    /** @return array<string, DiscoveredTable> */
    public function tables(): array
    {
        return $this->tables;
    }

    public function table(?string $schema, string $name): ?DiscoveredTable
    {
        return $this->tables[DiscoveredTable::keyOf($schema, $name)] ?? null;
    }

    public function tableCount(): int
    {
        return count($this->tables);
    }

    public function columnCount(): int
    {
        return array_sum(array_map(fn (DiscoveredTable $t) => count($t->columns), $this->tables));
    }

    public function fingerprint(): string
    {
        $lines = [];
        foreach ($this->tables as $key => $table) {
            foreach ($table->columns as $column) {
                $lines[] = $key.'.'.$column->name.':'.$column->typeClass->value;
            }
        }
        sort($lines);

        return hash('sha256', $this->dialect."\n".implode("\n", $lines));
    }

    /** @return array<string, mixed> */
    public function toArray(int $maxTables = 300, bool $withColumns = true): array
    {
        $tables = array_values($this->tables);
        usort($tables, fn (DiscoveredTable $a, DiscoveredTable $b) => [$b->estimatedRows ?? -1, $a->key()] <=> [$a->estimatedRows ?? -1, $b->key()]);
        $listed = array_slice($tables, 0, $maxTables);

        return [
            'dialect' => $this->dialect,
            'schema_fingerprint' => $this->fingerprint(),
            'tables_total' => count($tables),
            'columns_total' => $this->columnCount(),
            'truncated' => $this->truncated || count($tables) > $maxTables,
            'tables' => array_map(fn (DiscoveredTable $t) => $t->toArray($withColumns), $listed),
        ];
    }
}
