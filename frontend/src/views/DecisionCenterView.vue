<script setup lang="ts">
/**
 * Centro de decisiones (V2.5 · W5-E; ADR-V25-13 y -22): recomendaciones del Decision Engine y su workflow
 * suggested → assigned → reviewed → approved | rejected → in_progress → executed → measured → closed (+ expired), con las transiciones que permite el rol,
 * confirmación humana, versión esperada e idempotencia. Solo lee /decisions, /decisions/summary y /decisions/{id}; la transición es POST /decisions/{id}/transition.
 * Predictily propone y audita: no envía mensajes ni escribe en el ERP.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import DataOriginBand from '@/components/enterprise/DataOriginBand.vue'
import DecisionSummaryCards from '@/components/decisions/DecisionSummaryCards.vue'
import DecisionList from '@/components/decisions/DecisionList.vue'
import DecisionDetail from '@/components/decisions/DecisionDetail.vue'
import { decisionApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'

const props = defineProps<{ id?: string }>()
const { t } = useI18n()
const router = useRouter()
const tenant = useTenantStore()

const selected = computed(() => {
  const n = Number(props.id)
  return Number.isInteger(n) && n > 0 ? n : null
})
const refreshKey = ref(0)
const summary = useAsync(() => decisionApi.summary(), { watch: [refreshKey], scope: () => tenant.currentUuid })

function select(id: number): void {
  void router.replace({ name: 'decision-center', params: { id: String(id) } })
}
function close(): void {
  void router.replace({ name: 'decision-center', params: {} })
}
function changed(): void {
  refreshKey.value++
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('decisions.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('decisions.subtitle') }}</p>
    </header>

    <DataOriginBand />
    <InlineNotice kind="info" :title="t('decisions.noSendTitle')">{{ t('decisions.noSendNotice') }}</InlineNotice>

    <LoadingState v-if="summary.loading.value && !summary.data.value" :lines="3" />
    <div v-else-if="summary.error.value" class="card"><ErrorState :error="summary.error.value" @retry="summary.execute" /></div>
    <section v-else-if="summary.data.value" class="card p-4 sm:p-6"><DecisionSummaryCards :summary="summary.data.value" /></section>

    <div class="grid gap-6" :class="selected ? 'xl:grid-cols-[minmax(0,1fr)_32rem]' : ''">
      <section class="card p-4 sm:p-6 min-w-0"><DecisionList :selected-id="selected" :refresh-key="refreshKey" @select="select" /></section>
      <aside v-if="selected" class="card p-4 sm:p-6 min-w-0 h-fit" :aria-label="t('decisions.detail.title')">
        <div class="flex justify-end mb-2"><button type="button" class="btn btn-ghost btn-sm" data-test="close-detail" @click="close">{{ t('common.close') }}</button></div>
        <DecisionDetail :id="selected" @changed="changed" @close="close" />
      </aside>
    </div>
  </div>
</template>
