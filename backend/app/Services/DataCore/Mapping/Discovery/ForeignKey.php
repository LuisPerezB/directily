<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/** Clave foránea declarada en el catálogo del origen (hija → padre), con columnas emparejadas por posición. */
final readonly class ForeignKey
{
    /**
     * @param  list<string>  $columns  columnas de la tabla hija
     * @param  list<string>  $refColumns  columnas de la tabla padre, en el mismo orden
     */
    public function __construct(
        public string $name,
        public array $columns,
        public ?string $refSchema,
        public string $refTable,
        public array $refColumns,
    ) {}

    /** @return array{name: string, columns: list<string>, ref_schema: ?string, ref_table: string, ref_columns: list<string>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'columns' => $this->columns, 'ref_schema' => $this->refSchema, 'ref_table' => $this->refTable, 'ref_columns' => $this->refColumns];
    }
}
