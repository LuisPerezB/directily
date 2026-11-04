<script setup lang="ts">
/**
 * Detalle de una recomendación del Decision Engine con su contrato completo (§11), su línea de tiempo y las transiciones del workflow.
 *
 * - Cada botón se ofrece solo si el rol tiene la habilidad de la transición (`decisions.manage`, `decisions.approve`); el servidor sigue siendo la
 *   autoridad: se atienden 403 (sin habilidad o segregación de funciones), 409 (versión desfasada, transición no permitida, aprobación pendiente) y 422.
 * - Cada acción lleva `expected_version` (la versión leída) y una `idempotency_key` nueva; con un 409 de versión se recarga y se avisa que otra persona la cambió.
 * - Una cifra `null` se dice «No estimada» (nunca 0). Siempre se rotula «oportunidad potencial, atribución no demostrada» mientras no haya evidencia de incremento.
 * - El grupo del experimento SOLO se muestra aquí y SOLO a quien tiene `decisions.approve`.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import TransitionDialog from './TransitionDialog.vue'
import { decisionApi, tenantApi } from '@/api'
import type { DecisionAllowedTransition, DecisionRecommendation, RadarInventory, TenantUser } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { roleCan } from '@/utils/abilities'
import { actionScope, attemptIsUnresolved, buildTransitionBody, classifyActionError, constraintKey, createKeyHolder, transitionDisposition, type ActionError, type TransitionInput } from '@/utils/decisions'
import { shortHash } from '@/utils/envelope'
import { formatDateTime, formatNumber, formatProbability } from '@/utils/format'
import { isKnown } from '@/utils/enterprise'
import { inventoryView } from '@/utils/radar'

const props = defineProps<{ id: number }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t, te } = useI18n()
const { can } = useAbility()
const auth = useAuthStore()
const tenant = useTenantStore()

const rec = ref<DecisionRecommendation | null>(null)
const loading = ref(false)
const loadError = ref<unknown>(null)
const notice = ref<'conflict' | 'replay' | 'done' | 'applied' | null>(null)

async function load(keepOnError = false): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    rec.value = await decisionApi.show(props.id)
  } catch (e) {
    loadError.value = e
    // Al recargar tras una acción de resultado incierto se conserva lo que ya se mostraba; en cualquier otro caso no queda nada de lo anterior.
    if (!keepOnError) rec.value = null
  } finally {
    loading.value = false
  }
}
watch(() => [props.id, tenant.currentUuid] as const, () => {
  // Otra recomendación u otra empresa: nada de lo anterior queda en pantalla mientras llega lo nuevo.
  notice.value = null
  rec.value = null
  void load()
}, { immediate: true })

// ─── Qué se muestra ───
const r = computed(() => rec.value)
const canSeeExperiment = computed(() => can('decisions.approve'))
const entity = computed(() => {
  const x = r.value
  if (!x) return ''
  const parts: string[] = []
  if (x.customer) parts.push(x.customer.external_code ?? `#${x.customer.id}`)
  if (x.product) parts.push(x.product.name ?? x.product.sku ?? `#${x.product.id}`)
  if (x.branch) parts.push(x.branch.name ?? x.branch.code ?? `#${x.branch.id}`)
  return parts.join(' · ')
})
const nullContributionReasons = computed(() => {
  const u = r.value?.uncertainty as { expected_contribution_null_because?: unknown; cost?: { reasons?: unknown } } | undefined
  const raw = Array.isArray(u?.expected_contribution_null_because) ? u.expected_contribution_null_because : Array.isArray(u?.cost?.reasons) ? u.cost.reasons : []
  return (raw as unknown[]).filter((x): x is string => typeof x === 'string')
})
const inventory = computed(() => inventoryView((r.value?.availability ?? {}) as RadarInventory))
const interval = computed(() => Object.keys(r.value?.interval ?? {}).length > 0)
const explanation = computed(() => (r.value?.explanation ?? []).filter((x) => typeof x.label === 'string' || typeof x.code === 'string'))
// Restricciones: por `code` (Decision Engine y Radar) o por `family` (restricciones activas del plan de compras); sin texto propio se muestra el código tal cual.
const constraints = computed(() => {
  const seen = new Map<string, string>()
  for (const c of r.value?.constraints ?? []) {
    const k = constraintKey(c)
    if (!k) continue
    const i18nKey = k.kind === 'code' ? `decisions.constraint.${k.key}` : `supply.families.${k.key}`
    seen.set(`${k.kind}:${k.key}`, te(i18nKey) ? t(i18nKey) : k.key)
  }
  return [...seen.entries()].map(([id, label]) => ({ id, label }))
})

// Quién ejecuta/gestiona: solo el id llega en la API; "usted" si es el usuario actual.
function who(id: number | null | undefined): string {
  if (!id) return t('decisions.nobody')
  return id === auth.user?.id ? t('decisions.you') : t('enterprise.userN', { id })
}

// ─── Transiciones ───
const transitions = computed(() => (r.value?.allowed_transitions ?? []).map((tr) => ({ tr, mode: transitionDisposition(tr, can, r.value ?? undefined) })).filter((x) => x.mode !== 'hidden'))
function blockedText(tr: DecisionAllowedTransition): string | null {
  if (tr.allowed) return null
  const code = tr.blocked_by
  if (!code) return null
  return te(`decisions.blocked.${code}`) ? t(`decisions.blocked.${code}`) : code
}

const pending = ref<DecisionAllowedTransition | null>(null)
const busy = ref(false)
const actionError = ref<ActionError | null>(null)
const keys = createKeyHolder('decision')
const users = ref<TenantUser[]>([])

const assignees = computed(() => {
  const me = auth.user?.id
  const list: { id: number; label: string }[] = me ? [{ id: me, label: t('decisions.assignToMe') }] : []
  for (const u of users.value) if (u.id !== me && roleCan(u.role, 'decisions.manage')) list.push({ id: u.id, label: u.name })
  return list
})

async function begin(tr: DecisionAllowedTransition): Promise<void> {
  actionError.value = null
  pending.value = tr
  // Solo quien administra usuarios puede listarlos; el resto se asigna a sí mismo.
  if (tr.requires.assignee && can('users.manage') && users.value.length === 0) {
    try {
      users.value = await tenantApi.users()
    } catch {
      users.value = []
    }
  }
}

async function confirm(input: { reason: string; assigneeUserId: number | null }): Promise<void> {
  const tr = pending.value
  const current = rec.value
  // Una sola petición a la vez: un segundo clic (o un evento repetido) mientras la primera sigue en vuelo no envía nada.
  if (busy.value || !tr || !current) return
  busy.value = true
  actionError.value = null
  try {
    const draft = buildTransitionBody(current, tr, { reason: input.reason, assigneeUserId: input.assigneeUserId } satisfies TransitionInput, '')
    // La clave es de ESTA acción (recomendación, transición, versión leída y cuerpo): otra acción nunca reutiliza una clave pendiente.
    const body = { ...draft, idempotency_key: keys.take(actionScope(current.id, draft)) }
    const res = await decisionApi.transition(current.id, body)
    keys.settle()
    rec.value = res.data
    notice.value = res.meta.idempotent_replay ? 'replay' : 'done'
    pending.value = null
    emit('changed')
  } catch (e) {
    if (!attemptIsUnresolved(e)) keys.settle()
    const err = classifyActionError(e)
    actionError.value = err
    // Otra persona pudo mover la recomendación entre la lectura y la acción: se recarga con la versión vigente y se avisa.
    if (['version_conflict', 'not_allowed', 'control', 'not_found'].includes(err.kind)) {
      pending.value = null
      notice.value = err.kind === 'version_conflict' ? 'conflict' : null
      await load()
      emit('changed')
    } else if (attemptIsUnresolved(e)) {
      // Resultado incierto (red caída, 5xx): antes de permitir otra acción se recarga para ver si la anterior llegó a aplicarse. Si la transición ya no está
      // disponible, se cierra el cuadro; si sigue disponible, el reintento conserva la clave solo si la acción y la versión son las mismas.
      await load(true)
      const now = rec.value
      const stillAllowed = !!now?.allowed_transitions.some((x) => x.action === tr.action && x.allowed)
      if (!stillAllowed) {
        pending.value = null
        notice.value = now?.status === tr.to_status ? 'applied' : 'conflict'
        actionError.value = null
        emit('changed')
      }
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('decisions.detail.title')" data-test="decision-detail">
    <LoadingState v-if="loading && !r" :lines="5" />
    <ErrorState v-else-if="loadError && !r" :error="loadError" compact @retry="load" />
    <template v-else-if="r">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="section-title m-0">{{ t('decisions.detail.heading', { id: r.id, kind: t(`decisions.kind.${r.kind}`) }) }}</h2>
          <p class="m-0 text-sm text-fg-muted break-words">{{ entity || '—' }}</p>
        </div>
        <div class="flex flex-col items-end gap-1">
          <WorkflowBadge domain="workflow" :value="r.status" />
          <span class="text-xs text-fg-muted">{{ t('radar.priority', { n: r.priority }) }}</span>
        </div>
      </header>
      <ProvenanceLine :as-of="r.as_of" :unit="t('decisions.unitRecommendation')" :currency="r.currency" :uncertainty="interval ? t('decisions.hasInterval') : t('decisions.noInterval')" :coverage="r.source.data_snapshot_id ? t('decisions.snapshot', { id: r.source.data_snapshot_id }) : null" />

      <InlineNotice v-if="notice === 'conflict'" kind="warning" data-test="conflict-notice">{{ t('enterprise.errors.version_conflict') }}</InlineNotice>
      <InlineNotice v-else-if="notice === 'replay'" kind="info" data-test="replay-notice">{{ t('decisions.replay') }}</InlineNotice>
      <InlineNotice v-else-if="notice === 'applied'" kind="info" data-test="applied-notice">{{ t('decisions.alreadyApplied') }}</InlineNotice>
      <InlineNotice v-else-if="notice === 'done'" kind="success" data-test="done-notice">{{ t('decisions.done') }}</InlineNotice>

      <InlineNotice kind="warning" :title="t(`enterprise.attribution.${r.attribution_label}`)" data-test="attribution-note">{{ r.attribution_label === 'potential_unproven' || !r.attribution_note ? t('enterprise.attributionNote') : r.attribution_note }}</InlineNotice>

      <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" :aria-label="t('decisions.detail.value')">
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.grossValue') }}</h3>
          <p class="m-0 font-semibold" data-test="gross"><MoneyFigure :value="r.expected_gross_value" :currency="r.currency" /></p>
          <p class="m-0 text-xs text-fg-muted">{{ t('decisions.detail.grossHint') }}</p>
        </div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.contribution') }}</h3>
          <p class="m-0 font-semibold" data-test="contribution"><MoneyFigure :value="r.expected_contribution" :currency="r.currency" :reasons="nullContributionReasons" :unknown-label="nullContributionReasons.length ? t('enterprise.unreliableCost') : t('enterprise.notEstimatedF')" /></p>
        </div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.actionCost') }}</h3>
          <p class="m-0 font-semibold" data-test="action-cost"><MoneyFigure :value="r.action_cost" :currency="r.currency" :unknown-label="t('decisions.detail.costNotConfigured')" /></p>
        </div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.incremental') }}</h3>
          <p class="m-0 font-semibold" data-test="incremental"><MoneyFigure :value="r.incremental_estimate" :currency="r.currency" :unknown-label="t('decisions.detail.noUplift')" /></p>
        </div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.score') }}</h3>
          <p class="m-0 font-semibold" data-test="score">{{ isKnown(r.score) ? formatNumber(r.score, 2) : t('decisions.detail.noScore') }}</p>
          <p class="m-0 text-xs text-fg-muted" :title="r.score_formula_version">{{ t('decisions.detail.formula', { v: shortHash(r.score_formula_version, 12) ?? '—' }) }}</p>
        </div>
        <div class="rounded-xl border border-line p-3">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('decisions.detail.probability') }}</h3>
          <p class="m-0 font-semibold" data-test="probability">{{ r.probability === null ? t('decisions.detail.noProbability') : formatProbability(r.probability) }}</p>
        </div>
      </section>

      <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 text-sm m-0">
        <div><dt class="text-fg-muted">{{ t('decisions.detail.availability') }}</dt>
          <dd class="m-0" data-test="availability">
            <template v-if="inventory.status === 'known'">{{ inventory.available !== null ? t('radar.inventory.available', { n: formatNumber(inventory.available, 1), unit: r.product?.unit ?? '' }) : t('enterprise.notEstimated') }}<span v-if="inventory.inTransit" class="text-xs text-fg-muted"> · {{ t('radar.inventory.inTransit', { n: formatNumber(inventory.inTransit, 1) }) }}</span></template>
            <span v-else class="text-fg-muted">{{ t('radar.inventory.unknown') }}</span>
          </dd></div>
        <div><dt class="text-fg-muted">{{ t('decisions.detail.assignedTo') }}</dt><dd class="m-0">{{ who(r.assigned_user_id) }}</dd></div>
        <div><dt class="text-fg-muted">{{ t('decisions.detail.proposedBy') }}</dt><dd class="m-0">{{ r.proposed_by.kind === 'system' ? t('enterprise.system') : who(r.proposed_by.user_id) }}</dd></div>
        <div><dt class="text-fg-muted">{{ t('decisions.detail.expires') }}</dt><dd class="m-0">{{ r.expires_at ? formatDateTime(r.expires_at, tenant.timezone) : '—' }}</dd></div>
        <div><dt class="text-fg-muted">{{ t('decisions.detail.requiresApproval') }}</dt><dd class="m-0" data-test="requires-approval">{{ r.requires_approval === null ? '—' : r.requires_approval ? t('common.yes') : t('common.no') }}</dd></div>
        <div><dt class="text-fg-muted">{{ t('decisions.detail.version') }}</dt><dd class="m-0 tabular">{{ r.version ?? '—' }}</dd></div>
        <div v-if="r.next_step" class="sm:col-span-2"><dt class="text-fg-muted">{{ t('radar.cols.next') }}</dt><dd class="m-0">{{ r.next_step }}</dd></div>
        <div v-if="canSeeExperiment && r.experiment_group" class="sm:col-span-2" data-test="experiment-group">
          <dt class="text-fg-muted">{{ t('decisions.detail.experiment') }}</dt>
          <dd class="m-0 font-semibold">{{ t(`decisions.experimentGroup.${r.experiment_group}`) }}</dd>
          <dd class="m-0 hint">{{ t('decisions.detail.experimentHint') }}</dd>
        </div>
      </dl>

      <div v-if="constraints.length" data-test="constraints">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('decisions.detail.constraints') }}</h3>
        <ul class="list-none p-0 m-0 flex flex-wrap gap-1.5"><li v-for="c in constraints" :key="c.id" class="badge badge-warning" data-test="constraint">{{ c.label }}</li></ul>
      </div>

      <details v-if="explanation.length" class="rounded-xl border border-line p-3">
        <summary class="cursor-pointer text-sm font-semibold">{{ t('radar.explanation') }}</summary>
        <ul class="list-disc pl-5 mt-2 mb-0 text-sm flex flex-col gap-1">
          <li v-for="(x, i) in explanation" :key="i">{{ (x.label as string | undefined) ?? (x.code as string) }}<span v-if="typeof x.source === 'string'" class="text-xs text-fg-muted"> · {{ x.source }}</span></li>
        </ul>
      </details>

      <section :aria-label="t('decisions.detail.actions')" class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="actions">
        <h3 class="section-title m-0">{{ t('decisions.detail.actions') }}</h3>
        <p v-if="!can('decisions.manage')" class="m-0 text-sm text-fg-muted" data-test="read-only">{{ t('decisions.detail.readOnly') }}</p>
        <p v-else-if="!transitions.length" class="m-0 text-sm text-fg-muted" data-test="no-actions">{{ t('decisions.detail.noActions') }}</p>
        <ul v-else class="list-none p-0 m-0 flex flex-col gap-2">
          <li v-for="x in transitions" :key="x.tr.action" class="flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="x.mode === 'disabled'" :data-action="x.tr.action" :aria-describedby="blockedText(x.tr) ? `blocked-${x.tr.action}` : undefined" @click="begin(x.tr)">{{ t(`decisions.action.${x.tr.action}`) }}</button>
            <span v-if="blockedText(x.tr)" :id="`blocked-${x.tr.action}`" class="text-xs text-fg-muted" data-test="blocked">{{ blockedText(x.tr) }}</span>
          </li>
        </ul>
      </section>

      <section v-if="r.events?.length" :aria-label="t('decisions.detail.timeline')">
        <h3 class="section-title m-0 mb-2">{{ t('decisions.detail.timeline') }}</h3>
        <ol class="list-none p-0 m-0 flex flex-col gap-2" data-test="timeline">
          <li v-for="e in r.events.filter((x) => x.event !== 'comment')" :key="e.id" class="rounded-lg bg-surface-2 px-3 py-2 text-sm">
            <p class="m-0 font-medium">{{ e.event === 'feedback' ? t('decisions.timeline.feedback', { state: e.agenda_status && te(`radar.state.${e.agenda_status}`) ? t(`radar.state.${e.agenda_status}`) : (e.agenda_status ?? '—') }) : t('decisions.timeline.transition', { from: e.from_status && te(`decisions.status.${e.from_status}`) ? t(`decisions.status.${e.from_status}`) : '—', to: e.to_status && te(`decisions.status.${e.to_status}`) ? t(`decisions.status.${e.to_status}`) : (e.to_status ?? '—') }) }}</p>
            <p class="m-0 text-xs text-fg-muted">{{ formatDateTime(e.created_at, tenant.timezone) }} · {{ e.actor_kind === 'system' ? t('enterprise.system') : who(e.actor_user_id) }}</p>
            <p v-if="e.reason" class="m-0">{{ e.reason }}</p>
          </li>
        </ol>
      </section>
    </template>

    <TransitionDialog :transition="pending" :busy="busy" :error="actionError" :assignees="assignees" @confirm="confirm" @cancel="pending = null" />
  </section>
</template>
