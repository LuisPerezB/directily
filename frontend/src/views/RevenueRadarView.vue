<script setup lang="ts">
/**
 * Revenue Radar y Agenda Comercial (V2.5 · W5-E; ADR-V25-12 y -22): oportunidades cliente × producto con probabilidad, ventana de recompra, valor (pronóstico
 * rotulado «atribución no demostrada»), disponibilidad y próxima acción, y la agenda por cliente con los estados heredados de la V2.2. Solo lee
 * /radar/summary, /radar/opportunities, /radar/agenda y /radar/opportunities/{id}; el seguimiento (POST …/feedback) lo registra una persona con
 * `decisions.manage`. Predictily no envía mensajes ni escribe en el ERP. No muestra el grupo del experimento en ninguna parte.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import TabsNav from '@/components/ui/TabsNav.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import DataOriginBand from '@/components/enterprise/DataOriginBand.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import RadarSummaryPanel from '@/components/radar/RadarSummaryPanel.vue'
import OpportunityTable from '@/components/radar/OpportunityTable.vue'
import OpportunityDetail from '@/components/radar/OpportunityDetail.vue'
import AgendaPanel from '@/components/radar/AgendaPanel.vue'
import ActionErrorNotice from '@/components/enterprise/ActionErrorNotice.vue'
import { radarApi } from '@/api'
import { isApiError } from '@/api/errors'
import type { AgendaState, Product, RadarFilterQuery, RadarKind, RadarMaterialization, WorkflowStatus } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useHorizonSelection } from '@/composables/useHorizons'
import { useAbility } from '@/composables/useAbility'
import { useAuthStore } from '@/stores/auth'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { CUSTOMER_HORIZONS } from '@/utils/constants'
import { classifyActionError, type ActionError } from '@/utils/decisions'
import { AGENDA_STATES } from '@/utils/radar'
import type { CustomerHorizon } from '@/api/types'

const props = defineProps<{ tab?: string }>()
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const tenant = useTenantStore()
const auth = useAuthStore()
const catalog = useCatalogStore()
const { can } = useAbility()

const TABS = ['oportunidades', 'agenda'] as const
const tabs = computed(() => TABS.map((key) => ({ key, label: t(`radar.tabs.${key}`) })))
const active = computed({
  get: () => (TABS.includes(props.tab as (typeof TABS)[number]) ? props.tab! : 'oportunidades'),
  set: (key: string) => void router.replace({ name: 'radar', params: { tab: key }, query: route.query }),
})

// Starter desactiva el comportamiento de compra (`features.customer = false`): el Radar es una fachada sobre el Customer Core y hereda la regla del plan.
const included = computed(() => tenant.tenant?.plan?.limits?.features?.customer !== false)
const { allowed: allowedHorizons } = useHorizonSelection<CustomerHorizon>(CUSTOMER_HORIZONS, 30)

// ─── Filtros ───
const horizon = ref<number | null>(null)
const kind = ref<RadarKind | ''>('')
const state = ref<AgendaState | ''>('')
const status = ref<WorkflowStatus | ''>('')
const product = ref<Product | null>(null)
const categoryId = ref<number | null>(null)
const zone = ref('')
const mine = ref(false)
const orderBy = ref<NonNullable<RadarFilterQuery['order_by']>>('score')
const customerId = computed(() => {
  const raw = Array.isArray(route.query.cliente) ? route.query.cliente[0] : route.query.cliente
  const n = Number(raw)
  return Number.isInteger(n) && n > 0 ? n : null
})
const statuses: WorkflowStatus[] = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed', 'rejected', 'expired']

const filters = computed<RadarFilterQuery>(() => ({
  horizon: horizon.value ?? undefined,
  kind: kind.value || undefined,
  state: state.value || undefined,
  status: status.value || undefined,
  product_id: product.value?.id,
  category_id: categoryId.value ?? undefined,
  zone: zone.value.trim() || undefined,
  seller_id: mine.value ? auth.user?.id : undefined,
  customer_id: customerId.value ?? undefined,
}))
const page = ref(1)
watch([filters, orderBy], () => (page.value = 1), { deep: true })
watch(() => tenant.currentUuid, () => {
  horizon.value = null
  product.value = null
  categoryId.value = null
  page.value = 1
})

const refreshKey = ref(0)
// Con un plan sin Customer no se consulta: el servidor respondería 402 en cada filtro.
const summary = useAsync(() => (included.value ? radarApi.summary(filters.value) : Promise.resolve(null)), { watch: [filters, refreshKey], debounce: 250, scope: () => tenant.currentUuid })
const list = useAsync(
  () => (included.value ? radarApi.opportunities({ ...filters.value, order_by: orderBy.value, direction: 'desc', page: page.value, per_page: 25 }) : Promise.resolve(null)),
  { watch: [filters, orderBy, page, refreshKey], debounce: 250, scope: () => tenant.currentUuid },
)
const currency = computed(() => tenant.currency)
const planBlocked = computed(() => !included.value || (isApiError(summary.error.value) && summary.error.value.isPlanLimit))
const failure = computed(() => (planBlocked.value ? null : summary.error.value ?? list.error.value))

// ─── Detalle y seguimiento (una persona registra lo que hizo) ───
const selected = ref<number | null>(null)
function openDetail(id: number): void {
  selected.value = id
}
function changed(): void {
  refreshKey.value++
}

// ─── Generar oportunidades (propone al Decision Engine; no envía nada) ───
const materializing = ref(false)
const materialized = ref<RadarMaterialization | null>(null)
const materializeError = ref<ActionError | null>(null)
async function materialize(): Promise<void> {
  materializing.value = true
  materializeError.value = null
  try {
    materialized.value = await radarApi.materialize()
    refreshKey.value++
  } catch (e) {
    materializeError.value = classifyActionError(e)
  } finally {
    materializing.value = false
  }
}
const emptyList = computed(() => list.loaded.value && (list.data.value?.data.length ?? 0) === 0)
const noOpportunities = computed(() => summary.loaded.value && summary.data.value?.data.totals.opportunities === 0 && !!summary.data.value?.message)
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('radar.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('radar.subtitle') }}</p>
    </header>

    <DataOriginBand />
    <InlineNotice kind="info" :title="t('radar.noSendTitle')">{{ t('radar.noSendNotice') }}</InlineNotice>
    <InlineNotice v-if="planBlocked" kind="warning" data-test="radar-not-in-plan">{{ t('radar.notInPlan') }}</InlineNotice>

    <template v-else>
      <section class="card p-4 sm:p-6 flex flex-col gap-4" :aria-label="t('filters.title')" data-test="radar-filters">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="label" for="rf-horizon">{{ t('common.horizon') }}</label>
            <select id="rf-horizon" v-model="horizon" class="select">
              <option :value="null">{{ t('radar.filters.allHorizons') }}</option>
              <option v-for="h in allowedHorizons" :key="h" :value="h">{{ t('common.days', { n: h }) }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="rf-kind">{{ t('radar.filters.kind') }}</label>
            <select id="rf-kind" v-model="kind" class="select">
              <option value="">{{ t('radar.filters.all') }}</option>
              <option v-for="k in ['contact', 'recovery', 'customer_risk']" :key="k" :value="k">{{ t(`decisions.kind.${k}`) }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="rf-state">{{ t('radar.filters.state') }}</label>
            <select id="rf-state" v-model="state" class="select">
              <option value="">{{ t('radar.filters.worklist') }}</option>
              <option v-for="s in AGENDA_STATES" :key="s" :value="s">{{ t(`radar.state.${s}`) }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="rf-status">{{ t('radar.filters.status') }}</label>
            <select id="rf-status" v-model="status" class="select">
              <option value="">{{ t('radar.filters.worklist') }}</option>
              <option v-for="s in statuses" :key="s" :value="s">{{ t(`decisions.status.${s}`) }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="rf-product">{{ t('filters.product') }}</label>
            <ProductPicker id="rf-product" v-model="product" />
          </div>
          <div>
            <label class="label" for="rf-category">{{ t('filters.category') }}</label>
            <select id="rf-category" v-model="categoryId" class="select">
              <option :value="null">{{ t('filters.allCategories') }}</option>
              <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="rf-zone">{{ t('radar.filters.zone') }}</label>
            <input id="rf-zone" v-model="zone" class="input" type="text" maxlength="80" autocomplete="off">
          </div>
          <div>
            <label class="label" for="rf-order">{{ t('customers.orderBy') }}</label>
            <select id="rf-order" v-model="orderBy" class="select">
              <option v-for="o in ['score', 'priority', 'expected_gross_value', 'expected_contribution', 'probability', 'horizon_days']" :key="o" :value="o">{{ t(`radar.order.${o}`) }}</option>
            </select>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-4">
          <label class="inline-flex items-center gap-2 text-sm"><input v-model="mine" type="checkbox" data-test="filter-mine"> {{ t('radar.filters.mine') }}</label>
          <span v-if="customerId" class="badge" data-test="customer-chip">
            {{ t('radar.filters.customer', { id: customerId }) }}
            <button type="button" class="ml-1 underline" :aria-label="t('radar.filters.clearCustomer')" @click="router.replace({ name: 'radar', params: { tab: active }, query: {} })">×</button>
          </span>
        </div>
      </section>

      <LoadingState v-if="summary.loading.value && !summary.data.value" :lines="3" />
      <div v-else-if="failure" class="card"><ErrorState :error="failure" @retry="changed" /></div>
      <template v-else>
        <section v-if="summary.data.value" class="card p-4 sm:p-6">
          <RadarSummaryPanel :summary="summary.data.value.data" :envelope="summary.data.value.envelope" :message="summary.data.value.message" />
          <div v-if="noOpportunities && can('forecast.run')" class="mt-4 flex flex-col gap-2 items-start">
            <button type="button" class="btn btn-primary" :disabled="materializing" data-test="materialize" @click="materialize">{{ t('radar.materialize.button') }}</button>
            <p class="hint m-0">{{ t('radar.materialize.hint') }}</p>
          </div>
          <InlineNotice v-if="materialized" kind="success" class="mt-3" data-test="materialized">{{ t('radar.materialize.done', { created: materialized.created, existing: materialized.existing, failed: materialized.failed }) }}</InlineNotice>
          <div v-if="materializeError" class="mt-3"><ActionErrorNotice :error="materializeError" /></div>
        </section>

        <TabsNav v-model="active" :tabs="tabs" :label="t('radar.title')" id-prefix="radar" />
        <section id="radar-panel" role="tabpanel" :aria-labelledby="`radar-${active}`" class="card p-4 sm:p-6">
          <template v-if="active === 'oportunidades'">
            <ProvenanceLine :as-of="list.data.value?.envelope?.as_of" :unit="t('radar.unitOpportunities')" :currency="currency" :hide-origin="false" />
            <EmptyState v-if="emptyList" icon="users" :title="t('radar.emptyTitle')" :description="t('radar.emptyText')" data-test="radar-empty" />
            <div v-else class="mt-3">
              <OpportunityTable :rows="list.data.value?.data ?? []" :loading="list.loading.value" :currency="currency" :caption="t('radar.tabs.oportunidades')" @open="openDetail" @feedback="openDetail" />
              <PaginationBar v-model:page="page" :meta="list.data.value?.meta ?? null" />
            </div>
          </template>
          <AgendaPanel v-else :filters="filters" :currency="currency" :refresh-key="refreshKey" @open="openDetail" @feedback="openDetail" />
        </section>
      </template>
    </template>

    <OpportunityDetail :id="selected" :open="selected !== null" @close="selected = null" @changed="changed" />
  </div>
</template>
