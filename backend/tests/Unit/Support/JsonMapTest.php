<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Results\JsonMap;
use App\Support\Results\ResultEnvelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\TestCase;

/** Mapas JSON: causa del defecto (JsonResource renumera las claves numéricas) y su corrección (JsonMap). */
class JsonMapTest extends TestCase
{
    private function json(JsonResource $resource): string
    {
        return json_encode($resource->resolve(new Request));
    }

    public function test_a_json_resource_renumbers_a_map_with_numeric_keys_and_an_empty_map_is_a_list(): void
    {
        // Documenta el defecto de origen: sin JsonMap el mapa horizonte → modelo sale como lista y `{}` como `[]`.
        $resource = new JsonResource(['model_versions' => [7 => 'a', 30 => 'b'], 'options' => []]);

        $this->assertSame('{"model_versions":["a","b"],"options":[]}', $this->json($resource));
    }

    public function test_json_map_keeps_numeric_keys_empty_maps_and_nested_maps_as_objects(): void
    {
        $resource = new JsonResource([
            'model_versions' => JsonMap::of([7 => ['family' => 'a'], 30 => ['family' => 'b']]),
            'options' => JsonMap::orEmpty([]),
            'nested' => JsonMap::of(['by_horizon' => [7 => 1, 30 => 2]]),
            'missing' => JsonMap::of(null),
            'assumptions' => ['uno', 'dos'],     // las listas no se tocan
            'horizons' => [7, 30, 90],
        ]);

        $this->assertSame(
            '{"model_versions":{"7":{"family":"a"},"30":{"family":"b"}},"options":{},"nested":{"by_horizon":{"7":1,"30":2}},"missing":null,"assumptions":["uno","dos"],"horizons":[7,30,90]}',
            $this->json($resource),
        );
    }

    public function test_of_and_or_empty(): void
    {
        $this->assertNull(JsonMap::of(null));
        $this->assertSame('{}', json_encode(JsonMap::of([])));
        $this->assertSame('{"7":"x"}', json_encode(JsonMap::of([7 => 'x'])));
        $this->assertSame('{"0":"x"}', json_encode(JsonMap::of(['x'])), 'un mapa nunca se convierte en lista, ni con la clave 0');
        $this->assertSame('plain', JsonMap::of('plain'));
        $this->assertSame('{}', json_encode(JsonMap::orEmpty(null)));
        $this->assertSame('{}', json_encode(JsonMap::orEmpty([])));
        $this->assertSame('{"a":1}', json_encode(JsonMap::orEmpty(['a' => 1])));
        $object = new \stdClass;
        $this->assertSame($object, JsonMap::orEmpty($object));
    }

    public function test_envelope_normalize_turns_only_the_map_fields_into_objects(): void
    {
        $envelope = ResultEnvelope::normalize([
            'model_version' => [7 => ['probability' => 'v1']], 'target_definition' => [], 'assumptions' => [], 'limitations' => ['a'], 'entity_keys' => ['x'],
            'horizons' => [7, 30],
        ]);

        $this->assertSame(
            '{"model_version":{"7":{"probability":"v1"}},"target_definition":{},"assumptions":[],"limitations":["a"],"entity_keys":["x"],"horizons":[7,30]}',
            json_encode($envelope),
        );
        $this->assertEqualsCanonicalizing(['model_version', 'target_definition'], ResultEnvelope::MAP_FIELDS);
        $this->assertSame(['x' => 1], ResultEnvelope::normalize(['x' => 1]), 'sin campos de mapa no cambia nada');
    }
}
