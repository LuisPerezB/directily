<script setup lang="ts">
/**
 * Administración de la empresa (V2.5 · W5-E; ADR-V25-22): modelos y aprobaciones (W4), calidad de datos y cobertura (W2), uso por empresa y costos, y auditoría.
 * Reutiliza los endpoints y paneles existentes; cada acción se ofrece solo con la habilidad del rol y el servidor sigue siendo la autoridad.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TabsNav from '@/components/ui/TabsNav.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import DataOriginBand from '@/components/enterprise/DataOriginBand.vue'
import ModelApprovals from '@/components/admin/ModelApprovals.vue'
import UsageAndCosts from '@/components/admin/UsageAndCosts.vue'
import DataQualityPanel from '@/components/datasources/DataQualityPanel.vue'
import DataCoveragePanel from '@/components/datasources/DataCoveragePanel.vue'
import AuditLog from '@/components/settings/AuditLog.vue'
import { useTenantStore } from '@/stores/tenant'
import type { Ability } from '@/utils/abilities'

const props = defineProps<{ tab?: string }>()
const { t } = useI18n()
const router = useRouter()
const tenant = useTenantStore()

const ALL: { key: string; ability?: Ability }[] = [
  { key: 'modelos' },
  { key: 'calidad', ability: 'data.view' },
  { key: 'uso' },
  { key: 'auditoria', ability: 'audit.view' },
]
const tabs = computed(() => ALL.filter((x) => !x.ability || tenant.can(x.ability)).map((x) => ({ key: x.key, label: t(`entAdmin.tabs.${x.key}`) })))
const active = computed({
  get: () => (tabs.value.some((x) => x.key === props.tab) ? props.tab! : 'modelos'),
  set: (key: string) => void router.replace({ name: 'enterprise-admin', params: { tab: key } }),
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('entAdmin.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('entAdmin.subtitle') }}</p>
    </header>
    <DataOriginBand />
    <InlineNotice kind="info">{{ t('entAdmin.scope') }}</InlineNotice>

    <TabsNav v-model="active" :tabs="tabs" :label="t('entAdmin.title')" id-prefix="entadmin" />
    <section id="entadmin-panel" role="tabpanel" :aria-labelledby="`entadmin-${active}`" class="card p-4 sm:p-6">
      <ModelApprovals v-if="active === 'modelos'" />
      <div v-else-if="active === 'calidad'" class="flex flex-col gap-8">
        <DataQualityPanel />
        <DataCoveragePanel />
      </div>
      <UsageAndCosts v-else-if="active === 'uso'" />
      <AuditLog v-else-if="active === 'auditoria'" />
    </section>
  </div>
</template>
