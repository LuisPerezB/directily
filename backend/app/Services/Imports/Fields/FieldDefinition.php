<?php

declare(strict_types=1);

namespace App\Services\Imports\Fields;

/** Campo canónico de Predictily en una importación (docs/06 §2). */
final readonly class FieldDefinition
{
    /** @param list<string> $synonyms */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public string $type, // string, date, datetime, number, integer, boolean
        public string $description,
        public array $synonyms = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'type' => $this->type,
            'description' => $this->description,
            'synonyms' => $this->synonyms,
        ];
    }
}
