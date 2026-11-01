<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { HorizonSupport } from '@/api/types'
import { horizonSupportView } from '@/utils/envelope'

/**
 * Aviso del respaldo de un horizonte. `insufficient_data` y `not_computed` nunca se presentan como un resultado completo;
 * `degraded` avisa que las métricas son optimistas; `unknown` indica una ejecución anterior a la etapa 2.
 */
const props = defineProps<{ support: HorizonSupport | null | undefined; horizon: number }>()
const { t, te } = useI18n()
const view = computed(() => horizonSupportView(props.support))
const kind = computed(() => (view.value?.status === 'insufficient_data' ? 'warning' : view.value?.status === 'degraded' ? 'warning' : 'info'))
</script>

<template>
  <InlineNotice v-if="view?.noticeKey" :kind="kind" :title="t(`${view.noticeKey}.title`, { n: horizon })" data-test="horizon-support-notice" :data-status="view.status">
    <p class="m-0">{{ t(`${view.noticeKey}.text`, { n: horizon }) }}</p>
    <ul v-if="view.reasonItems.length" class="list-disc pl-5 mt-1 mb-0">
      <li v-for="(reason, i) in view.reasonItems" :key="i" :data-reason-code="reason.code ?? undefined">
        <strong v-if="reason.code && te(`horizonSupport.reasonCode.${reason.code}`)">{{ t(`horizonSupport.reasonCode.${reason.code}`) }}: </strong>{{ reason.message }}
      </li>
    </ul>
    <slot />
  </InlineNotice>
</template>
