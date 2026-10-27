import type { Granularity, Horizon, Sector } from '@/api/types'

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

export const HORIZONS: Horizon[] = [7, 30, 90]
export const GRANULARITIES: Granularity[] = ['day', 'week', 'month']
