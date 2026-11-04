<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\DataCore\Mapping\Heuristics\EntityProposer;
use App\Services\DataCore\Mapping\Templates\SourceQueryBuilder;
use App\Services\DataCore\Mapping\Templates\SourceSpec;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Unit\Mapping\Support\SchemaFixture;

final class SourceQueryAndFingerprintTest extends TestCase
{
    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [spec, field_map] */
    private function salesSpec(string $dialect = 'pgsql'): array
    {
        $fixture = match ($dialect) {
            'pgsql' => SchemaFixture::spanishErp(),
            'mysql' => (new SchemaFixture('mysql'))
                ->table(null, 'cab_venta', ['id_venta' => 'int', 'numero_factura' => 'varchar', 'fecha_emision' => 'datetime', 'fecha_modificacion' => 'datetime'], ['id_venta'])
                ->table(null, 'det_venta', ['id_venta' => 'int', 'cod_articulo' => 'varchar', 'cantidad' => 'decimal', 'precio_unitario' => 'decimal'], [], [['fk', ['id_venta'], null, 'cab_venta', ['id_venta']]]),
            default => (new SchemaFixture('sqlsrv'))
                ->table('dbo', 'cab_venta', ['id_venta' => 'int', 'numero_factura' => 'varchar', 'fecha_emision' => 'datetime2', 'fecha_modificacion' => 'datetime2'], ['id_venta'])
                ->table('dbo', 'det_venta', ['id_venta' => 'int', 'cod_articulo' => 'varchar', 'cantidad' => 'decimal', 'precio_unitario' => 'money'], [], [['fk', ['id_venta'], 'dbo', 'cab_venta', ['id_venta']]]),
        };
        $p = (new EntityProposer)->propose(ImportEntity::Sales, $fixture->build());
        $this->assertTrue($p->complete, $dialect.': '.json_encode($p->unmapped));

        return [$p->spec, $p->fieldMap];
    }

    public function test_generated_queries_use_the_dialect_quotes_pass_the_read_only_guard_and_alias_to_canonical_names(): void
    {
        $expectations = ['pgsql' => ['"comercial"."det_venta" t0', 'AS "quantity"', 't1."fecha_modificacion" >= :since'], 'mysql' => ['`det_venta` t0', 'AS `quantity`', 't1.`fecha_modificacion` >= :since'],
            'sqlsrv' => ['[dbo].[det_venta] t0', 'AS [quantity]', 't1.[fecha_modificacion] >= :since']];
        foreach ($expectations as $dialect => $needles) {
            [$spec, $map] = $this->salesSpec($dialect);
            $built = (new SourceQueryBuilder)->build($spec, $map);

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $built['sql'], $dialect);
            }
            $this->assertStringStartsWith('SELECT ', $built['sql']);
            $this->assertStringNotContainsString('ORDER BY', $built['sql'], 'SQL Server no admite ORDER BY en la vista previa');
            $validated = SqlQueryGuard::validate($built['sql'], $dialect);
            $this->assertTrue($validated->usesSince, $dialect);
            // field_map identidad: el mapeo SQL no depende de nombres de columnas del origen.
            foreach ($built['field_map'] as $field => $source) {
                $this->assertSame($field, $source);
            }
        }
    }

    public function test_every_canonical_field_name_is_a_valid_alias_for_the_guard_in_every_dialect(): void
    {
        $registry = new ImportFieldRegistry;
        foreach (ImportEntity::cases() as $entity) {
            foreach ($registry->fields($entity) as $field) {
                foreach (['pgsql' => ['"', '"'], 'mysql' => ['`', '`'], 'sqlsrv' => ['[', ']']] as $dialect => [$open, $close]) {
                    SqlQueryGuard::validate("SELECT t0.{$open}x{$close} AS {$open}{$field->key}{$close} FROM {$open}t{$close} t0", $dialect);
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function test_constants_stay_out_of_the_query_and_go_to_the_field_map(): void
    {
        [$spec, $map] = $this->salesSpec();
        $map['currency'] = ['const' => 'USD'];
        $built = (new SourceQueryBuilder)->build($spec, $map);

        $this->assertSame(['const' => 'USD'], $built['field_map']['currency']);
        $this->assertStringNotContainsString('USD', $built['sql']);
    }

    public function test_malformed_structures_are_rejected_before_any_query_is_built(): void
    {
        [$spec, $map] = $this->salesSpec();
        $broken = [
            'dialecto desconocido' => [['dialect' => 'oracle'] + $spec, $map],
            'referencia a una columna que no existe' => [$spec, array_merge($map, ['channel' => 't0.no_existe'])],
            'alias inexistente' => [$spec, array_merge($map, ['channel' => 't9.cantidad'])],
            'unión a una tabla posterior' => [array_replace_recursive($spec, ['tables' => [0 => ['join' => ['from' => 't1', 'type' => 'inner', 'on' => [['id_venta', 'id_venta']]]]]]), $map],
            'tipo de unión inválido' => [array_replace_recursive($spec, ['tables' => [1 => ['join' => ['type' => 'cross']]]]), $map],
            'sin tablas' => [['dialect' => 'pgsql', 'tables' => []], $map],
            'constante no escalar' => [$spec, array_merge($map, ['currency' => ['const' => ['x']]])],
        ];
        foreach ($broken as $name => [$s, $m]) {
            $this->assertNotSame([], SourceSpec::validate($s, $m), $name);
        }
        $this->expectException(InvalidArgumentException::class);
        (new SourceQueryBuilder)->build(['dialect' => 'oracle'] + $spec, $map);
    }

    public function test_guard_rejects_what_the_read_only_policy_rejects(): void
    {
        $builder = new SourceQueryBuilder;
        foreach (['DELETE FROM comercial.det_venta', 'SELECT 1; DROP TABLE x', 'SELECT * FROM pg_shadow', 'SELECT pg_sleep(60)', 'UPDATE x SET y = 1', 'SELECT t0."sp_cantidad" FROM "x" t0', 'SELECT * FROM information_schema.tables'] as $sql) {
            try {
                $builder->guard($sql, 'pgsql');
                $this->fail("la política de solo lectura debía rechazar: {$sql}");
            } catch (SqlQueryRejected) {
                $this->addToAssertionCount(1);
            }
        }
        $builder->guard('SELECT t0."a" AS "quantity" FROM "public"."t" t0 WHERE t0."m" >= :since', 'pgsql');
        $this->addToAssertionCount(1);
    }

    public function test_quote_characters_inside_identifiers_are_doubled_so_a_hostile_name_stays_one_identifier(): void
    {
        $spec = ['version' => 1, 'dialect' => 'pgsql', 'since' => null, 'tables' => [[
            'alias' => 't0', 'schema' => 'public', 'name' => 'tabla "rara"', 'kind' => 'table', 'join' => null,
            'columns' => ['cant"; DROP TABLE x;--idad' => 'decimal', 'otra' => 'text'],
        ]]];
        $built = (new SourceQueryBuilder)->build($spec, ['quantity' => 't0.cant"; DROP TABLE x;--idad']);

        $this->assertSame('SELECT t0."cant""; DROP TABLE x;--idad" AS "quantity" FROM "public"."tabla ""rara""" t0', $built['sql']);
        (new SourceQueryBuilder)->guard($built['sql'], 'pgsql');   // una sola sentencia SELECT: el nombre hostil nunca sale del identificador entrecomillado
        $this->addToAssertionCount(1);
    }

    public function test_identifiers_with_control_characters_or_backslash_are_rejected_instead_of_quoted(): void
    {
        foreach (["col\nsalto", "col\x00nulo", 'col\\barra', "col\ttab", ''] as $unsafe) {
            $spec = ['version' => 1, 'dialect' => 'pgsql', 'since' => null, 'tables' => [['alias' => 't0', 'schema' => 'public', 'name' => 'tabla', 'kind' => 'table', 'join' => null, 'columns' => [$unsafe => 'decimal']]]];
            $this->assertNotSame([], SourceSpec::validate($spec, []), json_encode($unsafe));
            try {
                (new SourceQueryBuilder)->build($spec, ['quantity' => 't0.'.$unsafe]);
                $this->fail('un identificador no seguro no debe llegar a una consulta: '.json_encode($unsafe));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fingerprint_depends_on_the_columns_the_template_uses_not_on_the_rest_of_the_schema(): void
    {
        [$spec, $map] = $this->salesSpec();
        $dialect = 'pgsql';
        $base = SourceSpec::fingerprint($dialect, SourceSpec::referenced($spec, $map));

        $this->assertSame($base, SourceSpec::fingerprint($dialect, SourceSpec::referenced($spec, $map)), 'estable');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $base);
        // Una columna usada con otro tipo, o quitada, cambia la huella.
        $retyped = $spec;
        $retyped['tables'][0]['columns']['cantidad'] = 'text';
        $this->assertNotSame($base, SourceSpec::fingerprint($dialect, SourceSpec::referenced($retyped, $map)));
        $fewer = $map;
        unset($fewer['discount']);
        $this->assertNotSame($base, SourceSpec::fingerprint($dialect, SourceSpec::referenced($spec, $fewer)));
        // Una columna que la plantilla NO usa no la cambia; otro dialecto, sí.
        $unused = $spec;
        $unused['tables'][0]['columns']['columna_nueva'] = 'text';
        $this->assertSame($base, SourceSpec::fingerprint($dialect, SourceSpec::referenced($unused, $map)));
        $this->assertNotSame($base, SourceSpec::fingerprint('mysql', SourceSpec::referenced($spec, $map)));
    }

    public function test_diff_reports_removed_retyped_and_added_columns_in_the_shape_of_the_w2_drift(): void
    {
        [$spec, $map] = $this->salesSpec();
        $same = SourceSpec::diff($spec, $map, SchemaFixture::spanishErp()->build());
        $this->assertTrue($same['matches']);
        $this->assertSame($same['expected_fingerprint'], $same['actual_fingerprint']);
        $this->assertSame([], [...$same['removed'], ...$same['retyped']]);

        $changed = (new SchemaFixture('pgsql'))
            ->table('comercial', 'cab_venta', ['id_venta' => 'integer!', 'numero_factura' => 'character varying!', 'tipo_documento' => 'character varying!', 'estado' => 'character varying!', 'fecha_emision' => 'timestamp without time zone!',
                'cod_sucursal' => 'character varying!', 'id_cliente' => 'integer', 'canal_venta' => 'character varying', 'fecha_modificacion' => 'timestamp with time zone!', 'columna_nueva' => 'text'], ['id_venta'])
            ->table('comercial', 'det_venta', ['id_venta' => 'integer!', 'nro_linea' => 'integer!', 'cod_articulo' => 'character varying!', 'cantidad_vendida' => 'numeric!', 'precio_unitario' => 'character varying!',
                'valor_descuento' => 'numeric!', 'valor_iva' => 'numeric!', 'subtotal_linea' => 'numeric', 'costo_unitario' => 'numeric'], ['id_venta', 'nro_linea'])
            ->table('comercial', 'cliente', ['id_cliente' => 'integer!', 'provincia' => 'character varying', 'tipo_cliente' => 'character varying'], ['id_cliente'])
            ->table('inventario', 'articulo', ['cod_articulo' => 'character varying!', 'descripcion' => 'character varying', 'linea' => 'character varying'], ['cod_articulo'])
            ->build();
        $diff = SourceSpec::diff($spec, $map, $changed);

        $this->assertFalse($diff['matches']);
        $this->assertSame(['comercial.det_venta.cantidad'], $diff['removed']);
        $this->assertSame(['comercial.det_venta.precio_unitario: decimal→text'], $diff['retyped']);
        $this->assertContains('comercial.cab_venta.columna_nueva', $diff['added']);
        $this->assertContains('comercial.det_venta.cantidad_vendida', $diff['added']);
        $this->assertNotSame($diff['expected_fingerprint'], $diff['actual_fingerprint']);
    }

    public function test_diff_flags_a_dialect_mismatch_and_removed_tables(): void
    {
        [$spec, $map] = $this->salesSpec();
        $mysql = (new SchemaFixture('mysql'))->table(null, 'otra', ['x' => 'int'])->build();
        $diff = SourceSpec::diff($spec, $map, $mysql);

        $this->assertTrue($diff['dialect_mismatch']);
        $this->assertFalse($diff['matches']);
        $this->assertContains('comercial.det_venta', $diff['tables_removed']);
    }
}
