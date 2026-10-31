<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use stdClass;

/**
 * Aserciones sobre la FORMA del JSON crudo de una respuesta. `$response->json()` decodifica con `assoc = true` y por eso no distingue `{}` de `[]`
 * ni un mapa con claves numéricas de una lista: aquí se decodifica como objetos (`assoc = false`) para comprobar lo que realmente recibe el cliente.
 */
trait AssertsJsonShapes
{
    /** Valor en una ruta con puntos sobre el JSON decodificado como objetos (índices para listas, nombres para objetos). */
    protected function rawJsonAt(TestResponse $response, string $path): mixed
    {
        $node = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            if ($node instanceof stdClass && property_exists($node, $segment)) {
                $node = $node->{$segment};
            } elseif (is_array($node) && array_key_exists((int) $segment, $node) && ctype_digit($segment)) {
                $node = $node[(int) $segment];
            } else {
                $this->fail("La ruta «{$path}» no existe en la respuesta (se detuvo en «{$segment}»): ".mb_substr((string) $response->getContent(), 0, 600));
            }
        }

        return $node;
    }

    /**
     * El valor de `$path` es un OBJETO JSON (`{...}`), nunca una lista, aunque esté vacío; si se indican `$keys`, tiene exactamente esas claves
     * (se compara como texto y sin orden: «7», «30»).
     *
     * @param  list<string|int>|null  $keys
     */
    protected function assertJsonObjectAt(TestResponse $response, string $path, ?array $keys = null): stdClass
    {
        $value = $this->rawJsonAt($response, $path);
        $this->assertInstanceOf(stdClass::class, $value, "«{$path}» debe ser un objeto JSON y es: ".json_encode($value));
        if ($keys !== null) {
            $expected = array_map('strval', $keys);
            $actual = array_map('strval', array_keys(get_object_vars($value)));
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "Claves de «{$path}»");   // jsonb no conserva el orden de las claves
        }

        return $value;
    }

    /** El valor de `$path` es una LISTA JSON (`[...]`), nunca un objeto. */
    protected function assertJsonListAt(TestResponse $response, string $path): array
    {
        $value = $this->rawJsonAt($response, $path);
        $this->assertIsArray($value, "«{$path}» debe ser una lista JSON y es: ".json_encode($value));

        return $value;
    }
}
