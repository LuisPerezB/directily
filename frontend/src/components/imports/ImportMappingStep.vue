<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { Branch, ColumnMapping, ImportField, ImportJob, ImportOptions } from '@/api/types'
import { compactMapping, initialMapping, validateMapping } from '@/utils/importMapping'

const props = defineProps<{ job: ImportJob; fields: ImportField[]; branches: Branch[]; saving: boolean; error: string | null }>()
const emit = defineEmits<{ submit: [mapping: ColumnMapping, options: ImportOptions]; cancel: [] }>()
const { t } = useI18n()

const mapping = reactive<ColumnMapping>(initialMapping(props.fields, props.job))
watch(
  () => props.fields,
  (fields) => Object.assign(mapping, initialMapping(fields, props.job)),
)

const defaults: ImportOptions = {
  date_format: 'auto',
  decimal_separator: '.',
  csv_delimiter: 'auto',
  default_branch_code: props.branches.length === 1 ? props.branches[0]!.code : null,
  create_missing_products: true,
  create_missing_branches: false,
  create_missing_categories: true,
  skip_invalid_rows: false,
}
const options = reactive<ImportOptions>({ ...defaults, ...(props.job.options ?? {}) })
const columns = computed(() => props.job.detected_columns ?? [])
const validation = computed(() => validateMapping(props.fields, mapping))
const suggested = computed(() => props.job.suggested_mapping ?? {})
const labelOf = (key: string) => props.fields.find((f) => f.key === key)?.label ?? key
const isSales = computed(() => props.job.entity_type === 'sales')
const usesBranch = computed(() => props.fields.some((f) => f.key === 'branch_code'))

function submit(): void {
  if (!validation.value.valid) return
  emit('submit', compactMapping({ ...mapping }), { ...options })
}
</script>

<template>
  <form class="flex flex-col gap-6" @submit.prevent="submit">
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <AppIcon name="file" :size="16" />
      <span class="font-semibold">{{ job.original_filename }}</span>
      <span class="text-fg-muted">· {{ t('imports.columnsDetected', { n: columns.length }) }}</span>
    </div>

    <section>
      <h2 class="section-title mb-1">{{ t('imports.mappingTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-4">{{ t('imports.mappingText') }}</p>
      <div class="grid gap-3 md:grid-cols-2">
        <div v-for="field in fields" :key="field.key" class="rounded-xl border border-line p-3">
          <label :for="`map-${field.key}`" class="label !mb-1">
            {{ field.label }}<span v-if="field.required" class="text-danger" aria-hidden="true"> *</span>
            <span v-if="field.required" class="sr-only">({{ t('common.required') }})</span>
          </label>
          <select
            :id="`map-${field.key}`"
            v-model="mapping[field.key]"
            class="select"
            :aria-invalid="validation.missingRequired.includes(field.key) || (mapping[field.key] && validation.duplicateColumns.includes(mapping[field.key]!)) ? 'true' : undefined"
          >
            <option :value="null">{{ t('imports.notMapped') }}</option>
            <option v-for="c in columns" :key="c" :value="c">{{ c }}</option>
          </select>
          <p v-if="suggested[field.key] && suggested[field.key] === mapping[field.key]" class="hint mt-1 text-success">{{ t('imports.suggested') }}</p>
          <p v-else-if="field.description" class="hint mt-1">{{ field.description }}</p>
        </div>
      </div>
      <InlineNotice v-if="validation.missingRequired.length" kind="warning" class="mt-3">
        {{ t('imports.missingRequired', { fields: validation.missingRequired.map(labelOf).join(', ') }) }}
      </InlineNotice>
      <InlineNotice v-if="validation.duplicateColumns.length" kind="warning" class="mt-3">
        {{ t('imports.duplicateColumns', { columns: validation.duplicateColumns.join(', ') }) }}
      </InlineNotice>
    </section>

    <section v-if="job.preview && job.preview.headers.length">
      <h2 class="section-title mb-1">{{ t('imports.previewTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-3">{{ t('imports.previewText', { n: job.preview.rows.length }) }}</p>
      <div class="table-wrap max-h-80 overflow-y-auto rounded-xl border border-line">
        <table class="table">
          <caption class="sr-only">{{ t('imports.previewTitle') }}</caption>
          <thead>
            <tr><th v-for="h in job.preview.headers" :key="h" scope="col">{{ h }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in job.preview.rows" :key="i">
              <td v-for="(cell, j) in row" :key="j" class="whitespace-nowrap">{{ cell ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section>
      <h2 class="section-title mb-3">{{ t('imports.optionsTitle') }}</h2>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label class="label" for="opt-date">{{ t('imports.options.dateFormat') }}</label>
          <select id="opt-date" v-model="options.date_format" class="select">
            <option value="auto">{{ t('imports.options.auto') }}</option>
            <option value="Y-m-d">AAAA-MM-DD</option>
            <option value="d/m/Y">DD/MM/AAAA</option>
            <option value="m/d/Y">MM/DD/AAAA</option>
            <option value="d-m-Y">DD-MM-AAAA</option>
          </select>
        </div>
        <div>
          <label class="label" for="opt-dec">{{ t('imports.options.decimal') }}</label>
          <select id="opt-dec" v-model="options.decimal_separator" class="select">
            <option value=".">{{ t('imports.options.dot') }}</option>
            <option value=",">{{ t('imports.options.comma') }}</option>
          </select>
        </div>
        <div v-if="job.file_format === 'csv'">
          <label class="label" for="opt-delim">{{ t('imports.options.delimiter') }}</label>
          <select id="opt-delim" v-model="options.csv_delimiter" class="select">
            <option value="auto">{{ t('imports.options.auto') }}</option>
            <option value=",">{{ t('imports.options.commaChar') }}</option>
            <option value=";">{{ t('imports.options.semicolon') }}</option>
            <option :value="'\t'">{{ t('imports.options.tab') }}</option>
          </select>
        </div>
        <div v-if="usesBranch">
          <label class="label" for="opt-branch">{{ t('imports.options.defaultBranch') }}</label>
          <select id="opt-branch" v-model="options.default_branch_code" class="select">
            <option :value="null">{{ t('imports.options.noDefault') }}</option>
            <option v-for="b in branches" :key="b.id" :value="b.code">{{ b.code }} · {{ b.name }}</option>
          </select>
        </div>
      </div>
      <div class="mt-4 flex flex-col gap-2 text-sm">
        <label v-if="isSales || job.entity_type === 'inventory'" class="flex items-start gap-2"><input v-model="options.create_missing_products" type="checkbox" class="mt-1" /> {{ t('imports.options.createProducts') }}</label>
        <label v-if="isSales || job.entity_type === 'products'" class="flex items-start gap-2"><input v-model="options.create_missing_categories" type="checkbox" class="mt-1" /> {{ t('imports.options.createCategories') }}</label>
        <label v-if="usesBranch" class="flex items-start gap-2"><input v-model="options.create_missing_branches" type="checkbox" class="mt-1" /> {{ t('imports.options.createBranches') }}</label>
        <label class="flex items-start gap-2"><input v-model="options.skip_invalid_rows" type="checkbox" class="mt-1" /> {{ t('imports.options.skipInvalid') }}</label>
      </div>
    </section>

    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div class="flex flex-wrap gap-2">
      <button type="submit" class="btn btn-primary" :disabled="saving || !validation.valid">{{ saving ? t('common.processing') : t('imports.validate') }}</button>
      <button type="button" class="btn btn-ghost" @click="emit('cancel')">{{ t('imports.discard') }}</button>
    </div>
  </form>
</template>
