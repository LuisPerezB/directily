<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppModal from '@/components/ui/AppModal.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { tenantApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { TenantExport } from '@/api/types'
import { usePolling } from '@/composables/usePolling'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'

/** Privacidad (LOPDP): consentimiento de entrenamiento compartido, exportación y eliminación. */
const { t } = useI18n()
const tenant = useTenantStore()
const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

const consent = computed(() => !!tenant.tenant?.allow_shared_training)
const savingConsent = ref(false)
async function setConsent(allow: boolean): Promise<void> {
  savingConsent.value = true
  try {
    const updated = await tenantApi.setSharedTrainingConsent(allow)
    if (tenant.tenant) Object.assign(tenant.tenant, updated ?? { allow_shared_training: allow })
    await tenant.load().catch(() => undefined)
    ui.notify(allow ? t('privacy.consentGiven') : t('privacy.consentRevoked'), 'success')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    savingConsent.value = false
  }
}

const exportJob = ref<TenantExport | null>(null)
const exportPolling = usePolling(() => tenantApi.exportStatus(exportJob.value!.export_id), {
  interval: 4000,
  until: (e) => e.status === 'completed' || e.status === 'failed' || e.status === 'expired' || !!e.download_url,
  onUpdate: (e) => (exportJob.value = { ...exportJob.value!, ...e }),
})
async function requestExport(): Promise<void> {
  try {
    exportJob.value = await tenantApi.requestExport()
    exportPolling.start(false)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

const showDelete = ref(false)
const confirmName = ref('')
const deleting = ref(false)
async function requestDeletion(): Promise<void> {
  deleting.value = true
  try {
    await tenantApi.requestDeletion(confirmName.value)
    showDelete.value = false
    ui.notify(t('privacy.deletionRequested'), 'warning', 10000)
    await auth.refresh()
    tenant.select(null)
    await router.push({ name: auth.memberships.length ? 'tenant-select' : 'tenant-create' })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6 max-w-3xl">
    <section class="rounded-xl border border-line p-5">
      <h3 class="section-title mb-2">{{ t('privacy.consentTitle') }}</h3>
      <p class="text-sm text-fg-muted">{{ t('privacy.consentText') }}</p>
      <ul class="text-sm text-fg-muted list-disc pl-5 my-3 flex flex-col gap-1">
        <li>{{ t('privacy.consentPoint1') }}</li>
        <li>{{ t('privacy.consentPoint2') }}</li>
        <li>{{ t('privacy.consentPoint3') }}</li>
      </ul>
      <p class="text-sm">
        <span class="font-semibold">{{ t('privacy.currentStatus') }}:</span>
        <span class="badge ml-1" :class="consent ? 'badge-success' : ''">{{ consent ? t('privacy.consentOn') : t('privacy.consentOff') }}</span>
        <span v-if="consent && tenant.tenant?.shared_training_consented_at" class="hint ml-2">{{ formatDateTime(tenant.tenant.shared_training_consented_at, tenant.timezone) }}</span>
      </p>
      <div v-if="tenant.can('tenant.consent')" class="mt-3">
        <button v-if="!consent" type="button" class="btn btn-secondary" :disabled="savingConsent" @click="setConsent(true)">{{ t('privacy.giveConsent') }}</button>
        <button v-else type="button" class="btn btn-secondary" :disabled="savingConsent" @click="setConsent(false)">{{ t('privacy.revokeConsent') }}</button>
      </div>
      <p v-else class="hint mt-2">{{ t('privacy.ownerOnly') }}</p>
    </section>

    <section class="rounded-xl border border-line p-5">
      <h3 class="section-title mb-2">{{ t('privacy.exportTitle') }}</h3>
      <p class="text-sm text-fg-muted">{{ t('privacy.exportText') }}</p>
      <template v-if="tenant.can('tenant.export_all')">
        <button type="button" class="btn btn-secondary mt-3" :disabled="exportPolling.active.value" @click="requestExport"><AppIcon name="download" :size="16" /> {{ t('privacy.requestExport') }}</button>
        <div v-if="exportJob" class="mt-3 text-sm flex flex-wrap items-center gap-2" aria-live="polite">
          <StatusBadge domain="job" :value="exportJob.status === 'completed' ? 'succeeded' : exportJob.status === 'processing' ? 'running' : exportJob.status ?? 'queued'" />
          <a v-if="exportJob.download_url" :href="exportJob.download_url" class="btn btn-primary btn-sm">{{ t('privacy.download') }}</a>
          <span v-else-if="exportJob.status === 'failed' || exportJob.status === 'expired'" class="field-error">{{ exportJob.error_message || t('privacy.exportFailed') }}</span>
          <span v-else class="hint">{{ t('privacy.exportPreparing') }}</span>
        </div>
      </template>
      <p v-else class="hint mt-2">{{ t('privacy.ownerOnly') }}</p>
    </section>

    <section class="rounded-xl border border-danger p-5">
      <h3 class="section-title mb-2 text-danger">{{ t('privacy.deleteTitle') }}</h3>
      <p class="text-sm text-fg-muted">{{ t('privacy.deleteText') }}</p>
      <button v-if="tenant.can('tenant.delete')" type="button" class="btn btn-danger mt-3" @click="showDelete = true">{{ t('privacy.deleteButton') }}</button>
      <p v-else class="hint mt-2">{{ t('privacy.ownerOnly') }}</p>
    </section>

    <AppModal :open="showDelete" :title="t('privacy.deleteTitle')" size="sm" @close="showDelete = false">
      <InlineNotice kind="danger" class="mb-3">{{ t('privacy.deleteWarning') }}</InlineNotice>
      <label class="label" for="del-name">{{ t('privacy.typeName', { name: tenant.tenant?.name ?? '' }) }}</label>
      <input id="del-name" v-model="confirmName" class="input" autocomplete="off" />
      <template #footer>
        <button type="button" class="btn btn-secondary" @click="showDelete = false">{{ t('common.cancel') }}</button>
        <button type="button" class="btn btn-danger" :disabled="deleting || confirmName !== tenant.tenant?.name" @click="requestDeletion">{{ t('privacy.deleteConfirm') }}</button>
      </template>
    </AppModal>
  </div>
</template>
