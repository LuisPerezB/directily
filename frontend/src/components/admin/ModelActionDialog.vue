<script setup lang="ts">
/**
 * Confirmación de una acción sobre un modelo del registro (validar, aprobar, rechazar, promover). La aprobación es SEPARADA: quien publicó el modelo o disparó su
 * entrenamiento no puede aprobarlo ni rechazarlo (el servidor lo hace cumplir con 403 `approval_segregation_of_duties`). El rechazo exige un motivo.
 */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from '@/components/ui/AppModal.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ActionErrorNotice from '@/components/enterprise/ActionErrorNotice.vue'
import type { ModelRegistryEntry } from '@/api/types'
import { MIN_REASON_LENGTH, type ActionError } from '@/utils/decisions'

export type ModelAction = 'validate' | 'approve' | 'reject' | 'promote'
const props = defineProps<{ action: ModelAction | null; model: ModelRegistryEntry | null; busy?: boolean; error?: ActionError | null }>()
const emit = defineEmits<{ confirm: [reason: string]; cancel: [] }>()
const { t } = useI18n()
const reason = ref('')
const tried = ref(false)
watch(() => [props.action, props.model?.id], () => {
  reason.value = ''
  tried.value = false
})
function confirm(): void {
  tried.value = true
  if (props.busy) return
  if (props.action === 'reject' && reason.value.trim().length < MIN_REASON_LENGTH) return
  emit('confirm', reason.value.trim())
}
</script>

<template>
  <AppModal :open="action !== null && model !== null" :title="action ? t(`entAdmin.models.actions.${action}`) : ''" size="md" :dismissible="!busy" @close="emit('cancel')">
    <div v-if="action && model" class="flex flex-col gap-4" data-test="model-dialog" :data-action="action">
      <p class="m-0 text-sm">{{ t(`entAdmin.models.hint.${action}`, { version: model.version }) }}</p>
      <InlineNotice v-if="action === 'approve' || action === 'reject'" kind="info">{{ t('entAdmin.models.segregation') }}</InlineNotice>
      <FormField id="ma-reason" :label="action === 'reject' ? t('decisions.reason') : t('decisions.noteOptional')" :hint="action === 'reject' ? t('decisions.reasonHint', { n: MIN_REASON_LENGTH }) : null" :error="tried && action === 'reject' && reason.trim().length < MIN_REASON_LENGTH ? t('decisions.reasonRequired', { n: MIN_REASON_LENGTH }) : null" :required="action === 'reject'">
        <template #default="{ describedby, invalid }">
          <textarea id="ma-reason" v-model="reason" class="textarea" rows="3" maxlength="1000" :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <ActionErrorNotice v-if="error" :error="error" />
    </div>
    <template #footer>
      <button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('cancel')">{{ t('common.cancel') }}</button>
      <button type="button" class="btn" :class="action === 'reject' ? 'btn-danger' : 'btn-primary'" :disabled="busy" data-test="confirm-model-action" @click="confirm">{{ action ? t(`entAdmin.models.actions.${action}`) : t('common.confirm') }}</button>
    </template>
  </AppModal>
</template>
