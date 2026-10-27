<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { exportsApi, importsApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { ImportErrorRow, ImportJob } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useUiStore } from '@/stores/ui'
import { canConfirmImport } from '@/utils/importMapping'
import { formatDate, formatInteger, formatNumber } from '@/utils/format'

const props = defineProps<{ job: ImportJob; busy: boolean; canManage: boolean }>()
const emit = defineEmits<{ confirm: []; reject: []; edit: [] }>()
const { t, te } = useI18n()
const ui = useUiStore()

const severity = ref<'all' | 'error' | 'warning'>('all')
const page = ref(1)
watch(severity, () => (page.value = 1))
const errors = useAsync(() => importsApi.errors(props.job.id, { severity: severity.value === 'all' ? undefined : severity.value, page: page.value, per_page: 20 }), { watch: [severity, page] })

const stats = computed(() => props.job.stats ?? {})
const check = computed(() => canConfirmImport(props.job))
const quality = computed(() => props.job.quality_report)
const isSales = computed(() => props.job.entity_type === 'sales')

const columns = computed<Column<ImportErrorRow>[]>(() => [
  { key: 'row_number', label: t('imports.row'), align: 'right' },
  { key: 'column', label: t('imports.column') },
  { key: 'severity', label: t('imports.severity') },
  { key: 'error_code', label: t('imports.errorType'), format: (r) => (te(`imports.errorCodes.${r.error_code}`) ? t(`imports.errorCodes.${r.error_code}`) : r.error_code) },
  { key: 'message', label: t('imports.message') },
  { key: 'raw_value', label: t('imports.value') },
])
const normalizedHeaders = computed(() => Object.keys(props.job.normalized_preview?.[0] ?? {}))

async function downloadErrors(): Promise<void> {
  try {
    await exportsApi.importErrors(props.job.id)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <InlineNotice v-if="job.status === 'failed'" kind="danger" :title="t('imports.failedTitle')">{{ job.error_message || t('imports.failedText') }}</InlineNotice>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <KpiCard :label="t('imports.stats.rowsTotal')" :value="formatInteger(Number(stats.rows_total ?? 0))" :hint="stats.date_from ? t('imports.stats.range', { from: formatDate(String(stats.date_from)), to: formatDate(String(stats.date_to ?? '')) }) : null" icon="file" />
      <KpiCard :label="t('imports.stats.rowsValid')" :value="formatInteger(Number(stats.rows_valid ?? 0))" icon="check" tone="brand" />
      <KpiCard :label="t('imports.stats.rowsError')" :value="formatInteger(Number(stats.rows_error ?? 0))" :hint="t('imports.stats.warnings', { n: formatInteger(Number(stats.rows_warning ?? 0)) })" icon="alert" :tone="Number(stats.rows_error ?? 0) > 0 ? 'danger' : 'default'" />
      <KpiCard :label="t('imports.stats.duplicates')" :value="formatInteger(Number(stats.duplicates_in_file ?? 0))" :hint="t('imports.stats.duplicatesHint')" icon="copy" />
    </section>

    <section v-if="isSales" class="card !shadow-none p-4 sm:p-5">
      <h2 class="section-title mb-3">{{ t('imports.documentsTitle') }}</h2>
      <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-4 text-sm">
        <div v-for="k in ['documents_total', 'documents_new', 'documents_updated', 'documents_unchanged', 'voided_documents', 'return_lines', 'products_new', 'branches_new', 'missing_days']" :key="k" class="flex justify-between gap-2">
          <dt class="text-fg-muted">{{ t(`imports.stats.${k}`) }}</dt>
          <dd class="m-0 font-semibold tabular">{{ formatInteger(Number(stats[k] ?? 0)) }}</dd>
        </div>
      </dl>
      <p class="hint mt-3">{{ t('imports.documentsNote') }}</p>
    </section>

    <section v-if="quality" class="card !shadow-none p-4 sm:p-5">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h2 class="section-title">{{ t('imports.qualityTitle') }}</h2>
        <span v-if="quality.score !== null" class="badge" :class="quality.score >= 80 ? 'badge-success' : quality.score >= 60 ? 'badge-warning' : 'badge-danger'">{{ t('imports.qualityScore', { score: formatNumber(quality.score, 0) }) }}</span>
      </div>
      <ul v-if="quality.issues.length" class="flex flex-col gap-2 list-none p-0 m-0">
        <li v-for="(issue, i) in quality.issues" :key="i" class="flex items-start gap-2 text-sm">
          <StatusBadge domain="severity" :value="issue.severity === 'error' ? 'critical' : issue.severity" />
          <span>{{ issue.message }}<template v-if="issue.count"> ({{ formatInteger(issue.count) }})</template></span>
        </li>
      </ul>
      <p v-else class="text-sm text-success">{{ t('imports.noIssues') }}</p>
      <p class="hint mt-2">{{ t('imports.qualityNote') }}</p>
    </section>

    <section class="card !shadow-none p-2 sm:p-4">
      <div class="flex flex-wrap items-center justify-between gap-3 px-2 pt-2 pb-3">
        <h2 class="section-title">{{ t('imports.errorsTitle') }}</h2>
        <div class="flex flex-wrap items-center gap-2">
          <SegmentedControl v-model="severity" size="sm" :options="[{ value: 'all', label: t('filters.all') }, { value: 'error', label: t('imports.errorsOnly') }, { value: 'warning', label: t('imports.warningsOnly') }]" :label="t('imports.severity')" />
          <button type="button" class="btn btn-ghost btn-sm" @click="downloadErrors"><AppIcon name="download" :size="15" /> {{ t('imports.downloadErrors') }}</button>
        </div>
      </div>
      <DataTable :columns="columns" :rows="errors.data.value?.data ?? []" :row-key="(r) => `${r.row_number}-${r.column}-${r.error_code}`" :loading="errors.loading.value" :caption="t('imports.errorsTitle')" :empty-text="t('imports.noErrors')">
        <template #cell-severity="{ row }"><span class="badge" :class="row.severity === 'error' ? 'badge-danger' : 'badge-warning'">{{ t(`imports.sev.${row.severity}`) }}</span></template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="errors.data.value?.meta ?? null" />
    </section>

    <section v-if="job.normalized_preview?.length" class="card !shadow-none p-4 sm:p-5">
      <h2 class="section-title mb-1">{{ t('imports.normalizedTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-3">{{ t('imports.normalizedText') }}</p>
      <div class="table-wrap max-h-72 overflow-y-auto rounded-xl border border-line">
        <table class="table">
          <caption class="sr-only">{{ t('imports.normalizedTitle') }}</caption>
          <thead><tr><th v-for="h in normalizedHeaders" :key="h" scope="col">{{ h }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in job.normalized_preview" :key="i">
              <td v-for="h in normalizedHeaders" :key="h" class="whitespace-nowrap">{{ row[h] ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="canManage" class="card p-4 sm:p-5 flex flex-col gap-3">
      <InlineNotice v-if="check.reason === 'has_errors'" kind="warning">{{ t('imports.confirmBlocked') }}</InlineNotice>
      <InlineNotice v-else-if="check.reason === 'no_valid_rows'" kind="danger">{{ t('imports.noValidRows') }}</InlineNotice>
      <InlineNotice v-else-if="check.allowed && Number(stats.rows_error ?? 0) > 0" kind="info">{{ t('imports.willSkip', { n: formatInteger(Number(stats.rows_error ?? 0)) }) }}</InlineNotice>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" :disabled="busy || !check.allowed" @click="emit('confirm')"><AppIcon name="check" :size="16" /> {{ t('imports.confirm') }}</button>
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('edit')">{{ t('imports.editMapping') }}</button>
        <button type="button" class="btn btn-ghost" :disabled="busy" @click="emit('reject')">{{ t('imports.reject') }}</button>
      </div>
    </div>
  </div>
</template>
