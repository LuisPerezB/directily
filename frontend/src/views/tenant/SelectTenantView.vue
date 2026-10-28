<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AuthShell from '@/layouts/AuthShell.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { tenantApi } from '@/api'
import type { TenantMembership } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'
import { useUiStore } from '@/stores/ui'

const { t } = useI18n()
const auth = useAuthStore()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const ui = useUiStore()
const router = useRouter()
const cancelling = ref<string | null>(null)

const isAvailable = (m: TenantMembership): boolean => (m.status ?? 'active') === 'active'

async function choose(m: TenantMembership): Promise<void> {
  if (!isAvailable(m)) return
  tenant.select(m.uuid)
  catalog.reset()
  await router.push({ name: m.onboarding_completed_at ? 'dashboard' : 'onboarding' })
}

/** Solo el propietario puede revertir una solicitud de eliminación durante el período de gracia. */
async function cancelDeletion(m: TenantMembership): Promise<void> {
  cancelling.value = m.uuid
  try {
    tenant.select(m.uuid) // el encabezado X-Tenant-ID sale de la empresa seleccionada
    await tenantApi.cancelDeletion()
    await auth.refresh()
    ui.notify(t('tenant.deletionCancelled'), 'success')
  } finally {
    tenant.select(null)
    cancelling.value = null
  }
}
</script>

<template>
  <AuthShell :title="t('tenant.selectTitle')" :subtitle="t('tenant.selectSubtitle')">
    <ul class="flex flex-col gap-2 list-none p-0 m-0">
      <li v-for="m in auth.memberships" :key="m.uuid">
        <button
          type="button"
          class="w-full card !shadow-none px-4 py-3 flex items-center gap-3 text-left"
          :class="isAvailable(m) ? 'hover:border-brand' : 'opacity-70 cursor-not-allowed'"
          :disabled="!isAvailable(m)"
          :aria-describedby="isAvailable(m) ? undefined : `tenant-status-${m.uuid}`"
          @click="choose(m)"
        >
          <span class="rounded-lg bg-brand-soft text-brand-strong p-2"><AppIcon name="building" :size="18" /></span>
          <span class="flex-1 min-w-0">
            <span class="block font-semibold truncate">{{ m.name }}</span>
            <span class="block text-xs text-fg-muted">{{ t(`sectors.${m.sector}`) }} · {{ t(`roles.${m.role}`) }}</span>
          </span>
          <AppIcon v-if="isAvailable(m)" name="chevron-right" :size="18" class="text-fg-muted" />
        </button>
        <div v-if="!isAvailable(m)" :id="`tenant-status-${m.uuid}`" class="px-4 pt-2 text-sm text-fg-muted flex flex-wrap items-center gap-2">
          <span>{{ m.status === 'pending_deletion' ? t('tenant.pendingDeletion') : t('tenant.suspended') }}</span>
          <button
            v-if="m.status === 'pending_deletion' && m.role === 'owner'"
            type="button"
            class="btn btn-secondary btn-sm"
            :disabled="cancelling === m.uuid"
            @click="cancelDeletion(m)"
          >
            {{ t('tenant.cancelDeletion') }}
          </button>
        </div>
      </li>
    </ul>
    <RouterLink :to="{ name: 'tenant-create' }" class="btn btn-secondary w-full mt-4"><AppIcon name="plus" :size="16" /> {{ t('tenant.createAnother') }}</RouterLink>
  </AuthShell>
</template>
