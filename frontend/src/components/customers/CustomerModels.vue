<script setup lang="ts">
/**
 * Modelos del Customer Core por horizonte y objetivo. Una versión validada o retirada se puede volver a servir
 * (habilidad forecast.run) con confirmación; la reversión retira la versión en producción del mismo horizonte y objetivo.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ModelRegistryTable from '@/components/models/ModelRegistryTable.vue'
import RollbackDialog from '@/components/models/RollbackDialog.vue'
import { accuracyApi, modelsApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { CustomerRollbackResult, ModelRegistryEntry } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useUiStore } from '@/stores/ui'
import { rollbackErrorKey } from '@/utils/envelope'

const { t, te } = useI18n()
const { can } = useAbility()
const ui = useUiStore()
const models = useAsync(() => accuracyApi.models({ core: 'customer', per_page: 100 }))
const rows = computed(() => models.data.value?.data ?? [])

const target = ref<ModelRegistryEntry | null>(null)
const busy = ref(false)
const errorText = ref<string | null>(null)
const result = ref<CustomerRollbackResult | null>(null)

const restorable = (m: ModelRegistryEntry): boolean => (m.status === 'validated' || m.status === 'retired') && typeof m.hyperparameters?.slot === 'string'
function slotOf(m: ModelRegistryEntry): string {
  return String(m.hyperparameters?.slot ?? '—')
}
function slotText(slot: string): string {
  const m = /^h(\d+):(\w+)$/.exec(slot)
  if (!m) return slot
  const objective = te(`customers.eval.objectives.${m[2]}`) ? t(`customers.eval.objectives.${m[2]}`) : m[2]
  return `${t('common.days', { n: m[1] })} · ${objective}`
}
const details = computed(() =>
  target.value
    ? [
        { label: t('rollback.customer.slot'), value: slotText(slotOf(target.value)) },
        { label: t('modelRegistry.version'), value: target.value.version },
        { label: t('modelRegistry.role'), value: target.value.role ? t(`modelRegistry.roles.${target.value.role}`) : '—' },
      ]
    : [],
)

async function confirm(): Promise<void> {
  if (!target.value) return
  busy.value = true
  errorText.value = null
  try {
    result.value = await modelsApi.rollbackCustomer(target.value.id)
    target.value = null
    ui.notify(t('rollback.customer.done'), 'success')
    void models.execute()
  } catch (e) {
    const key = isApiError(e) ? rollbackErrorKey(e.code) : null
    errorText.value = key ? t(key) : isApiError(e) && e.isPlanLimit ? t('errors.planLimit') : errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('customers.tabs.models')" data-test="customer-models">
    <p class="text-sm text-fg-muted m-0">{{ t('customers.models.text') }}</p>
    <InlineNotice v-if="!can('forecast.run')" kind="info">{{ t('rollback.needsAbility') }}</InlineNotice>
    <ErrorState v-if="models.error.value" :error="models.error.value" compact @retry="models.execute" />
    <ModelRegistryTable v-else :models="rows" :loading="models.loading.value" :caption="t('customers.tabs.models')">
      <template #actions="{ model }">
        <span v-if="model.hyperparameters?.slot" class="block text-xs text-fg-muted">{{ slotText(slotOf(model)) }}</span>
        <button v-if="can('forecast.run') && restorable(model)" type="button" class="btn btn-secondary btn-sm mt-1" data-test="customer-rollback-open" @click="target = model; errorText = null">{{ t('rollback.customer.action') }}</button>
      </template>
    </ModelRegistryTable>
    <InlineNotice v-if="result" kind="success" data-test="rollback-result">
      <p class="m-0">{{ result.note }}</p>
      <p class="m-0 mt-1">{{ result.artifact_sha256_verified === true ? t('rollback.verified') : t('rollback.notVerified') }}</p>
      <p v-if="result.ranking_source" class="m-0 mt-1">{{ t('customers.rankingSource', { source: t(`rankingSources.${result.ranking_source}`) }) }}</p>
    </InlineNotice>
    <RollbackDialog
      :open="target !== null"
      :title="t('rollback.customer.confirmTitle')"
      :message="t('rollback.customer.confirmText')"
      :details="details"
      :busy="busy"
      :error-text="errorText"
      @confirm="confirm"
      @cancel="target = null"
    />
  </section>
</template>
