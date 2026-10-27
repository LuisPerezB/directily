<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AuthShell from '@/layouts/AuthShell.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'

const { t } = useI18n()
const auth = useAuthStore()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const router = useRouter()

async function choose(uuid: string, onboarded: boolean): Promise<void> {
  tenant.select(uuid)
  catalog.reset()
  await router.push({ name: onboarded ? 'dashboard' : 'onboarding' })
}
</script>

<template>
  <AuthShell :title="t('tenant.selectTitle')" :subtitle="t('tenant.selectSubtitle')">
    <ul class="flex flex-col gap-2 list-none p-0 m-0">
      <li v-for="m in auth.memberships" :key="m.uuid">
        <button type="button" class="w-full card !shadow-none px-4 py-3 flex items-center gap-3 text-left hover:border-brand" @click="choose(m.uuid, !!m.onboarding_completed_at)">
          <span class="rounded-lg bg-brand-soft text-brand-strong p-2"><AppIcon name="building" :size="18" /></span>
          <span class="flex-1 min-w-0">
            <span class="block font-semibold truncate">{{ m.name }}</span>
            <span class="block text-xs text-fg-muted">{{ t(`sectors.${m.sector}`) }} · {{ t(`roles.${m.role}`) }}</span>
          </span>
          <AppIcon name="chevron-right" :size="18" class="text-fg-muted" />
        </button>
      </li>
    </ul>
    <RouterLink :to="{ name: 'tenant-create' }" class="btn btn-secondary w-full mt-4"><AppIcon name="plus" :size="16" /> {{ t('tenant.createAnother') }}</RouterLink>
  </AuthShell>
</template>
