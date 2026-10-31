<script setup lang="ts">
/**
 * Datos que consume el planificador: ofertas (con su comparabilidad), tasas de cambio, capacidades de almacén, presupuestos,
 * recursos y recetas. Lo desconocido se deja vacío (null): no se rellena con cero. Una oferta sin conversión de unidades
 * declarada o sin tasa de cambio explícita no es comparable y el planificador la excluye.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CrudPanel, { type FieldDef } from '@/components/ui/CrudPanel.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import TabsNav from '@/components/ui/TabsNav.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { catalogApi, supplyApi, supplyConfigCrud } from '@/api'
import type { ExchangeRate, LocationCapacity, Product, ProductionRecipe, ProductionResource, PurchaseBudget, SupplierOffer } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { formatDate, formatNumber } from '@/utils/format'

const { t } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const sub = ref('offers')
const subTabs = computed(() => ['offers', 'rates', 'capacities', 'budgets', 'resources', 'recipes'].map((k) => ({ key: k, label: t(`supply.config.tabs.${k}`) })))
const canWrite = computed(() => can('catalog.manage'))

const suppliers = useAsync(() => catalogApi.suppliers({ per_page: 100 }))
const offers = useAsync(() => supplyApi.offers())
const rates = useAsync(() => supplyApi.exchangeRates())
const capacities = useAsync(() => supplyApi.capacities())
const budgets = useAsync(() => supplyApi.budgets())
const resources = useAsync(() => supplyApi.resources())
const recipes = useAsync(() => supplyApi.recipes())

const supplierOptions = computed(() => (suppliers.data.value?.data ?? []).map((s) => ({ value: s.id, label: `${s.name} (${s.code})` })))
const branchOptions = computed(() => catalog.branches.map((b) => ({ value: b.id, label: b.name })))
const resourceOptions = computed(() => (resources.data.value?.data ?? []).map((r) => ({ value: r.id, label: `${r.name} (${r.code})` })))
const crud = {
  offers: supplyConfigCrud<SupplierOffer>('offers'),
  rates: supplyConfigCrud<ExchangeRate>('exchange-rates'),
  capacities: supplyConfigCrud<LocationCapacity>('capacities'),
  budgets: supplyConfigCrud<PurchaseBudget>('budgets'),
  resources: supplyConfigCrud<ProductionResource>('production/resources'),
  recipes: supplyConfigCrud<ProductionRecipe>('production/recipes'),
}
const cur = computed(() => tenant.currency)

const offerFields = computed<FieldDef[]>(() => [
  { key: 'supplier_id', label: t('supply.config.offers.supplier'), type: 'select', required: true, options: supplierOptions.value },
  { key: 'product_id', label: t('common.product'), type: 'product', required: true },
  { key: 'branch_id', label: t('supply.config.offers.branch'), type: 'select', options: branchOptions.value, hint: t('supply.config.offers.branchHint') },
  { key: 'unit_cost', label: t('supply.config.offers.unitCost'), type: 'number', required: true, min: 0, step: 'any' },
  { key: 'currency', label: t('supply.config.offers.currency'), type: 'text', required: true, maxlength: 3, hint: t('supply.config.offers.currencyHint', { currency: cur.value }) },
  { key: 'unit', label: t('supply.config.offers.unit'), type: 'text', required: true, maxlength: 20, hint: t('supply.config.offers.unitHint') },
  { key: 'min_order_qty', label: t('supply.config.offers.minOrder'), type: 'number', min: 0, step: 'any', hint: t('supply.config.unknownHint') },
  { key: 'order_multiple', label: t('supply.config.offers.multiple'), type: 'number', min: 0, step: 'any', hint: t('supply.config.unknownHint') },
  { key: 'lead_time_days', label: t('supply.config.offers.leadTime'), type: 'number', min: 1, max: 365, hint: t('supply.config.unknownHint') },
  { key: 'lead_time_std_days', label: t('supply.config.offers.leadTimeStd'), type: 'number', min: 0, step: 'any', hint: t('supply.config.unknownHint') },
  { key: 'capacity_per_period', label: t('supply.config.offers.capacity'), type: 'number', min: 0, step: 'any', hint: t('supply.config.unknownHint') },
  { key: 'capacity_period_days', label: t('supply.config.offers.capacityDays'), type: 'number', min: 1, max: 365 },
  { key: 'fixed_order_cost', label: t('supply.config.offers.fixedCost'), type: 'number', min: 0, step: 'any' },
  { key: 'acquisition_cost_pct', label: t('supply.config.offers.acquisitionPct'), type: 'number', min: 0, max: 5, step: 'any', hint: t('supply.config.offers.acquisitionPctHint') },
  { key: 'acquisition_cost_per_unit', label: t('supply.config.offers.acquisitionUnit'), type: 'number', min: 0, step: 'any' },
  { key: 'valid_from', label: t('supply.config.validFrom'), type: 'date' },
  { key: 'valid_to', label: t('supply.config.validTo'), type: 'date' },
  { key: 'known_at', label: t('calendar.fields.knownAt'), type: 'date', hint: t('calendar.hints.knownAt') },
  { key: 'is_active', label: t('common.active'), type: 'checkbox' },
])
const offerColumns = computed(() => [
  { key: 'product', label: t('common.product'), format: (o: SupplierOffer) => o.product?.name ?? `#${o.product_id}` },
  { key: 'supplier', label: t('supply.config.offers.supplier'), format: (o: SupplierOffer) => o.supplier?.name ?? `#${o.supplier_id}` },
  { key: 'unit_cost', label: t('supply.config.offers.unitCost'), align: 'right' as const, format: (o: SupplierOffer) => `${formatNumber(o.unit_cost, 4)} ${o.currency}/${o.unit}` },
  { key: 'lead_time_days', label: t('supply.config.offers.leadTime'), align: 'right' as const, format: (o: SupplierOffer) => (o.lead_time_days == null ? t('supply.config.unknown') : t('common.days', { n: o.lead_time_days })) },
  { key: 'comparability', label: t('supply.config.offers.comparability') },
])
const offerToForm = (o: SupplierOffer): Record<string, unknown> => ({ ...o, product_id__obj: o.product ? { id: o.product.id, sku: o.product.sku, name: o.product.name } : null })

const rateFields = computed<FieldDef[]>(() => [
  { key: 'from_currency', label: t('supply.config.rates.from'), type: 'text', required: true, maxlength: 3 },
  { key: 'to_currency', label: t('supply.config.rates.to'), type: 'text', required: true, maxlength: 3 },
  { key: 'rate', label: t('supply.config.rates.rate'), type: 'number', required: true, min: 0, step: 'any', hint: t('supply.config.rates.rateHint') },
  { key: 'valid_on', label: t('supply.config.rates.validOn'), type: 'date', required: true },
  { key: 'source', label: t('supply.config.rates.source'), type: 'text', required: true, maxlength: 50, hint: t('supply.config.rates.sourceHint') },
  { key: 'known_at', label: t('calendar.fields.knownAt'), type: 'date' },
])
const rateColumns = computed(() => [
  { key: 'pair', label: t('supply.config.rates.pair'), format: (r: ExchangeRate) => `${r.from_currency} → ${r.to_currency}` },
  { key: 'rate', label: t('supply.config.rates.rate'), align: 'right' as const, format: (r: ExchangeRate) => formatNumber(r.rate, 6) },
  { key: 'valid_on', label: t('supply.config.rates.validOn'), format: (r: ExchangeRate) => formatDate(r.valid_on) },
  { key: 'source', label: t('supply.config.rates.source') },
])

const capFields = computed<FieldDef[]>(() => [
  { key: 'branch_id', label: t('common.branch'), type: 'select', required: true, options: branchOptions.value },
  { key: 'capacity', label: t('supply.config.capacities.capacity'), type: 'number', required: true, min: 0, step: 'any' },
  { key: 'capacity_unit', label: t('supply.config.capacities.unit'), type: 'text', required: true, maxlength: 20, hint: t('supply.config.capacities.unitHint') },
  { key: 'valid_from', label: t('supply.config.validFrom'), type: 'date' },
  { key: 'valid_to', label: t('supply.config.validTo'), type: 'date' },
  { key: 'notes', label: t('supply.config.notes'), type: 'textarea', maxlength: 2000 },
])
const capColumns = computed(() => [
  { key: 'branch', label: t('common.branch'), format: (c: LocationCapacity) => c.branch?.name ?? `#${c.branch_id}` },
  { key: 'capacity', label: t('supply.config.capacities.capacity'), align: 'right' as const, format: (c: LocationCapacity) => `${formatNumber(c.capacity, 2)} ${c.capacity_unit}` },
  { key: 'valid_from', label: t('supply.config.validity'), format: (c: LocationCapacity) => `${formatDate(c.valid_from)} – ${formatDate(c.valid_to)}` },
])

const budgetFields = computed<FieldDef[]>(() => [
  { key: 'branch_id', label: t('common.branch'), type: 'select', options: branchOptions.value, hint: t('supply.config.budgets.branchHint') },
  { key: 'period_start', label: t('supply.config.budgets.start'), type: 'date', required: true },
  { key: 'period_end', label: t('supply.config.budgets.end'), type: 'date', required: true },
  { key: 'amount', label: t('supply.config.budgets.amount'), type: 'number', required: true, min: 0, step: 'any' },
  { key: 'currency', label: t('supply.config.offers.currency'), type: 'text', required: true, maxlength: 3 },
  { key: 'notes', label: t('supply.config.notes'), type: 'textarea', maxlength: 2000 },
])
const budgetColumns = computed(() => [
  { key: 'period', label: t('supply.config.budgets.period'), format: (b: PurchaseBudget) => `${formatDate(b.period_start)} – ${formatDate(b.period_end)}` },
  { key: 'branch', label: t('common.branch'), format: (b: PurchaseBudget) => b.branch?.name ?? t('filters.allBranches') },
  { key: 'amount', label: t('supply.config.budgets.amount'), align: 'right' as const, format: (b: PurchaseBudget) => `${formatNumber(b.amount, 2)} ${b.currency}` },
])

const resFields = computed<FieldDef[]>(() => [
  { key: 'code', label: t('warehouses.fields.code'), type: 'text', required: true, maxlength: 50 },
  { key: 'name', label: t('warehouses.fields.name'), type: 'text', required: true, maxlength: 150 },
  { key: 'branch_id', label: t('common.branch'), type: 'select', options: branchOptions.value },
  { key: 'capacity_per_period', label: t('supply.config.resources.capacity'), type: 'number', required: true, min: 0, step: 'any' },
  { key: 'capacity_unit', label: t('supply.config.resources.unit'), type: 'text', required: true, maxlength: 20, hint: t('supply.config.resources.unitHint') },
  { key: 'period_days', label: t('supply.config.resources.periodDays'), type: 'number', min: 1, max: 365 },
  { key: 'is_active', label: t('common.active'), type: 'checkbox' },
])
const resColumns = computed(() => [
  { key: 'code', label: t('warehouses.fields.code') },
  { key: 'name', label: t('warehouses.fields.name') },
  { key: 'capacity_per_period', label: t('supply.config.resources.capacity'), align: 'right' as const, format: (r: ProductionResource) => `${formatNumber(r.capacity_per_period, 2)} ${r.capacity_unit} / ${t('common.days', { n: r.period_days })}` },
])

const recipeFields = computed<FieldDef[]>(() => [
  { key: 'code', label: t('warehouses.fields.code'), type: 'text', required: true, maxlength: 50 },
  { key: 'name', label: t('warehouses.fields.name'), type: 'text', required: true, maxlength: 150 },
  { key: 'output_product_id', label: t('supply.config.recipes.output'), type: 'product', required: true },
  { key: 'output_quantity', label: t('supply.config.recipes.outputQty'), type: 'number', required: true, min: 0, step: 'any' },
  { key: 'yield_rate', label: t('supply.config.recipes.yield'), type: 'number', min: 0, max: 1, step: 'any', hint: t('supply.config.recipes.yieldHint') },
  { key: 'production_days', label: t('supply.config.recipes.days'), type: 'number', min: 1, max: 365 },
  { key: 'resource_id', label: t('supply.config.recipes.resource'), type: 'select', options: resourceOptions.value },
  { key: 'resource_usage_per_batch', label: t('supply.config.recipes.usage'), type: 'number', min: 0, step: 'any' },
  { key: 'cost_per_batch', label: t('supply.config.recipes.cost'), type: 'number', min: 0, step: 'any', hint: t('supply.config.unknownHint') },
  { key: 'is_active', label: t('common.active'), type: 'checkbox' },
])
const recipeColumns = computed(() => [
  { key: 'code', label: t('warehouses.fields.code') },
  { key: 'output_product', label: t('supply.config.recipes.output'), format: (r: ProductionRecipe) => r.output_product?.name ?? `#${r.output_product_id}` },
  { key: 'useful', label: t('supply.config.recipes.useful'), align: 'right' as const, format: (r: ProductionRecipe) => formatNumber(r.useful_output_per_batch, 2) },
  { key: 'inputs', label: t('supply.config.recipes.inputs'), format: (r: ProductionRecipe) => r.lines.map((l) => l.input_product?.name ?? `#${l.input_product_id}`).join(', ') },
])
// Líneas (insumos) de la receta: un editor propio porque cada línea es un producto + cantidad por lote.
interface RecipeLine { product: Product | null; quantity: number | null }
const recipeLines = reactive<RecipeLine[]>([{ product: null, quantity: null }])
const recipeToForm = (r: ProductionRecipe): Record<string, unknown> => {
  recipeLines.splice(0, recipeLines.length, ...r.lines.map((l) => ({ product: l.input_product ? ({ id: l.input_product.id, sku: l.input_product.sku, name: l.input_product.name } as Product) : null, quantity: l.quantity_per_batch })))
  return { ...r, output_product_id__obj: r.output_product ? { id: r.output_product.id, sku: r.output_product.sku, name: r.output_product.name } : null }
}
const recipeExtra = (): Record<string, unknown> => ({
  lines: recipeLines.filter((l) => l.product && l.quantity != null).map((l) => ({ input_product_id: l.product!.id, quantity_per_batch: l.quantity })),
})
function resetLines(): void {
  recipeLines.splice(0, recipeLines.length, { product: null, quantity: null })
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('supply.tabs.config')" data-test="supply-config">
    <InlineNotice kind="info">{{ t('supply.config.intro') }}</InlineNotice>
    <InlineNotice v-if="!canWrite" kind="info">{{ t('supply.config.readOnly') }}</InlineNotice>
    <TabsNav v-model="sub" :tabs="subTabs" :label="t('supply.tabs.config')" id-prefix="scfg" />
    <div id="scfg-panel" role="tabpanel" :aria-labelledby="`scfg-${sub}`">
      <CrudPanel v-if="sub === 'offers'" :title="t('supply.config.tabs.offers')" :description="t('supply.config.offers.intro')" :columns="offerColumns" :rows="offers.data.value?.data ?? []" :fields="offerFields" :loading="offers.loading.value" :error="offers.error.value" :can-write="canWrite" :create="crud.offers.create" :update="crud.offers.update" :remove="(o) => crud.offers.remove(o.id)" :to-form="offerToForm" :row-label="(o) => o.product?.name ?? `#${o.id}`" :defaults="{ currency: cur, capacity_period_days: 7, fixed_order_cost: 0, acquisition_cost_pct: 0, acquisition_cost_per_unit: 0, is_active: true }" :empty-text="t('supply.config.offers.empty')" @changed="offers.execute" @retry="offers.execute">
        <template #cell-comparability="{ row }">
          <span v-if="row.comparability.comparable" class="badge badge-success" data-test="comparable">{{ t('supply.config.offers.comparable') }}</span>
          <template v-else>
            <span class="badge badge-warning" data-test="not-comparable">{{ t('supply.config.offers.notComparable') }}</span>
            <span v-for="i in row.comparability.issues" :key="i" class="block text-xs text-fg-muted">{{ t(`supply.config.offers.issues.${i}`, { from: row.comparability.unit_conversion.from ?? '', to: row.comparability.unit_conversion.to ?? '', cfrom: row.comparability.exchange_rate.from, cto: row.comparability.exchange_rate.to }) }}</span>
          </template>
        </template>
      </CrudPanel>
      <CrudPanel v-else-if="sub === 'rates'" :title="t('supply.config.tabs.rates')" :description="t('supply.config.rates.intro')" :columns="rateColumns" :rows="rates.data.value?.data ?? []" :fields="rateFields" :loading="rates.loading.value" :error="rates.error.value" :can-write="canWrite" :create="crud.rates.create" :update="crud.rates.update" :remove="(r) => crud.rates.remove(r.id)" :row-label="(r) => `${r.from_currency} → ${r.to_currency}`" :defaults="{ to_currency: cur, source: 'manual' }" :empty-text="t('supply.config.rates.empty')" @changed="rates.execute" @retry="rates.execute" />
      <CrudPanel v-else-if="sub === 'capacities'" :title="t('supply.config.tabs.capacities')" :description="t('supply.config.capacities.intro')" :columns="capColumns" :rows="capacities.data.value?.data ?? []" :fields="capFields" :loading="capacities.loading.value" :error="capacities.error.value" :can-write="canWrite" :create="crud.capacities.create" :update="crud.capacities.update" :remove="(c) => crud.capacities.remove(c.id)" :row-label="(c) => c.branch?.name ?? `#${c.id}`" :empty-text="t('supply.config.capacities.empty')" @changed="capacities.execute" @retry="capacities.execute" />
      <CrudPanel v-else-if="sub === 'budgets'" :title="t('supply.config.tabs.budgets')" :description="t('supply.config.budgets.intro')" :columns="budgetColumns" :rows="budgets.data.value?.data ?? []" :fields="budgetFields" :loading="budgets.loading.value" :error="budgets.error.value" :can-write="canWrite" :create="crud.budgets.create" :update="crud.budgets.update" :remove="(b) => crud.budgets.remove(b.id)" :row-label="(b) => `${formatDate(b.period_start)} – ${formatDate(b.period_end)}`" :defaults="{ currency: cur }" :empty-text="t('supply.config.budgets.empty')" @changed="budgets.execute" @retry="budgets.execute" />
      <CrudPanel v-else-if="sub === 'resources'" :title="t('supply.config.tabs.resources')" :description="t('supply.config.resources.intro')" :columns="resColumns" :rows="resources.data.value?.data ?? []" :fields="resFields" :loading="resources.loading.value" :error="resources.error.value" :can-write="canWrite" :create="crud.resources.create" :update="crud.resources.update" :remove="(r) => crud.resources.remove(r.id)" :row-label="(r) => r.name" :defaults="{ period_days: 7, is_active: true }" :empty-text="t('supply.config.resources.empty')" @changed="resources.execute" @retry="resources.execute" />
      <CrudPanel v-else :title="t('supply.config.tabs.recipes')" :description="t('supply.config.recipes.intro')" :columns="recipeColumns" :rows="recipes.data.value?.data ?? []" :fields="recipeFields" :loading="recipes.loading.value" :error="recipes.error.value" :can-write="canWrite" :create="crud.recipes.create" @opened="(r) => { if (!r) resetLines() }" :update="crud.recipes.update" :remove="(r) => crud.recipes.remove(r.id)" :to-form="recipeToForm" :extra-payload="recipeExtra" :row-label="(r) => r.name" :defaults="{ yield_rate: 1, production_days: 1, resource_usage_per_batch: 0, is_active: true }" :empty-text="t('supply.config.recipes.empty')" @changed="recipes.execute" @retry="recipes.execute">
        <template #form-extra>
          <fieldset class="sm:col-span-2 border border-line rounded-xl p-3 m-0">
            <legend class="label px-1">{{ t('supply.config.recipes.inputs') }}</legend>
            <div v-for="(l, i) in recipeLines" :key="i" class="grid gap-2 sm:grid-cols-[1fr_8rem_auto] items-end mb-2">
              <FormField :id="`rl-${i}-p`" :label="t('common.product')"><template #default><ProductPicker :id="`rl-${i}-p`" v-model="l.product" /></template></FormField>
              <FormField :id="`rl-${i}-q`" :label="t('supply.config.recipes.perBatch')"><template #default><input :id="`rl-${i}-q`" v-model.number="l.quantity" type="number" min="0" step="any" class="input" /></template></FormField>
              <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" :disabled="recipeLines.length === 1" @click="recipeLines.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="recipeLines.push({ product: null, quantity: null })"><AppIcon name="plus" :size="15" /> {{ t('supply.config.recipes.addInput') }}</button>
          </fieldset>
        </template>
      </CrudPanel>
    </div>
  </section>
</template>
