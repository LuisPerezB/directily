<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AuthShell from '@/layouts/AuthShell.vue'
import FormField from '@/components/ui/FormField.vue'
import { useAuthStore } from '@/stores/auth'
import { errorMessage, isApiError } from '@/api/errors'
import { isValidEmail, passwordStrength } from '@/utils/validation'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const form = reactive({ name: '', email: '', password: '', password_confirmation: '', accept_terms: false })
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string | undefined>>({})
const strength = computed(() => passwordStrength(form.password))

const clientErrors = computed(() => ({
  email: form.email && !isValidEmail(form.email) ? t('validation.email') : undefined,
  password: form.password && form.password.length < 8 ? t('validation.passwordMin') : undefined,
  password_confirmation: form.password_confirmation && form.password !== form.password_confirmation ? t('validation.passwordMatch') : undefined,
}))
const canSubmit = computed(() => form.name && isValidEmail(form.email) && form.password.length >= 8 && form.password === form.password_confirmation && form.accept_terms)

async function submit(): Promise<void> {
  if (!canSubmit.value) return
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    await auth.register({ ...form })
    await router.replace({ name: 'tenant-create' })
  } catch (e) {
    if (isApiError(e) && e.isValidation) {
      fieldErrors.value = Object.fromEntries(Object.keys(e.fieldErrors).map((k) => [k, e.field(k)]))
      error.value = e.message
    } else error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell :title="t('auth.registerTitle')" :subtitle="t('auth.registerSubtitle')">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <p v-if="error" class="rounded-lg bg-danger-soft text-danger px-3 py-2 text-sm" role="alert">{{ error }}</p>
      <FormField id="name" :label="t('auth.name')" :error="fieldErrors.name" required>
        <template #default="{ describedby, invalid }">
          <input id="name" v-model.trim="form.name" class="input" autocomplete="name" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="email" :label="t('auth.email')" :error="fieldErrors.email ?? clientErrors.email" required>
        <template #default="{ describedby, invalid }">
          <input id="email" v-model.trim="form.email" type="email" class="input" autocomplete="email" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="password" :label="t('auth.password')" :hint="form.password ? t(`auth.strength.${strength}`) : t('validation.passwordMin')" :error="fieldErrors.password ?? clientErrors.password" required>
        <template #default="{ describedby, invalid }">
          <input id="password" v-model="form.password" type="password" class="input" autocomplete="new-password" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <FormField id="password_confirmation" :label="t('auth.passwordConfirm')" :error="clientErrors.password_confirmation" required>
        <template #default="{ describedby, invalid }">
          <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="input" autocomplete="new-password" required :aria-describedby="describedby" :aria-invalid="invalid" />
        </template>
      </FormField>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.accept_terms" type="checkbox" class="mt-1" required />
        <span>{{ t('auth.acceptTerms') }}</span>
      </label>
      <p v-if="fieldErrors.accept_terms" class="field-error">{{ fieldErrors.accept_terms }}</p>
      <button type="submit" class="btn btn-primary w-full" :disabled="busy || !canSubmit">{{ busy ? t('common.processing') : t('auth.createAccount') }}</button>
    </form>
    <template #below>
      {{ t('auth.haveAccount') }} <RouterLink :to="{ name: 'login' }" class="font-semibold text-brand-strong">{{ t('auth.login') }}</RouterLink>
    </template>
  </AuthShell>
</template>
