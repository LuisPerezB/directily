<script setup lang="ts">
/**
 * Cobertura de fuentes: hasta qué fecha está completa cada fuente. Dentro de la cobertura un día sin registros es un
 * CERO observado; fuera de ella (o sin cobertura declarada) es AUSENTE y no se convierte en cero.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { dataCoreApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { CoverageEntity } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateTime, todayISO } from '@/utils/format'

const ENTITIES: CoverageEntity[] = ['sales', 'inventory', 'purchases', 'costs', 'promotions', 'customers', 'commitments', 'calendar', 'externals']
const { t } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const ui = useUiStore()
const coverage = useAsync(() => dataCoreApi.coverage())
const effective = computed(() => ENTITIES.map((e) => ({ entity: e, eff: coverage.data.value?.effective?.[e] ?? null })))
const rows = computed(() => coverage.data.value?.data ?? [])

const form = reactive<{ entity: CoverageEntity; complete_from: string; complete_through: string; branch_id: number | null; notes: string }>({ entity: 'sales', complete_from: '', complete_through: '', branch_id: null, notes: '' })
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const message = ref<string | null>(null)
const today = computed(() => todayISO(tenant.timezone))

async function declare(): Promise<void> {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    await dataCoreApi.declareCoverage({
      entity: form.entity,
      complete_through: form.complete_through,
      ...(form.complete_from ? { complete_from: form.complete_from } : {}),
      ...(form.branch_id ? { branch_id: form.branch_id } : {}),
      ...(form.notes.trim() ? { notes: form.notes.trim() } : {}),
    })
    ui.notify(t('coverage.saved'), 'success')
    await coverage.execute()
  } catch (e) {
    if (isApiError(e) && e.isValidation) {
      errors.value = Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0] ?? '']))
    } else {
      message.value = errorMessage(e, t)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('coverage.title')" data-test="coverage-panel">
    <p class="text-sm text-fg-muted m-0 max-w-3xl">{{ t('coverage.intro') }}</p>
    <ErrorState v-if="coverage.error.value" :error="coverage.error.value" compact @retry="coverage.execute" />
    <template v-else>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('coverage.effectiveTitle') }}</caption>
          <thead><tr><th scope="col">{{ t('coverage.entity') }}</th><th scope="col">{{ t('coverage.completeFrom') }}</th><th scope="col">{{ t('coverage.completeThrough') }}</th><th scope="col">{{ t('coverage.rule') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in effective" :key="r.entity" :data-rule="r.eff?.rule ?? 'none'">
              <td class="font-medium">{{ t(`coverage.entities.${r.entity}`) }}</td>
              <td>{{ formatDate(r.eff?.complete_from) }}</td>
              <td>{{ formatDate(r.eff?.complete_through) }}</td>
              <td>
                <span class="badge" :class="r.eff?.rule === 'declared_by_user' ? 'badge-success' : r.eff?.rule === 'min_over_sources' ? 'badge-info' : 'badge-warning'">{{ t(`coverage.rules.${r.eff?.rule ?? 'none'}`) }}</span>
                <span v-if="!r.eff || r.eff.rule === 'none'" class="block text-xs text-fg-muted">{{ t('coverage.noneHint') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="rows.length">
        <h3 class="section-title mb-2">{{ t('coverage.declaredTitle') }}</h3>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('coverage.declaredTitle') }}</caption>
            <thead><tr><th scope="col">{{ t('coverage.entity') }}</th><th scope="col">{{ t('common.branch') }}</th><th scope="col">{{ t('coverage.completeThrough') }}</th><th scope="col">{{ t('coverage.basis') }}</th><th scope="col">{{ t('coverage.lastIngested') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id">
                <td>{{ t(`coverage.entities.${r.entity}`) }}<span v-if="r.data_source_id" class="block text-xs text-fg-muted">{{ t('coverage.fromSource', { id: r.data_source_id }) }}</span></td>
                <td>{{ r.branch_id ? catalog.branchName(r.branch_id) || `#${r.branch_id}` : t('filters.allBranches') }}</td>
                <td>{{ formatDate(r.complete_through) }}</td>
                <td>{{ t(`coverage.bases.${r.basis}`) }}</td>
                <td>{{ formatDateTime(r.last_ingested_at, tenant.timezone) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <form v-if="can('imports.manage')" class="rounded-xl border border-line p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="coverage-form" @submit.prevent="declare">
        <h3 class="section-title m-0 sm:col-span-2 lg:col-span-4">{{ t('coverage.declareTitle') }}</h3>
        <FormField id="cv-entity" :label="t('coverage.entity')">
          <template #default><select id="cv-entity" v-model="form.entity" class="select"><option v-for="e in ENTITIES" :key="e" :value="e">{{ t(`coverage.entities.${e}`) }}</option></select></template>
        </FormField>
        <FormField id="cv-from" :label="t('coverage.completeFrom')" :hint="t('coverage.fromHint')" :error="errors.complete_from">
          <template #default="{ describedby }"><input id="cv-from" v-model="form.complete_from" type="date" class="input" :max="today" :aria-describedby="describedby" /></template>
        </FormField>
        <FormField id="cv-through" :label="t('coverage.completeThrough')" :hint="t('coverage.throughHint')" :error="errors.complete_through" required>
          <template #default="{ describedby }"><input id="cv-through" v-model="form.complete_through" type="date" class="input" :max="today" required :aria-describedby="describedby" /></template>
        </FormField>
        <FormField id="cv-branch" :label="t('common.branch')" :error="errors.branch_id">
          <template #default><select id="cv-branch" v-model="form.branch_id" class="select"><option :value="null">{{ t('filters.allBranches') }}</option><option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></template>
        </FormField>
        <FormField id="cv-notes" :label="t('coverage.notes')" :error="errors.notes" class="sm:col-span-2 lg:col-span-3">
          <template #default><input id="cv-notes" v-model="form.notes" class="input" maxlength="500" /></template>
        </FormField>
        <div class="flex items-end"><button type="submit" class="btn btn-primary" :disabled="saving || !form.complete_through">{{ t('coverage.declare') }}</button></div>
        <p v-if="message" class="field-error sm:col-span-2 lg:col-span-4" role="alert">{{ message }}</p>
      </form>
      <InlineNotice v-else kind="info">{{ t('coverage.readOnly') }}</InlineNotice>
    </template>
  </section>
</template>
