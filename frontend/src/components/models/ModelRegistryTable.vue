<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import type { ModelRegistryEntry } from '@/api/types'
import { formatDate } from '@/utils/format'

/** Registro de modelos con los estados de la etapa 2 (candidato, validado, producción, retirado, fallido) y su papel. */
defineProps<{ models: ModelRegistryEntry[]; loading?: boolean; caption: string }>()
defineSlots<{ actions?: (props: { model: ModelRegistryEntry }) => unknown }>()
const { t, te } = useI18n()

function familyLabel(m: ModelRegistryEntry): string {
  if (m.label) return m.label
  return te(`models.${m.model_family}`) ? t(`models.${m.model_family}`) : m.model_family
}

const columns = computed<Column<ModelRegistryEntry>[]>(() => [
  { key: 'version', label: t('modelRegistry.version') },
  { key: 'model_family', label: t('products.model'), format: familyLabel },
  { key: 'status', label: t('common.status') },
  { key: 'role', label: t('modelRegistry.role') },
  { key: 'trained_at', label: t('modelRegistry.trainedAt'), format: (m) => formatDate(m.trained_at) },
  { key: 'actions', label: '' },
])
</script>

<template>
  <DataTable :columns="columns" :rows="models" :row-key="(m) => m.id" :loading="loading" :caption="caption" :empty-text="t('modelRegistry.empty')">
    <template #cell-status="{ row }">
      <StatusBadge domain="model" :value="row.status" />
      <span v-if="row.legacy_status && row.legacy_status !== row.status" class="block text-xs text-fg-muted">{{ t('modelRegistry.legacy', { status: row.legacy_status }) }}</span>
    </template>
    <template #cell-role="{ row }">
      <span v-if="row.role" class="badge" :class="row.role === 'fallback' ? 'badge-info' : ''">{{ t(`modelRegistry.roles.${row.role}`) }}</span>
      <span v-else>—</span>
    </template>
    <template #cell-actions="{ row }"><slot name="actions" :model="row" /></template>
  </DataTable>
</template>
