<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from '@/components/ui/AppModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { tenantApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { TenantRole, TenantUser } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { ROLES } from '@/utils/abilities'
import { formatDate } from '@/utils/format'
import { isValidEmail } from '@/utils/validation'

const { t } = useI18n()
const tenant = useTenantStore()
const auth = useAuthStore()
const ui = useUiStore()
const users = useAsync(() => tenantApi.users())
const assignable = computed(() => (tenant.can('owners.manage') ? ROLES : ROLES.filter((r) => r !== 'owner')))

const showAdd = ref(false)
const form = reactive<{ email: string; name: string; role: TenantRole }>({ email: '', name: '', role: 'viewer' })
const adding = ref(false)
const addError = ref<string | null>(null)
const tempPassword = ref<string | null>(null)
const toRemove = ref<TenantUser | null>(null)

async function add(): Promise<void> {
  adding.value = true
  addError.value = null
  try {
    const created = await tenantApi.addUser({ email: form.email.trim(), role: form.role, name: form.name.trim() || undefined })
    tempPassword.value = created?.temporary_password ?? null
    if (!tempPassword.value) showAdd.value = false
    form.email = ''
    form.name = ''
    await users.execute()
    ui.notify(t('settings.userAdded'), 'success')
  } catch (e) {
    addError.value = errorMessage(e, t)
  } finally {
    adding.value = false
  }
}

async function changeRole(u: TenantUser, role: TenantRole): Promise<void> {
  try {
    await tenantApi.updateUser(u.id, role)
    await users.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
    await users.execute()
  }
}

async function remove(): Promise<void> {
  if (!toRemove.value) return
  try {
    await tenantApi.removeUser(toRemove.value.id)
    await users.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    toRemove.value = null
  }
}

function closeAdd(): void {
  showAdd.value = false
  tempPassword.value = null
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-fg-muted m-0 max-w-2xl">{{ t('settings.usersIntro') }}</p>
      <button type="button" class="btn btn-primary btn-sm" @click="showAdd = true"><AppIcon name="plus" :size="15" /> {{ t('settings.addUser') }}</button>
    </div>
    <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 text-sm">
      <div v-for="r in ROLES" :key="r" class="rounded-xl bg-surface-2 p-3">
        <dt class="font-semibold">{{ t(`roles.${r}`) }}</dt>
        <dd class="m-0 text-fg-muted text-xs">{{ t(`roleDescriptions.${r}`) }}</dd>
      </div>
    </dl>
    <ErrorState v-if="users.error.value" :error="users.error.value" compact @retry="users.execute" />
    <div v-else class="table-wrap">
      <table class="table">
        <caption class="sr-only">{{ t('settings.tabs.usuarios') }}</caption>
        <thead><tr><th scope="col">{{ t('auth.name') }}</th><th scope="col">{{ t('auth.email') }}</th><th scope="col">{{ t('settings.role') }}</th><th scope="col">{{ t('settings.joined') }}</th><th scope="col"></th></tr></thead>
        <tbody>
          <tr v-for="u in users.data.value ?? []" :key="u.id">
            <td class="font-medium">{{ u.name }}<span v-if="u.id === auth.user?.id" class="badge ml-1">{{ t('settings.you') }}</span></td>
            <td>{{ u.email }}</td>
            <td>
              <label class="sr-only" :for="`role-${u.id}`">{{ t('settings.role') }}</label>
              <select :id="`role-${u.id}`" class="select !w-auto !min-h-8 !py-1 text-sm" :value="u.role" :disabled="u.id === auth.user?.id || (u.role === 'owner' && !tenant.can('owners.manage'))" @change="changeRole(u, ($event.target as HTMLSelectElement).value as TenantRole)">
                <option v-for="r in (u.role === 'owner' && !tenant.can('owners.manage') ? ROLES : assignable)" :key="r" :value="r">{{ t(`roles.${r}`) }}</option>
              </select>
            </td>
            <td>{{ formatDate(u.joined_at) }}</td>
            <td class="text-right">
              <button v-if="u.id !== auth.user?.id && (u.role !== 'owner' || tenant.can('owners.manage'))" type="button" class="btn btn-ghost btn-sm" :aria-label="t('settings.removeUser')" @click="toRemove = u"><AppIcon name="trash" :size="15" /></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AppModal :open="showAdd" :title="t('settings.addUser')" @close="closeAdd">
      <template v-if="tempPassword">
        <InlineNotice kind="warning" class="mb-3">{{ t('settings.tempPasswordNote') }}</InlineNotice>
        <label class="label" for="u-temp">{{ t('settings.tempPassword') }}</label>
        <input id="u-temp" :value="tempPassword" class="input font-mono" readonly />
      </template>
      <form v-else id="user-form" class="flex flex-col gap-4" @submit.prevent="add">
        <div>
          <label class="label" for="u-email">{{ t('auth.email') }} *</label>
          <input id="u-email" v-model="form.email" type="email" class="input" required autocomplete="off" />
        </div>
        <div>
          <label class="label" for="u-name">{{ t('auth.name') }}</label>
          <input id="u-name" v-model="form.name" class="input" maxlength="150" />
          <p class="hint mt-1">{{ t('settings.userNameHint') }}</p>
        </div>
        <div>
          <label class="label" for="u-role">{{ t('settings.role') }}</label>
          <select id="u-role" v-model="form.role" class="select">
            <option v-for="r in assignable" :key="r" :value="r">{{ t(`roles.${r}`) }}</option>
          </select>
        </div>
        <p v-if="addError" class="field-error" role="alert">{{ addError }}</p>
      </form>
      <template #footer>
        <template v-if="tempPassword"><button type="button" class="btn btn-primary" @click="closeAdd">{{ t('common.close') }}</button></template>
        <template v-else>
          <button type="button" class="btn btn-secondary" @click="closeAdd">{{ t('common.cancel') }}</button>
          <button type="submit" form="user-form" class="btn btn-primary" :disabled="adding || !isValidEmail(form.email)">{{ t('settings.addUser') }}</button>
        </template>
      </template>
    </AppModal>
    <ConfirmDialog :open="toRemove !== null" :title="t('settings.removeUser')" :message="t('settings.removeUserText', { name: toRemove?.name ?? '' })" danger :confirm-label="t('settings.removeUser')" @confirm="remove" @cancel="toRemove = null" />
  </div>
</template>
