<script setup lang="ts" generic="R extends { id: number }">
/**
 * Tabla + formulario modal para recursos de configuración (ofertas, tasas, capacidades, presupuestos, almacenes...).
 * - Crear, editar y eliminar según las funciones recibidas y el permiso (`canWrite`).
 * - Los errores 422 de la API se muestran junto a su campo (o arriba si no se asocian a uno).
 * - Los campos desconocidos se envían como `null` (nunca como cero).
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'
import AppIcon from './AppIcon.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import DataTable, { type Column } from './DataTable.vue'
import ErrorState from './ErrorState.vue'
import EmptyState from './EmptyState.vue'
import FormField from './FormField.vue'
import { errorMessage, isApiError } from '@/api/errors'
import { useUiStore } from '@/stores/ui'

export interface FieldDef {
  key: string
  label: string
  type: 'text' | 'number' | 'date' | 'select' | 'checkbox' | 'textarea'
  required?: boolean
  hint?: string
  options?: { value: string | number; label: string }[]
  step?: number | string
  min?: number
  max?: number
  maxlength?: number
  /** Se muestra solo al crear (claves naturales que no se cambian después). */
  createOnly?: boolean
}

const props = withDefaults(
  defineProps<{
    title: string
    description?: string | null
    columns: Column<R>[]
    rows: R[]
    fields: FieldDef[]
    loading?: boolean
    error?: unknown
    canWrite?: boolean
    create?: (payload: Record<string, unknown>) => Promise<unknown>
    update?: (id: number, payload: Record<string, unknown>) => Promise<unknown>
    remove?: (row: R) => Promise<unknown>
    /** Valores iniciales de un registro existente (por defecto, las mismas claves de la fila). */
    toForm?: (row: R) => Record<string, unknown>
    defaults?: Record<string, unknown>
    emptyTitle?: string
    emptyText?: string
    addLabel?: string | null
    rowLabel?: (row: R) => string
  }>(),
  { description: null, loading: false, canWrite: false, defaults: () => ({}), emptyTitle: '', emptyText: '', addLabel: null },
)
const emit = defineEmits<{ changed: []; retry: [] }>()
const { t } = useI18n()
const ui = useUiStore()
const uid = Math.random().toString(36).slice(2, 7)

const open = ref(false)
const editing = ref<R | null>(null)
const form = reactive<Record<string, unknown>>({})
const busy = ref(false)
const fieldErrors = ref<Record<string, string>>({})
const formError = ref<string | null>(null)
const toDelete = ref<R | null>(null)

const visibleFields = computed(() => props.fields.filter((f) => !f.createOnly || editing.value === null))
const tableColumns = computed<Column<R>[]>(() => (props.canWrite && (props.update || props.remove) ? [...props.columns, { key: '__actions', label: '' }] : props.columns))

function fresh(): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const f of props.fields) out[f.key] = f.type === 'checkbox' ? true : f.type === 'number' ? null : ''
  return { ...out, ...props.defaults }
}

function openCreate(): void {
  editing.value = null
  for (const k of Object.keys(form)) delete form[k]
  Object.assign(form, fresh())
  fieldErrors.value = {}
  formError.value = null
  open.value = true
}

function openEdit(row: R): void {
  editing.value = row
  for (const k of Object.keys(form)) delete form[k]
  const base = props.toForm ? props.toForm(row) : (row as unknown as Record<string, unknown>)
  const values: Record<string, unknown> = {}
  for (const f of props.fields) values[f.key] = base[f.key] ?? (f.type === 'checkbox' ? true : f.type === 'number' ? null : '')
  Object.assign(form, values)
  fieldErrors.value = {}
  formError.value = null
  open.value = true
}

/** Vacío → null (desconocido); números → número; el resto, texto sin espacios sobrantes. */
function payload(): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const f of visibleFields.value) {
    const v = form[f.key]
    if (f.type === 'checkbox') out[f.key] = Boolean(v)
    else if (f.type === 'number') out[f.key] = v === '' || v === null || v === undefined ? null : Number(v)
    else if (f.type === 'select') out[f.key] = v === '' || v === null || v === undefined ? null : v
    else {
      const text = typeof v === 'string' ? v.trim() : v
      out[f.key] = text === '' ? null : text
    }
  }
  return out
}

async function submit(): Promise<void> {
  busy.value = true
  formError.value = null
  fieldErrors.value = {}
  try {
    if (editing.value && props.update) await props.update(editing.value.id, payload())
    else if (props.create) await props.create(payload())
    open.value = false
    ui.notify(t('crud.saved'), 'success')
    emit('changed')
  } catch (e) {
    if (isApiError(e) && e.isValidation) {
      const mapped: Record<string, string> = {}
      for (const [key, messages] of Object.entries(e.fieldErrors)) {
        const msg = messages[0]
        if (msg && props.fields.some((f) => f.key === key)) mapped[key] = msg
      }
      fieldErrors.value = mapped
      const unmapped = Object.entries(e.fieldErrors).filter(([k]) => !mapped[k]).map(([, m]) => m[0]).filter(Boolean)
      formError.value = unmapped.length ? unmapped.join(' ') : Object.keys(mapped).length ? null : errorMessage(e, t)
    } else {
      formError.value = errorMessage(e, t)
    }
  } finally {
    busy.value = false
  }
}

async function confirmRemove(): Promise<void> {
  if (!toDelete.value || !props.remove) return
  busy.value = true
  try {
    await props.remove(toDelete.value)
    emit('changed')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    busy.value = false
    toDelete.value = null
  }
}

const deleteName = computed(() => (toDelete.value && props.rowLabel ? props.rowLabel(toDelete.value) : ''))
</script>

<template>
  <section class="flex flex-col gap-3" :aria-label="title">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 class="section-title m-0">{{ title }}</h3>
        <p v-if="description" class="text-sm text-fg-muted m-0 mt-0.5 max-w-3xl">{{ description }}</p>
      </div>
      <button v-if="canWrite && create" type="button" class="btn btn-secondary btn-sm" @click="openCreate">
        <AppIcon name="plus" :size="15" /> {{ addLabel ?? t('crud.add') }}
      </button>
    </header>
    <slot name="before" />
    <ErrorState v-if="error" :error="error" compact @retry="emit('retry')" />
    <template v-else>
      <EmptyState v-if="!loading && rows.length === 0 && emptyTitle" icon="layers" :title="emptyTitle" :description="emptyText" compact />
      <DataTable v-else :columns="tableColumns" :rows="rows" :row-key="(r) => r.id" :loading="loading" :caption="title" :empty-text="emptyText || t('table.empty')">
        <template v-for="(_, name) in $slots" :key="name" #[name]="slotProps">
          <slot :name="name" v-bind="slotProps ?? {}" />
        </template>
        <template #cell-__actions="{ row }">
          <span class="inline-flex gap-1">
            <button v-if="update" type="button" class="btn btn-ghost btn-sm" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="remove" type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" @click="toDelete = row"><AppIcon name="trash" :size="15" /></button>
          </span>
        </template>
      </DataTable>
    </template>

    <AppModal :open="open" :title="editing ? t('crud.editTitle', { name: title }) : t('crud.createTitle', { name: title })" size="lg" @close="open = false">
      <form :id="`crud-${uid}`" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
        <template v-for="f in visibleFields" :key="f.key">
          <FormField :id="`crud-${uid}-${f.key}`" :label="f.label" :hint="f.hint ?? null" :error="fieldErrors[f.key] ?? null" :required="f.required" :class="f.type === 'textarea' ? 'sm:col-span-2' : ''">
            <template #default="{ describedby, invalid }">
              <select v-if="f.type === 'select'" :id="`crud-${uid}-${f.key}`" v-model="form[f.key]" class="select" :required="f.required" :aria-describedby="describedby" :aria-invalid="invalid">
                <option value="">{{ t('common.select') }}</option>
                <option v-for="o in f.options ?? []" :key="String(o.value)" :value="o.value">{{ o.label }}</option>
              </select>
              <label v-else-if="f.type === 'checkbox'" class="flex items-center gap-2 text-sm min-h-10">
                <input :id="`crud-${uid}-${f.key}`" v-model="form[f.key]" type="checkbox" :aria-describedby="describedby" /> {{ t('common.yes') }}
              </label>
              <textarea v-else-if="f.type === 'textarea'" :id="`crud-${uid}-${f.key}`" v-model="form[f.key] as string" class="textarea" rows="2" :maxlength="f.maxlength" :aria-describedby="describedby" :aria-invalid="invalid" />
              <input
                v-else
                :id="`crud-${uid}-${f.key}`"
                v-model="form[f.key]"
                class="input"
                :type="f.type"
                :step="f.step"
                :min="f.min"
                :max="f.max"
                :maxlength="f.maxlength"
                :required="f.required"
                :aria-describedby="describedby"
                :aria-invalid="invalid"
              />
            </template>
          </FormField>
        </template>
        <slot name="form-extra" :form="form" :errors="fieldErrors" />
        <p v-if="formError" class="field-error sm:col-span-2" role="alert" data-test="crud-error">{{ formError }}</p>
      </form>
      <template #footer>
        <button type="button" class="btn btn-secondary" @click="open = false">{{ t('common.cancel') }}</button>
        <button type="submit" :form="`crud-${uid}`" class="btn btn-primary" :disabled="busy">{{ t('common.save') }}</button>
      </template>
    </AppModal>

    <ConfirmDialog :open="toDelete !== null" :title="t('crud.deleteTitle')" :message="t('crud.deleteText', { name: deleteName })" danger :busy="busy" :confirm-label="t('common.delete')" @confirm="confirmRemove" @cancel="toDelete = null" />
  </section>
</template>
