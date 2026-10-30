<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore\Support;

use Carbon\CarbonImmutable;
use PDO;
use PDOException;

/**
 * Base «ERP de origen» ficticia para probar el conector SQL (PostgreSQL) de punta a punta. Vive en una base de datos
 * distinta de la de Predictily (por defecto predictily_erp_source_p3) y se recrea en cada prueba. TODOS los datos son ficticios.
 */
final class ErpSourceDatabase
{
    public PDO $pdo;

    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 5432,
        public readonly string $database = 'predictily_erp_source_p3',
        public readonly string $username = 'predictily',
        public readonly string $password = 'secret',
    ) {
        $this->pdo = new PDO("pgsql:host={$host};port={$port};dbname={$database}", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public static function tryConnect(): ?self
    {
        try {
            return new self(
                host: (string) (getenv('TEST_ERP_HOST') ?: '127.0.0.1'),
                port: (int) (getenv('TEST_ERP_PORT') ?: 5432),
                database: (string) (getenv('TEST_ERP_DATABASE') ?: 'predictily_erp_source_p3'),
                username: (string) (getenv('TEST_ERP_USERNAME') ?: 'predictily'),
                password: (string) (getenv('TEST_ERP_PASSWORD') ?: 'secret'),
            );
        } catch (PDOException) {
            return null;
        }
    }

    /** Esquema tipo ERP: facturas, líneas, productos, clientes, pedidos e inventario. */
    public function createSchema(): void
    {
        $this->dropSchema();
        $this->pdo->exec(<<<'SQL'
CREATE TABLE erp_clientes (id_cliente serial PRIMARY KEY, ruc varchar(13), nombre varchar(100));
CREATE TABLE erp_productos (cod_item varchar(30) PRIMARY KEY, descripcion varchar(120), linea varchar(50), unidad varchar(20), activo boolean DEFAULT true, precio numeric(14,4), costo numeric(14,4));
CREATE TABLE erp_facturas (
    id_factura serial PRIMARY KEY, num_factura varchar(30) UNIQUE NOT NULL, tipo varchar(20) NOT NULL, estado varchar(20) NOT NULL,
    fecha_emision timestamp NOT NULL, cod_sucursal varchar(10) NOT NULL, id_cliente int REFERENCES erp_clientes, canal varchar(20),
    modificado_en timestamptz NOT NULL DEFAULT now());
CREATE TABLE erp_factura_lineas (
    id_factura int NOT NULL REFERENCES erp_facturas, linea int NOT NULL, cod_item varchar(30) NOT NULL, cantidad numeric(14,4) NOT NULL,
    precio_unit numeric(14,4) NOT NULL, descuento numeric(14,4) NOT NULL DEFAULT 0, iva numeric(14,4) NOT NULL DEFAULT 0, subtotal numeric(14,4), costo_unit numeric(14,4),
    PRIMARY KEY (id_factura, linea));
CREATE TABLE erp_pedidos (
    id_pedido serial PRIMARY KEY, num_pedido varchar(30) UNIQUE NOT NULL, tipo varchar(15) NOT NULL, estado varchar(20), fecha timestamp NOT NULL,
    fecha_registro timestamptz, fecha_cancelacion timestamp, entrega_prevista date, cod_sucursal varchar(10), id_cliente int REFERENCES erp_clientes,
    modificado_en timestamptz NOT NULL DEFAULT now());
CREATE TABLE erp_pedido_lineas (
    id_pedido int NOT NULL REFERENCES erp_pedidos, linea int NOT NULL, cod_item varchar(30) NOT NULL, cantidad numeric(14,4) NOT NULL,
    entregado numeric(14,4) NOT NULL DEFAULT 0, unidad varchar(20), precio numeric(14,4), PRIMARY KEY (id_pedido, linea));
CREATE TABLE erp_inventario (fecha date NOT NULL, cod_sucursal varchar(10) NOT NULL, cod_item varchar(30) NOT NULL, existencia numeric(14,4) NOT NULL,
    reservado numeric(14,4) DEFAULT 0, en_transito numeric(14,4) DEFAULT 0, PRIMARY KEY (fecha, cod_sucursal, cod_item));
SQL);
    }

    public function dropSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS erp_inventario, erp_pedido_lineas, erp_pedidos, erp_factura_lineas, erp_facturas, erp_productos, erp_clientes CASCADE');
    }

    /**
     * Datos ficticios: 3 productos, 2 clientes (una cédula ficticia y consumidor final), 5 facturas, una nota de crédito y una anulada.
     *
     * @return array{days: list<string>}
     */
    public function seed(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('America/Guayaquil');
        $d = fn (int $ago) => $today->subDays($ago)->toDateString();
        $this->pdo->exec("INSERT INTO erp_clientes (ruc, nombre) VALUES ('1710034065', 'Cliente Ficticio Uno'), ('9999999999999', 'CONSUMIDOR FINAL')");
        $this->pdo->exec("INSERT INTO erp_productos (cod_item, descripcion, linea, unidad, precio, costo) VALUES
            ('CEM-50', 'Cemento 50kg', 'Cemento', 'unidad', 8.50, 6.90), ('VAR-12', 'Varilla 12mm', 'Hierro', 'unidad', 7.20, 5.10), ('PIN-01', 'Pintura galón', 'Pinturas', 'unidad', 15.00, 11.00)");
        $factura = $this->pdo->prepare('INSERT INTO erp_facturas (num_factura, tipo, estado, fecha_emision, cod_sucursal, id_cliente, canal, modificado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id_factura');
        $linea = $this->pdo->prepare('INSERT INTO erp_factura_lineas (id_factura, linea, cod_item, cantidad, precio_unit, descuento, iva, subtotal, costo_unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $docs = [
            ['001-001-000000001', 'FACTURA', 'EMITIDA', $d(20).' 09:15:00', 1, 'LOCAL', [['CEM-50', 10, 8.5, 0, 10.2, 85, 6.9], ['VAR-12', 5, 7.2, 1, 5.25, 35, 5.1]]],
            ['001-001-000000002', 'FACTURA', 'EMITIDA', $d(18).' 11:00:00', 2, 'MAYOREO', [['CEM-50', 4, 8.5, 0, 4.08, 34, 6.9]]],
            ['001-001-000000003', 'FACTURA', 'ANULADA', $d(15).' 16:30:00', null, null, [['PIN-01', 2, 15, 0, 3.6, 30, 11]]],
            ['001-001-000000004', 'FACTURA', 'EMITIDA', $d(10).' 10:05:00', 1, 'LOCAL', [['PIN-01', 3, 15, 0, 5.4, 45, 11], ['CEM-50', 6, 8.5, 0, 6.12, 51, 6.9]]],
            ['001-001-000000005', 'FACTURA', 'EMITIDA', $d(5).' 15:45:00', 2, 'LOCAL', [['VAR-12', 8, 7.2, 0, 6.91, 57.6, 5.1]]],
            ['001-001-000000006', 'NOTA DE CREDITO', 'EMITIDA', $d(4).' 12:00:00', 1, 'LOCAL', [['CEM-50', 1, 8.5, 0, 1.02, 8.5, 6.9]]],
        ];
        foreach ($docs as [$number, $type, $status, $issued, $customer, $channel, $lines]) {
            $factura->execute([$number, $type, $status, $issued, '001', $customer, $channel, $issued.'-05']); // el ERP fecha su última modificación al emitir
            $id = (int) $factura->fetchColumn();
            foreach ($lines as $i => [$sku, $qty, $price, $discount, $tax, $net, $cost]) {
                $linea->execute([$id, $i + 1, $sku, $qty, $price, $discount, $tax, $net, $cost]);
            }
        }

        return ['days' => array_map($d, [20, 18, 15, 10, 5, 4])];
    }

    /** Corrección en origen: cambia la cantidad de una línea y la fecha de modificación (como haría el ERP). */
    public function correctInvoiceLine(string $number, string $sku, float $quantity): void
    {
        $stmt = $this->pdo->prepare('UPDATE erp_factura_lineas SET cantidad = ?, subtotal = ? * precio_unit - descuento WHERE cod_item = ? AND id_factura = (SELECT id_factura FROM erp_facturas WHERE num_factura = ?)');
        $stmt->execute([$quantity, $quantity, $sku, $number]);
        $this->pdo->prepare('UPDATE erp_facturas SET modificado_en = now() WHERE num_factura = ?')->execute([$number]);
    }

    public function scalar(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }
}
