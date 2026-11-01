import type { ColumnMapping, ImportEntityType, ImportField, ImportJob, ImportStatus } from '@/api/types'

/**
 * Lógica pura del asistente de importación (mapeo de columnas y pasos).
 * Los campos canónicos provienen de GET /import-fields/{entity}; si el endpoint no
 * responde se usa esta copia del contrato (docs/06 §2).
 */

const f = (key: string, label: string, required: boolean, type: string, description = ''): ImportField => ({
  key,
  label,
  required,
  type,
  description,
})

export const FALLBACK_FIELDS: Record<ImportEntityType, ImportField[]> = {
  sales: [
    f('document_number', 'Número de documento', true, 'text', 'Junto al tipo de documento identifica la venta; reimportar no duplica.'),
    f('document_type', 'Tipo de documento', false, 'text', 'Factura, nota de venta o nota de crédito. Por defecto: factura.'),
    f('status', 'Estado', false, 'text', 'Anulada / emitida. Las anuladas no cuentan como venta.'),
    f('issued_at', 'Fecha de emisión', true, 'date'),
    f('branch_code', 'Código de sucursal', false, 'text', 'Requerido si tiene más de una sucursal.'),
    f('product_sku', 'Código de producto (SKU)', true, 'text'),
    f('product_name', 'Nombre del producto', false, 'text'),
    f('category', 'Categoría', false, 'text'),
    f('quantity', 'Cantidad', true, 'number', 'Cantidades negativas se tratan como devoluciones.'),
    f('unit_price', 'Precio unitario', true, 'number', 'Precio original antes de descuento.'),
    f('discount', 'Descuento', false, 'number'),
    f('tax', 'Impuesto (IVA)', false, 'number'),
    f('net_amount', 'Importe neto', false, 'number'),
    f('unit_cost', 'Costo unitario', false, 'number'),
    f('customer_code', 'Código de cliente', false, 'text'),
    f('customer_segment', 'Segmento de cliente', false, 'text'),
    f('customer_province', 'Provincia del cliente', false, 'text'),
    f('channel', 'Canal de venta', false, 'text'),
    f('currency', 'Moneda', false, 'text'),
    f('line_number', 'Número de línea', false, 'integer'),
  ],
  products: [
    f('sku', 'Código (SKU)', true, 'text'),
    f('name', 'Nombre', true, 'text'),
    f('category', 'Categoría', false, 'text'),
    f('unit', 'Unidad', false, 'text'),
    f('brand', 'Marca', false, 'text'),
    f('current_price', 'Precio actual', false, 'number'),
    f('current_cost', 'Costo actual', false, 'number'),
    f('is_active', 'Activo', false, 'boolean'),
  ],
  inventory: [
    f('snapshot_date', 'Fecha del inventario', true, 'date'),
    f('branch_code', 'Código de sucursal', true, 'text'),
    f('product_sku', 'Código de producto (SKU)', true, 'text'),
    f('on_hand', 'Existencias', true, 'number'),
    f('reserved', 'Reservado', false, 'number'),
    f('in_transit', 'En tránsito', false, 'number'),
    f('unit_cost', 'Costo unitario', false, 'number'),
  ],
  lead_times: [
    f('product_sku', 'Código de producto (SKU)', false, 'text'),
    f('supplier_code', 'Código de proveedor', false, 'text'),
    f('branch_code', 'Código de sucursal', false, 'text'),
    f('lead_time_days', 'Plazo de entrega (días)', true, 'integer'),
    f('lead_time_std_days', 'Variabilidad del plazo (días)', false, 'number'),
  ],
  branches: [
    f('code', 'Código', true, 'text', 'Código del establecimiento (p. ej. 001).'),
    f('name', 'Nombre', true, 'text'),
    f('province', 'Provincia', false, 'text'),
    f('canton', 'Cantón', false, 'text'),
    f('commercial_zone', 'Zona comercial', false, 'text'),
    f('address', 'Dirección', false, 'text'),
  ],
  suppliers: [
    f('code', 'Código', true, 'text'),
    f('name', 'Nombre', true, 'text'),
    f('tax_id', 'RUC', false, 'text'),
    f('country', 'País', false, 'text'),
    f('default_lead_time_days', 'Plazo habitual (días)', false, 'integer'),
  ],
  promotions: [
    f('name', 'Nombre', true, 'text'),
    f('promo_type', 'Tipo', false, 'text', 'percentage, fixed_amount, bundle u other.'),
    f('discount_pct', 'Descuento', false, 'number'),
    f('starts_on', 'Desde', true, 'date'),
    f('ends_on', 'Hasta', true, 'date'),
    f('branch_code', 'Sucursal', false, 'text'),
    f('product_sku', 'Producto', false, 'text'),
    f('category', 'Categoría', false, 'text'),
    f('known_at', 'Registrada el', false, 'date', 'Cuándo el negocio aprobó o registró la promoción (no futura). Si falta, el momento de la importación.'),
  ],
  // Etapa 2: los campos de estas entidades los entrega GET /import-fields/{entity}; sin respuesta no se inventan.
  commitments: [],
  calendar_events: [],
  unit_conversions: [],
  warehouses: [],
}

/** Mapeo inicial: el guardado por el usuario o el sugerido por la API, limitado a columnas detectadas. */
export function initialMapping(fields: ImportField[], job: Pick<ImportJob, 'column_mapping' | 'suggested_mapping' | 'detected_columns'>): ColumnMapping {
  // Un mapeo guardado vacío ({}) no cuenta: en ese caso se usa la sugerencia de la API.
  const saved = job.column_mapping && Object.values(job.column_mapping).some(Boolean) ? job.column_mapping : null
  const source = saved ?? job.suggested_mapping ?? {}
  const columns = new Set(job.detected_columns ?? [])
  const mapping: ColumnMapping = {}
  for (const field of fields) {
    const column = source[field.key]
    mapping[field.key] = column && columns.has(column) ? column : null
  }
  return mapping
}

export interface MappingValidation {
  valid: boolean
  missingRequired: string[]
  duplicateColumns: string[]
}

export function validateMapping(fields: ImportField[], mapping: ColumnMapping): MappingValidation {
  const missingRequired = fields.filter((fd) => fd.required && !mapping[fd.key]).map((fd) => fd.key)
  const counts = new Map<string, number>()
  for (const column of Object.values(mapping)) {
    if (column) counts.set(column, (counts.get(column) ?? 0) + 1)
  }
  const duplicateColumns = [...counts.entries()].filter(([, n]) => n > 1).map(([column]) => column)
  return { valid: missingRequired.length === 0 && duplicateColumns.length === 0, missingRequired, duplicateColumns }
}

/** Mapeo listo para enviar (sin claves vacías). */
export function compactMapping(mapping: ColumnMapping): ColumnMapping {
  return Object.fromEntries(Object.entries(mapping).filter(([, v]) => Boolean(v)))
}

export type WizardStep = 'upload' | 'mapping' | 'validating' | 'review' | 'importing' | 'done' | 'rejected'

export function stepForStatus(status: ImportStatus | null | undefined): WizardStep {
  switch (status) {
    case 'uploaded':
    case 'awaiting_mapping':
      return 'mapping'
    case 'validating':
      return 'validating'
    case 'validated':
    case 'failed':
      return 'review'
    case 'importing':
      return 'importing'
    case 'completed':
      return 'done'
    case 'rejected':
      return 'rejected'
    default:
      return 'upload'
  }
}

export function isImportBusy(status: ImportStatus | null | undefined): boolean {
  return status === 'validating' || status === 'importing' || status === 'uploaded'
}

export interface ConfirmCheck {
  allowed: boolean
  reason: 'ok' | 'not_validated' | 'has_errors' | 'no_valid_rows'
}

export function canConfirmImport(job: Pick<ImportJob, 'status' | 'stats' | 'options'>): ConfirmCheck {
  if (job.status !== 'validated') return { allowed: false, reason: 'not_validated' }
  const errors = Number(job.stats?.rows_error ?? 0)
  const valid = Number(job.stats?.rows_valid ?? 0)
  if (valid <= 0) return { allowed: false, reason: 'no_valid_rows' }
  if (errors > 0 && !job.options?.skip_invalid_rows) return { allowed: false, reason: 'has_errors' }
  return { allowed: true, reason: 'ok' }
}

export const MAX_UPLOAD_BYTES = 20 * 1024 * 1024
export const ACCEPTED_EXTENSIONS = ['csv', 'txt', 'xlsx']

export function checkUploadFile(file: Pick<File, 'name' | 'size'>): 'ok' | 'too_large' | 'bad_type' | 'empty' {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!ACCEPTED_EXTENSIONS.includes(ext)) return 'bad_type'
  if (file.size === 0) return 'empty'
  if (file.size > MAX_UPLOAD_BYTES) return 'too_large'
  return 'ok'
}
