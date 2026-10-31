<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import AssistantPanel from '@/components/assistant/AssistantPanel.vue'
import { useAssistantStore } from '@/stores/assistant'
import { useTenantStore } from '@/stores/tenant'

/** Asistente opcional. Si está desactivado, la pantalla solo lo explica: el resto de la aplicación no depende de él. */
const { t, te } = useI18n()
const assistant = useAssistantStore()
const tenant = useTenantStore()
onMounted(() => void assistant.ensureLoaded(tenant.currentUuid, true))
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('assistant.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('assistant.subtitle') }}</p>
    </header>
    <div class="card p-4 sm:p-6">
      <AssistantPanel v-if="assistant.enabled && assistant.status" :status="assistant.status" />
      <EmptyState v-else icon="shield" :title="t('assistant.disabledTitle')" :description="assistant.status?.reason && te(`assistant.reasons.${assistant.status.reason}`) ? t(`assistant.reasons.${assistant.status.reason}`) : t('assistant.disabledText')" data-test="assistant-disabled" />
    </div>
  </div>
</template>
