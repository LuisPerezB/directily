<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics\Concerns;

/**
 * Filtros booleanos de la query string (V2.5 · W3-D). Un cliente conforme a OpenAPI (`type: boolean`) o axios serializan `true`/`false`, y un cliente
 * PHP o un formulario `1`/`0`; la regla `boolean` de Laravel solo acepta `true`/`false`/`1`/`0` como valores ya tipados o `"0"`/`"1"` y rechaza las
 * cadenas `"true"`/`"false"` con 422. Aquí se normalizan todas a `bool` ANTES de validar; un valor que no es un booleano reconocible se deja como
 * llegó para que la regla `boolean` lo rechace (nunca se convierte en silencio en «sin filtro»).
 */
trait NormalizesBooleanQuery
{
    /** @return list<string> nombres de los campos booleanos de la petición */
    abstract protected function booleanFilters(): array;

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach ($this->booleanFilters() as $field) {
            $raw = $this->query($field);
            if ($raw === null || $raw === '' || is_array($raw)) {
                continue;
            }
            $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
