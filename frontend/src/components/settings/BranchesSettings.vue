<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from '@/components/ui/AppModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { catalogApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { Branch, BranchPayload } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'

const { t } = useI18n()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const ui = useUiStore()
const canEdit = computed(() => tenant.can('branches.manage'))
const branches = useAsync(() => catalogApi.branches())

const PROVINCES = ['Azuay', 'Bolívar', 'Cañar', 'Carchi', 'Chimborazo', 'Cotopaxi', 'El Oro', 'Esmeraldas', 'Galápagos', 'Guayas', 'Imbabura', 'Loja', 'Los Ríos', 'Manabí', 'Morona Santiago', 'Napo', 'Orellana', 'Pastaza', 'Pichincha', 'Santa Elena', 'Santo Domingo de los Tsáchilas', 'Sucumbíos', 'Tungurahua', 'Zamora Chinchipe']

const editing = ref<Branch | null>(null)
const open = ref(false)
const saving = ref(false)
const fieldErrors = ref<Record<string, string | undefined>>({})
const toDelete = ref<Branch | null>(null)
const empty = (): BranchPayload => ({ code: '', name: '', province: null, canton: null, commercial_zone: null, address: null, is_active: true })
const form = reactive<BranchPayload>(empty())

function startCreate(): void {
  editing.value = null
  Object.assign(form, empty())
  fieldErrors.value = {}
  open.value = true
}
function startEdit(b: Branch): void {
  editing.value = b
  Object.assign(form, { code: b.code, name: b.name, province: b.province, canton: b.canton, commercial_zone: b.commercial_zone, address: b.address, is_active: b.is_active })
  fieldErrors.value = {}
  open.value = true
}

async function save(): Promise<void> {
  saving.value = true
  fieldErrors.value = {}
  try {
    if (editing.value) await catalogApi.updateBranch(editing.value.id, { ...form })
    else await catalogApi.createBranch({ ...form })
    open.value = false
    await branches.execute()
    await catalog.ensureLoaded(tenant.currentUuid, true)
    void tenant.load().catch(() => undefined)
    ui.notify(t('settings.saved'), 'success')
  } catch (e) {
    if (isApiError(e) && e.isValidation) fieldErrors.value = Object.fromEntries(Object.keys(e.fieldErrors).map((k) => [k, e.field(k)]))
    else ui.notify(errorMessage(e, t), 'error')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!toDelete.value) return
  try {
    await catalogApi.deleteBranch(toDelete.value.id)
    await branches.execute()
    await catalog.ensureLoaded(tenant.currentUuid, true)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    toDelete.value = null
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm text-fg-muted m-0 max-w-2xl">{{ t('settings.branchesIntro') }}</p>
      <button v-if="canEdit" type="button" class="btn btn-primary btn-sm" @click="startCreate"><AppIcon name="plus" :size="15" /> {{ t('settings.addBranch') }}</button>
    </div>
    <ErrorState v-if="branches.error.value" :error="branches.error.value" compact @retry="branches.execute" />
    <EmptyState v-else-if="branches.loaded.value && !branches.data.value?.length" icon="building" :title="t('settings.noBranches')" :description="t('settings.noBranchesText')" compact />
    <div v-else class="table-wrap">
      <table class="table">
        <caption class="sr-only">{{ t('settings.tabs.sucursales') }}</caption>
        <thead><tr><th scope="col">{{ t('settings.code') }}</th><th scope="col">{{ t('settings.branchName') }}</th><th scope="col">{{ t('settings.province') }}</th><th scope="col">{{ t('settings.canton') }}</th><th scope="col">{{ t('common.status') }}</th><th scope="col"></th></tr></thead>
        <tbody>
          <tr v-for="b in branches.data.value ?? []" :key="b.id">
            <td class="font-mono text-xs">{{ b.code }}</td>
            <td class="font-medium">{{ b.name }}</td>
            <td>{{ b.province ?? '—' }}</td>
            <td>{{ b.canton ?? '—' }}</td>
            <td><span class="badge" :class="b.is_active ? 'badge-success' : ''">{{ b.is_active ? t('common.active') : t('common.inactive') }}</span></td>
            <td class="text-right whitespace-nowrap">
              <template v-if="canEdit">
                <button type="button" class="btn btn-ghost btn-sm" @click="startEdit(b)">{{ t('common.edit') }}</button>
                <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" @click="toDelete = b"><AppIcon name="trash" :size="15" /></button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AppModal :open="open" :title="editing ? t('settings.editBranch') : t('settings.addBranch')" @close="open = false">
      <form id="branch-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="save">
        <div>
          <label class="label" for="b-code">{{ t('settings.code') }} *</label>
          <input id="b-code" v-model.trim="form.code" class="input" required maxlength="50" :aria-invalid="fieldErrors.code ? 'true' : undefined" />
          <p class="hint mt-1">{{ t('settings.codeHint') }}</p>
          <p v-if="fieldErrors.code" class="field-error">{{ fieldErrors.code }}</p>
        </div>
        <div>
          <label class="label" for="b-name">{{ t('settings.branchName') }} *</label>
          <input id="b-name" v-model.trim="form.name" class="input" required maxlength="150" />
          <p v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name }}</p>
        </div>
        <div>
          <label class="label" for="b-prov">{{ t('settings.province') }}</label>
          <select id="b-prov" v-model="form.province" class="select">
            <option :value="null">—</option>
            <option v-for="p in PROVINCES" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="b-canton">{{ t('settings.canton') }}</label>
          <input id="b-canton" v-model.trim="form.canton" class="input" maxlength="80" />
        </div>
        <div>
          <label class="label" for="b-zone">{{ t('settings.zone') }}</label>
          <input id="b-zone" v-model.trim="form.commercial_zone" class="input" maxlength="80" />
        </div>
        <div>
          <label class="label" for="b-addr">{{ t('settings.address') }}</label>
          <input id="b-addr" v-model.trim="form.address" class="input" maxlength="255" />
        </div>
        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.is_active" type="checkbox" /> {{ t('common.active') }}</label>
      </form>
      <template #footer>
        <button type="button" class="btn btn-secondary" @click="open = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="branch-form" class="btn btn-primary" :disabled="saving || !form.code || !form.name">{{ t('common.save') }}</button>
      </template>
    </AppModal>
    <ConfirmDialog :open="toDelete !== null" :title="t('settings.deleteBranch')" :message="t('settings.deleteBranchText', { name: toDelete?.name ?? '' })" danger :confirm-label="t('common.delete')" @confirm="remove" @cancel="toDelete = null" />
  </div>
</template>
