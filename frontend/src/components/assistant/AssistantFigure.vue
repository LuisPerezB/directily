<script setup lang="ts">
/**
 * Cifras de una herramienta, tal como las devolvió, con su procedencia (envoltura). Es la ÚNICA fuente de números
 * del asistente: nada se extrae del texto de la respuesta.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import type { AssistantFigure } from '@/api/types'
import { flattenFigure, formatFigureValue } from '@/utils/assistant'
import { formatNumber } from '@/utils/format'

const props = defineProps<{ figure: AssistantFigure }>()
const { t, te } = useI18n()
const flat = computed(() => flattenFigure(props.figure.data))
const title = computed(() => (te(`assistant.tools.${props.figure.tool}`) ? t(`assistant.tools.${props.figure.tool}`) : props.figure.tool))
const fmt = (n: number): string => formatNumber(n, Math.abs(n) < 10 && !Number.isInteger(n) ? 3 : 2)
</script>

<template>
  <article class="rounded-xl border border-line p-4 flex flex-col gap-3" :data-tool="figure.tool" data-test="assistant-figure">
    <header class="flex flex-wrap items-center gap-2">
      <h3 class="text-sm font-semibold m-0">{{ title }}</h3>
      <span class="badge" :class="figure.status === 'ok' ? 'badge-success' : ''">{{ t(`assistant.figureStatus.${figure.status}`) }}</span>
      <QualityBadge v-if="figure.envelope" :status="figure.envelope.quality_status" />
    </header>
    <p v-if="figure.status === 'no_data'" class="text-sm text-fg-muted m-0">{{ t('assistant.noData') }}</p>
    <div v-else-if="flat.rows.length" class="table-wrap max-h-80 overflow-y-auto">
      <table class="table" data-test="figure-rows">
        <caption class="sr-only">{{ title }}</caption>
        <thead><tr><th scope="col">{{ t('assistant.field') }}</th><th scope="col" class="num">{{ t('assistant.value') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in flat.rows" :key="r.path"><td class="font-mono text-xs break-all">{{ r.path }}</td><td class="num">{{ formatFigureValue(r.value, fmt, t('common.yes'), t('common.no')) }}</td></tr>
        </tbody>
      </table>
    </div>
    <p v-if="flat.truncated" class="hint m-0">{{ t('assistant.truncated') }}</p>
    <EnvelopePanel v-if="figure.envelope" :envelope="figure.envelope" :title="t('assistant.provenance')" compact />
  </article>
</template>
