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

/** Horizontes del dominio de demanda; el plan de la empresa decide cuáles puede usar. */
export const HORIZONS: Horizon[] = [7, 14, 30, 60, 90]
/** Horizontes del Customer Core (comportamiento de compra). */
export const CUSTOMER_HORIZONS: CustomerHorizon[] = [7, 30, 90]
export const GRANULARITIES: Granularity[] = ['day', 'week', 'month']
