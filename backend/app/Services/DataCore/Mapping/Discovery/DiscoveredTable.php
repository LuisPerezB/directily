<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/** Tabla o vista del catálogo del origen, con sus columnas, su clave primaria y sus claves foráneas declaradas. */
final class DiscoveredTable
{
    /**
     * @param  list<DiscoveredColumn>  $columns
     * @param  list<string>  $primaryKey
     * @param  list<ForeignKey>  $foreignKeys
     */
    public function __construct(
        public readonly ?string $schema,
        public readonly string $name,
        public readonly string $kind,
        public readonly ?int $estimatedRows,
        public array $columns = [],
        public array $primaryKey = [],
        public array $foreignKeys = [],
    ) {}

    public function key(): string
    {
        return self::keyOf($this->schema, $this->name);
    }

    public static function keyOf(?string $schema, string $name): string
    {
        return ($schema === null || $schema === '' ? '' : $schema.'.').$name;
    }

    public function column(string $name): ?DiscoveredColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withColumns = true): array
    {
        $out = ['schema' => $this->schema, 'name' => $this->name, 'kind' => $this->kind, 'estimated_rows' => $this->estimatedRows,
            'column_count' => count($this->columns), 'primary_key' => $this->primaryKey, 'foreign_keys' => array_map(fn (ForeignKey $f) => $f->toArray(), $this->foreignKeys)];
        if ($withColumns) {
            $out['columns'] = array_map(fn (DiscoveredColumn $c) => $c->toArray(), $this->columns);
        }

        return $out;
    }
}
