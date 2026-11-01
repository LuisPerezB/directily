<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TabsNav from '@/components/ui/TabsNav.vue'
import CompanySettings from '@/components/settings/CompanySettings.vue'
import ParametersSettings from '@/components/settings/ParametersSettings.vue'
import BranchesSettings from '@/components/settings/BranchesSettings.vue'
import UsersSettings from '@/components/settings/UsersSettings.vue'
import PrivacySettings from '@/components/settings/PrivacySettings.vue'
import PlanUsage from '@/components/settings/PlanUsage.vue'
import AuditLog from '@/components/settings/AuditLog.vue'
import EffectiveConfigSettings from '@/components/settings/EffectiveConfigSettings.vue'
import { useTenantStore } from '@/stores/tenant'
import type { Ability } from '@/utils/abilities'

const props = defineProps<{ tab?: string }>()
const { t } = useI18n()
const router = useRouter()
const tenant = useTenantStore()

const ALL: { key: string; ability?: Ability }[] = [
  { key: 'empresa' },
  { key: 'parametros' },
  { key: 'modulos' },
  { key: 'sucursales' },
  { key: 'usuarios', ability: 'users.manage' },
  { key: 'privacidad' },
  { key: 'plan' },
  { key: 'auditoria', ability: 'audit.view' },
]
const tabs = computed(() => ALL.filter((x) => !x.ability || tenant.can(x.ability)).map((x) => ({ key: x.key, label: t(`settings.tabs.${x.key}`) })))
const active = computed({
  get: () => (tabs.value.some((x) => x.key === props.tab) ? props.tab! : 'empresa'),
  set: (key: string) => void router.replace({ name: 'settings', params: { tab: key } }),
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('settings.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('settings.subtitle') }}</p>
    </header>
    <TabsNav v-model="active" :tabs="tabs" :label="t('settings.title')" id-prefix="settings" />
    <section id="settings-panel" role="tabpanel" :aria-labelledby="`settings-${active}`" class="card p-4 sm:p-6">
      <CompanySettings v-if="active === 'empresa'" />
      <ParametersSettings v-else-if="active === 'parametros'" />
      <EffectiveConfigSettings v-else-if="active === 'modulos'" />
      <BranchesSettings v-else-if="active === 'sucursales'" />
      <UsersSettings v-else-if="active === 'usuarios'" />
      <PrivacySettings v-else-if="active === 'privacidad'" />
      <PlanUsage v-else-if="active === 'plan'" />
      <AuditLog v-else-if="active === 'auditoria'" />
    </section>
  </div>
</template>
