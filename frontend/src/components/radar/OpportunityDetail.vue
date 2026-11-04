<script setup lang="ts">
/**
 * Detalle de una oportunidad del Radar con su explicación verificable, su historial inmutable y el seguimiento (estados heredados de la agenda).
 * El seguimiento REGISTRA lo que una persona hizo: Predictily no envía mensajes ni escribe en el ERP. No muestra el grupo del experimento (seller-facing).
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppModal from '@/components/ui/AppModal.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import FormField from '@/components/ui/FormField.vue'
import CustomerWindowCell from '@/components/customers/CustomerWindowCell.vue'
import OpportunityValue from './OpportunityValue.vue'
import InventoryCell from './InventoryCell.vue'
import ActionErrorNotice from '@/components/enterprise/ActionErrorNotice.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import { radarApi } from '@/api'
import type { AgendaState, RadarEvent, RadarFeedbackResult, RadarOpportunityDetail, StatusMap } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useTenantStore } from '@/stores/tenant'
import { attemptIsUnresolved, classifyActionError, createKeyHolder, type ActionError } from '@/utils/decisions'
import { formatDateTime, formatNumber, formatProbability, todayISO } from '@/utils/format'
import { buildFeedbackBody, feedbackChoices, feedbackInputError, feedbackScope, snoozeBounds } from '@/utils/radar'

const props = defineProps<{ id: number | null; open: boolean }>()
const emit = defineEmits<{ close: []; changed: [] }>()
const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()

const detail = ref<RadarOpportunityDetail | null>(null)
const statusMap = ref<StatusMap | null>(null)
const loading = ref(false)
const loadError = ref<unknown>(null)

// Seguimiento: el estado nuevo, la nota y, al posponer, la fecha de reactivación. La clave de idempotencia se renueva en cada intento resuelto.
const keys = createKeyHolder('radar-feedback')
const state = ref<AgendaState | ''>('')
const note = ref('')
const snoozedUntil = ref('')
const busy = ref(false)
const actionError = ref<ActionError | null>(null)
const snoozeMissing = ref(false)
const lastResult = ref<RadarFeedbackResult['data'] | null>(null)
// Resultado incierto resuelto al recargar: el seguimiento ya figuraba registrado (sin respuesta del servidor que mostrar).
const alreadyApplied = ref(false)

const today = computed(() => todayISO(tenant.timezone))
const choices = computed(() => feedbackChoices(detail.value))
const bounds = computed(() => snoozeBounds(today.value, detail.value?.expires_at))

async function load(): Promise<void> {
  if (props.id === null) return
  loading.value = true
  loadError.value = null
  try {
    const res = await radarApi.opportunity(props.id)
    detail.value = res.data
    statusMap.value = res.status_map ?? null
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

watch(() => [props.open, props.id, tenant.currentUuid] as const, ([open]) => {
  if (!open) return
  detail.value = null
  state.value = ''
  note.value = ''
  snoozedUntil.value = ''
  actionError.value = null
  lastResult.value = null
  alreadyApplied.value = false
  void load()
}, { immediate: true })

async function submit(): Promise<void> {
  // Una sola petición a la vez: un segundo clic mientras la primera sigue en vuelo no envía nada.
  if (busy.value || !detail.value || state.value === '') return
  const input = { state: state.value, note: note.value, snoozedUntil: snoozedUntil.value }
  snoozeMissing.value = feedbackInputError(input) === 'snooze'
  if (snoozeMissing.value) return
  busy.value = true
  actionError.value = null
  alreadyApplied.value = false
  try {
    // La clave es de ESTE seguimiento (oportunidad, estado, nota y fecha): otro seguimiento nunca reutiliza una clave pendiente.
    const res = await radarApi.feedback(detail.value.id, buildFeedbackBody(input, keys.take(feedbackScope(detail.value.id, input))))
    keys.settle()
    detail.value = res.data.opportunity
    lastResult.value = res.data
    state.value = ''
    note.value = ''
    snoozedUntil.value = ''
    emit('changed')
  } catch (e) {
    if (!attemptIsUnresolved(e)) keys.settle()
    actionError.value = classifyActionError(e)
    // Otra persona pudo mover la oportunidad: se recarga para mostrar el estado vigente.
    if (['conflict', 'validation', 'not_allowed', 'not_found'].includes(actionError.value.kind)) {
      void load()
    } else if (attemptIsUnresolved(e)) {
      // Resultado incierto (red caída, 5xx): antes de otra acción se recarga para ver si el seguimiento llegó a registrarse.
      await load()
      if (detail.value?.state === input.state) {
        keys.settle()
        actionError.value = null
        alreadyApplied.value = true
        state.value = ''
        note.value = ''
        snoozedUntil.value = ''
        emit('changed')
      }
    }
  } finally {
    busy.value = false
  }
}

function eventLine(e: RadarEvent): string {
  if (e.event === 'feedback') {
    const from = e.from_agenda_status && te(`radar.state.${e.from_agenda_status}`) ? t(`radar.state.${e.from_agenda_status}`) : null
    const to = e.agenda_status && te(`radar.state.${e.agenda_status}`) ? t(`radar.state.${e.agenda_status}`) : (e.agenda_status ?? '—')
    return from ? t('radar.history.feedbackFromTo', { from, to }) : t('radar.history.feedbackTo', { to })
  }
  const to = e.to_status && te(`decisions.status.${e.to_status}`) ? t(`decisions.status.${e.to_status}`) : (e.to_status ?? '—')
  return t(`radar.history.${e.event}`, { to })
}
function syncText(e: RadarEvent): string | null {
  if (!e.workflow_sync || e.workflow_sync === 'not_applicable') return null
  const reason = e.workflow_sync_reason && te(`radar.syncReasons.${e.workflow_sync_reason}`) ? t(`radar.syncReasons.${e.workflow_sync_reason}`) : (e.workflow_sync_reason ?? '')
  return e.workflow_sync === 'applied'
    ? t('radar.sync.applied', { to: e.workflow_status_after && te(`decisions.status.${e.workflow_status_after}`) ? t(`decisions.status.${e.workflow_status_after}`) : (e.workflow_status_after ?? '—') })
    : t(`radar.sync.${e.workflow_sync}`, { reason })
}
const resultSync = computed(() => (lastResult.value ? syncText(lastResult.value.event) : null))
const explanation = computed(() => (detail.value?.explanation ?? []).filter((x) => typeof x.label === 'string' || typeof x.code === 'string'))
const costReasons = computed(() => [...new Set(detail.value?.value.cost_reasons ?? [])])
</script>

<template>
  <AppModal :open="open" :title="t('radar.detailTitle', { id: id ?? '' })" size="lg" :dismissible="!busy" @close="emit('close')">
    <LoadingState v-if="loading && !detail" :lines="4" />
    <ErrorState v-else-if="loadError && !detail" :error="loadError" compact @retry="load" />
    <div v-else-if="detail" class="flex flex-col gap-4" data-test="opportunity-detail">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <RouterLink :to="{ name: 'customer-detail', params: { id: String(detail.customer.id) } }" class="font-semibold">{{ detail.customer.code ?? `#${detail.customer.id}` }}</RouterLink>
          <p class="m-0 text-sm">{{ detail.product ? (detail.product.name ?? `#${detail.product.id}`) : t('radar.wholeCustomer') }}</p>
          <p class="m-0 text-xs text-fg-muted">{{ t(`decisions.kind.${detail.kind}`) }} · {{ t('common.days', { n: detail.horizon_days }) }} · {{ t('radar.priority', { n: detail.priority }) }}</p>
        </div>
        <div class="flex flex-col items-end gap-1">
          <WorkflowBadge domain="agenda" :value="detail.state" />
          <WorkflowBadge domain="workflow" :value="detail.status" />
        </div>
      </header>
      <ProvenanceLine :as-of="detail.as_of" :unit="detail.product?.unit ?? t('radar.unitOpportunities')" :currency="tenant.currency" />

      <section class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-line p-3"><h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('radar.cols.value') }}</h3><OpportunityValue :opportunity="detail" /></div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('radar.cols.signal') }}</h3>
          <p v-if="detail.entity_type === 'customer'" class="m-0 text-sm text-fg-muted">{{ t('radar.customerLevel') }}</p>
          <template v-else>
            <p class="m-0 text-sm"><span v-if="detail.probability === null" class="text-fg-muted">{{ t('customers.noProbability') }}</span><span v-else class="font-semibold">{{ formatProbability(detail.probability) }}</span></p>
            <p v-if="detail.expected_orders !== null" class="m-0 text-xs text-fg-muted">{{ t('radar.expectedOrders', { n: formatNumber(detail.expected_orders, 2) }) }}</p>
            <p v-if="detail.expected_units !== null" class="m-0 text-xs text-fg-muted">{{ t('radar.expectedUnits', { n: formatNumber(detail.expected_units, 1), unit: detail.product?.unit ?? '' }) }}</p>
            <div class="mt-2"><CustomerWindowCell :window="detail.window" /></div>
          </template>
        </div>
        <div class="rounded-xl border border-line p-3"><h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('radar.cols.inventory') }}</h3><InventoryCell :inventory="detail.inventory" :unit="detail.product?.unit" /></div>
      </section>

      <InlineNotice v-if="detail.value.cost_status !== 'reliable' && detail.entity_type === 'customer_product'" kind="warning" data-test="cost-notice">
        {{ detail.value.cost_status === 'unreliable' ? t('radar.costUnreliable') : t('radar.costUnknown') }}
        <span v-if="costReasons.length"> {{ costReasons.map((r) => (te(`enterprise.reasons.${r}`) ? t(`enterprise.reasons.${r}`) : r)).join(' · ') }}</span>
      </InlineNotice>
      <p v-if="detail.activity" class="m-0 text-sm text-fg-muted" data-test="activity">{{ t('radar.activity', { status: te(`customers.profile.inactivity.${detail.activity.inactivity_status}`) ? t(`customers.profile.inactivity.${detail.activity.inactivity_status}`) : detail.activity.inactivity_status }) }}</p>
      <p v-if="detail.next_step" class="m-0 text-sm"><span class="font-semibold">{{ t('radar.cols.next') }}:</span> {{ detail.next_step }}</p>

      <details v-if="explanation.length" class="rounded-xl border border-line p-3">
        <summary class="cursor-pointer text-sm font-semibold">{{ t('radar.explanation') }}</summary>
        <ul class="list-disc pl-5 mt-2 mb-0 text-sm flex flex-col gap-1">
          <li v-for="(x, i) in explanation" :key="i">{{ (x.label as string | undefined) ?? (x.code as string) }}<span v-if="typeof x.source === 'string'" class="text-xs text-fg-muted"> · {{ x.source }}</span></li>
        </ul>
      </details>

      <section v-if="detail.history.length" :aria-label="t('radar.history.title')">
        <h3 class="section-title m-0 mb-2">{{ t('radar.history.title') }}</h3>
        <ol class="list-none p-0 m-0 flex flex-col gap-2" data-test="history">
          <li v-for="e in detail.history" :key="e.id" class="rounded-lg bg-surface-2 px-3 py-2 text-sm">
            <p class="m-0 font-medium">{{ eventLine(e) }}</p>
            <p class="m-0 text-xs text-fg-muted">{{ formatDateTime(e.at, tenant.timezone) }} · {{ e.actor_kind === 'system' ? t('enterprise.system') : t('enterprise.userN', { id: e.actor_user_id ?? '?' }) }}</p>
            <p v-if="e.note" class="m-0 text-sm">{{ e.note }}</p>
            <p v-if="e.snoozed_until" class="m-0 text-xs text-fg-muted">{{ t('radar.snoozedUntil', { date: e.snoozed_until }) }}</p>
            <p v-if="syncText(e)" class="m-0 text-xs text-fg-muted" data-test="sync">{{ syncText(e) }}</p>
          </li>
        </ol>
      </section>

      <section v-if="can('decisions.manage')" class="rounded-xl border border-line p-4 flex flex-col gap-3" :aria-label="t('radar.feedback.title')" data-test="feedback-form">
        <h3 class="section-title m-0">{{ t('radar.feedback.title') }}</h3>
        <InlineNotice kind="info">{{ t('radar.feedback.noSend') }}</InlineNotice>
        <p v-if="!choices.length" class="m-0 text-sm text-fg-muted" data-test="no-feedback">{{ t('radar.feedback.none') }}</p>
        <template v-else>
          <FormField id="fb-state" :label="t('radar.feedback.state')">
            <template #default="{ describedby }">
              <select id="fb-state" v-model="state" class="select" :aria-describedby="describedby">
                <option value="">{{ t('radar.feedback.choose') }}</option>
                <option v-for="c in choices" :key="c" :value="c" :disabled="c === 'pospuesto' && !bounds.available">{{ t(`radar.state.${c}`) }}{{ c === 'pospuesto' && !bounds.available ? ` (${t('radar.feedback.snoozeUnavailableShort')})` : '' }}</option>
              </select>
            </template>
          </FormField>
          <p v-if="choices.includes('pospuesto') && !bounds.available" class="m-0 text-sm text-fg-muted" data-test="snooze-unavailable">{{ t('radar.feedback.snoozeUnavailable') }}</p>
          <FormField v-if="state === 'pospuesto' && bounds.available" id="fb-snooze" :label="t('radar.feedback.snoozeUntil')" :hint="t('radar.feedback.snoozeHint')" :error="snoozeMissing ? t('radar.feedback.snoozeRequired') : null" required>
            <template #default="{ describedby, invalid }">
              <input id="fb-snooze" v-model="snoozedUntil" type="date" class="input" :min="bounds.min" :max="bounds.max" :aria-describedby="describedby" :aria-invalid="invalid">
            </template>
          </FormField>
          <FormField id="fb-note" :label="t('radar.feedback.note')" :hint="t('radar.feedback.noteHint')">
            <template #default="{ describedby }">
              <textarea id="fb-note" v-model="note" class="textarea" rows="2" maxlength="500" :aria-describedby="describedby" />
            </template>
          </FormField>
          <ActionErrorNotice v-if="actionError" :error="actionError" />
          <InlineNotice v-if="alreadyApplied" kind="info" data-test="feedback-applied">{{ t('radar.feedback.alreadyApplied') }}</InlineNotice>
          <InlineNotice v-if="lastResult" kind="success" data-test="feedback-done">
            {{ lastResult.replayed ? t('radar.feedback.replayed') : t('radar.feedback.saved') }}
            <span v-if="resultSync" class="block text-sm" data-test="feedback-sync">{{ resultSync }}</span>
          </InlineNotice>
          <div><button type="button" class="btn btn-primary" :disabled="busy || state === ''" data-test="submit-feedback" @click="submit">{{ t('radar.feedback.submit') }}</button></div>
        </template>
      </section>
      <p v-else class="m-0 text-sm text-fg-muted" data-test="read-only">{{ t('radar.feedback.readOnly') }}</p>

      <details v-if="statusMap" class="rounded-xl border border-line p-3">
        <summary class="cursor-pointer text-sm font-semibold">{{ t('radar.statusMap.title', { version: statusMap.version }) }}</summary>
        <div class="table-wrap mt-2">
          <table class="table">
            <caption class="sr-only">{{ t('radar.statusMap.title', { version: statusMap.version }) }}</caption>
            <thead><tr><th scope="col">{{ t('radar.statusMap.legacy') }}</th><th scope="col">{{ t('radar.statusMap.workflow') }}</th><th scope="col">{{ t('radar.statusMap.note') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in statusMap.table" :key="row.legacy">
                <td><WorkflowBadge domain="agenda" :value="row.legacy" /></td>
                <td><WorkflowBadge v-if="row.workflow" domain="workflow" :value="row.workflow" /><span v-else class="text-fg-muted text-sm">{{ t('radar.statusMap.noChange') }}</span></td>
                <td class="text-sm">{{ row.note }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>
    <template #footer><button type="button" class="btn btn-secondary" @click="emit('close')">{{ t('common.close') }}</button></template>
  </AppModal>
</template>
