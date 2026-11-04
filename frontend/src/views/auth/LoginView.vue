<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/layouts/AuthShell.vue'
import FormField from '@/components/ui/FormField.vue'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { errorMessage, isApiError } from '@/api/errors'
import { homeFor, safeRedirect } from '@/router/guards'

const { t } = useI18n()
const auth = useAuthStore()
const tenant = useTenantStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '', remember: true })
const mfaForm = reactive({ code: '', recovery_code: '' })
const useRecovery = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string | undefined>>({})

async function enter(): Promise<void> {
  tenant.ensureSelection()
  const redirect = safeRedirect(route.query.redirect)
  await router.replace(redirect ?? homeFor({ hasTenant: tenant.hasTenant, hasMemberships: auth.memberships.length > 0 }))
}

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    // Con el segundo factor activo la contraseña correcta no inicia sesión: el store guarda el desafío y esta pantalla pide el código (V2.5 · W6-A).
    if ((await auth.login({ ...form })) === 'mfa_required') {
      mfaForm.code = ''
      mfaForm.recovery_code = ''
      useRecovery.value = false
      return
    }
    await enter()
  } catch (e) {
    if (isApiError(e) && e.status === 429) error.value = t('auth.tooManyAttempts')
    else if (isApiError(e) && e.isValidation) fieldErrors.value = { email: e.field('email'), password: e.field('password') }
    else error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}

async function submitMfa(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    await auth.completeMfa(useRecovery.value ? { recovery_code: mfaForm.recovery_code.trim() } : { code: mfaForm.code.replace(/\s+/g, '') })
    await enter()
  } catch (e) {
    if (isApiError(e) && e.status === 429) error.value = t('auth.tooManyAttempts')
    else if (isApiError(e) && e.code === 'mfa_locked') error.value = t('auth.mfa.locked')
    else if (isApiError(e) && e.code === 'mfa_challenge_invalid') error.value = t('auth.mfa.expired')
    else if (isApiError(e) && (e.code === 'mfa_invalid_code' || e.isValidation)) error.value = t('auth.mfa.invalidCode')
    else error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}

function backToPassword(): void {
  auth.cancelMfa()
  error.value = null
  mfaForm.code = ''
  mfaForm.recovery_code = ''
}
</script>

<template>
  <AuthShell :title="auth.mfaPending ? t('auth.mfa.title') : t('auth.loginTitle')" :subtitle="auth.mfaPending ? t('auth.mfa.subtitle') : t('auth.loginSubtitle')">
    <form v-if="auth.mfaPending" class="flex flex-col gap-4" data-test="mfa-form" novalidate @submit.prevent="submitMfa">
      <p v-if="error" class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-sm" role="alert">{{ error }}</p>
      <FormField v-if="!useRecovery" id="mfa-code" :label="t('auth.mfa.code')" :hint="t('auth.mfa.codeHint')" required>
        <template #default="{ describedby, invalid }">
          <input id="mfa-code" v-model="mfaForm.code" type="text" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField v-else id="mfa-recovery" :label="t('auth.mfa.recoveryCode')" :hint="t('auth.mfa.recoveryHint')" required>
        <template #default="{ describedby, invalid }">
          <input id="mfa-recovery" v-model="mfaForm.recovery_code" type="text" class="input" autocomplete="off" autocapitalize="characters" maxlength="24" required autofocus :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <button type="submit" class="btn btn-primary w-full" :disabled="busy || (useRecovery ? !mfaForm.recovery_code : !mfaForm.code)">{{ busy ? t('common.processing') : t('auth.mfa.verify') }}</button>
      <div class="flex items-center justify-between text-sm">
        <button type="button" class="font-semibold text-brand-strong" data-test="mfa-toggle" @click="useRecovery = !useRecovery">{{ useRecovery ? t('auth.mfa.useCode') : t('auth.mfa.useRecovery') }}</button>
        <button type="button" class="text-fg-muted" data-test="mfa-back" @click="backToPassword">{{ t('auth.mfa.back') }}</button>
      </div>
    </form>
    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p v-if="error" class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-sm" role="alert">{{ error }}</p>
      <FormField id="email" :label="t('auth.email')" :error="fieldErrors.email" required>
        <template #default="{ describedby, invalid }">
          <input id="email" v-model.trim="form.email" type="email" class="input" autocomplete="email" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="password" :label="t('auth.password')" :error="fieldErrors.password" required>
        <template #default="{ describedby, invalid }">
          <input id="password" v-model="form.password" type="password" class="input" autocomplete="current-password" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <label class="flex items-center gap-2 text-sm"><input v-model="form.remember" type="checkbox" /> {{ t('auth.remember') }}</label>
      <button type="submit" class="btn btn-primary w-full" :disabled="busy || !form.email || !form.password">{{ busy ? t('common.processing') : t('auth.login') }}</button>
    </form>
    <template #below>
      {{ t('auth.noAccount') }} <RouterLink :to="{ name: 'register' }" class="font-semibold text-brand-strong">{{ t('auth.createAccount') }}</RouterLink>
    </template>
  </AuthShell>
</template>
