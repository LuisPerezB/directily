<script setup lang="ts">
/**
 * Confirmación humana de una transición del workflow. Explica qué registra la acción (Predictily no envía mensajes ni escribe en el ERP: anota lo que una
 * persona decide o hizo), pide el motivo cuando la transición lo exige (rechazar o descartar) y el responsable al asignar. El servidor valida igual.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from '@/components/ui/AppModal.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ActionErrorNotice from '@/components/enterprise/ActionErrorNotice.vue'
import type { DecisionAllowedTransition } from '@/api/types'
import { MIN_REASON_LENGTH, transitionInputError, type ActionError } from '@/utils/decisions'

const props = defineProps<{
  transition: DecisionAllowedTransition | null
  busy?: boolean
  error?: ActionError | null
  assignees: { id: number; label: string }[]
}>()
const emit = defineEmits<{ confirm: [input: { reason: string; assigneeUserId: number | null }]; cancel: [] }>()
const { t } = useI18n()

const reason = ref('')
const assignee = ref<number | null>(null)
const tried = ref(false)
watch(() => props.transition, () => {
  reason.value = ''
  tried.value = false
  assignee.value = props.assignees[0]?.id ?? null
})

const problem = computed(() => (props.transition ? transitionInputError(props.transition, { reason: reason.value, assigneeUserId: assignee.value }) : null))
function confirm(): void {
  tried.value = true
  if (props.busy || problem.value) return
  emit('confirm', { reason: reason.value.trim(), assigneeUserId: assignee.value })
}
</script>

<template>
  <AppModal :open="transition !== null" :title="transition ? t(`decisions.action.${transition.action}`) : ''" size="md" :dismissible="!busy" @close="emit('cancel')">
    <div v-if="transition" class="flex flex-col gap-4" data-test="transition-dialog" :data-action="transition.action">
      <p class="m-0 text-sm">{{ t(`decisions.actionHint.${transition.action}`) }}</p>
      <InlineNotice kind="info">{{ t('decisions.noSendNotice') }}</InlineNotice>
      <FormField v-if="transition.requires.assignee" id="tr-assignee" :label="t('decisions.assignee')" :error="tried && problem === 'assignee' ? t('decisions.assigneeRequired') : null" required>
        <template #default="{ describedby, invalid }">
          <select id="tr-assignee" v-model="assignee" class="select" :aria-describedby="describedby" :aria-invalid="invalid">
            <option v-for="a in assignees" :key="a.id" :value="a.id">{{ a.label }}</option>
          </select>
        </template>
      </FormField>
      <FormField v-if="transition.requires.reason" id="tr-reason" :label="t('decisions.reason')" :hint="t('decisions.reasonHint', { n: MIN_REASON_LENGTH })" :error="tried && problem === 'reason' ? t('decisions.reasonRequired', { n: MIN_REASON_LENGTH }) : null" required>
        <template #default="{ describedby, invalid }">
          <textarea id="tr-reason" v-model="reason" class="textarea" rows="3" maxlength="1000" :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField v-else id="tr-note" :label="t('decisions.noteOptional')">
        <template #default="{ describedby }">
          <textarea id="tr-note" v-model="reason" class="textarea" rows="2" maxlength="1000" :aria-describedby="describedby" />
        </template>
      </FormField>
      <ActionErrorNotice v-if="error" :error="error" />
    </div>
    <template #footer>
      <button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('cancel')">{{ t('common.cancel') }}</button>
      <button type="button" class="btn btn-primary" :disabled="busy" data-test="confirm-transition" @click="confirm">{{ transition ? t(`decisions.action.${transition.action}`) : t('common.confirm') }}</button>
    </template>
  </AppModal>
</template>
