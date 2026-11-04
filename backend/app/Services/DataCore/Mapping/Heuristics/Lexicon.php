<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\Imports\Fields\FieldDefinition;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;

/**
 * Léxico del Auto Mapper: para cada campo canónico del importador, los patrones de nombre (la clave y los sinónimos del ImportFieldRegistry —el mismo
 * vocabulario del asistente de importación— más variantes de ERP en español), los tokens que lo desmienten y la tabla de origen esperada.
 */
final class Lexicon
{
    /** Palabras demasiado genéricas para identificar un campo por sí solas: una coincidencia exacta vale menos y con más palabras casi nada. */
    public const WEAK = ['codigo', 'numero', 'fecha', 'nombre', 'tipo', 'estado', 'linea', 'grupo', 'item', 'tienda', 'local', 'date', 'line', 'desc', 'documento', 'descripcion', 'detalle',
        'referencia', 'pedido', 'dias', 'id', 'valor', 'total', 'inicio', 'fin', 'desde', 'hasta', 'fuente', 'origen', 'alcance', 'clase', 'situacion'];

    /** Conceptos del negocio presentes en los nombres: el campo de un concepto desconfía de un nombre que trae solo otro concepto. */
    public const CONCEPTS = [
        'customer' => ['cliente', 'comprador', 'customer'],
        'branch' => ['sucursal', 'establecimiento', 'tienda', 'agencia', 'bodega', 'almacen'],
        'product' => ['producto', 'articulo', 'sku', 'item', 'mercaderia', 'material'],
        'supplier' => ['proveedor'],
        'document' => ['factura', 'documento', 'comprobante', 'pedido', 'orden', 'venta', 'compra'],
        'category' => ['categoria', 'familia', 'grupo'],
    ];

    private const SALES_HEADER = ['document_number', 'document_type', 'status', 'issued_at', 'branch_code', 'customer_code', 'customer_segment', 'customer_province', 'channel', 'currency', 'source_recorded_at'];

    /** Campos que identifican registros: el nombre debe traer un token de código o identificador y la muestra no puede tener forma de dato personal. */
    private const IDENTITY = ['sales.document_number', 'sales.product_sku', 'sales.branch_code', 'sales.customer_code', 'products.sku', 'inventory.product_sku', 'inventory.branch_code', 'suppliers.code',
        'lead_times.product_sku', 'lead_times.supplier_code', 'lead_times.branch_code', 'branches.code', 'suppliers.tax_id'];

    private const REQUIRES_CODE = 'codigo id numero referencia';

    private const CATEGORICAL = ['document_type', 'status', 'channel', 'currency', 'customer_segment', 'unit', 'country', 'promo_type', 'commitment_type', 'category'];

    /** @var array<string, array{extra?: string, penalize?: string, own?: string, requires?: string}> clave «entidad.campo» */
    private const EXTRA = [
        'sales.document_number' => ['extra' => 'folio|numero factura|numero comprobante|numero documento|numero venta|factura|comprobante|secuencial', 'penalize' => 'linea detalle item autorizacion clave acceso id estado tipo fecha', 'own' => 'document', 'requires' => 'numero folio secuencial codigo'],
        'sales.document_type' => ['extra' => 'tipo documento|tipo comprobante|tipo factura|tipo venta|clase documento|tipo transaccion', 'penalize' => 'pago cliente producto precio', 'own' => 'document'],
        'sales.status' => ['extra' => 'estado documento|estado factura|estado venta|situacion|anulada|anulado|estado comprobante', 'penalize' => 'pago cobro entrega envio despacho credito cliente producto', 'own' => 'document'],
        'sales.issued_at' => ['extra' => 'fecha emision|fecha venta|fecha factura|fecha documento|fecha hora|emision|fecha comprobante', 'penalize' => 'vencimiento entrega modificacion actualizacion registro anulacion creacion pago nacimiento cancelacion caducidad expiracion despacho autorizacion', 'own' => 'document'],
        'sales.branch_code' => ['extra' => 'sucursal|codigo sucursal|establecimiento|punto emision|bodega|agencia|tienda', 'penalize' => 'nombre descripcion direccion', 'own' => 'branch', 'requires' => self::REQUIRES_CODE],
        'sales.product_sku' => ['extra' => 'codigo producto|codigo articulo|sku|item|referencia|articulo|producto', 'penalize' => 'barras categoria nombre descripcion tipo unidad', 'own' => 'product', 'requires' => 'codigo id sku referencia item numero'],
        'sales.product_name' => ['extra' => 'descripcion producto|nombre producto|descripcion articulo|nombre articulo|descripcion|producto|detalle producto', 'penalize' => 'codigo categoria grupo familia tipo unidad', 'own' => 'product'],
        'sales.category' => ['extra' => 'categoria|familia|grupo|linea producto|departamento', 'penalize' => 'nombre descripcion', 'own' => 'category'],
        'sales.quantity' => ['extra' => 'cantidad|cantidad vendida|unidades vendidas|cantidad facturada', 'penalize' => 'entregada pendiente minimo maximo reservado devuelta stock existencia inventario precio costo valor total'],
        'sales.unit_price' => ['extra' => 'precio unitario|precio|precio venta|valor unitario|precio unidad', 'penalize' => 'costo compra total lista minimo maximo promedio descuento neto subtotal'],
        'sales.discount' => ['extra' => 'descuento|valor descuento', 'penalize' => 'precio total subtotal porcentaje'],
        'sales.tax' => ['extra' => 'iva|impuesto|valor iva', 'penalize' => 'base porcentaje tarifa codigo retencion'],
        'sales.net_amount' => ['extra' => 'subtotal|valor neto|total linea|neto|importe|valor total|subtotal linea', 'penalize' => 'iva impuesto descuento costo precio'],
        'sales.unit_cost' => ['extra' => 'costo unitario|costo|costo promedio|costo venta', 'penalize' => 'total'],
        'sales.customer_code' => ['extra' => 'cliente|codigo cliente|id cliente|identificacion cliente|ruc cliente|cedula cliente', 'penalize' => 'nombre razon direccion telefono correo segmento tipo provincia', 'own' => 'customer', 'requires' => 'codigo id ruc cedula numero identificacion nit dni'],
        'sales.customer_segment' => ['extra' => 'segmento|tipo cliente|segmento cliente', 'own' => 'customer'],
        'sales.customer_province' => ['extra' => 'provincia|provincia cliente', 'own' => 'customer'],
        'sales.channel' => ['extra' => 'canal|canal venta'],
        'sales.currency' => ['extra' => 'moneda|divisa'],
        'sales.line_number' => ['extra' => 'linea|numero linea|item numero|secuencia|renglon|numero item', 'penalize' => 'producto codigo'],
        'sales.source_recorded_at' => ['extra' => 'modificacion|fecha modificacion|actualizacion|fecha actualizacion|fecha ultima modificacion|fecha registro sistema|modificado|ultima modificacion', 'penalize' => 'creacion emision venta factura'],
        'products.sku' => ['extra' => 'codigo|codigo producto|codigo articulo|sku|item|referencia|articulo', 'penalize' => 'barras categoria nombre descripcion cliente proveedor', 'own' => 'product', 'requires' => 'codigo id sku referencia item numero'],
        'products.name' => ['extra' => 'nombre|nombre producto|descripcion|descripcion producto|producto|articulo', 'penalize' => 'codigo categoria grupo familia tipo unidad cliente proveedor', 'own' => 'product'],
        'products.category' => ['extra' => 'categoria|familia|grupo|linea|departamento', 'own' => 'category'],
        'products.unit' => ['extra' => 'unidad|unidad medida', 'penalize' => 'precio costo'],
        'products.brand' => ['extra' => 'marca|fabricante'],
        'products.current_price' => ['extra' => 'precio|precio venta|precio actual|precio publico', 'penalize' => 'costo compra minimo maximo anterior lista'],
        'products.current_cost' => ['extra' => 'costo|costo actual|costo unitario|costo promedio', 'penalize' => 'precio venta'],
        'products.is_active' => ['extra' => 'activo|habilitado|estado', 'penalize' => 'fecha'],
        'inventory.snapshot_date' => ['extra' => 'fecha corte|fecha inventario|fecha|fecha saldo|fecha cierre', 'penalize' => 'vencimiento modificacion registro creacion ingreso'],
        'inventory.branch_code' => ['extra' => 'sucursal|codigo sucursal|bodega|establecimiento|almacen', 'penalize' => 'nombre descripcion', 'own' => 'branch', 'requires' => self::REQUIRES_CODE],
        'inventory.product_sku' => ['extra' => 'codigo producto|sku|item|referencia|articulo|codigo', 'penalize' => 'barras categoria nombre descripcion', 'own' => 'product', 'requires' => 'codigo id sku referencia item numero'],
        'inventory.on_hand' => ['extra' => 'existencia|stock|saldo|disponible|cantidad|cantidad existente|stock actual', 'penalize' => 'minimo maximo reservado transito costo seguridad'],
        'inventory.reserved' => ['extra' => 'reservado|comprometido|apartado|cantidad reservada'],
        'inventory.in_transit' => ['extra' => 'transito|en transito|por recibir|cantidad transito'],
        'inventory.unit_cost' => ['extra' => 'costo|costo unitario|costo promedio', 'penalize' => 'total'],
        'suppliers.code' => ['extra' => 'codigo|codigo proveedor|proveedor|id proveedor', 'penalize' => 'nombre razon', 'own' => 'supplier', 'requires' => 'codigo id ruc numero'],
        'suppliers.name' => ['extra' => 'nombre|razon social|nombre proveedor|proveedor|razon', 'penalize' => 'codigo', 'own' => 'supplier'],
        'suppliers.tax_id' => ['extra' => 'ruc|identificacion|ruc proveedor|nit'],
        'suppliers.country' => ['extra' => 'pais'],
        'suppliers.default_lead_time_days' => ['extra' => 'plazo|plazo dias|dias entrega|tiempo entrega|plazo habitual'],
        'lead_times.product_sku' => ['extra' => 'codigo producto|sku|item|articulo|codigo', 'own' => 'product', 'requires' => 'codigo id sku referencia item numero'],
        'lead_times.supplier_code' => ['extra' => 'proveedor|codigo proveedor|id proveedor', 'own' => 'supplier', 'requires' => 'codigo id ruc numero'],
        'lead_times.branch_code' => ['extra' => 'sucursal|codigo sucursal|bodega', 'own' => 'branch', 'requires' => self::REQUIRES_CODE],
        'lead_times.lead_time_days' => ['extra' => 'plazo|plazo dias|dias entrega|tiempo entrega|dias', 'penalize' => 'desviacion variabilidad'],
        'lead_times.lead_time_std_days' => ['extra' => 'desviacion|variabilidad|desviacion plazo'],
        'branches.code' => ['extra' => 'codigo|codigo sucursal|establecimiento|sucursal|id sucursal', 'penalize' => 'nombre descripcion', 'own' => 'branch', 'requires' => self::REQUIRES_CODE],
        'branches.name' => ['extra' => 'nombre|nombre sucursal|descripcion|sucursal|local', 'penalize' => 'codigo', 'own' => 'branch'],
        'branches.province' => ['extra' => 'provincia'],
        'branches.canton' => ['extra' => 'canton|ciudad'],
        'branches.commercial_zone' => ['extra' => 'zona|zona comercial|sector'],
        'branches.address' => ['extra' => 'direccion|domicilio'],
    ];

    /** Entidad → tabla de origen esperada de cada campo, salvo ventas, donde cabecera y línea son tablas distintas. */
    private const HOME = ['sales' => 'sales_line', 'products' => 'product', 'inventory' => 'inventory', 'suppliers' => 'supplier', 'lead_times' => 'lead_time', 'branches' => 'branch'];

    /** @var array<string, array<string, FieldRules>> */
    private array $cache = [];

    public function __construct(private readonly ImportFieldRegistry $registry = new ImportFieldRegistry) {}

    /** @return array<string, FieldRules> campo → reglas, en el orden del registro del importador */
    public function rules(ImportEntity $entity): array
    {
        return $this->cache[$entity->value] ??= $this->build($entity);
    }

    /** @return array<string, FieldRules> */
    private function build(ImportEntity $entity): array
    {
        $rules = [];
        foreach ($this->registry->fields($entity) as $field) {
            $rules[$field->key] = $this->rulesFor($entity, $field);
        }

        return $rules;
    }

    private function rulesFor(ImportEntity $entity, FieldDefinition $field): FieldRules
    {
        $extra = self::EXTRA[$entity->value.'.'.$field->key] ?? [];
        $raw = array_merge([$field->key], $field->synonyms, isset($extra['extra']) ? explode('|', $extra['extra']) : []);
        $patterns = [];
        foreach ($raw as $text) {
            $tokens = array_values(array_unique(NameTokens::of($text)));
            if ($tokens !== []) {
                $patterns[implode(' ', $tokens)] = $tokens;
            }
        }
        $penalize = isset($extra['penalize']) ? array_values(array_unique(NameTokens::of(str_replace(' ', '_', $extra['penalize'])))) : [];
        $own = isset($extra['own']) ? array_values(array_filter(array_map('trim', explode(' ', $extra['own'])))) : [];
        $requires = isset($extra['requires']) ? array_values(array_unique(NameTokens::of(str_replace(' ', '_', $extra['requires'])))) : [];
        $home = $entity === ImportEntity::Sales && in_array($field->key, self::SALES_HEADER, true) ? 'sales_header' : (self::HOME[$entity->value] ?? 'other');

        return new FieldRules($field->key, $field->type, $field->required, array_values($patterns), $penalize, $own, $home, in_array($field->key, self::CATEGORICAL, true), $requires, in_array($entity->value.'.'.$field->key, self::IDENTITY, true));
    }
}
