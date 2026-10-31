<script setup lang="ts">
/**
 * Compromisos comerciales (cotizaciones, pedidos confirmados y contratos). NO son ventas realizadas y no se suman a la
 * demanda: aquí se ven con su estado, fechas, cantidades pendientes y revisiones. Cancelar agrega una revisión.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from '@/components/ui/AppModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { dataCoreApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { Commitment } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateTime, formatNumber } from '@/utils/format'

const { t } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const ui = useUiStore()
const type = ref('')
const status = ref('')
const page = ref(1)
watch([type, status], () => (page.value = 1))
const list = useAsync(() => dataCoreApi.commitments({ type: type.value || undefined, status: status.value || undefined, page: page.value, per_page: 20 }), { watch: [type, status, page] })

const detail = ref<Commitment | null>(null)
const detailLoading = ref(false)
const toCancel = ref<Commitment | null>(null)
const busy = ref(false)
const cancelError = ref<string | null>(null)

async function open(c: Commitment): Promise<void> {
  detail.value = c
  detailLoading.value = true
  try {
    detail.value = await dataCoreApi.commitment(c.id)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    detailLoading.value = false
  }
}

async function cancel(): Promise<void> {
  if (!toCancel.value) return
  busy.value = true
  cancelError.value = null
  try {
    const updated = await dataCoreApi.cancelCommitment(toCancel.value.id)
    ui.notify(t('commitments.cancelled'), 'success')
    if (detail.value?.id === updated.id) detail.value = await dataCoreApi.commitment(updated.id)
    toCancel.value = null
    await list.execute()
  } catch (e) {
    cancelError.value = isApiError(e) && e.code === 'already_cancelled' ? t('commitments.alreadyCancelled') : errorMessage(e, t)
  } finally {
    busy.value = false
  }
}

const columns = computed<Column<Commitment>[]>(() => [
  { key: 'external_id', label: t('commitments.number') },
  { key: 'commitment_type', label: t('commitments.type'), format: (c) => t(`commitments.types.${c.commitment_type}`) },
  { key: 'status', label: t('common.status') },
  { key: 'issued_at', label: t('commitments.issued'), format: (c) => formatDate(c.issued_at) },
  { key: 'pending_total', label: t('commitments.pending'), align: 'right', format: (c) => formatNumber(c.pending_total, 2) },
  { key: 'known_at', label: t('calendar.fields.knownAt') },
  { key: 'open', label: '' },
])
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('commitments.title')" data-test="commitments-panel">
    <InlineNotice kind="info" :title="t('commitments.notSalesTitle')">{{ t('commitments.notSales') }}</InlineNotice>
    <div class="flex flex-wrap items-end gap-3">
      <div><label class="label" for="cm-type">{{ t('commitments.type') }}</label><select id="cm-type" v-model="type" class="select !w-auto"><option value="">{{ t('calendar.all') }}</option><option v-for="x in ['quote', 'order', 'contract']" :key="x" :value="x">{{ t(`commitments.types.${x}`) }}</option></select></div>
      <div><label class="label" for="cm-status">{{ t('common.status') }}</label><select id="cm-status" v-model="status" class="select !w-auto"><option value="">{{ t('calendar.all') }}</option><option v-for="x in ['open', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled', 'expired']" :key="x" :value="x">{{ t(`status.commitment.${x}`) }}</option></select></div>
    </div>
    <ErrorState v-if="list.error.value" :error="list.error.value" compact @retry="list.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="list.data.value?.data ?? []" :row-key="(c) => c.id" :loading="list.loading.value" :caption="t('commitments.title')" :empty-text="t('commitments.empty')">
        <template #cell-status="{ row }"><StatusBadge domain="commitment" :value="row.status" /><span v-if="row.cancellation_date_unknown" class="block text-xs text-warning">{{ t('commitments.cancelDateUnknown') }}</span></template>
        <template #cell-known_at="{ row }"><span v-if="row.known_at_unknown" class="badge badge-warning">{{ t('calendar.knownAtUnknown') }}</span><span v-else>{{ formatDateTime(row.known_at, tenant.timezone) }}</span></template>
        <template #cell-open="{ row }"><button type="button" class="btn btn-ghost btn-sm" data-test="commitment-open" @click="open(row)">{{ t('commitments.detail') }}</button></template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="list.data.value?.meta ?? null" />
    </template>

    <AppModal :open="detail !== null" :title="t('commitments.detailTitle', { number: detail?.external_id ?? '' })" size="lg" @close="detail = null">
      <div v-if="detail" class="flex flex-col gap-4" data-test="commitment-detail">
        <div class="flex flex-wrap items-center gap-2"><span class="badge">{{ t(`commitments.types.${detail.commitment_type}`) }}</span><StatusBadge domain="commitment" :value="detail.status" /><span v-if="detail.known_at_unknown" class="badge badge-warning">{{ t('calendar.knownAtUnknown') }}</span></div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm m-0">
          <dt class="text-fg-muted">{{ t('commitments.issued') }}</dt><dd class="m-0">{{ formatDate(detail.issued_at) }}</dd>
          <dt class="text-fg-muted">{{ t('commitments.validity') }}</dt><dd class="m-0">{{ formatDate(detail.valid_from) }} – {{ formatDate(detail.valid_to) }}</dd>
          <dt class="text-fg-muted">{{ t('commitments.expectedDelivery') }}</dt><dd class="m-0">{{ formatDate(detail.expected_delivery_date) }}</dd>
          <dt class="text-fg-muted">{{ t('commitments.pending') }}</dt><dd class="m-0 font-semibold">{{ formatNumber(detail.pending_total, 2) }}</dd>
        </dl>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('commitments.items') }}</caption>
            <thead><tr><th scope="col">#</th><th scope="col">{{ t('common.product') }}</th><th scope="col" class="num">{{ t('commitments.quantity') }}</th><th scope="col" class="num">{{ t('commitments.fulfilled') }}</th><th scope="col" class="num">{{ t('commitments.pending') }}</th><th scope="col">{{ t('commitments.delivery') }}</th></tr></thead>
            <tbody>
              <tr v-for="(i, k) in detail.items" :key="k">
                <td>{{ i.line_number ?? k + 1 }}</td><td>#{{ i.product_id }}</td>
                <td class="num">{{ formatNumber(i.quantity, 2) }} {{ i.unit }}<span v-if="i.source_unit" class="block text-xs text-fg-muted">{{ t('commitments.converted', { q: formatNumber(i.source_quantity ?? null, 2), u: i.source_unit, f: formatNumber(i.conversion_factor ?? null, 4) }) }}</span></td>
                <td class="num">{{ formatNumber(i.quantity_fulfilled, 2) }}</td><td class="num">{{ formatNumber(i.pending, 2) }}</td><td>{{ formatDate(i.delivery_date) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="detail.revisions?.length">
          <h3 class="section-title mb-1">{{ t('commitments.revisions') }}</h3>
          <ul class="list-none p-0 m-0 text-sm flex flex-col gap-1">
            <li v-for="r in detail.revisions" :key="r.revision">{{ t('commitments.revisionLine', { n: r.revision, status: r.status, date: formatDateTime(r.recorded_at, tenant.timezone) }) }}</li>
          </ul>
        </div>
      </div>
      <template #footer>
        <button v-if="detail && can('imports.manage') && detail.status !== 'cancelled'" type="button" class="btn btn-danger" data-test="commitment-cancel" @click="toCancel = detail; cancelError = null">{{ t('commitments.cancel') }}</button>
        <button type="button" class="btn btn-secondary" @click="detail = null">{{ t('common.close') }}</button>
      </template>
    </AppModal>

    <ConfirmDialog :open="toCancel !== null" :title="t('commitments.cancelTitle')" :message="t('commitments.cancelText', { number: toCancel?.external_id ?? '' })" danger :busy="busy" :confirm-label="t('commitments.cancel')" @confirm="cancel" @cancel="toCancel = null">
      <p v-if="cancelError" class="field-error mt-2" role="alert">{{ cancelError }}</p>
    </ConfirmDialog>
  </section>
</template>
