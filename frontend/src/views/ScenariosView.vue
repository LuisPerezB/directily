<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import ScenarioResults from '@/components/forecast/ScenarioResults.vue'
import { scenariosApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { Horizon, Product, SavedScenario, ScenarioResult } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'
import { HORIZONS } from '@/utils/constants'
import { DEFAULT_SCENARIO_FORM, LIMITS, assumptionsToForm, formToAssumptions, isNeutral } from '@/utils/scenario'

const { t } = useI18n()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()
const canRun = computed(() => tenant.can('scenarios.run'))

const horizon = ref<Horizon>(30)
const branchId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const product = ref<Product | null>(null)
const form = reactive({ ...DEFAULT_SCENARIO_FORM })
const result = ref<ScenarioResult | null>(null)
const simulating = ref(false)
const simError = ref<unknown>(null)
const scenarioName = ref('')
const saving = ref(false)
const toDelete = ref<SavedScenario | null>(null)

const saved = useAsync(() => scenariosApi.list())
const horizonOptions = computed(() => HORIZONS.map((h) => ({ value: h, label: t('common.days', { n: h }) })))

function request() {
  return {
    horizon_days: horizon.value,
    filters: { branch_id: branchId.value, category_id: product.value ? null : categoryId.value, product_id: product.value?.id ?? null },
    assumptions: formToAssumptions(form),
  }
}

async function simulate(): Promise<void> {
  simulating.value = true
  simError.value = null
  try {
    result.value = await scenariosApi.simulate(request())
  } catch (e) {
    simError.value = e
  } finally {
    simulating.value = false
  }
}

async function save(): Promise<void> {
  if (!scenarioName.value.trim()) return
  saving.value = true
  try {
    await scenariosApi.save({ ...request(), name: scenarioName.value.trim() })
    scenarioName.value = ''
    ui.notify(t('scenarios.saved'), 'success')
    await saved.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    saving.value = false
  }
}

function load(s: SavedScenario): void {
  horizon.value = s.horizon_days
  branchId.value = s.filters?.branch_id ?? null
  categoryId.value = s.filters?.category_id ?? null
  product.value = null
  Object.assign(form, assumptionsToForm(s.assumptions))
  result.value = s.results ?? null
  ui.notify(t('scenarios.loadedScenario', { name: s.name }), 'info')
}

async function confirmDelete(): Promise<void> {
  if (!toDelete.value) return
  try {
    await scenariosApi.remove(toDelete.value.id)
    await saved.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    toDelete.value = null
  }
}

function reset(): void {
  Object.assign(form, DEFAULT_SCENARIO_FORM)
  result.value = null
}

// Sin pronóstico vigente la API responde 404 o un resultado sin escenario base (base_run nulo).
const noForecast = computed(
  () => (isApiError(simError.value) && (simError.value.status === 404 || simError.value.code === 'no_forecast')) || (result.value !== null && !result.value.base_run),
)
const sliders = computed(() => [
  { key: 'price_change' as const, label: t('scenarios.priceChange'), hint: t('scenarios.priceChangeHint'), ...LIMITS.price_change },
  { key: 'discount' as const, label: t('scenarios.discount'), hint: t('scenarios.discountHint'), ...LIMITS.discount },
  { key: 'cost_change' as const, label: t('scenarios.costChange'), hint: t('scenarios.costChangeHint'), ...LIMITS.cost_change },
  { key: 'demand_change' as const, label: t('scenarios.demandChange'), hint: t('scenarios.demandChangeHint'), ...LIMITS.demand_change },
])
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('scenarios.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('scenarios.subtitle') }}</p>
    </header>

    <InlineNotice kind="warning" :title="t('scenarios.disclaimerTitle')">{{ t('scenarios.disclaimer') }}</InlineNotice>
    <section class="grid gap-3 md:grid-cols-3" :aria-label="t('scenarios.conceptsLabel')">
      <article v-for="c in ['simulation', 'prediction', 'causal']" :key="c" class="rounded-xl border border-line bg-surface p-4" :class="c === 'simulation' ? 'border-brand' : ''">
        <p class="font-semibold text-sm m-0">{{ t(`scenarios.concepts.${c}.title`) }}</p>
        <p class="text-sm text-fg-muted mt-1 mb-0">{{ t(`scenarios.concepts.${c}.text`) }}</p>
      </article>
    </section>

    <InlineNotice v-if="!canRun" kind="info">{{ t('scenarios.readOnly') }}</InlineNotice>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_1fr]">
      <form class="card p-4 sm:p-6 flex flex-col gap-5" @submit.prevent="simulate">
        <fieldset class="flex flex-col gap-4 border-0 p-0 m-0" :disabled="!canRun">
          <legend class="section-title mb-2">{{ t('scenarios.scope') }}</legend>
          <SegmentedControl v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" />
          <div>
            <label class="label" for="s-branch">{{ t('filters.branch') }}</label>
            <select id="s-branch" v-model="branchId" class="select">
              <option :value="null">{{ t('filters.allBranches') }}</option>
              <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="s-cat">{{ t('filters.category') }}</label>
            <select id="s-cat" v-model="categoryId" class="select">
              <option :value="null">{{ t('filters.allCategories') }}</option>
              <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="s-product">{{ t('filters.product') }}</label>
            <ProductPicker id="s-product" v-model="product" :category-id="categoryId" />
          </div>
        </fieldset>

        <fieldset class="flex flex-col gap-4 border-0 p-0 m-0" :disabled="!canRun">
          <legend class="section-title mb-2">{{ t('scenarios.assumptions') }}</legend>
          <div v-for="sl in sliders" :key="sl.key">
            <div class="flex items-center justify-between gap-2">
              <label class="label !mb-0" :for="`s-${sl.key}`">{{ sl.label }}</label>
              <span class="flex items-center gap-1">
                <input v-model.number="form[sl.key]" type="number" class="input !w-20 !min-h-8 !py-1 text-right tabular" :min="sl.min" :max="sl.max" step="1" :aria-label="`${sl.label} (%)`" />
                <span class="text-sm text-fg-muted">%</span>
              </span>
            </div>
            <input :id="`s-${sl.key}`" v-model.number="form[sl.key]" type="range" class="w-full accent-[var(--p-brand)]" :min="sl.min" :max="sl.max" step="1" :aria-describedby="`s-${sl.key}-hint`" />
            <p :id="`s-${sl.key}-hint`" class="hint">{{ sl.hint }}</p>
          </div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div>
              <label class="label" for="s-lead">{{ t('scenarios.leadTime') }}</label>
              <input id="s-lead" v-model.number="form.lead_time_days" type="number" min="1" max="180" class="input" :placeholder="t('scenarios.leadTimePlaceholder')" />
            </div>
            <div>
              <label class="label" for="s-budget">{{ t('scenarios.budget') }}</label>
              <input id="s-budget" v-model.number="form.purchase_budget" type="number" min="0" step="100" class="input" :placeholder="tenant.currency" />
            </div>
          </div>
          <div>
            <label class="label" for="s-macro">{{ t('scenarios.macroNote') }}</label>
            <textarea id="s-macro" v-model="form.macro_note" rows="2" class="textarea" maxlength="500" :placeholder="t('scenarios.macroPlaceholder')" />
            <p class="hint">{{ t('scenarios.macroHint') }}</p>
          </div>
        </fieldset>

        <div class="flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary" :disabled="!canRun || simulating">
            <AppIcon name="play" :size="16" /> {{ simulating ? t('common.processing') : t('scenarios.simulate') }}
          </button>
          <button type="button" class="btn btn-ghost" :disabled="isNeutral(form) && !result" @click="reset">{{ t('scenarios.reset') }}</button>
        </div>
      </form>

      <div class="flex flex-col gap-6 min-w-0">
        <section class="card p-4 sm:p-6" aria-live="polite">
          <EmptyState v-if="noForecast" icon="chart" :title="t('forecasts.noForecastTitle')" :description="t('scenarios.needForecast')" compact>
            <RouterLink :to="{ name: 'forecasts' }" class="btn btn-primary btn-sm">{{ t('products.goForecasts') }}</RouterLink>
          </EmptyState>
          <ErrorState v-else-if="simError" :error="simError" compact @retry="simulate" />
          <ScenarioResults v-else-if="result" :result="result" :currency="tenant.currency" />
          <EmptyState v-else icon="sliders" :title="t('scenarios.emptyTitle')" :description="t('scenarios.emptyText')" compact />
        </section>

        <section v-if="result && canRun" class="card p-4 sm:p-6">
          <h2 class="section-title mb-3">{{ t('scenarios.saveTitle') }}</h2>
          <form class="flex flex-wrap gap-2" @submit.prevent="save">
            <label class="sr-only" for="s-name">{{ t('scenarios.name') }}</label>
            <input id="s-name" v-model="scenarioName" class="input flex-1 min-w-[12rem]" maxlength="150" :placeholder="t('scenarios.namePlaceholder')" />
            <button type="submit" class="btn btn-secondary" :disabled="saving || !scenarioName.trim()">{{ t('common.save') }}</button>
          </form>
        </section>

        <section class="card p-4 sm:p-6">
          <h2 class="section-title mb-3">{{ t('scenarios.savedTitle') }}</h2>
          <ErrorState v-if="saved.error.value" :error="saved.error.value" compact @retry="saved.execute" />
          <ul v-else-if="saved.data.value?.length" class="flex flex-col divide-y divide-line list-none p-0 m-0">
            <li v-for="sc in saved.data.value" :key="sc.id" class="py-2.5 flex flex-wrap items-center justify-between gap-2">
              <span class="min-w-0">
                <span class="font-medium block">{{ sc.name }}</span>
                <span class="text-xs text-fg-muted">{{ t('common.days', { n: sc.horizon_days }) }} · {{ formatDateTime(sc.created_at, tenant.timezone) }}</span>
              </span>
              <span class="flex gap-1">
                <button type="button" class="btn btn-ghost btn-sm" @click="load(sc)">{{ t('scenarios.load') }}</button>
                <button v-if="canRun" type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" @click="toDelete = sc"><AppIcon name="trash" :size="15" /></button>
              </span>
            </li>
          </ul>
          <p v-else class="text-sm text-fg-muted">{{ t('scenarios.noSaved') }}</p>
        </section>
      </div>
    </div>

    <ConfirmDialog :open="toDelete !== null" :title="t('scenarios.deleteTitle')" :message="t('scenarios.deleteText', { name: toDelete?.name ?? '' })" danger :confirm-label="t('common.delete')" @confirm="confirmDelete" @cancel="toDelete = null" />
  </div>
</template>
