<?php

declare(strict_types=1);

namespace Tests\Feature\Mapping\Support;

use Carbon\CarbonImmutable;
use PDO;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;

/**
 * «ERP de origen» ficticio de W6-C: esquemas de PostgreSQL con columnas en español (comercial, inventario, compras) dentro de la base de pruebas
 * `predictily_erp_source_w6` (o la que indique TEST_ERP_DATABASE). Se parece a un ERP legado: cabecera y detalle de ventas con claves foráneas, catálogo de
 * artículos, clientes con datos personales, existencias por bodega, proveedores y plazos de entrega, más tablas de ruido. TODOS los datos son ficticios.
 *
 * Códigos de documento y de estado a propósito NO reconocidos por el importador (FC, NCR, COT; EMI, ANU, PEN) para que el Auto Mapper proponga mapeos de valores.
 */
final class MappingErpDatabase
{
    public PDO $pdo;

    public function __construct(public readonly ErpSourceDatabase $erp)
    {
        $this->pdo = $erp->pdo;
    }

    public static function tryConnect(): ?self
    {
        $erp = ErpSourceDatabase::tryConnect();

        return $erp === null ? null : new self($erp);
    }

    public function createSchema(): void
    {
        $this->dropSchema();
        $this->pdo->exec(<<<'SQL'
CREATE SCHEMA comercial; CREATE SCHEMA inventario; CREATE SCHEMA compras;
CREATE TABLE comercial.cliente (
    id_cliente serial PRIMARY KEY, ruc varchar(13), razon_social varchar(120), correo_electronico varchar(120), telefono varchar(20), direccion varchar(200),
    provincia varchar(40), tipo_cliente varchar(20));
CREATE TABLE inventario.articulo (
    cod_articulo varchar(30) PRIMARY KEY, descripcion varchar(150), linea varchar(60), unidad_medida varchar(10), marca varchar(40),
    precio_venta numeric(14,4), costo_promedio numeric(14,4), activo boolean DEFAULT true);
CREATE TABLE comercial.cab_venta (
    id_venta serial PRIMARY KEY, numero_factura varchar(30) UNIQUE NOT NULL, tipo_documento varchar(10) NOT NULL, estado varchar(10) NOT NULL,
    fecha_emision timestamp NOT NULL, cod_sucursal varchar(10) NOT NULL, id_cliente int REFERENCES comercial.cliente(id_cliente), canal_venta varchar(15),
    fecha_modificacion timestamptz NOT NULL DEFAULT now(), vendedor varchar(40), total_venta numeric(14,4));
CREATE TABLE comercial.det_venta (
    id_venta int NOT NULL REFERENCES comercial.cab_venta(id_venta), nro_linea int NOT NULL, cod_articulo varchar(30) NOT NULL REFERENCES inventario.articulo(cod_articulo),
    cantidad numeric(14,4) NOT NULL, precio_unitario numeric(14,4) NOT NULL, valor_descuento numeric(14,4) NOT NULL DEFAULT 0, valor_iva numeric(14,4) NOT NULL DEFAULT 0,
    subtotal_linea numeric(14,4), costo_unitario numeric(14,4), PRIMARY KEY (id_venta, nro_linea));
CREATE TABLE inventario.existencia_bodega (
    fecha_corte date NOT NULL, cod_bodega varchar(10) NOT NULL, cod_articulo varchar(30) NOT NULL REFERENCES inventario.articulo(cod_articulo),
    existencia numeric(14,4) NOT NULL, reservado numeric(14,4) DEFAULT 0, en_transito numeric(14,4) DEFAULT 0, costo_promedio numeric(14,4),
    PRIMARY KEY (fecha_corte, cod_bodega, cod_articulo));
CREATE TABLE compras.proveedor (cod_proveedor varchar(20) PRIMARY KEY, razon_social varchar(120), ruc varchar(13), pais varchar(2), plazo_habitual_dias int);
CREATE TABLE compras.plazo_entrega (cod_proveedor varchar(20) NOT NULL REFERENCES compras.proveedor(cod_proveedor), cod_articulo varchar(30), dias_entrega int NOT NULL, desviacion_dias numeric(6,2));
CREATE TABLE public.bitacora_usuario (id bigserial PRIMARY KEY, usuario varchar(40), accion varchar(200), fecha_hora timestamp);
CREATE TABLE public.parametros_sistema (clave varchar(40) PRIMARY KEY, valor varchar(200));
SQL);
    }

    public function dropSchema(): void
    {
        $this->pdo->exec('DROP SCHEMA IF EXISTS comercial, inventario, compras CASCADE; DROP TABLE IF EXISTS public.bitacora_usuario, public.parametros_sistema CASCADE');
    }

    /**
     * Datos ficticios: 2 clientes, 3 artículos, 5 documentos (3 facturas emitidas, 1 anulada, 1 nota de crédito), existencias de dos fechas, 2 proveedores.
     *
     * @return array{days: list<string>}
     */
    public function seed(?CarbonImmutable $today = null, bool $withQuotation = false): array
    {
        $today ??= CarbonImmutable::now('America/Guayaquil');
        $d = fn (int $ago) => $today->subDays($ago)->toDateString();
        $this->pdo->exec("INSERT INTO comercial.cliente (ruc, razon_social, correo_electronico, telefono, direccion, provincia, tipo_cliente) VALUES
            ('1710034065', 'Cliente Ficticio Uno', 'uno@ficticio.test', '0999999991', 'Calle Falsa 123', 'Pichincha', 'MAYORISTA'),
            ('9999999999999', 'CONSUMIDOR FINAL', 'cf@ficticio.test', '0999999992', 'S/N', 'Guayas', 'MINORISTA')");
        $this->pdo->exec("INSERT INTO inventario.articulo (cod_articulo, descripcion, linea, unidad_medida, marca, precio_venta, costo_promedio) VALUES
            ('CEM-50', 'Cemento 50kg', 'Cemento', 'unidad', 'Ficticia', 8.50, 6.90), ('VAR-12', 'Varilla 12mm', 'Hierro', 'unidad', 'Ficticia', 7.20, 5.10), ('PIN-01', 'Pintura galón', 'Pinturas', 'unidad', 'Ficticia', 15.00, 11.00)");
        $header = $this->pdo->prepare('INSERT INTO comercial.cab_venta (numero_factura, tipo_documento, estado, fecha_emision, cod_sucursal, id_cliente, canal_venta, fecha_modificacion, vendedor, total_venta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id_venta');
        $line = $this->pdo->prepare('INSERT INTO comercial.det_venta (id_venta, nro_linea, cod_articulo, cantidad, precio_unitario, valor_descuento, valor_iva, subtotal_linea, costo_unitario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $docs = [
            ['001-001-000000001', 'FC', 'EMI', $d(20).' 09:15:00', 1, 'LOCAL', [['CEM-50', 10, 8.5, 0, 10.2, 85, 6.9], ['VAR-12', 5, 7.2, 1, 5.25, 35, 5.1]]],
            ['001-001-000000002', 'FC', 'EMI', $d(18).' 11:00:00', 2, 'MAYOREO', [['CEM-50', 4, 8.5, 0, 4.08, 34, 6.9]]],
            ['001-001-000000003', 'FC', 'ANU', $d(15).' 16:30:00', null, null, [['PIN-01', 2, 15, 0, 3.6, 30, 11]]],
            ['001-001-000000004', 'FC', 'EMI', $d(10).' 10:05:00', 1, 'LOCAL', [['PIN-01', 3, 15, 0, 5.4, 45, 11], ['CEM-50', 6, 8.5, 0, 6.12, 51, 6.9]]],
            ['001-001-000000005', 'NCR', 'EMI', $d(4).' 12:00:00', 1, 'LOCAL', [['CEM-50', 1, 8.5, 0, 1.02, 8.5, 6.9]]],
        ];
        if ($withQuotation) {
            $docs[] = ['COT-000000001', 'COT', 'PEN', $d(3).' 12:00:00', 2, 'LOCAL', [['VAR-12', 20, 7.2, 0, 17.28, 144, 5.1]]];
        }
        foreach ($docs as [$number, $type, $status, $issued, $customer, $channel, $lines]) {
            $header->execute([$number, $type, $status, $issued, '001', $customer, $channel, $issued.'-05', 'vendedor ficticio', array_sum(array_map(fn ($l) => $l[5], $lines))]);
            $id = (int) $header->fetchColumn();
            foreach ($lines as $i => [$sku, $qty, $price, $discount, $tax, $net, $cost]) {
                $line->execute([$id, $i + 1, $sku, $qty, $price, $discount, $tax, $net, $cost]);
            }
        }
        $this->pdo->exec("INSERT INTO inventario.existencia_bodega (fecha_corte, cod_bodega, cod_articulo, existencia, reservado, en_transito, costo_promedio) VALUES
            ('{$d(9)}', '001', 'CEM-50', 120, 10, 0, 6.9), ('{$d(9)}', '001', 'VAR-12', 80, 0, 20, 5.1), ('{$d(2)}', '001', 'CEM-50', 95, 5, 0, 6.9), ('{$d(2)}', '001', 'PIN-01', 40, 0, 0, 11)");
        $this->pdo->exec("INSERT INTO compras.proveedor (cod_proveedor, razon_social, ruc, pais, plazo_habitual_dias) VALUES ('PRV-01', 'Proveedora Ficticia SA', '1790011223001', 'EC', 7), ('PRV-02', 'Importadora Ficticia', '1790099887001', 'CN', 45)");
        $this->pdo->exec("INSERT INTO compras.plazo_entrega (cod_proveedor, cod_articulo, dias_entrega, desviacion_dias) VALUES ('PRV-01', 'CEM-50', 5, 1.5), ('PRV-02', 'VAR-12', 40, 6.0)");
        $this->pdo->exec("INSERT INTO public.bitacora_usuario (usuario, accion, fecha_hora) VALUES ('ficticio', 'inicio de sesión', now())");

        return ['days' => array_map($d, [20, 18, 15, 10, 4])];
    }

    public function scalar(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    public function connection(): array
    {
        return ['host' => $this->erp->host, 'port' => $this->erp->port, 'database' => $this->erp->database, 'username' => $this->erp->username, 'password' => $this->erp->password];
    }
}
