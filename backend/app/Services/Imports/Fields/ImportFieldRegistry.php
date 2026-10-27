<?php

declare(strict_types=1);

namespace App\Services\Imports\Fields;

use App\Services\Imports\ImportEntity;

/** Definición de campos canónicos y sinónimos por tipo de importación. */
final class ImportFieldRegistry
{
    /** @return list<FieldDefinition> */
    public function fields(ImportEntity $entity): array
    {
        return match ($entity) {
            ImportEntity::Sales => $this->sales(),
            ImportEntity::Products => $this->products(),
            ImportEntity::Inventory => $this->inventory(),
            ImportEntity::LeadTimes => $this->leadTimes(),
            ImportEntity::Branches => $this->branches(),
            ImportEntity::Suppliers => $this->suppliers(),
            ImportEntity::Promotions => $this->promotions(),
        };
    }

    /** @return array<string, FieldDefinition> */
    public function keyed(ImportEntity $entity): array
    {
        $keyed = [];
        foreach ($this->fields($entity) as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }

    /** @return list<string> */
    public function requiredKeys(ImportEntity $entity): array
    {
        return array_values(array_map(fn (FieldDefinition $f) => $f->key, array_filter($this->fields($entity), fn (FieldDefinition $f) => $f->required)));
    }

    /** @return list<FieldDefinition> */
    private function sales(): array
    {
        return [
            new FieldDefinition('document_number', 'Número de documento', true, 'string', 'Número de factura o comprobante (p. ej. 001-001-000012345). Junto con el tipo identifica el documento y evita duplicados al reimportar.', ['numero_factura', 'nro_factura', 'num_factura', 'factura', 'numero_documento', 'nro_documento', 'num_documento', 'documento', 'comprobante', 'numero_comprobante', 'secuencial', 'invoice', 'invoice_number', 'doc_number', 'numero']),
            new FieldDefinition('document_type', 'Tipo de documento', false, 'string', 'Factura, nota de venta o nota de crédito (devolución). Por defecto: factura.', ['tipo_documento', 'tipo_doc', 'tipo_comprobante', 'tipo', 'document_type', 'doc_type']),
            new FieldDefinition('status', 'Estado', false, 'string', 'Anulada/anulado se excluye de la demanda. Por defecto: completada.', ['estado', 'anulada', 'anulado', 'estado_documento', 'status']),
            new FieldDefinition('issued_at', 'Fecha de emisión', true, 'datetime', 'Fecha o fecha-hora de la venta. Se interpreta en la zona horaria de la empresa.', ['fecha', 'fecha_emision', 'fecha_venta', 'fecha_factura', 'fecha_documento', 'fecha_hora', 'date', 'issued_at', 'sale_date']),
            new FieldDefinition('branch_code', 'Código de sucursal', false, 'string', 'Código del establecimiento. Requerido si la empresa tiene más de una sucursal y no se define una sucursal por defecto.', ['sucursal', 'codigo_sucursal', 'cod_sucursal', 'establecimiento', 'local', 'tienda', 'bodega', 'agencia', 'branch', 'branch_code', 'store']),
            new FieldDefinition('product_sku', 'Código de producto (SKU)', true, 'string', 'Código interno del producto.', ['codigo', 'codigo_producto', 'cod_producto', 'sku', 'item', 'codigo_item', 'cod_item', 'referencia', 'product_code', 'product_sku', 'codigo_articulo', 'articulo']),
            new FieldDefinition('product_name', 'Nombre del producto', false, 'string', 'Se usa si el producto no existe y se crea automáticamente.', ['producto', 'descripcion', 'descripcion_producto', 'nombre_producto', 'nombre', 'detalle', 'product', 'product_name', 'description']),
            new FieldDefinition('category', 'Categoría', false, 'string', 'Categoría, línea o familia comercial.', ['categoria', 'linea', 'familia', 'grupo', 'subgrupo', 'category', 'departamento']),
            new FieldDefinition('quantity', 'Cantidad', true, 'number', 'Unidades vendidas. Una cantidad negativa se trata como devolución.', ['cantidad', 'cant', 'unidades', 'qty', 'quantity', 'cantidad_vendida']),
            new FieldDefinition('unit_price', 'Precio unitario', true, 'number', 'Precio unitario ORIGINAL antes de descuento, vigente en la fecha de la venta.', ['precio', 'precio_unitario', 'pvp', 'p_unitario', 'valor_unitario', 'unit_price', 'price', 'precio_venta']),
            new FieldDefinition('discount', 'Descuento', false, 'number', 'Monto del descuento de la línea. Si termina en % se interpreta como porcentaje.', ['descuento', 'dscto', 'desc', 'discount', 'valor_descuento']),
            new FieldDefinition('tax', 'Impuesto (IVA)', false, 'number', 'Monto de impuestos de la línea.', ['iva', 'impuesto', 'impuestos', 'valor_iva', 'tax', 'vat']),
            new FieldDefinition('net_amount', 'Importe neto', false, 'number', 'Valor de la línea sin impuestos. Si falta se calcula como cantidad × precio − descuento.', ['subtotal', 'valor_neto', 'total_linea', 'neto', 'importe', 'net_amount', 'subtotal_linea', 'valor_total']),
            new FieldDefinition('unit_cost', 'Costo unitario', false, 'number', 'Costo histórico de compra en la fecha de la venta.', ['costo', 'costo_unitario', 'cost', 'unit_cost', 'costo_promedio']),
            new FieldDefinition('customer_code', 'Código de cliente', false, 'string', 'Código, RUC o cédula del cliente. Las cédulas y RUC se guardan solo como huella (HMAC), nunca en claro.', ['cliente', 'codigo_cliente', 'cod_cliente', 'ruc_cliente', 'cedula_cliente', 'identificacion', 'customer', 'customer_code', 'ruc_ci']),
            new FieldDefinition('customer_segment', 'Segmento de cliente', false, 'string', 'Tipo o segmento comercial del cliente.', ['segmento', 'tipo_cliente', 'segmento_cliente', 'customer_segment', 'canal_cliente']),
            new FieldDefinition('customer_province', 'Provincia del cliente', false, 'string', 'Provincia (agregación geográfica; no se requiere dirección exacta).', ['provincia', 'provincia_cliente', 'province', 'customer_province']),
            new FieldDefinition('channel', 'Canal de venta', false, 'string', 'Local, mayoreo, web, teléfono, vendedor en campo.', ['canal', 'canal_venta', 'channel', 'medio']),
            new FieldDefinition('currency', 'Moneda', false, 'string', 'Debe coincidir con la moneda de la empresa (USD).', ['moneda', 'currency', 'divisa']),
            new FieldDefinition('line_number', 'Número de línea', false, 'integer', 'Posición de la línea dentro del documento. Si falta se usa el orden de aparición.', ['linea_numero', 'nro_linea', 'num_linea', 'item_no', 'line', 'line_number', 'secuencia_linea']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function products(): array
    {
        return [
            new FieldDefinition('sku', 'Código (SKU)', true, 'string', 'Código interno único del producto.', ['codigo', 'codigo_producto', 'cod_producto', 'sku', 'item', 'referencia', 'product_code', 'product_sku', 'codigo_articulo']),
            new FieldDefinition('name', 'Nombre', true, 'string', 'Nombre o descripción del producto.', ['nombre', 'producto', 'descripcion', 'nombre_producto', 'product', 'product_name', 'description']),
            new FieldDefinition('category', 'Categoría', false, 'string', 'Categoría, línea o familia.', ['categoria', 'linea', 'familia', 'grupo', 'category']),
            new FieldDefinition('unit', 'Unidad de medida', false, 'string', 'unidad, kg, litro, metro, caja...', ['unidad', 'unidad_medida', 'um', 'unit', 'medida']),
            new FieldDefinition('brand', 'Marca', false, 'string', 'Marca comercial.', ['marca', 'brand', 'fabricante']),
            new FieldDefinition('current_price', 'Precio actual', false, 'number', 'Precio de venta vigente (referencia; no reemplaza precios históricos).', ['precio', 'pvp', 'precio_venta', 'precio_actual', 'price', 'current_price']),
            new FieldDefinition('current_cost', 'Costo actual', false, 'number', 'Costo de compra vigente.', ['costo', 'costo_actual', 'costo_unitario', 'cost', 'current_cost']),
            new FieldDefinition('is_active', 'Activo', false, 'boolean', 'Sí/No. Por defecto: sí.', ['activo', 'estado', 'is_active', 'habilitado']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function inventory(): array
    {
        return [
            new FieldDefinition('snapshot_date', 'Fecha de corte', true, 'date', 'Fecha de las existencias (cierre del día).', ['fecha', 'fecha_corte', 'fecha_inventario', 'snapshot_date', 'date']),
            new FieldDefinition('branch_code', 'Código de sucursal', true, 'string', 'Código del establecimiento o bodega.', ['sucursal', 'codigo_sucursal', 'establecimiento', 'bodega', 'local', 'branch', 'branch_code']),
            new FieldDefinition('product_sku', 'Código de producto (SKU)', true, 'string', 'Código interno del producto.', ['codigo', 'codigo_producto', 'sku', 'item', 'product_sku', 'product_code', 'referencia']),
            new FieldDefinition('on_hand', 'Existencia', true, 'number', 'Unidades disponibles físicamente.', ['existencia', 'stock', 'cantidad', 'saldo', 'on_hand', 'disponible', 'existencias']),
            new FieldDefinition('reserved', 'Reservado', false, 'number', 'Unidades comprometidas con clientes.', ['reservado', 'comprometido', 'reserved', 'apartado']),
            new FieldDefinition('in_transit', 'En tránsito', false, 'number', 'Unidades compradas aún no recibidas.', ['en_transito', 'transito', 'in_transit', 'por_recibir', 'pedido']),
            new FieldDefinition('unit_cost', 'Costo unitario', false, 'number', 'Costo unitario del inventario.', ['costo', 'costo_unitario', 'unit_cost', 'costo_promedio']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function leadTimes(): array
    {
        return [
            new FieldDefinition('product_sku', 'Código de producto (SKU)', false, 'string', 'Producto (al menos producto o proveedor).', ['codigo', 'codigo_producto', 'sku', 'product_sku', 'item']),
            new FieldDefinition('supplier_code', 'Código de proveedor', false, 'string', 'Proveedor (al menos producto o proveedor).', ['proveedor', 'codigo_proveedor', 'cod_proveedor', 'supplier', 'supplier_code']),
            new FieldDefinition('branch_code', 'Código de sucursal', false, 'string', 'Opcional: plazo específico para una sucursal.', ['sucursal', 'codigo_sucursal', 'establecimiento', 'branch_code']),
            new FieldDefinition('lead_time_days', 'Plazo (días)', true, 'integer', 'Días entre el pedido y la recepción.', ['plazo', 'plazo_dias', 'dias_entrega', 'tiempo_entrega', 'lead_time', 'lead_time_days', 'dias']),
            new FieldDefinition('lead_time_std_days', 'Variabilidad del plazo (días)', false, 'number', 'Desviación estándar del plazo, si se conoce.', ['desviacion', 'variabilidad', 'lead_time_std_days', 'desviacion_plazo']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function branches(): array
    {
        return [
            new FieldDefinition('code', 'Código', true, 'string', 'Código del establecimiento (p. ej. 001).', ['codigo', 'codigo_sucursal', 'establecimiento', 'code', 'branch_code']),
            new FieldDefinition('name', 'Nombre', true, 'string', 'Nombre de la sucursal.', ['nombre', 'sucursal', 'name', 'branch_name', 'local']),
            new FieldDefinition('province', 'Provincia', false, 'string', 'Provincia.', ['provincia', 'province']),
            new FieldDefinition('canton', 'Cantón', false, 'string', 'Cantón o ciudad.', ['canton', 'ciudad', 'city']),
            new FieldDefinition('commercial_zone', 'Zona comercial', false, 'string', 'Zona o sector comercial.', ['zona', 'zona_comercial', 'sector', 'commercial_zone']),
            new FieldDefinition('address', 'Dirección', false, 'string', 'Dirección del local (dato de la empresa).', ['direccion', 'address']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function suppliers(): array
    {
        return [
            new FieldDefinition('code', 'Código', true, 'string', 'Código del proveedor.', ['codigo', 'codigo_proveedor', 'cod_proveedor', 'code', 'supplier_code']),
            new FieldDefinition('name', 'Nombre', true, 'string', 'Razón social o nombre comercial.', ['nombre', 'proveedor', 'razon_social', 'name', 'supplier_name']),
            new FieldDefinition('tax_id', 'RUC', false, 'string', 'RUC del proveedor (persona jurídica).', ['ruc', 'tax_id', 'identificacion']),
            new FieldDefinition('country', 'País', false, 'string', 'Código ISO de 2 letras (EC, CN, US...).', ['pais', 'country']),
            new FieldDefinition('default_lead_time_days', 'Plazo habitual (días)', false, 'integer', 'Plazo de entrega habitual.', ['plazo', 'plazo_dias', 'default_lead_time_days', 'dias_entrega']),
        ];
    }

    /** @return list<FieldDefinition> */
    private function promotions(): array
    {
        return [
            new FieldDefinition('name', 'Nombre', true, 'string', 'Nombre de la promoción.', ['nombre', 'promocion', 'name', 'campana']),
            new FieldDefinition('promo_type', 'Tipo', false, 'string', 'percentage, fixed_amount, bundle u other.', ['tipo', 'promo_type', 'tipo_promocion']),
            new FieldDefinition('discount_pct', 'Descuento', false, 'number', 'Fracción (0.15) o porcentaje (15 o 15%).', ['descuento', 'discount_pct', 'porcentaje', 'dscto']),
            new FieldDefinition('starts_on', 'Desde', true, 'date', 'Fecha de inicio.', ['desde', 'inicio', 'fecha_inicio', 'starts_on']),
            new FieldDefinition('ends_on', 'Hasta', true, 'date', 'Fecha de fin (incluida).', ['hasta', 'fin', 'fecha_fin', 'ends_on']),
            new FieldDefinition('branch_code', 'Sucursal', false, 'string', 'Vacío = todas las sucursales.', ['sucursal', 'branch_code', 'establecimiento']),
            new FieldDefinition('product_sku', 'Producto', false, 'string', 'Producto específico (opcional).', ['codigo', 'sku', 'product_sku', 'codigo_producto']),
            new FieldDefinition('category', 'Categoría', false, 'string', 'Categoría completa (opcional).', ['categoria', 'category', 'linea', 'familia']),
        ];
    }
}
