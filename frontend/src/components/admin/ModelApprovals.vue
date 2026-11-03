<script setup lang="ts">
/**
 * Modelos y aprobaciones (W4; ADR-V25-07). Un modelo pasa por candidato → validado → aprobado → producción, con la aprobación SEPARADA de quien lo publicó o entrenó.
 * Cada acción se ofrece solo con la habilidad del rol (`forecast.run` para validar; `models.approve` para aprobar, rechazar y promover); el servidor decide
 * la segregación de funciones y los gates de datos (403/409/422). Validar NO vuelve servible al modelo.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppModal from '@/components/ui/AppModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import ModelActionDialog, { type ModelAction } from './ModelActionDialog.vue'
import { accuracyApi, modelLifecycleApi } from '@/api'
import type { ModelLifecycleDetail, ModelRegistryEntry } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { classifyActionError, type ActionError } from '@/utils/decisions'
import { formatDateTime } from '@/utils/format'

const { t, te } = useI18n()
const { can } = useAbility()
const auth = useAuthStore()
const tenant = useTenantStore()

const status = ref('')
const page = ref(1)
const list = useAsync(() => accuracyApi.models({ status: status.value || undefined, per_page: 15, page: page.value }), { watch: [status, page], scope: () => tenant.currentUuid })
const rows = computed(() => list.data.value?.data ?? [])

function who(id: number | null | undefined): string {
  if (!id) return '—'
  return id === auth.user?.id ? t('decisions.you') : t('enterprise.userN', { id })
}

/** Acciones que el rol puede pedir sobre este estado (el servidor decide si hoy se pueden). */
function actionsFor(m: ModelRegistryEntry): { action: ModelAction; disabled: boolean; hint: string | null }[] {
  const out: { action: ModelAction; disabled: boolean; hint: string | null }[] = []
  const mine = m.published_by !== null && m.published_by !== undefined && m.published_by === auth.user?.id
  if (m.status === 'candidate' && can('forecast.run')) out.push({ action: 'validate', disabled: false, hint: null })
  if (m.status === 'validated' && can('models.approve')) out.push({ action: 'approve', disabled: mine, hint: mine ? t('entAdmin.models.youPublished') : null })
  if (['candidate', 'validated', 'approved'].includes(m.status) && can('models.approve')) out.push({ action: 'reject', disabled: mine, hint: mine ? t('entAdmin.models.youPublished') : null })
  if (m.status === 'approved' && can('models.approve')) out.push({ action: 'promote', disabled: false, hint: null })
  return out
}

const pending = ref<{ action: ModelAction; model: ModelRegistryEntry } | null>(null)
const busy = ref(false)
const error = ref<ActionError | null>(null)
const done = ref<ModelAction | null>(null)

async function confirm(reason: string): Promise<void> {
  const p = pending.value
  // Una sola petición a la vez: un segundo clic mientras la primera sigue en vuelo no envía nada.
  if (busy.value || !p) return
  busy.value = true
  error.value = null
  try {
    if (p.action === 'reject') await modelLifecycleApi.reject(p.model.id, reason)
    else await modelLifecycleApi[p.action](p.model.id, reason || undefined)
    done.value = p.action
    pending.value = null
    await list.execute()
  } catch (e) {
    error.value = classifyActionError(e)
    if (['not_allowed', 'conflict', 'not_found'].includes(error.value.kind)) void list.execute()
  } finally {
    busy.value = false
  }
}

const detail = ref<ModelLifecycleDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref<unknown>(null)
const detailOpen = ref(false)
async function openHistory(m: ModelRegistryEntry): Promise<void> {
  detailOpen.value = true
  detail.value = null
  detailError.value = null
  detailLoading.value = true
  try {
    detail.value = await modelLifecycleApi.show(m.id)
  } catch (e) {
    detailError.value = e
  } finally {
    detailLoading.value = false
  }
}

const columns = computed<Column<ModelRegistryEntry>[]>(() => [
  { key: 'version', label: t('modelRegistry.version') },
  { key: 'status', label: t('common.status') },
  { key: 'published', label: t('entAdmin.models.published') },
  { key: 'approved', label: t('entAdmin.models.approved') },
  { key: 'actions', label: t('enterprise.actionsHeader') },
])
const statuses = ['candidate', 'validated', 'approved', 'production', 'retired', 'rejected', 'failed']
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('entAdmin.models.title')" data-test="model-approvals">
    <div class="flex flex-wrap items-end gap-4">
      <div>
        <label class="label" for="ma-status">{{ t('modelRegistry.filter') }}</label>
        <select id="ma-status" v-model="status" class="select" @change="page = 1">
          <option value="">{{ t('modelRegistry.allStatuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ t(`status.model.${s}`) }}</option>
        </select>
      </div>
      <p class="hint m-0 max-w-xl">{{ t('entAdmin.models.text') }}</p>
    </div>
    <InlineNotice v-if="done" kind="success" data-test="model-done">{{ t(`entAdmin.models.done.${done}`) }}</InlineNotice>
    <ErrorState v-if="list.error.value" :error="list.error.value" compact @retry="list.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="rows" :row-key="(m) => m.id" :loading="list.loading.value" :caption="t('entAdmin.models.title')" :empty-text="t('modelRegistry.empty')">
        <template #cell-version="{ row }">
          <span class="font-medium block">{{ row.version }}</span>
          <span class="text-xs text-fg-muted">{{ row.label ?? row.model_family }}{{ row.core ? ` · ${row.core}` : '' }}</span>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge domain="model" :value="row.status" />
          <span v-if="row.serving_basis" class="block text-xs text-fg-muted mt-1" data-test="serving-basis">{{ te(`entAdmin.models.basis.${row.serving_basis}`) ? t(`entAdmin.models.basis.${row.serving_basis}`) : row.serving_basis }}</span>
          <span v-if="row.rejected_reason" class="block text-xs text-fg-muted">{{ row.rejected_reason }}</span>
        </template>
        <template #cell-published="{ row }">{{ who(row.published_by) }}<span v-if="row.published_at" class="block text-xs text-fg-muted">{{ formatDateTime(row.published_at, tenant.timezone) }}</span></template>
        <template #cell-approved="{ row }">
          <template v-if="row.approved_by">{{ who(row.approved_by) }}<span v-if="row.approved_at" class="block text-xs text-fg-muted">{{ formatDateTime(row.approved_at, tenant.timezone) }}</span></template>
          <span v-else class="text-fg-muted text-sm">{{ t('entAdmin.models.notApproved') }}</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-col gap-1 items-start">
            <button v-for="a in actionsFor(row)" :key="a.action" type="button" class="btn btn-secondary btn-sm" :disabled="a.disabled" :title="a.hint ?? undefined" :data-action="a.action" @click="pending = { action: a.action, model: row }; error = null; done = null">{{ t(`entAdmin.models.actions.${a.action}`) }}</button>
            <button type="button" class="btn btn-ghost btn-sm" data-test="model-history" @click="openHistory(row)">{{ t('entAdmin.models.history') }}</button>
          </div>
        </template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="list.data.value?.meta ?? null" />
    </template>

    <ModelActionDialog :action="pending?.action ?? null" :model="pending?.model ?? null" :busy="busy" :error="error" @confirm="confirm" @cancel="pending = null" />

    <AppModal :open="detailOpen" :title="t('entAdmin.models.history')" size="lg" @close="detailOpen = false">
      <LoadingState v-if="detailLoading" :lines="3" />
      <ErrorState v-else-if="detailError" :error="detailError" compact />
      <div v-else-if="detail" class="flex flex-col gap-3" data-test="model-detail">
        <p class="m-0 text-sm">{{ detail.label ?? detail.model_family }} · {{ detail.version }} · <StatusBadge domain="model" :value="detail.status" /></p>
        <p v-if="detail.promotion_decision" class="m-0 text-sm text-fg-muted" data-test="promotion-decision">{{ t('entAdmin.models.promotionDecision', { policy: detail.promotion_decision.policy, decision: detail.promotion_decision.decision }) }}</p>
        <ol class="list-none p-0 m-0 flex flex-col gap-2">
          <li v-for="e in detail.events" :key="e.id" class="rounded-lg bg-surface-2 px-3 py-2 text-sm">
            <p class="m-0 font-medium">{{ e.from_status ? t('entAdmin.models.transition', { from: te(`status.model.${e.from_status}`) ? t(`status.model.${e.from_status}`) : e.from_status, to: te(`status.model.${e.to_status}`) ? t(`status.model.${e.to_status}`) : e.to_status }) : t('entAdmin.models.created', { to: te(`status.model.${e.to_status}`) ? t(`status.model.${e.to_status}`) : e.to_status }) }}</p>
            <p class="m-0 text-xs text-fg-muted">{{ formatDateTime(e.created_at, tenant.timezone) }} · {{ e.actor_kind === 'system' ? t('enterprise.system') : who(e.actor_user_id) }}</p>
            <p v-if="e.reason" class="m-0">{{ e.reason }}</p>
          </li>
        </ol>
      </div>
      <template #footer><button type="button" class="btn btn-secondary" @click="detailOpen = false">{{ t('common.close') }}</button></template>
    </AppModal>
  </section>
</template>
