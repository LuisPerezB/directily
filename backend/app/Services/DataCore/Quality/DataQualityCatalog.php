<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Services\Imports\Support\Issue;
use App\Services\Sales\SalesLineNormalizer;

/**
 * Catálogo ÚNICO código → clase de calidad de la ingestión (ADR-V25-05 y ADR-V25-24; W2-A).
 *
 * Punto de partida: `Issue::DQ_CLASS_BY_CODE` (W1-A1: tipo/estado desconocido = REVIEW_REQUIRED), que sigue siendo la fuente de esos
 * dos códigos. Cinco clases:
 *  - BLOCKER: problema estructural que impide la carga completa; bloquea siempre (no hay `skip_invalid_rows` que lo salte).
 *  - REVIEW_REQUIRED: valor no reconocido que exige una decisión humana (mapeo de valores, alta de sucursal, conversión de unidad).
 *  - QUARANTINED: dato inválido que no se puede corregir con seguridad (fecha inválida, cantidad imposible, producto ausente…).
 *  - WARNING: anomalía que no impide usar el dato (se informa).
 *  - AUTO_FIX_SAFE: normalización segura que el importador ya aplica (se registra agregada, nunca una fila por celda).
 *
 * La clase depende del código y, para los códigos que el importador emite tanto como error como advertencia (`required`,
 * `invalid_number`, `unknown_product`…), de la severidad: una ADVERTENCIA nunca es REVIEW_REQUIRED, QUARANTINED ni BLOCKER (no
 * detiene el registro), así que es WARNING. Un código que no está en el catálogo se clasifica por severidad (error → QUARANTINED,
 * el resto → WARNING) y la prueba `DataQualityCatalogTest` falla si el código emitido por el importador no está documentado aquí.
 */
final class DataQualityCatalog
{
    public const BLOCKER = 'BLOCKER';

    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    public const QUARANTINED = 'QUARANTINED';

    public const WARNING = 'WARNING';

    public const AUTO_FIX_SAFE = 'AUTO_FIX_SAFE';

    /** De mayor a menor prioridad (orden de retención bajo el tope de filas y de presentación). */
    public const CLASSES = [self::BLOCKER, self::REVIEW_REQUIRED, self::QUARANTINED, self::WARNING, self::AUTO_FIX_SAFE];

    /** Clases cuyo documento se retiene en cuarentena (con `skip_invalid_rows`) en lugar de descartarse. */
    public const QUARANTINE_CLASSES = [self::REVIEW_REQUIRED, self::QUARANTINED];

    /** Versión del código de reglas (parte de `rules_version`): súbala al cambiar una regla o su clase. */
    public const CODE_VERSION = 'dq-ingestion@2026.03.1';

    /** @var array<string, array{0: string, 1: string}>|null */
    private static ?array $catalog = null;

    /**
     * código => [clase, justificación]. La justificación se documenta en docs/data/DATA_QUALITY_ENGINE.md §2.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $blocker = self::BLOCKER;
        $review = self::REVIEW_REQUIRED;
        $quarantined = self::QUARANTINED;
        $warning = self::WARNING;
        $autoFix = self::AUTO_FIX_SAFE;

        $catalog = [
            // ── BLOCKER: estructurales ──
            'bronze_capture_failed' => [$blocker, 'El original no pudo guardarse en Bronze ANTES de normalizar: sin él no hay linaje ni reprocesamiento; la carga se detiene.'],
            'source_columns_missing' => [$blocker, 'El mapeo aprobado referencia columnas que la consulta ya no devuelve: ninguna fila puede leerse completa.'],
            'empty_file' => [$blocker, 'El archivo no tiene encabezados o está vacío: no hay nada que cargar.'],
            'unreadable_file' => [$blocker, 'El archivo no se pudo leer (formato corrupto o no soportado).'],

            // ── REVIEW_REQUIRED: valores no reconocidos que requieren una decisión ──
            'unknown_document_type' => [Issue::DQ_CLASS_BY_CODE['unknown_document_type'], 'Un tipo de documento fuera del vocabulario (cotización, proforma, guía…) no se cuenta como venta; se mapea por fuente (value_maps) o se excluye.'],
            'unknown_document_status' => [Issue::DQ_CLASS_BY_CODE['unknown_document_status'], 'Un estado fuera del vocabulario (rechazada, reversada, no autorizada…) no se supone completado; se mapea por fuente (value_maps) o se excluye.'],
            'unknown_branch' => [$review, 'La sucursal no existe y la empresa no autoriza crearla: se da de alta, se declara una sucursal por defecto o se excluye.'],
            'unknown_category' => [$review, 'La categoría no existe y no se autoriza crearla: se da de alta o se excluye.'],
            'unit_conversion_missing' => [$review, 'La unidad no se puede convertir a la unidad base del producto: falta declarar la conversión.'],
            'conversion_conflict' => [$review, 'La conversión de unidades declarada contradice otra vigente: un humano decide cuál rige.'],

            // ── QUARANTINED: inválido y no corregible con seguridad ──
            'required' => [$quarantined, 'Falta un dato obligatorio; no se inventa (fecha, producto, cantidad, precio o número de documento).'],
            'invalid_number' => [$quarantined, 'Número no interpretable o imposible (cantidad/precio/descuento): corregirlo cambiaría el importe.'],
            'invalid_date' => [$quarantined, 'Fecha no interpretable: adivinarla movería la venta de día.'],
            'future_date' => [$quarantined, 'Fecha de emisión posterior a mañana: no es una venta ya ocurrida.'],
            'negative_price' => [$quarantined, 'Precio unitario negativo: un dato imposible que no se corrige con seguridad.'],
            'invalid_value' => [$quarantined, 'Valor fuera del dominio permitido del campo.'],
            'duplicate_in_file' => [$quarantined, 'Como error: número de línea repetido en el documento (el contenido no se puede conciliar). Como advertencia: fila idéntica repetida, que se omite (ver WARNING).'],
            'document_conflict' => [$quarantined, 'El mismo documento trae cabeceras distintas (fecha, sucursal, estado o cliente): no hay una versión única.'],
            'currency_mismatch' => [$quarantined, 'La moneda difiere de la de la empresa: no hay conversión declarada.'],
            'unknown_product' => [$quarantined, 'Como error: producto ausente del catálogo y sin autorización para crearlo. Como advertencia: se creará.'],
            'capacity_unit_required' => [$quarantined, 'La capacidad exige unidad y no la trae.'],
            'negative_lot_quantity' => [$quarantined, 'La cantidad de un lote es negativa: un lote negativo no existe y corregir el signo cambiaría el stock (W5-D).'],
            'expiry_implausible' => [$quarantined, 'El vencimiento de un lote está a más de 15 años del corte: se presume un error de captura y adivinarlo movería pérdidas y compras (W5-D).'],

            // ── WARNING: anomalías utilizables ──
            'negative_quantity' => [$warning, 'Cantidad negativa: se registra como devolución.'],
            'zero_quantity' => [$warning, 'Cantidad cero: la línea se omite.'],
            'net_amount_mismatch' => [$warning, 'El importe neto del archivo difiere de cantidad × precio − descuento; se conserva el del archivo.'],
            'negative_stock' => [$warning, 'Existencia negativa: se interpreta como quiebre de stock.'],
            'unknown_supplier' => [$warning, 'El proveedor no existe y se creará.'],
            'unknown_status' => [$warning, 'Estado no reconocido en una entidad que tolera un valor por defecto.'],
            'unit_converted' => [$warning, 'Se aplicó una conversión de unidades declarada.'],
            'status_quantity_mismatch' => [$warning, 'El estado del compromiso y las cantidades no concuerdan.'],
            'status_overridden' => [$warning, 'El estado declarado se reemplazó por el que imponen las fechas.'],
            'scope_ref_ignored' => [$warning, 'La referencia de alcance se ignoró porque el alcance no la admite.'],
            'known_before_issued' => [$warning, 'La fecha de conocimiento es anterior a la emisión.'],
            'known_at_unknown' => [$warning, 'No se conoce desde cuándo se sabía el dato (queda sin fecha de disponibilidad).'],
            'known_after_start' => [$warning, 'El dato se conoció después del inicio del evento.'],
            'invalid_tax_id' => [$warning, 'La identificación tributaria no es válida; no se usa para identificar al cliente.'],
            'fulfilled_exceeds_quantity' => [$warning, 'La cantidad cumplida excede la comprometida.'],
            'cancelled_without_date' => [$warning, 'Compromiso cancelado sin fecha de cancelación.'],
            'cancelled_before_issued' => [$warning, 'Compromiso cancelado antes de emitirse.'],
            'document_number_shared_with_unknown_type' => [$warning, 'Un documento reconocido comparte número con filas de un tipo desconocido (numeración por tipo o serie): se escribe y se avisa (W1, hallazgo 7).'],
            'stale_document_skipped' => [$warning, 'Llegó una versión ANTERIOR (por fecha de registro en la fuente) a la ya guardada: no se aplica (ADR-V25-23).'],
            'stale_skipped' => [$warning, 'Alias del anterior en estadísticas: documentos descartados por versión anterior.'],
            'updated_without_source_order' => [$warning, 'Se actualizó un documento sin poder comparar el orden de versiones (falta la fecha de registro en la fuente): gana la última carga.'],
            'unchanged_mode_mismatch' => [$warning, 'Documentos sin cambios cuyo modo de ingestión almacenado difiere del declarado: declarar otro modo no reescribe.'],
            'future_recorded_at' => [$warning, 'La fecha de registro en la fuente es posterior a ahora + tolerancia: se deja desconocida y no ordena versiones.'],
            'recorded_at_unreadable' => [$warning, 'La fecha de registro en la fuente es ilegible: se deja desconocida y no ordena versiones.'],
            'missing_dates' => [$warning, 'Días sin ventas dentro del rango cargado (feriados, cierres o datos faltantes).'],
            'existing_document_changed' => [$warning, 'Documentos que ya existían con contenido distinto: se actualizarán y se archivará la versión anterior.'],
            'existing_commitment_changed' => [$warning, 'Compromisos que ya existían con contenido distinto: se actualizarán.'],
            'voided_document' => [$warning, 'Documentos anulados: se guardan para trazabilidad y se excluyen de la demanda.'],
            'empty_document' => [$warning, 'Documento sin líneas válidas.'],
            'documents_quarantined' => [$warning, 'Documentos con hallazgos de revisión retenidos en cuarentena (no se descartan): se liberan o se descartan con un motivo.'],
            'schema_drift' => [$warning, 'El esquema de la fuente cambió respecto de la carga anterior (columnas agregadas o quitadas, sin valores).'],
            'decimal_separator_ambiguous' => [$warning, 'Número con una sola coma seguida de exactamente tres dígitos (p. ej. «1,234»): puede ser 1,234 o 1234. Se lee como miles (1234) salvo que la carga declare el separador decimal; confirmar el formato de la fuente.'],
            'date_format_ambiguous' => [$warning, 'Fecha en d/m/a con día y mes ≤ 12: se interpretó como día/mes (convención de la empresa); confirmar el formato de la fuente.'],
            'alias_resolved' => [$warning, 'El código del maestro de productos es un alias vigente de otro producto (cambio de código): se actualiza el producto canónico en lugar de crear un duplicado (ADR-V25-24).'],
            'lot_expired_on_snapshot' => [$warning, 'El lote ya estaba vencido en la fecha de corte: no es vendible; se escribe y el motor lo excluye de la existencia utilizable (W5-D).'],
            'lot_expiry_missing' => [$warning, 'Un producto perecible trae un lote sin vencimiento: el motor lo trata como existencia sin vencimiento, no inventa la fecha (W5-D).'],
            'expiry_exceeds_shelf_life' => [$warning, 'El vencimiento del lote excede la vida útil declarada del producto: revisar la vida útil o la fecha (W5-D).'],
            'lots_exceed_on_hand' => [$warning, 'Los lotes de una serie suman más que la existencia del snapshot del mismo día: el motor limita los lotes a la existencia (W5-D).'],
            'perishable_without_shelf_life' => [$warning, 'Producto perecible sin vida útil declarada: el plan no proyecta vencimientos sin vida útil ni lotes (W5-D).'],
            'shelf_life_without_perishable' => [$warning, 'Vida útil declarada en un producto no perecible: el plan no la usa (W5-D).'],
            'lots_replaced' => [$warning, 'Una foto de lotes se reemplazó: los lotes de la misma sucursal × producto × fecha que el archivo no trae se eliminan (W5-D).'],

            // ── AUTO_FIX_SAFE: normalizaciones seguras (siempre agregadas) ──
            'whitespace_trimmed' => [$autoFix, 'Espacios sobrantes al inicio, al final o repetidos: se recortan.'],
            'decimal_separator_normalized' => [$autoFix, 'Separador decimal/de miles distinto del punto: se normaliza a número.'],
            'date_format_normalized' => [$autoFix, 'Fecha en un formato inequívoco (ISO con barras/puntos o con día > 12): se normaliza a ISO.'],
            'code_case_normalized' => [$autoFix, 'Códigos (documento, SKU, sucursal) con minúsculas: se comparan en mayúsculas.'],
        ];
        // Los códigos de W1 nunca se redefinen aquí: lo que diga Issue::DQ_CLASS_BY_CODE manda.
        foreach (Issue::DQ_CLASS_BY_CODE as $code => $class) {
            $catalog[$code][0] = $class;
        }

        return self::$catalog = $catalog;
    }

    /** Clase de un código emitido con una severidad (`error`, `warning` o `info`). */
    public static function classFor(string $code, string $severity = 'error'): string
    {
        $entry = self::all()[$code] ?? null;
        if ($severity !== 'error') {
            // Una advertencia no detiene el registro: nunca es REVIEW_REQUIRED, QUARANTINED ni BLOCKER.
            return $entry !== null && $entry[0] === self::AUTO_FIX_SAFE ? self::AUTO_FIX_SAFE : self::WARNING;
        }

        return $entry[0] ?? self::QUARANTINED;
    }

    /** Código y clase con que se registra un hallazgo del normalizador (las marcas de la fuente tienen código propio). */
    public static function resolve(Issue $issue): array
    {
        $code = $issue->code;
        if ($issue->column === 'source_recorded_at') {
            $code = match ($issue->code) {
                'future_date' => 'future_recorded_at',
                'invalid_date' => 'recorded_at_unreadable',
                default => $issue->code,
            };
        }

        return [$code, self::classFor($code, $issue->severity)];
    }

    public static function rationale(string $code): ?string
    {
        return self::all()[$code][1] ?? null;
    }

    public static function priority(string $class): int
    {
        $index = array_search($class, self::CLASSES, true);

        return $index === false ? count(self::CLASSES) : (int) $index;
    }

    /**
     * Huella del conjunto de reglas aplicado: SHA-256 del catálogo código → clase, los parámetros de las reglas y la versión del
     * código (más lo que aporte cada carga: p. ej. sus mapeos de valores). Cambiar una clase, un umbral o un mapeo cambia la huella.
     *
     * @param  array<string, mixed>  $extra  parámetros propios de la carga (value_maps, modo de ingestión…)
     */
    public static function rulesVersion(array $extra = []): string
    {
        $classes = [];
        foreach (self::all() as $code => [$class]) {
            $classes[$code] = $class;
        }
        ksort($classes);
        $params = [
            'recorded_at_tolerance_minutes' => (int) config('predictily.imports.source_recorded_at_tolerance_minutes', SalesLineNormalizer::DEFAULT_RECORDED_AT_TOLERANCE_MINUTES),
            'max_issue_rows_per_report' => (int) config('data_quality.max_issue_rows_per_report', 2000),
            'original_value_max_chars' => (int) config('data_quality.original_value_max_chars', 200),
            'reconciliation_amount_tolerance' => (float) config('data_quality.reconciliation_amount_tolerance', 0.01),
        ];
        ksort($extra);

        return hash('sha256', json_encode(['code_version' => self::CODE_VERSION, 'catalog' => $classes, 'params' => $params, 'extra' => $extra], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
