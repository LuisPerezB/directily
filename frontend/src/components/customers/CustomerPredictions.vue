<script setup lang="ts">
/**
 * Predicciones de compra por comprador × producto. La probabilidad es de al menos una compra en el horizonte; el monto
 * es una ESTIMACIÓN con supuesto de precio (probabilidad × cantidad × último precio neto), nunca una venta confirmada.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import { customerApi } from '@/api'
import type { CustomerHorizon, CustomerPrediction, Product } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatExpectedAmount, horizonNotAvailable, predictionAbstains } from '@/utils/customers'
import { formatNumber, formatProbability } from '@/utils/format'

const props = defineProps<{ horizon: CustomerHorizon }>()
const emit = defineEmits<{ 'update:horizon': [value: CustomerHorizon] }>()
const { t, te } = useI18n()
const tenant = useTenantStore()

const orderBy = ref<'probability' | 'expected_quantity' | 'expected_amount' | 'quantity_if_purchase'>('probability')
const minProbability = ref<number | null>(null)
const product = ref<Product | null>(null)
const page = ref(1)

watch([() => props.horizon, orderBy, minProbability, product], () => (page.value = 1))

const result = useAsync(
  () => customerApi.predictions({ horizon: props.horizon, order_by: orderBy.value, direction: 'desc', min_probability: minProbability.value ?? undefined, product_id: product.value?.id, page: page.value, per_page: 25 }),
  { watch: [() => props.horizon, orderBy, minProbability, product, page] },
)

const unavailable = computed(() => horizonNotAvailable(result.error.value))
const failure = computed(() => (unavailable.value ? null : result.error.value))
const envelope = computed(() => result.data.value?.envelope ?? null)
const rows = computed(() => result.data.value?.data ?? [])
// Sin ninguna predicción y con calidad "datos insuficientes": es una abstención explícita, no una tabla vacía.
const abstains = computed(() => result.loaded.value && rows.value.length === 0 && envelope.value?.quality_status === 'insufficient_data' && !product.value && minProbability.value === null)
const rankingSource = computed(() => result.data.value?.ranking_source ?? null)

function modelText(p: CustomerPrediction): string {
  const family = p.model.family
  if (!family) return '—'
  return te(`customerModels.${family}`) ? t(`customerModels.${family}`) : family
}
function flagText(flag: string): string {
  return te(`customerFlags.${flag}`) ? t(`customerFlags.${flag}`) : flag
}

const columns = computed<Column<CustomerPrediction>[]>(() => [
  { key: 'customer', label: t('customers.buyer') },
  { key: 'product', label: t('common.product') },
  { key: 'purchase_probability', label: t('customers.probability'), align: 'right' },
  { key: 'quantity_if_purchase', label: t('customers.quantityIfPurchase'), align: 'right' },
  { key: 'expected_quantity', label: t('customers.expectedQuantity'), align: 'right' },
  { key: 'expected_amount', label: t('customers.estimatedAmount'), align: 'right' },
  { key: 'committed_quantity', label: t('customers.committed'), align: 'right', format: (r) => formatNumber(r.committed_quantity, 1) },
  { key: 'model', label: t('customers.model') },
  { key: 'quality_flags', label: t('customers.flags') },
])
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('customers.tabs.predictions')" data-test="customer-predictions">
    <div class="flex flex-wrap items-end gap-4">
      <div>
        <label class="label" for="cp-order">{{ t('customers.orderBy') }}</label>
        <select id="cp-order" v-model="orderBy" class="select">
          <option value="probability">{{ t('customers.probability') }}</option>
          <option value="expected_quantity">{{ t('customers.expectedQuantity') }}</option>
          <option value="expected_amount">{{ t('customers.estimatedAmount') }}</option>
          <option value="quantity_if_purchase">{{ t('customers.quantityIfPurchase') }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="cp-min">{{ t('customers.minProbability') }}</label>
        <select id="cp-min" v-model="minProbability" class="select">
          <option :value="null">{{ t('customers.anyProbability') }}</option>
          <option v-for="p in [0.05, 0.1, 0.25, 0.5]" :key="p" :value="p">≥ {{ formatProbability(p) }}</option>
        </select>
      </div>
      <div class="min-w-[14rem] flex-1">
        <label class="label" for="cp-product">{{ t('filters.product') }}</label>
        <ProductPicker id="cp-product" v-model="product" />
      </div>
    </div>

    <ErrorState v-if="failure" :error="failure" @retry="result.execute" />

    <EmptyState v-else-if="unavailable" icon="users" :title="t('customers.horizonUnavailableTitle', { n: horizon })" :description="t('customers.horizonUnavailableText')" data-test="horizon-not-available">
      <button v-for="h in unavailable.availableHorizons" :key="h" type="button" class="btn btn-secondary btn-sm" @click="emit('update:horizon', h as CustomerHorizon)">{{ t('customers.viewHorizon', { n: h }) }}</button>
    </EmptyState>

    <template v-else>
      <EmptyState v-if="abstains" icon="users" :title="t('customers.noPredictionTitle')" :description="t('customers.noPredictionText')" data-test="customer-abstention">
        <RouterLink v-if="tenant.can('forecast.run')" :to="{ name: 'customers', params: { tab: 'ejecuciones' } }" class="btn btn-primary btn-sm">{{ t('customers.goRuns') }}</RouterLink>
      </EmptyState>
      <template v-else>
        <InlineNotice kind="info" :title="t('customers.amountNoticeTitle')" data-test="amount-notice">{{ t('customers.amountNotice') }}</InlineNotice>
        <p v-if="rankingSource" class="text-sm m-0" data-test="ranking-source">{{ t('customers.rankingSource', { source: t(`rankingSources.${rankingSource}`) }) }}</p>
        <DataTable :columns="columns" :rows="rows" :row-key="(r) => `${r.customer.id}-${r.product.id}`" :loading="result.loading.value" :caption="t('customers.tabs.predictions')" :empty-text="t('customers.noRowsFilter')">
          <template #cell-customer="{ row }">
            <RouterLink :to="{ name: 'customer-detail', params: { id: String(row.customer.id) } }" class="font-medium">{{ row.customer.code ?? `#${row.customer.id}` }}</RouterLink>
            <span v-if="row.n_purchases != null" class="block text-xs text-fg-muted">{{ t('customers.purchasesCount', { n: row.n_purchases }) }}</span>
          </template>
          <template #cell-product="{ row }">
            <span class="font-medium block">{{ row.product.name ?? `#${row.product.id}` }}</span>
            <span class="text-xs text-fg-muted">{{ row.product.sku }}</span>
          </template>
          <template #cell-purchase_probability="{ row }">
            <span v-if="predictionAbstains(row)" class="text-fg-muted" data-test="prob-abstains">{{ t('customers.noProbability') }}</span>
            <template v-else>
              <span class="font-semibold" data-test="prob">{{ formatProbability(row.purchase_probability) }}</span>
              <span v-if="row.calibration_status && row.calibration_status !== 'calibrated'" class="block text-xs text-warning">{{ t(`customers.calibration.${row.calibration_status}`) }}</span>
              <span v-if="row.probability_coherence_adjusted" class="block text-xs text-fg-muted">{{ t('customers.coherenceAdjusted') }}</span>
            </template>
          </template>
          <template #cell-quantity_if_purchase="{ row }">
            {{ formatNumber(row.quantity_if_purchase, 1) }} <span class="text-xs text-fg-muted">{{ row.product.unit }}</span>
            <span v-if="row.quantity_interval?.lo != null && row.quantity_interval?.hi != null" class="block text-xs text-fg-muted">{{ t('customers.range80', { lo: formatNumber(row.quantity_interval.lo, 1), hi: formatNumber(row.quantity_interval.hi, 1) }) }}</span>
          </template>
          <template #cell-expected_quantity="{ row }">{{ formatNumber(row.expected_quantity, 2) }} <span class="text-xs text-fg-muted">{{ row.product.unit }}</span></template>
          <template #cell-expected_amount="{ row }">
            <span data-test="amount">{{ formatExpectedAmount(row.expected_amount, tenant.currency) }}</span>
            <span class="block text-xs text-fg-muted" data-test="amount-label">{{ t('customers.amountAssumption') }}</span>
          </template>
          <template #cell-model="{ row }">
            {{ modelText(row) }}
            <span v-if="row.model.role === 'fallback'" class="badge badge-info ml-1">{{ t('customers.fallbackModel') }}</span>
          </template>
          <template #cell-quality_flags="{ row }">
            <span v-if="!row.quality_flags?.length" class="text-fg-muted">—</span>
            <span v-for="f in row.quality_flags" :key="f" class="badge badge-warning mr-1">{{ flagText(f) }}</span>
          </template>
        </DataTable>
        <PaginationBar v-model:page="page" :meta="result.data.value?.meta ?? null" />
        <details v-if="result.data.value?.definitions" class="text-sm">
          <summary class="cursor-pointer text-fg-muted hover:text-fg">{{ t('customers.definitions') }}</summary>
          <dl class="grid gap-x-8 gap-y-2 md:grid-cols-2 mt-2">
            <div v-for="(text, key) in result.data.value.definitions" :key="key"><dt class="font-semibold">{{ te(`customers.defTerms.${key}`) ? t(`customers.defTerms.${key}`) : key }}</dt><dd class="m-0 text-fg-muted">{{ text }}</dd></div>
          </dl>
        </details>
      </template>
      <div v-if="envelope" class="flex flex-col gap-2">
        <div class="flex items-center gap-2"><span class="text-sm text-fg-muted">{{ t('envelope.quality') }}</span><QualityBadge :status="envelope.quality_status" /></div>
        <EnvelopePanel :envelope="envelope" />
      </div>
    </template>
  </section>
</template>
