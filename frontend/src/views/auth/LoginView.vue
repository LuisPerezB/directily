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
const busy = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string | undefined>>({})

async function submit(): Promise<void> {
  busy.value = true
  error.value = null
  fieldErrors.value = {}
  try {
    await auth.login({ ...form })
    tenant.ensureSelection()
    const redirect = safeRedirect(route.query.redirect)
    await router.replace(redirect ?? homeFor({ hasTenant: tenant.hasTenant, hasMemberships: auth.memberships.length > 0 }))
  } catch (e) {
    if (isApiError(e) && e.status === 429) error.value = t('auth.tooManyAttempts')
    else if (isApiError(e) && e.isValidation) fieldErrors.value = { email: e.field('email'), password: e.field('password') }
    else error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell :title="t('auth.loginTitle')" :subtitle="t('auth.loginSubtitle')">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
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
