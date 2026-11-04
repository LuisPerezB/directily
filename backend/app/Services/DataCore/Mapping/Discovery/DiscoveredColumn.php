<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/** Columna del catálogo del origen: solo metadatos (nombre, tipo, nulabilidad), nunca valores. */
final readonly class DiscoveredColumn
{
    public function __construct(
        public string $name,
        public string $dataType,
        public TypeClass $typeClass,
        public bool $nullable,
        public int $position,
        public ?int $maxLength = null,
    ) {}

    /** @return array{name: string, data_type: string, type_class: string, nullable: bool, position: int, max_length: ?int} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'data_type' => $this->dataType, 'type_class' => $this->typeClass->value, 'nullable' => $this->nullable,
            'position' => $this->position, 'max_length' => $this->maxLength];
    }
}
