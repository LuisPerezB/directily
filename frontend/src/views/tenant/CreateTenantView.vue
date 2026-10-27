<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AuthShell from '@/layouts/AuthShell.vue'
import FormField from '@/components/ui/FormField.vue'
import { useTenantStore } from '@/stores/tenant'
import { useAuthStore } from '@/stores/auth'
import { errorMessage, isApiError } from '@/api/errors'
import { isValidRuc } from '@/utils/validation'
import { SECTORS } from '@/utils/constants'
import type { Sector } from '@/api/types'

const { t } = useI18n()
const tenant = useTenantStore()
const auth = useAuthStore()
const router = useRouter()

const form = reactive({ name: '', legal_name: '', tax_id: '', sector: 'ferreteria' as Sector, timezone: 'America/Guayaquil' })
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string | undefined>>({})
const rucError = computed(() => (form.tax_id && !isValidRuc(form.tax_id) ? t('validation.ruc') : undefined))

async function submit(): Promise<void> {
  if (!form.name || rucError.value) return
  busy.value = true
  error.value = null
  try {
    await tenant.create({
      name: form.name,
      legal_name: form.legal_name || null,
      tax_id: form.tax_id || null,
      sector: form.sector,
      timezone: form.timezone,
      currency: 'USD',
    })
    await router.replace({ name: 'onboarding' })
  } catch (e) {
    if (isApiError(e) && e.isValidation) fieldErrors.value = Object.fromEntries(Object.keys(e.fieldErrors).map((k) => [k, e.field(k)]))
    error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell :title="t('tenant.createTitle')" :subtitle="t('tenant.createSubtitle')">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p v-if="error" class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-sm" role="alert">{{ error }}</p>
      <FormField id="t-name" :label="t('tenant.name')" :error="fieldErrors.name" required>
        <template #default="{ describedby, invalid }">
          <input id="t-name" v-model.trim="form.name" class="input" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="t-legal" :label="t('tenant.legalName')" :error="fieldErrors.legal_name">
        <template #default="{ describedby }"><input id="t-legal" v-model.trim="form.legal_name" class="input" :aria-describedby="describedby" /></template>
      </FormField>
      <FormField id="t-ruc" :label="t('tenant.taxId')" :hint="t('tenant.taxIdHint')" :error="fieldErrors.tax_id ?? rucError">
        <template #default="{ describedby, invalid }">
          <input id="t-ruc" v-model.trim="form.tax_id" class="input" inputmode="numeric" maxlength="13" :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="t-sector" :label="t('tenant.sector')" :error="fieldErrors.sector" required>
        <template #default="{ describedby }">
          <select id="t-sector" v-model="form.sector" class="select" :aria-describedby="describedby">
            <option v-for="s in SECTORS" :key="s" :value="s">{{ t(`sectors.${s}`) }}</option>
          </select>
        </template>
      </FormField>
      <div class="grid sm:grid-cols-2 gap-4">
        <FormField id="t-tz" :label="t('tenant.timezone')">
          <template #default>
            <select id="t-tz" v-model="form.timezone" class="select">
              <option value="America/Guayaquil">{{ t('tenant.tzMainland') }}</option>
              <option value="Pacific/Galapagos">{{ t('tenant.tzGalapagos') }}</option>
            </select>
          </template>
        </FormField>
        <FormField id="t-currency" :label="t('tenant.currency')" :hint="t('tenant.currencyHint')">
          <template #default><input id="t-currency" value="USD" class="input" disabled /></template>
        </FormField>
      </div>
      <button type="submit" class="btn btn-primary w-full" :disabled="busy || !form.name || !!rucError">{{ busy ? t('common.processing') : t('tenant.create') }}</button>
    </form>
    <template v-if="auth.memberships.length" #below>
      <RouterLink :to="{ name: 'tenant-select' }" class="font-semibold text-brand-strong">{{ t('tenant.backToSelect') }}</RouterLink>
    </template>
  </AuthShell>
</template>
