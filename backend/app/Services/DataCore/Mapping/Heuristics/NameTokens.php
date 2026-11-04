<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use Illuminate\Support\Str;

/**
 * Normaliza el nombre de una columna o tabla en una lista de tokens comparables (determinista, sin LLM):
 * camelCase y PascalCase → palabras; sin tildes ni mayúsculas; separadores no alfanuméricos; artículos y preposiciones fuera; plurales simples;
 * abreviaturas habituales de los ERP en español («cant» → cantidad, «nro» → numero, «fec» → fecha…); y, para nombres pegados («codproducto»,
 * «fechaemision»), segmentación con el vocabulario conocido (solo si cubre el token completo).
 * Parte de ColumnMatcher::normalize del asistente de importación (mismos separadores y tildes).
 */
final class NameTokens
{
    /** Abreviatura (ya sin tildes) → palabras completas. */
    private const ABBREVIATIONS = [
        'cant' => 'cantidad', 'cantd' => 'cantidad', 'ctd' => 'cantidad', 'qty' => 'cantidad', 'quantity' => 'cantidad', 'cantidades' => 'cantidad',
        'und' => 'unidad', 'unid' => 'unidad', 'uds' => 'unidad', 'um' => 'unidad medida', 'uom' => 'unidad medida', 'unit' => 'unitario',
        'cod' => 'codigo', 'cdg' => 'codigo', 'cve' => 'codigo', 'clave' => 'codigo', 'code' => 'codigo', 'id' => 'id',
        'num' => 'numero', 'nro' => 'numero', 'numer' => 'numero', 'no' => 'numero', 'number' => 'numero',
        'fec' => 'fecha', 'fch' => 'fecha', 'fcha' => 'fecha', 'dt' => 'fecha', 'date' => 'fecha', 'datetime' => 'fecha hora', 'timestamp' => 'fecha hora',
        'prec' => 'precio', 'price' => 'precio', 'pvp' => 'precio venta', 'pu' => 'precio unitario', 'punit' => 'precio unitario',
        'dscto' => 'descuento', 'dto' => 'descuento', 'dcto' => 'descuento', 'discount' => 'descuento', 'desct' => 'descuento',
        'imp' => 'impuesto', 'impto' => 'impuesto', 'tax' => 'impuesto', 'vat' => 'iva',
        'tot' => 'total', 'val' => 'valor', 'vlr' => 'valor', 'vr' => 'valor', 'sub' => 'sub', 'neto' => 'neto', 'net' => 'neto',
        'cli' => 'cliente', 'clt' => 'cliente', 'customer' => 'cliente', 'cust' => 'cliente', 'client' => 'cliente',
        'prod' => 'producto', 'prd' => 'producto', 'product' => 'producto', 'art' => 'articulo', 'articulos' => 'articulo', 'sku' => 'sku',
        'suc' => 'sucursal', 'sucur' => 'sucursal', 'branch' => 'sucursal', 'store' => 'tienda', 'estab' => 'establecimiento', 'bod' => 'bodega', 'alm' => 'almacen', 'warehouse' => 'bodega',
        'exist' => 'existencia', 'existencias' => 'existencia', 'stk' => 'stock', 'saldo' => 'saldo', 'disp' => 'disponible',
        'cost' => 'costo', 'cto' => 'costo', 'costos' => 'costo',
        'cat' => 'categoria', 'categ' => 'categoria', 'category' => 'categoria', 'fam' => 'familia', 'grp' => 'grupo', 'group' => 'grupo',
        'est' => 'estado', 'status' => 'estado', 'sit' => 'situacion', 'tip' => 'tipo', 'type' => 'tipo', 'tp' => 'tipo',
        'doc' => 'documento', 'docto' => 'documento', 'fac' => 'factura', 'fact' => 'factura', 'fra' => 'factura', 'invoice' => 'factura', 'comp' => 'comprobante',
        'cab' => 'cabecera', 'encab' => 'cabecera', 'header' => 'cabecera', 'det' => 'detalle', 'detail' => 'detalle', 'lin' => 'linea', 'line' => 'linea', 'items' => 'item',
        'emi' => 'emision', 'emis' => 'emision', 'ped' => 'pedido', 'order' => 'pedido', 'prov' => 'proveedor', 'supplier' => 'proveedor', 'vendor' => 'proveedor',
        'desc' => 'desc', 'descr' => 'descripcion', 'descripc' => 'descripcion', 'description' => 'descripcion', 'nom' => 'nombre', 'nomb' => 'nombre', 'name' => 'nombre',
        'mod' => 'modificacion', 'modif' => 'modificacion', 'upd' => 'modificacion', 'updated' => 'modificacion', 'actualiz' => 'actualizacion',
        'reg' => 'registro', 'creac' => 'creacion', 'created' => 'creacion', 'venc' => 'vencimiento', 'cancel' => 'cancelacion', 'anul' => 'anulacion',
        'ruc' => 'ruc', 'ci' => 'cedula', 'dni' => 'cedula', 'nit' => 'cedula', 'ced' => 'cedula', 'cedul' => 'cedula', 'tel' => 'telefono', 'telf' => 'telefono', 'tlf' => 'telefono', 'tlfn' => 'telefono', 'tlfno' => 'telefono',
        'cel' => 'celular', 'celu' => 'celular', 'wsp' => 'whatsapp', 'wpp' => 'whatsapp', 'mail' => 'correo', 'email' => 'correo', 'fnac' => 'fecha nacimiento', 'nac' => 'nacimiento', 'tarj' => 'tarjeta',
        'obs' => 'observacion', 'observ' => 'observacion', 'pwd' => 'password', 'pass' => 'password',
        'dir' => 'direccion', 'address' => 'direccion', 'ciu' => 'ciudad', 'city' => 'ciudad', 'pais' => 'pais', 'country' => 'pais',
        'mon' => 'moneda', 'currency' => 'moneda', 'divisa' => 'moneda', 'canal' => 'canal', 'channel' => 'canal', 'seg' => 'segmento',
        'plazo' => 'plazo', 'lead' => 'plazo', 'time' => 'tiempo', 'dias' => 'dias', 'days' => 'dias', 'dia' => 'dias',
        'act' => 'activo', 'active' => 'activo', 'enabled' => 'activo', 'habilitado' => 'activo', 'vigente' => 'activo',
        'res' => 'reservado', 'reserved' => 'reservado', 'transito' => 'transito', 'transit' => 'transito',
        'ult' => 'ultimo', 'min' => 'minimo', 'max' => 'maximo', 'prom' => 'promedio', 'avg' => 'promedio', 'pct' => 'porcentaje', 'porc' => 'porcentaje',
    ];

    /** Palabras sin valor para comparar. */
    private const STOP = ['de', 'del', 'la', 'el', 'los', 'las', 'en', 'por', 'para', 'a', 'y', 'e', 'o', 'the', 'of', 'con', 'at'];

    /** Excepciones al corte del plural. */
    private const KEEP_S = ['iva', 'ruc', 'gas', 'mas', 'pais', 'dias', 'tres', 'lunes', 'stock', 'status', 'canal', 'lis', 'analisis', 'bus'];

    /** @var array<string, true>|null vocabulario conocido para segmentar nombres pegados */
    private static ?array $vocabulary = null;

    /**
     * Tokens normalizados y expandidos del nombre.
     *
     * @return list<string>
     */
    public static function of(string $name): array
    {
        $tokens = [];
        foreach (self::split($name) as $token) {
            foreach (self::expand($token) as $expanded) {
                $tokens[] = $expanded;
            }
        }

        return $tokens;
    }

    /**
     * Tokens del nombre sin expandir abreviaturas (plural simple y artículos sí).
     *
     * @return list<string>
     */
    public static function split(string $name): array
    {
        $spaced = preg_replace(['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'], '_', Str::ascii($name)) ?? $name;
        $lower = strtolower($spaced);
        $parts = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $part) {
            // Separa los dígitos pegados: «precio2» → precio, 2.
            foreach (preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $piece = self::singular($piece);
                if ($piece === '' || in_array($piece, self::STOP, true)) {
                    continue;
                }
                foreach (self::segment($piece) as $word) {
                    $out[] = $word;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function expand(string $token): array
    {
        if (isset(self::ABBREVIATIONS[$token])) {
            return array_values(array_filter(explode(' ', self::ABBREVIATIONS[$token])));
        }

        return [$token];
    }

    /**
     * Plural simple. Prefiere la forma singular que está en el vocabulario («nombres» → nombre, «direcciones» → direccion, «detalles» → detalle);
     * una palabra desconocida pierde solo la «s». El mismo tratamiento se aplica a los sinónimos del léxico, así que la coincidencia es coherente.
     */
    private static function singular(string $token): string
    {
        if (strlen($token) <= 3 || in_array($token, self::KEEP_S, true) || ! str_ends_with($token, 's') || str_ends_with($token, 'ss')) {
            return $token;
        }
        $vocabulary = self::vocabulary();
        $dropS = substr($token, 0, -1);
        if (isset($vocabulary[$dropS]) || ! str_ends_with($token, 'es')) {
            return $dropS;
        }
        $dropEs = substr($token, 0, -2);

        return isset($vocabulary[$dropEs]) ? $dropEs : $dropS;
    }

    /**
     * Segmenta un nombre pegado («codproducto») en palabras del vocabulario conocido. Solo acepta una segmentación que cubra el token completo con
     * segmentos de 3+ letras; si el token ya es una palabra conocida o no se puede cubrir, queda tal cual.
     *
     * @return list<string>
     */
    private static function segment(string $token): array
    {
        $vocabulary = self::vocabulary();
        if (strlen($token) < 6 || isset($vocabulary[$token]) || preg_match('/^[0-9]+$/', $token) === 1) {
            return [$token];
        }
        $n = strlen($token);
        $best = array_fill(0, $n + 1, null);
        $best[0] = [];
        for ($i = 0; $i < $n; $i++) {
            if ($best[$i] === null) {
                continue;
            }
            for ($j = $i + 3; $j <= $n; $j++) {
                $piece = substr($token, $i, $j - $i);
                if (isset($vocabulary[$piece]) && ($best[$j] === null || count($best[$i]) + 1 < count($best[$j]))) {
                    $best[$j] = [...$best[$i], $piece];
                }
            }
        }

        return $best[$n] !== null && count($best[$n]) >= 2 ? $best[$n] : [$token];
    }

    /** @return array<string, true> */
    private static function vocabulary(): array
    {
        if (self::$vocabulary === null) {
            $words = [];
            foreach (self::ABBREVIATIONS as $abbreviation => $full) {
                $words[$abbreviation] = true;
                foreach (explode(' ', $full) as $word) {
                    $words[$word] = true;
                }
            }
            foreach (['factura', 'venta', 'ventas', 'documento', 'comprobante', 'producto', 'articulo', 'emision', 'cliente', 'proveedor', 'sucursal', 'bodega', 'precio', 'unitario', 'cantidad',
                'codigo', 'numero', 'fecha', 'hora', 'nombre', 'descripcion', 'categoria', 'familia', 'grupo', 'costo', 'valor', 'total', 'impuesto', 'iva', 'descuento', 'existencia', 'stock', 'saldo',
                'inventario', 'pedido', 'entrega', 'estado', 'tipo', 'canal', 'moneda', 'unidad', 'medida', 'registro', 'modificacion', 'detalle', 'linea', 'item', 'cabecera', 'plazo', 'dias', 'marca',
                'activo', 'provincia', 'ciudad', 'pais', 'segmento', 'vencimiento', 'observacion', 'comentario', 'nota', 'tarjeta', 'edad', 'nacimiento', 'password', 'whatsapp', 'identificacion', 'lote', 'ruc', 'cedula', 'telefono', 'correo', 'direccion', 'establecimiento', 'tienda', 'sku', 'anulado', 'anulada',
                'proveedor', 'vendedor', 'comprador', 'persona', 'contacto', 'razon', 'social', 'apellido', 'celular', 'barras', 'referencia', 'compra', 'renglon', 'partida', 'orden', 'condicion', 'pago', 'autorizacion',
                'perecible', 'caducidad', 'util', 'vida', 'capacidad', 'almacen', 'zona', 'comercial', 'canton', 'reservado', 'comprometido', 'transito', 'minimo', 'maximo', 'ultimo', 'promedio', 'porcentaje',
                'base', 'tarifa', 'retencion', 'sistema', 'corte', 'cierre', 'saldo', 'disponible', 'unitario', 'neto', 'importe', 'subtotal', 'sub', 'hora', 'desde', 'hasta', 'inicio', 'fin'] as $word) {
                $words[$word] = true;
            }
            self::$vocabulary = $words;
        }

        return self::$vocabulary;
    }
}
