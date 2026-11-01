<script setup lang="ts">
/** Almacenes (capacidad desconocida = vacío, nunca cero) y conversiones de unidades declaradas (sin conversión declarada, otra unidad es un error). */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import CrudPanel, { type FieldDef } from '@/components/ui/CrudPanel.vue'
import { dataCoreApi } from '@/api'
import type { UnitConversion, UnitConversionPayload, Warehouse, WarehousePayload } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useCatalogStore } from '@/stores/catalog'
import { formatDate, formatNumber } from '@/utils/format'

const { t } = useI18n()
const { can } = useAbility()
const catalog = useCatalogStore()
const warehouses = useAsync(() => dataCoreApi.warehouses())
const conversions = useAsync(() => dataCoreApi.unitConversions())

const whFields = computed<FieldDef[]>(() => [
  { key: 'code', label: t('warehouses.fields.code'), type: 'text', required: true, maxlength: 50 },
  { key: 'name', label: t('warehouses.fields.name'), type: 'text', required: true, maxlength: 150 },
  { key: 'branch_code', label: t('common.branch'), type: 'select', required: true, options: catalog.branches.map((b) => ({ value: b.code, label: `${b.name} (${b.code})` })) },
  { key: 'storage_capacity', label: t('warehouses.fields.capacity'), type: 'number', min: 0, step: 'any', hint: t('warehouses.hints.capacity') },
  { key: 'capacity_unit', label: t('warehouses.fields.unit'), type: 'text', maxlength: 20, hint: t('warehouses.hints.unit') },
  { key: 'is_active', label: t('common.active'), type: 'checkbox' },
])
const whColumns = computed(() => [
  { key: 'code', label: t('warehouses.fields.code') },
  { key: 'name', label: t('warehouses.fields.name') },
  { key: 'branch.name', label: t('common.branch') },
  { key: 'storage_capacity', label: t('warehouses.fields.capacity'), align: 'right' as const, format: (w: Warehouse) => (w.storage_capacity == null ? t('warehouses.capacityUnknown') : `${formatNumber(w.storage_capacity, 2)} ${w.capacity_unit ?? ''}`) },
  { key: 'is_active', label: t('common.status'), format: (w: Warehouse) => (w.is_active ? t('common.active') : t('common.inactive')) },
])
const ucFields = computed<FieldDef[]>(() => [
  { key: 'product_sku', label: t('units.fields.productSku'), type: 'text', maxlength: 100, hint: t('units.hints.productSku') },
  { key: 'from_unit', label: t('units.fields.from'), type: 'text', required: true, maxlength: 20 },
  { key: 'to_unit', label: t('units.fields.to'), type: 'text', required: true, maxlength: 20 },
  { key: 'factor', label: t('units.fields.factor'), type: 'number', required: true, min: 0, step: 'any', hint: t('units.hints.factor') },
  { key: 'valid_from', label: t('units.fields.validFrom'), type: 'date' },
])
const ucColumns = computed(() => [
  { key: 'product', label: t('common.product'), format: (c: UnitConversion) => (c.product ? `${c.product.name} (${c.product.sku})` : t('units.general')) },
  { key: 'from_unit', label: t('units.fields.from') },
  { key: 'to_unit', label: t('units.fields.to') },
  { key: 'factor', label: t('units.fields.factor'), align: 'right' as const, format: (c: UnitConversion) => formatNumber(c.factor, 6) },
  { key: 'valid_from', label: t('units.fields.validFrom'), format: (c: UnitConversion) => formatDate(c.valid_from) },
])
const createWarehouse = (p: Record<string, unknown>): Promise<unknown> => dataCoreApi.createWarehouse(p as unknown as WarehousePayload)
const createConversion = (p: Record<string, unknown>): Promise<unknown> => dataCoreApi.createUnitConversion(p as unknown as UnitConversionPayload)
const removeConversion = (c: UnitConversion): Promise<void> => dataCoreApi.deleteUnitConversion(c.id)
const conversionLabel = (c: UnitConversion): string => `${c.from_unit} → ${c.to_unit}`
</script>

<template>
  <div class="flex flex-col gap-8" data-test="warehouses-units">
    <CrudPanel :title="t('warehouses.title')" :description="t('warehouses.intro')" :columns="whColumns" :rows="warehouses.data.value ?? []" :fields="whFields" :loading="warehouses.loading.value" :error="warehouses.error.value" :can-write="can('catalog.manage')" :create="createWarehouse" :add-label="t('warehouses.add')" :empty-text="t('warehouses.empty')" @changed="warehouses.execute" @retry="warehouses.execute" />
    <CrudPanel :title="t('units.title')" :description="t('units.intro')" :columns="ucColumns" :rows="conversions.data.value ?? []" :fields="ucFields" :loading="conversions.loading.value" :error="conversions.error.value" :can-write="can('catalog.manage')" :create="createConversion" :remove="removeConversion" :row-label="conversionLabel" :add-label="t('units.add')" :empty-text="t('units.empty')" @changed="conversions.execute" @retry="conversions.execute" />
  </div>
</template>
