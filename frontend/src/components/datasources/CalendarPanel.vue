<script setup lang="ts">
/**
 * Calendario de eventos con alcance, estado y fecha de conocimiento (`known_at`). Un evento sin `known_at` no se usa como
 * conocido a futuro en un corte histórico. Cambiar un evento crea una revisión; "cancelar" no borra el historial.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import CrudPanel, { type FieldDef } from '@/components/ui/CrudPanel.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { dataCoreApi } from '@/api'
import type { CalendarCategory, CalendarEvent, CalendarEventPayload, CalendarScope } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useTenantStore } from '@/stores/tenant'
import { formatDate, formatDateTime } from '@/utils/format'

const SCOPES: CalendarScope[] = ['country', 'region', 'tenant', 'branch', 'category', 'product', 'sector']
const CATEGORIES: CalendarCategory[] = ['holiday', 'local_holiday', 'commercial_season', 'campaign', 'promotion', 'sector_event', 'agricultural_season', 'international_peak', 'exceptional']
const { t } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const scope = ref<string>('')
const category = ref<string>('')
const status = ref<string>('')
const includePublic = ref(true)
const events = useAsync(() => dataCoreApi.calendar({ scope: scope.value || undefined, category: category.value || undefined, status: status.value || undefined, include_public: includePublic.value, limit: 500 }), { watch: [scope, category, status, includePublic] })
const rows = computed(() => events.data.value?.data ?? [])

const fields = computed<FieldDef[]>(() => [
  { key: 'scope', label: t('calendar.fields.scope'), type: 'select', required: true, options: SCOPES.map((s) => ({ value: s, label: t(`calendar.scopes.${s}`) })), hint: t('calendar.hints.scope') },
  { key: 'scope_ref', label: t('calendar.fields.scopeRef'), type: 'text', maxlength: 80, hint: t('calendar.hints.scopeRef') },
  { key: 'code', label: t('calendar.fields.code'), type: 'text', required: true, maxlength: 80 },
  { key: 'name', label: t('calendar.fields.name'), type: 'text', required: true, maxlength: 200 },
  { key: 'category', label: t('calendar.fields.category'), type: 'select', required: true, options: CATEGORIES.map((c) => ({ value: c, label: t(`calendar.categories.${c}`) })) },
  { key: 'starts_on', label: t('calendar.fields.startsOn'), type: 'date', required: true },
  { key: 'ends_on', label: t('calendar.fields.endsOn'), type: 'date' },
  { key: 'status', label: t('calendar.fields.status'), type: 'select', options: ['planned', 'confirmed', 'cancelled'].map((s) => ({ value: s, label: t(`status.calendar.${s}`) })) },
  { key: 'known_at', label: t('calendar.fields.knownAt'), type: 'date', hint: t('calendar.hints.knownAt') },
])

const columns = computed(() => [
  { key: 'name', label: t('calendar.fields.name') },
  { key: 'scope', label: t('calendar.fields.scope') },
  { key: 'category', label: t('calendar.fields.category'), format: (e: CalendarEvent) => t(`calendar.categories.${e.category}`) },
  { key: 'starts_on', label: t('calendar.dates') },
  { key: 'status', label: t('common.status') },
  { key: 'known_at', label: t('calendar.fields.knownAt') },
])
const cancelEvent = (e: CalendarEvent): Promise<unknown> => dataCoreApi.cancelCalendarEvent(e.id)
const rowActions = (e: CalendarEvent): { edit: boolean; remove: boolean } => ({ edit: false, remove: !e.is_public && e.status !== 'cancelled' })
async function create(payload: Record<string, unknown>): Promise<unknown> {
  return dataCoreApi.createCalendarEvent(payload as unknown as CalendarEventPayload)
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('calendar.title')" data-test="calendar-panel">
    <p class="text-sm text-fg-muted m-0 max-w-3xl">{{ t('calendar.intro') }}</p>
    <div class="flex flex-wrap items-end gap-3">
      <div><label class="label" for="cal-scope">{{ t('calendar.fields.scope') }}</label><select id="cal-scope" v-model="scope" class="select !w-auto"><option value="">{{ t('calendar.all') }}</option><option v-for="s in SCOPES" :key="s" :value="s">{{ t(`calendar.scopes.${s}`) }}</option></select></div>
      <div><label class="label" for="cal-cat">{{ t('calendar.fields.category') }}</label><select id="cal-cat" v-model="category" class="select !w-auto"><option value="">{{ t('calendar.all') }}</option><option v-for="c in CATEGORIES" :key="c" :value="c">{{ t(`calendar.categories.${c}`) }}</option></select></div>
      <div><label class="label" for="cal-status">{{ t('common.status') }}</label><select id="cal-status" v-model="status" class="select !w-auto"><option value="">{{ t('calendar.all') }}</option><option v-for="s in ['planned', 'confirmed', 'cancelled']" :key="s" :value="s">{{ t(`status.calendar.${s}`) }}</option></select></div>
      <label class="flex items-center gap-2 text-sm min-h-10"><input v-model="includePublic" type="checkbox" /> {{ t('calendar.includePublic') }}</label>
    </div>
    <InlineNotice v-if="rows.some((e) => e.known_at_unknown)" kind="warning" data-test="unknown-known-at">{{ t('calendar.unknownWarning') }}</InlineNotice>
    <CrudPanel
      :title="t('calendar.title')"
      :columns="columns"
      :rows="rows"
      :fields="fields"
      :loading="events.loading.value"
      :error="events.error.value"
      :can-write="can('catalog.manage')"
      :create="create"
      :remove="cancelEvent"
      :row-actions="rowActions"
      :remove-title="t('calendar.cancelTitle')"
      :remove-text="t('calendar.cancelText')"
      :remove-confirm="t('calendar.cancel')"
      :row-label="(e) => e.name"
      :defaults="{ scope: 'tenant', category: 'campaign', status: 'planned' }"
      :add-label="t('calendar.add')"
      :empty-text="t('calendar.empty')"
      @changed="events.execute"
      @retry="events.execute"
    >
      <template #cell-name="{ row }">
        <span class="font-medium block">{{ row.name }}</span>
        <span class="text-xs text-fg-muted font-mono">{{ row.code }}</span>
        <span v-if="row.is_public" class="badge ml-1">{{ t('calendar.public') }}</span>
        <span class="text-xs text-fg-muted ml-1">{{ t('calendar.revision', { n: row.revision }) }}</span>
      </template>
      <template #cell-scope="{ row }">{{ t(`calendar.scopes.${row.scope}`) }}<span v-if="row.scope_ref" class="block text-xs text-fg-muted">{{ row.scope_ref }}</span></template>
      <template #cell-starts_on="{ row }">{{ formatDate(row.starts_on) }}<template v-if="row.ends_on !== row.starts_on"> – {{ formatDate(row.ends_on) }}</template></template>
      <template #cell-status="{ row }"><StatusBadge domain="calendar" :value="row.status" /></template>
      <template #cell-known_at="{ row }">
        <span v-if="row.known_at_unknown" class="badge badge-warning" data-test="known-at-unknown">{{ t('calendar.knownAtUnknown') }}</span>
        <span v-else>{{ formatDateTime(row.known_at, tenant.timezone) }}</span>
      </template>
    </CrudPanel>
  </section>
</template>
