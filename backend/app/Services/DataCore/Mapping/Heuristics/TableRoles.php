<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\DataCore\Mapping\Discovery\DiscoveredTable;

/**
 * Rol probable de una tabla por su nombre (cabecera o línea de ventas, producto, inventario, proveedor, plazo, sucursal, bodega, cliente, categoría).
 * No lee datos: solo nombres. Los roles no son excluyentes (una vista «vw_ventas» sirve de cabecera y de línea).
 */
final class TableRoles
{
    private const NOISE = ['tbl', 'tb', 't', 'erp', 'vw', 'v', 'mst', 'tabla', 'view', 'dbo', 'gen', 'tmp'];

    private const SALES_TOKENS = ['factura', 'venta', 'documento', 'comprobante', 'ticket', 'boleta'];

    private const LINE_TOKENS = ['detalle', 'linea', 'renglon', 'partida', 'cuerpo'];

    /**
     * @return array<string, float> rol → 1.0 (no hay grados: o el nombre lo dice o no)
     */
    public static function detect(DiscoveredTable $table): array
    {
        $tokens = array_values(array_filter(NameTokens::of($table->name), fn (string $t) => ! in_array($t, self::NOISE, true)));
        $has = fn (array $set): bool => array_intersect($set, $tokens) !== [];
        $roles = [];

        $sales = $has(self::SALES_TOKENS);
        $line = $has(self::LINE_TOKENS) || ($sales && $has(['item']));
        $inventory = $has(['inventario', 'existencia', 'stock', 'saldo', 'kardex']);
        $leadTime = $has(['plazo']) || ($has(['entrega']) && $has(['proveedor']));
        $supplier = $has(['proveedor']) && ! $leadTime;
        $customer = $has(['cliente', 'comprador', 'customer']) && ! $sales && ! $line;
        $branch = $has(['sucursal', 'establecimiento', 'agencia', 'tienda']) && ! $sales && ! $inventory;
        $warehouse = $has(['bodega', 'almacen']) && ! $inventory && ! $sales;
        $category = $has(['categoria', 'familia', 'grupo']) && ! $sales && ! $inventory;
        $product = $has(['producto', 'articulo', 'mercaderia', 'material', 'sku']) && ! $sales && ! $line && ! $inventory && ! $leadTime && ! $supplier && ! $customer;

        if ($sales && $line) {
            $roles['sales_line'] = 1.0;
        } elseif ($sales && ! $line) {
            $roles['sales_header'] = 1.0;
            // Una vista con nombre de ventas o facturas suele traer cabecera y línea aplanadas.
            if ($table->kind === 'view') {
                $roles['sales_line'] = 1.0;
                $roles['sales_flat'] = 1.0;
            }
        } elseif ($line && ! $has(['inventario', 'producto', 'articulo', 'categoria', 'familia'])) {
            $roles['sales_line'] = 1.0; // «detalle», «lineas»: sin otro sustantivo, la línea de un documento
        }
        if ($has(['cabecera', 'encabezado', 'maestro']) && ! isset($roles['sales_line'])) {
            $roles['sales_header'] = 1.0;
        }
        foreach (['inventory' => $inventory, 'lead_time' => $leadTime, 'supplier' => $supplier, 'customer' => $customer, 'branch' => $branch, 'warehouse' => $warehouse, 'category' => $category, 'product' => $product] as $role => $hit) {
            if ($hit) {
                $roles[$role] = 1.0;
            }
        }

        return $roles;
    }
}
