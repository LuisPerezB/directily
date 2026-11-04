<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge\Support;

use Carbon\CarbonImmutable;
use PDO;

/**
 * Esquema «ERP de origen» ficticio para el extremo a extremo del Bridge, con prefijo ALEATORIO de tablas: no choca con `erp_*` (SqlSourceSyncTest y
 * otros paquetes que usan la misma base) ni con otra ejecución simultánea. Mismos datos que ErpSourceDatabase::seed (6 documentos, 8 líneas, una
 * anulada y una nota de crédito), para poder comparar el resultado con la sincronización SQL del backend. TODOS los datos son ficticios.
 */
final class BridgeErpFixture
{
    public readonly string $prefix;

    public function __construct(public readonly PDO $pdo)
    {
        $this->prefix = 'brg'.bin2hex(random_bytes(4)).'_';
    }

    public function table(string $name): string
    {
        return $this->prefix.$name;
    }

    public function create(): void
    {
        $p = $this->prefix;
        $this->pdo->exec(<<<SQL
CREATE TABLE {$p}clientes (id_cliente serial PRIMARY KEY, ruc varchar(13), nombre varchar(100));
CREATE TABLE {$p}productos (cod_item varchar(30) PRIMARY KEY, descripcion varchar(120), linea varchar(50));
CREATE TABLE {$p}facturas (
    id_factura serial PRIMARY KEY, num_factura varchar(30) UNIQUE NOT NULL, tipo varchar(20) NOT NULL, estado varchar(20) NOT NULL,
    fecha_emision timestamp NOT NULL, cod_sucursal varchar(10) NOT NULL, id_cliente int REFERENCES {$p}clientes, canal varchar(20),
    modificado_en timestamptz NOT NULL DEFAULT now());
CREATE TABLE {$p}factura_lineas (
    id_factura int NOT NULL REFERENCES {$p}facturas, linea int NOT NULL, cod_item varchar(30) NOT NULL, cantidad numeric(14,4) NOT NULL,
    precio_unit numeric(14,4) NOT NULL, descuento numeric(14,4) NOT NULL DEFAULT 0, iva numeric(14,4) NOT NULL DEFAULT 0, subtotal numeric(14,4),
    costo_unit numeric(14,4), PRIMARY KEY (id_factura, linea));
SQL);
    }

    public function drop(): void
    {
        $p = $this->prefix;
        $this->pdo->exec("DROP TABLE IF EXISTS {$p}factura_lineas, {$p}facturas, {$p}productos, {$p}clientes CASCADE");
    }

    public function seed(): void
    {
        $p = $this->prefix;
        $today = CarbonImmutable::now('America/Guayaquil');
        $d = fn (int $ago) => $today->subDays($ago)->toDateString();
        $this->pdo->exec("INSERT INTO {$p}clientes (ruc, nombre) VALUES ('1710034065', 'Cliente Ficticio Uno'), ('9999999999999', 'CONSUMIDOR FINAL')");
        $this->pdo->exec("INSERT INTO {$p}productos (cod_item, descripcion, linea) VALUES ('CEM-50', 'Cemento 50kg', 'Cemento'), ('VAR-12', 'Varilla 12mm', 'Hierro'), ('PIN-01', 'Pintura galón', 'Pinturas')");
        $factura = $this->pdo->prepare("INSERT INTO {$p}facturas (num_factura, tipo, estado, fecha_emision, cod_sucursal, id_cliente, canal, modificado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id_factura");
        $linea = $this->pdo->prepare("INSERT INTO {$p}factura_lineas (id_factura, linea, cod_item, cantidad, precio_unit, descuento, iva, subtotal, costo_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $docs = [
            ['001-001-000000001', 'FACTURA', 'EMITIDA', $d(20).' 09:15:00', 1, 'LOCAL', [['CEM-50', 10, 8.5, 0, 10.2, 85, 6.9], ['VAR-12', 5, 7.2, 1, 5.25, 35, 5.1]]],
            ['001-001-000000002', 'FACTURA', 'EMITIDA', $d(18).' 11:00:00', 2, 'MAYOREO', [['CEM-50', 4, 8.5, 0, 4.08, 34, 6.9]]],
            ['001-001-000000003', 'FACTURA', 'ANULADA', $d(15).' 16:30:00', null, null, [['PIN-01', 2, 15, 0, 3.6, 30, 11]]],
            ['001-001-000000004', 'FACTURA', 'EMITIDA', $d(10).' 10:05:00', 1, 'LOCAL', [['PIN-01', 3, 15, 0, 5.4, 45, 11], ['CEM-50', 6, 8.5, 0, 6.12, 51, 6.9]]],
            ['001-001-000000005', 'FACTURA', 'EMITIDA', $d(5).' 15:45:00', 2, 'LOCAL', [['VAR-12', 8, 7.2, 0, 6.91, 57.6, 5.1]]],
            ['001-001-000000006', 'NOTA DE CREDITO', 'EMITIDA', $d(4).' 12:00:00', 1, 'LOCAL', [['CEM-50', 1, 8.5, 0, 1.02, 8.5, 6.9]]],
        ];
        foreach ($docs as [$number, $type, $status, $issued, $customer, $channel, $lines]) {
            $factura->execute([$number, $type, $status, $issued, '001', $customer, $channel, $issued.'-05']);
            $id = (int) $factura->fetchColumn();
            foreach ($lines as $i => [$sku, $qty, $price, $discount, $tax, $net, $cost]) {
                $linea->execute([$id, $i + 1, $sku, $qty, $price, $discount, $tax, $net, $cost]);
            }
        }
    }

    /** Corrección en origen (como haría el ERP): cambia la cantidad de una línea y la fecha de modificación del documento. */
    public function correctInvoiceLine(string $number, string $sku, float $quantity): void
    {
        $p = $this->prefix;
        $this->pdo->prepare("UPDATE {$p}factura_lineas SET cantidad = ?, subtotal = ? * precio_unit - descuento WHERE cod_item = ? AND id_factura = (SELECT id_factura FROM {$p}facturas WHERE num_factura = ?)")
            ->execute([$quantity, $quantity, $sku, $number]);
        $this->pdo->prepare("UPDATE {$p}facturas SET modificado_en = now() WHERE num_factura = ?")->execute([$number]);
    }

    public function checksum(): string
    {
        $p = $this->prefix;

        return (string) $this->pdo->query(
            "SELECT md5(coalesce((SELECT string_agg(f::text, '|' ORDER BY f.id_factura) FROM {$p}facturas f), '') || coalesce((SELECT string_agg(l::text, '|' ORDER BY l.id_factura, l.linea) FROM {$p}factura_lineas l), '') || coalesce((SELECT string_agg(c::text, '|' ORDER BY c.id_cliente) FROM {$p}clientes c), ''))"
        )->fetchColumn();
    }

    /** Consulta autorizada del agente (plantilla de la entidad «sales»): alias = campos canónicos del importador. */
    public function salesTemplateSql(): string
    {
        $p = $this->prefix;

        return <<<SQL
SELECT f.num_factura AS document_number, f.tipo AS document_type, f.estado AS status, f.fecha_emision AS issued_at, f.cod_sucursal AS branch_code,
       l.linea AS line_number, l.cod_item AS product_sku, pr.descripcion AS product_name, pr.linea AS category,
       l.cantidad AS quantity, l.precio_unit AS unit_price, l.descuento AS discount, l.iva AS tax, l.subtotal AS net_amount, l.costo_unit AS unit_cost,
       c.ruc AS customer_code, f.canal AS channel, f.modificado_en AS source_recorded_at
FROM {$p}facturas f
JOIN {$p}factura_lineas l ON l.id_factura = f.id_factura
JOIN {$p}productos pr ON pr.cod_item = l.cod_item
LEFT JOIN {$p}clientes c ON c.id_cliente = f.id_cliente
WHERE f.modificado_en >= :since
SQL;
    }
}
