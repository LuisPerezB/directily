<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping\Support;

use App\Services\DataCore\Mapping\Discovery\DiscoveredSchema;
use App\Services\DataCore\Mapping\Discovery\SchemaDiscoveryService;

/**
 * Esquemas de catálogo EN MEMORIA con la forma que devuelven las consultas de catálogo de cada motor (tipos nativos incluidos): sirven para probar las
 * heurísticas sobre muchos esquemas y los dialectos sin servidor de prueba (MySQL y SQL Server) sin tocar ninguna base de datos.
 */
final class SchemaFixture
{
    /** @var list<array<string, mixed>> */
    private array $tables = [];

    /** @var list<array<string, mixed>> */
    private array $columns = [];

    /** @var list<array<string, mixed>> */
    private array $primaryKeys = [];

    /** @var list<array<string, mixed>> */
    private array $foreignKeys = [];

    public function __construct(private readonly string $dialect = 'pgsql') {}

    /**
     * @param  array<string, string>  $columns  nombre → tipo nativo (sufijo «!» = NOT NULL)
     * @param  list<string>  $pk
     * @param  list<array{0: string, 1: list<string>, 2: ?string, 3: string, 4: list<string>}>  $fks  [constraint, columnas, esquema padre, tabla padre, columnas padre]
     */
    public function table(?string $schema, string $name, array $columns, array $pk = [], array $fks = [], string $kind = 'table', ?int $rows = 1000): self
    {
        $this->tables[] = ['schema_name' => $schema, 'table_name' => $name, 'kind' => $kind, 'estimated_rows' => $rows];
        $position = 0;
        foreach ($columns as $column => $type) {
            $position++;
            $notNull = str_ends_with($type, '!');
            $type = rtrim($type, '!');
            $this->columns[] = ['schema_name' => $schema, 'table_name' => $name, 'column_name' => (string) $column, 'data_type' => $type, 'column_type' => $this->dialect === 'mysql' ? $type : null,
                'is_nullable' => $notNull ? 'NO' : 'YES', 'ordinal_position' => $position, 'max_length' => null];
        }
        foreach (array_values($pk) as $i => $column) {
            $this->primaryKeys[] = ['schema_name' => $schema, 'table_name' => $name, 'column_name' => $column, 'position' => $i + 1];
        }
        foreach ($fks as [$constraint, $cols, $refSchema, $refTable, $refCols]) {
            foreach (array_values($cols) as $i => $column) {
                $this->foreignKeys[] = ['constraint_name' => $constraint, 'schema_name' => $schema, 'table_name' => $name, 'column_name' => $column,
                    'ref_schema' => $refSchema, 'ref_table' => $refTable, 'ref_column' => $refCols[$i], 'position' => $i + 1];
            }
        }

        return $this;
    }

    public function build(): DiscoveredSchema
    {
        return SchemaDiscoveryService::build($this->dialect, $this->tables, $this->columns, $this->primaryKeys, $this->foreignKeys);
    }

    /** Mismo esquema con las tablas en otro orden (la propuesta no debe depender del orden del catálogo). */
    public function buildReversed(): DiscoveredSchema
    {
        return SchemaDiscoveryService::build($this->dialect, array_reverse($this->tables), array_reverse($this->columns), $this->primaryKeys, array_reverse($this->foreignKeys));
    }

    /** ERP en español, esquema PostgreSQL (el mismo del fixture vivo, sin servidor). */
    public static function spanishErp(): self
    {
        return (new self('pgsql'))
            ->table('comercial', 'cliente', ['id_cliente' => 'integer!', 'ruc' => 'character varying', 'razon_social' => 'character varying', 'correo_electronico' => 'character varying', 'telefono' => 'character varying',
                'direccion' => 'character varying', 'provincia' => 'character varying', 'tipo_cliente' => 'character varying'], ['id_cliente'])
            ->table('inventario', 'articulo', ['cod_articulo' => 'character varying!', 'descripcion' => 'character varying', 'linea' => 'character varying', 'unidad_medida' => 'character varying',
                'marca' => 'character varying', 'precio_venta' => 'numeric', 'costo_promedio' => 'numeric', 'activo' => 'boolean'], ['cod_articulo'])
            ->table('comercial', 'cab_venta', ['id_venta' => 'integer!', 'numero_factura' => 'character varying!', 'tipo_documento' => 'character varying!', 'estado' => 'character varying!',
                'fecha_emision' => 'timestamp without time zone!', 'cod_sucursal' => 'character varying!', 'id_cliente' => 'integer', 'canal_venta' => 'character varying',
                'fecha_modificacion' => 'timestamp with time zone!', 'vendedor' => 'character varying', 'total_venta' => 'numeric'], ['id_venta'],
                [['cab_venta_id_cliente_fkey', ['id_cliente'], 'comercial', 'cliente', ['id_cliente']]])
            ->table('comercial', 'det_venta', ['id_venta' => 'integer!', 'nro_linea' => 'integer!', 'cod_articulo' => 'character varying!', 'cantidad' => 'numeric!', 'precio_unitario' => 'numeric!',
                'valor_descuento' => 'numeric!', 'valor_iva' => 'numeric!', 'subtotal_linea' => 'numeric', 'costo_unitario' => 'numeric'], ['id_venta', 'nro_linea'],
                [['det_venta_id_venta_fkey', ['id_venta'], 'comercial', 'cab_venta', ['id_venta']], ['det_venta_cod_articulo_fkey', ['cod_articulo'], 'inventario', 'articulo', ['cod_articulo']]]);
    }
}
