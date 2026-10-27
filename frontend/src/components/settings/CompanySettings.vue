<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormField from '@/components/ui/FormField.vue'
import { errorMessage, isApiError } from '@/api/errors'
import type { Sector } from '@/api/types'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { SECTORS } from '@/utils/constants'
import { isValidRuc } from '@/utils/validation'

const { t } = useI18n()
const tenant = useTenantStore()
const ui = useUiStore()
const canEdit = computed(() => tenant.can('settings.manage'))
const form = reactive({ name: '', legal_name: '', tax_id: '', sector: 'otro' as Sector, timezone: 'America/Guayaquil' })
const saving = ref(false)
const fieldErrors = ref<Record<string, string | undefined>>({})

watch(
  () => tenant.tenant,
  (tn) => {
    if (!tn) return
    Object.assign(form, { name: tn.name, legal_name: tn.legal_name ?? '', tax_id: tn.tax_id ?? '', sector: tn.sector, timezone: tn.timezone })
  },
  { immediate: true },
)
const rucError = computed(() => (form.tax_id && !isValidRuc(form.tax_id) ? t('validation.ruc') : undefined))

async function save(): Promise<void> {
  saving.value = true
  fieldErrors.value = {}
  try {
    await tenant.update({ name: form.name, legal_name: form.legal_name || null, tax_id: form.tax_id || null, sector: form.sector, timezone: form.timezone })
    ui.notify(t('settings.saved'), 'success')
  } catch (e) {
    if (isApiError(e) && e.isValidation) fieldErrors.value = Object.fromEntries(Object.keys(e.fieldErrors).map((k) => [k, e.field(k)]))
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="grid gap-4 md:grid-cols-2 max-w-3xl" @submit.prevent="save">
    <fieldset class="contents" :disabled="!canEdit">
      <FormField id="c-name" :label="t('tenant.name')" :error="fieldErrors.name" required>
        <template #default="{ describedby, invalid }"><input id="c-name" v-model.trim="form.name" class="input" required :aria-describedby="describedby" :aria-invalid="invalid" /></template>
      </FormField>
      <FormField id="c-legal" :label="t('tenant.legalName')" :error="fieldErrors.legal_name">
        <template #default><input id="c-legal" v-model.trim="form.legal_name" class="input" /></template>
      </FormField>
      <FormField id="c-ruc" :label="t('tenant.taxId')" :hint="t('tenant.taxIdHint')" :error="fieldErrors.tax_id ?? rucError">
        <template #default="{ describedby, invalid }"><input id="c-ruc" v-model.trim="form.tax_id" class="input" inputmode="numeric" maxlength="13" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
      </FormField>
      <FormField id="c-sector" :label="t('tenant.sector')">
        <template #default>
          <select id="c-sector" v-model="form.sector" class="select">
            <option v-for="s in SECTORS" :key="s" :value="s">{{ t(`sectors.${s}`) }}</option>
          </select>
        </template>
      </FormField>
      <FormField id="c-tz" :label="t('tenant.timezone')">
        <template #default>
          <select id="c-tz" v-model="form.timezone" class="select">
            <option value="America/Guayaquil">{{ t('tenant.tzMainland') }}</option>
            <option value="Pacific/Galapagos">{{ t('tenant.tzGalapagos') }}</option>
          </select>
        </template>
      </FormField>
      <FormField id="c-cur" :label="t('tenant.currency')" :hint="t('tenant.currencyHint')">
        <template #default><input id="c-cur" :value="tenant.tenant?.currency ?? 'USD'" class="input" disabled /></template>
      </FormField>
    </fieldset>
    <div v-if="canEdit" class="md:col-span-2"><button type="submit" class="btn btn-primary" :disabled="saving || !form.name || !!rucError">{{ t('common.save') }}</button></div>
    <p v-else class="md:col-span-2 hint">{{ t('settings.readOnly') }}</p>
  </form>
</template>
