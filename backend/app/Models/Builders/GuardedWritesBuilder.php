<?php

declare(strict_types=1);

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Eloquent Builder que aplica a las operaciones MASIVAS (update, delete, forceDelete, increment, decrement, touch, insert, upsert…) las mismas reglas de
 * escritura que los eventos de modelo imponen a las instancias. Sin él, `Modelo::query()->update([...])` o `->insert([...])` se saltan los hooks
 * `creating/updating/deleting` y desde una empresa podrían modificar, crear o borrar filas públicas o de otra parte.
 *
 * Las subclases definen QUÉ filas puede escribir quien llama (`restrictWrites`) y qué filas nuevas puede crear (`assertInsertable`).
 * Lo que no se puede validar de forma segura (`truncate`, `updateOrInsert`, `insertUsing`…) se rechaza.
 *
 * Alcance: protege el acceso por modelos Eloquent (el único que usa la aplicación). `DB::table(...)` es acceso directo a la BD y queda fuera: solo lo usan
 * los comandos de mantenimiento y los seeders de plataforma.
 */
abstract class GuardedWritesBuilder extends Builder
{
    /** Métodos que ejecutan SQL de escritura sin pasar por filtros ni validaciones y que estos modelos no admiten. */
    private const BLOCKED = ['truncate', 'updateorinsert', 'insertusing', 'insertorignoreusing', 'insertorignorereturning'];

    /** Restringe ESTE builder a las filas que quien llama puede modificar o borrar (mutando su cláusula where). */
    abstract protected function restrictWrites(): void;

    /**
     * Valida las filas que se quieren crear; lanza si alguna no puede crearla quien llama.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    abstract protected function assertInsertable(array $rows): void;

    public function update(array $values)
    {
        $this->restrictWrites();

        return parent::update($values);
    }

    public function touch($column = null)
    {
        $this->restrictWrites();

        return parent::touch($column);
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->restrictWrites();

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->restrictWrites();

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->restrictWrites();

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->restrictWrites();

        return parent::decrementEach($columns, $extra);
    }

    public function delete()
    {
        $this->restrictWrites();

        return parent::delete();
    }

    /** `forceDelete` de Eloquent ejecuta el DELETE sin aplicar los global scopes; aquí sí se aplican y se restringe. */
    public function forceDelete()
    {
        $this->restrictWrites();

        return $this->toBase()->delete();
    }

    public function insert(array $values)
    {
        $this->assertInsertable($this->asRows($values));

        return $this->toBase()->insert($values);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        $this->assertInsertable([$values]);

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function insertOrIgnore(array $values)
    {
        $this->assertInsertable($this->asRows($values));

        return $this->toBase()->insertOrIgnore($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $rows = $this->asRows($values);
        $this->assertInsertable($rows);
        $this->assertUpsertSafe($rows, (array) $uniqueBy, $update === null ? null : (array) $update);

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Un upsert puede actualizar una fila EXISTENTE que coincide por la clave única: hay que impedir que esa fila sea una que quien llama no puede
     * escribir. Por defecto no hace falta nada; los builders que sí lo necesitan lo redefinen.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>|null  $update
     */
    protected function assertUpsertSafe(array $rows, array $uniqueBy, ?array $update): void {}

    public function __call($method, $parameters)
    {
        if (in_array(strtolower((string) $method), self::BLOCKED, true)) {
            $model = $this->getModel()::class;

            throw new LogicException("«{$method}» no está permitido en {$model}: use los métodos del modelo (save, create, updateOrCreate) para que se apliquen sus reglas.");
        }

        return parent::__call($method, $parameters);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function asRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        return is_array(array_first($values)) ? array_values($values) : [$values];
    }
}
