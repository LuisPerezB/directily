<script setup lang="ts" generic="R extends object">
/**
 * Tabla semántica reutilizable.
 * - Orden en servidor (v-model:sort con 'campo' o '-campo') o en cliente (clientSide).
 * - Celdas personalizables con slots #cell-<key>="{ row }" (los puntos de la clave se
 *   reemplazan por guion bajo: product.name → #cell-product_name).
 * - Estados de carga y vacío.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'

export interface Column<Row> {
  key: string
  label: string
  sortable?: boolean
  /** Clave de orden enviada a la API si difiere de key. */
  sortKey?: string
  align?: 'left' | 'right' | 'center'
  format?: (row: Row) => string
  class?: string
}

const props = withDefaults(
  defineProps<{
    columns: Column<R>[]
    rows: R[]
    rowKey: (row: R) => string | number
    loading?: boolean
    caption?: string | null
    clientSide?: boolean
    emptyText?: string | null
  }>(),
  { loading: false, caption: null, clientSide: false, emptyText: null },
)
const sort = defineModel<string | null>('sort', { default: null })
const { t } = useI18n()

function slotName(col: Column<R>): string {
  return `cell-${col.key.replace(/\./g, '_')}`
}

function sortKeyOf(col: Column<R>): string {
  return col.sortKey ?? col.key
}

function toggle(col: Column<R>): void {
  if (!col.sortable) return
  const key = sortKeyOf(col)
  sort.value = sort.value === `-${key}` ? key : `-${key}`
}

function ariaSort(col: Column<R>): 'ascending' | 'descending' | 'none' | undefined {
  if (!col.sortable) return undefined
  const key = sortKeyOf(col)
  if (sort.value === key) return 'ascending'
  if (sort.value === `-${key}`) return 'descending'
  return 'none'
}

function rawValue(row: R, key: string): unknown {
  return key.split('.').reduce<unknown>((acc, part) => (acc && typeof acc === 'object' ? (acc as Record<string, unknown>)[part] : undefined), row)
}

function display(row: R, col: Column<R>): string {
  if (col.format) return col.format(row)
  const v = rawValue(row, col.key)
  return v === null || v === undefined || v === '' ? '—' : String(v)
}

const sortedRows = computed(() => {
  if (!props.clientSide || !sort.value) return props.rows
  const desc = sort.value.startsWith('-')
  const key = desc ? sort.value.slice(1) : sort.value
  return [...props.rows].sort((a, b) => {
    const va = rawValue(a, key)
    const vb = rawValue(b, key)
    if (va === vb) return 0
    if (va === null || va === undefined) return 1
    if (vb === null || vb === undefined) return -1
    const cmp = typeof va === 'number' && typeof vb === 'number' ? va - vb : String(va).localeCompare(String(vb), 'es')
    return desc ? -cmp : cmp
  })
})
</script>

<template>
  <div class="table-wrap" :aria-busy="loading">
    <table class="table">
      <caption v-if="caption" class="sr-only">{{ caption }}</caption>
      <thead>
        <tr>
          <th v-for="col in columns" :key="col.key" scope="col" :aria-sort="ariaSort(col)" :class="[col.align === 'right' ? 'num' : '', col.class]">
            <button v-if="col.sortable" type="button" class="inline-flex items-center gap-1 uppercase hover:text-fg" @click="toggle(col)">
              {{ col.label }}
              <AppIcon v-if="ariaSort(col) === 'ascending'" name="chevron-down" :size="14" class="rotate-180" />
              <AppIcon v-else-if="ariaSort(col) === 'descending'" name="chevron-down" :size="14" />
            </button>
            <template v-else>{{ col.label }}</template>
          </th>
        </tr>
      </thead>
      <tbody>
        <template v-if="loading && rows.length === 0">
          <tr v-for="i in 5" :key="`s${i}`">
            <td v-for="col in columns" :key="col.key"><div class="h-3.5 rounded bg-surface-2 animate-pulse" /></td>
          </tr>
        </template>
        <tr v-else-if="rows.length === 0">
          <td :colspan="columns.length" class="text-center text-fg-muted py-8">
            <slot name="empty">{{ emptyText ?? t('table.empty') }}</slot>
          </td>
        </tr>
        <tr v-for="row in sortedRows" v-else :key="rowKey(row)" :class="loading ? 'opacity-60' : ''">
          <td v-for="col in columns" :key="col.key" :class="[col.align === 'right' ? 'num' : col.align === 'center' ? 'text-center' : '', col.class]">
            <slot :name="slotName(col)" :row="row">{{ display(row, col) }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
