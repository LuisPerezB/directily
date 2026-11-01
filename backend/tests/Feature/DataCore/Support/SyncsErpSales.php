<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore\Support;

/**
 * Ayudas para sincronizar ventas desde la base «ERP de origen» ficticia (ver ErpSourceDatabase): fuente SQL, mapeo de ventas
 * aprobado y sincronización síncrona (cola `sync`). Requiere `$this->erp`, `$this->sourceId` y un usuario con `datasources.manage`.
 */
trait SyncsErpSales
{
    private function createErpSource(): int
    {
        $response = $this->postJson('/api/v1/data-sources', [
            'type' => 'connector', 'connector_key' => 'sql_postgresql', 'name' => 'ERP ficticio (PostgreSQL)',
            'config' => ['host' => $this->erp->host, 'port' => $this->erp->port, 'database' => $this->erp->database, 'statement_timeout_seconds' => 30],
            'credentials' => ['username' => $this->erp->username, 'password' => $this->erp->password],
        ])->assertCreated();

        return (int) $response->json('data.id');
    }

    /** @return array<string, mixed> */
    private function erpSalesMapping(array $options = [], string $extraWhere = ''): array
    {
        return [
            'entity' => 'sales',
            'query' => <<<SQL
SELECT f.num_factura, f.tipo, f.estado, f.fecha_emision, f.cod_sucursal, l.linea AS num_linea, l.cod_item, p.descripcion, p.linea AS categoria,
       l.cantidad, l.precio_unit, l.descuento, l.iva, l.subtotal, l.costo_unit, c.ruc AS cliente, f.canal, f.modificado_en
FROM erp_facturas f
JOIN erp_factura_lineas l ON l.id_factura = f.id_factura
JOIN erp_productos p ON p.cod_item = l.cod_item
LEFT JOIN erp_clientes c ON c.id_cliente = f.id_cliente
WHERE f.modificado_en >= :since {$extraWhere}
ORDER BY f.id_factura, l.linea
SQL,
            'field_map' => [
                'document_number' => 'num_factura', 'document_type' => 'tipo', 'status' => 'estado', 'issued_at' => 'fecha_emision', 'branch_code' => 'cod_sucursal',
                'line_number' => 'num_linea', 'product_sku' => 'cod_item', 'product_name' => 'descripcion', 'category' => 'categoria', 'quantity' => 'cantidad',
                'unit_price' => 'precio_unit', 'discount' => 'descuento', 'tax' => 'iva', 'net_amount' => 'subtotal', 'unit_cost' => 'costo_unit',
                'customer_code' => 'cliente', 'channel' => 'canal', 'source_recorded_at' => 'modificado_en',
            ],
            'options' => $options,
        ];
    }

    /** @param  array<string, mixed>  $mapping */
    private function approveErpMapping(array $mapping): int
    {
        $id = (int) $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $mapping)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings/{$id}/approve")->assertOk()->assertJsonPath('data.is_active', true);

        return $id;
    }

    /** @return array<string, mixed> la sincronización terminada (el trabajo corre en línea con la cola `sync`) */
    private function runSync(string $mode = 'incremental', array $extra = []): array
    {
        $response = $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales', 'mode' => $mode] + $extra)->assertStatus(202);

        return $this->getJson('/api/v1/syncs/'.$response->json('data.id'))->assertOk()->json('data');
    }
}
