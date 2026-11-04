import type { CustomerHorizon, Granularity, Horizon, Sector } from '@/api/types'

export const SECTORS: Sector[] = [
  'ferreteria',
  'distribuidora',
  'repuestos_automotrices',
  'repuestos_agricolas',
  'retail_multisucursal',
  'importadora',
  'consumo_recurrente',
  'otro',
]

/** Catálogo único de horizontes de demanda (ADR-V25-02, igual al del backend y el motor); el plan de la empresa decide cuáles puede usar. */
export const HORIZONS: Horizon[] = [7, 14, 15, 30, 60, 90]
/** Horizontes del Customer Core (comportamiento de compra): subconjunto del catálogo, sin 60. */
export const CUSTOMER_HORIZONS: CustomerHorizon[] = [7, 14, 15, 30, 90]
export const GRANULARITIES: Granularity[] = ['day', 'week', 'month']
