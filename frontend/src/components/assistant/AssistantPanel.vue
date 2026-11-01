<script setup lang="ts">
/**
 * Asistente conversacional opcional. El texto solo EXPLICA resultados ya calculados; las cifras y su procedencia vienen
 * de `figures`. No calcula, no estima ni decide: si no hay una herramienta aplicable, responde con un mensaje fijo.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AssistantFigure from './AssistantFigure.vue'
import { assistantApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { AssistantHistoryItem, AssistantReply, AssistantStatus } from '@/api/types'
import { useAssistantStore } from '@/stores/assistant'
import { useTenantStore } from '@/stores/tenant'
import { assistantError, replyView, trimHistory } from '@/utils/assistant'

const props = defineProps<{ status: AssistantStatus }>()
const { t } = useI18n()
const assistant = useAssistantStore()
const tenant = useTenantStore()
const message = ref('')
const sending = ref(false)
const error = ref<string | null>(null)
const turns = ref<{ question: string; reply: AssistantReply }[]>([])
const views = computed(() => turns.value.map((turn) => replyView(turn.reply)))
const max = computed(() => props.status.limits?.max_message_chars ?? 1000)
const maxHistory = computed(() => props.status.limits?.max_history_messages ?? 6)
const examples = computed(() => [t('assistant.examples.sales'), t('assistant.examples.accuracy'), t('assistant.examples.buyers')])

async function send(): Promise<void> {
  const question = message.value.trim()
  if (!question || sending.value) return
  sending.value = true
  error.value = null
  // El historial reenviado es solo el texto de la conversación, sin reinterpretar cifras.
  const history: AssistantHistoryItem[] = trimHistory(
    turns.value.flatMap((turn): AssistantHistoryItem[] => [
      { role: 'user', content: turn.question },
      ...(turn.reply.status === 'answered' ? [{ role: 'assistant' as const, content: turn.reply.answer }] : []),
    ]),
    maxHistory.value,
  )
  try {
    const reply = await assistantApi.send(question, history)
    turns.value.push({ question, reply })
    message.value = ''
  } catch (e) {
    const info = assistantError(e)
    if (info.kind === 'disabled') void assistant.ensureLoaded(tenant.currentUuid, true)
    error.value = info.kind === 'other' ? errorMessage(e, t) : t(`assistant.errors.${info.kind}`, { s: info.retryAfter ?? 60 })
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('assistant.title')" data-test="assistant-panel">
    <InlineNotice kind="warning" :title="t('assistant.noticeTitle')" data-test="assistant-notice">{{ t('assistant.notice') }}</InlineNotice>
    <p class="text-sm text-fg-muted m-0">{{ status.notice }}</p>

    <div v-if="!turns.length" class="flex flex-wrap gap-2" :aria-label="t('assistant.examplesLabel')">
      <button v-for="ex in examples" :key="ex" type="button" class="btn btn-secondary btn-sm" @click="message = ex">{{ ex }}</button>
    </div>

    <ol class="list-none p-0 m-0 flex flex-col gap-5" aria-live="polite">
      <li v-for="(turn, i) in turns" :key="i" class="flex flex-col gap-3" data-test="assistant-turn">
        <p class="m-0 self-end max-w-xl rounded-xl bg-brand-soft text-brand-strong px-4 py-2 text-sm" data-test="assistant-question">{{ turn.question }}</p>
        <template v-if="views[i]">
          <div v-if="views[i]!.showExplanation" class="rounded-xl border border-line px-4 py-3" data-test="assistant-explanation">
            <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0 mb-1">{{ t('assistant.explanation') }}</p>
            <p class="m-0 text-sm whitespace-pre-line">{{ turn.reply.answer }}</p>
          </div>
          <InlineNotice v-else-if="views[i]!.fixedMessage" :kind="turn.reply.status === 'tool_denied' ? 'danger' : 'info'" :title="t(`assistant.status.${turn.reply.status}`)" data-test="assistant-fixed-message">{{ views[i]!.fixedMessage }}</InlineNotice>
          <InlineNotice v-if="views[i]!.discardedNumbers.length" kind="warning" data-test="assistant-discarded">{{ t('assistant.discarded', { list: views[i]!.discardedNumbers.join(', ') }) }}</InlineNotice>
          <AssistantFigure v-for="(f, k) in views[i]!.figures" :key="k" :figure="f" />
          <InlineNotice v-for="(f, k) in views[i]!.deniedFigures" :key="`d${k}`" kind="warning">{{ t(`assistant.figureStatus.${f.status}`) }}: {{ f.tool }}</InlineNotice>
          <p class="hint m-0">{{ turn.reply.notice }}</p>
        </template>
      </li>
    </ol>

    <form class="flex flex-col gap-2" @submit.prevent="send">
      <label class="label" for="as-message">{{ t('assistant.ask') }}</label>
      <textarea id="as-message" v-model="message" class="textarea" rows="2" :maxlength="max" :placeholder="t('assistant.placeholder')" @keydown.enter.exact.prevent="send" />
      <div class="flex flex-wrap items-center gap-3">
        <button type="submit" class="btn btn-primary" :disabled="sending || !message.trim()" data-test="assistant-send"><AppIcon name="play" :size="16" /> {{ sending ? t('common.processing') : t('assistant.send') }}</button>
        <span class="hint m-0">{{ message.length }} / {{ max }}</span>
      </div>
      <p v-if="error" class="field-error" role="alert" data-test="assistant-error">{{ error }}</p>
    </form>
  </section>
</template>
