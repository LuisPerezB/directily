<script setup lang="ts">
/**
 * Configuración efectiva por módulo (core) con la procedencia de cada valor: core, perfil sectorial o empresa
 * (precedencia core → perfil → empresa). Los valores de empresa se editan aquí; el motor valida (claves desconocidas,
 * tipos y rangos) y los errores se muestran junto a su clave. Cambiar la configuración no modifica pronósticos pasados.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { configApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { ConfigCore, EffectiveConfig, PlatformExtensions } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useUiStore } from '@/stores/ui'
import { shortHash } from '@/utils/envelope'
import { applyDraftValue, configIssues, formatConfigValue, issuesByKey, parseConfigValue, profileOfSource, sourceKind, valuesEqual } from '@/utils/configEdit'

const CORES: ConfigCore[] = ['demand', 'customer', 'supply', 'cost', 'scenario']
const { t } = useI18n()
const { can } = useAbility()
const ui = useUiStore()
const core = ref<ConfigCore>('demand')
const config = useAsync(() => configApi.effective(core.value), { watch: [core] })
const extensions = useAsync(() => configApi.extensions())
const canEdit = computed(() => can('settings.manage'))

const draft = ref<Record<string, unknown>>({})
const texts = reactive<Record<string, string>>({})
const localErrors = ref<Record<string, string>>({})
const serverIssues = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)
const saving = ref(false)

/** Valor heredado = el que tendría la clave sin la sobrescritura de empresa (core o perfil). */
const inherited = reactive<Record<string, unknown>>({})
watch(
  () => config.data.value,
  (c: EffectiveConfig | null) => {
    if (!c) return
    draft.value = { ...c.tenant_overrides }
    for (const k of Object.keys(texts)) delete texts[k]
    for (const k of Object.keys(inherited)) delete inherited[k]
    for (const row of c.keys) {
      texts[row.key] = formatConfigValue(row.value)
      // Si el valor viene de la empresa, el heredado es desconocido en el cliente: se conserva el valor actual como referencia de tipo.
      inherited[row.key] = row.source === 'tenant' ? undefined : row.value
    }
    localErrors.value = {}
    serverIssues.value = {}
    generalError.value = null
  },
  { immediate: true },
)

const rows = computed(() => config.data.value?.keys ?? [])
const dirty = computed(() => !valuesEqual(draft.value, config.data.value?.tenant_overrides ?? {}))
const profile = computed(() => config.data.value?.profile ?? null)

function edit(key: string, original: unknown, raw: string): void {
  texts[key] = raw
  const parsed = parseConfigValue(raw, original)
  if (!parsed.ok) {
    localErrors.value = { ...localErrors.value, [key]: t(`effectiveConfig.invalid.${parsed.message}`) }
    return
  }
  const { [key]: _removed, ...rest } = localErrors.value
  void _removed
  localErrors.value = rest
  // Un valor igual al heredado no es una sobrescritura.
  draft.value = inherited[key] === undefined ? { ...draft.value, [key]: parsed.value } : applyDraftValue(draft.value, key, parsed.value, inherited[key])
}

function restore(key: string): void {
  const next = { ...draft.value }
  delete next[key]
  draft.value = next
}

async function save(): Promise<void> {
  if (Object.keys(localErrors.value).length) return
  saving.value = true
  serverIssues.value = {}
  generalError.value = null
  try {
    config.data.value = await configApi.saveOverrides(core.value, draft.value)
    ui.notify(t('effectiveConfig.saved'), 'success')
  } catch (e) {
    const issues = configIssues(e)
    serverIssues.value = issuesByKey(issues)
    generalError.value = issues.length ? t('effectiveConfig.rejected') : isApiError(e) && e.code === 'engine_unavailable' ? t('effectiveConfig.engineUnavailable') : errorMessage(e, t)
  } finally {
    saving.value = false
  }
}

const isEdited = (key: string): boolean => key in draft.value
const sourceText = (s: string): string => (sourceKind(s) === 'profile' ? t('effectiveConfig.sources.profile', { name: profileOfSource(s) ?? '' }) : t(`effectiveConfig.sources.${sourceKind(s)}`))
const extension = computed<PlatformExtensions | null>(() => extensions.data.value)
</script>

<template>
  <div class="flex flex-col gap-5" data-test="effective-config">
    <InlineNotice kind="info">{{ t('effectiveConfig.intro') }}</InlineNotice>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="ec-core">{{ t('effectiveConfig.module') }}</label>
        <select id="ec-core" v-model="core" class="select !w-auto min-w-[14rem]"><option v-for="c in CORES" :key="c" :value="c">{{ t(`effectiveConfig.cores.${c}`) }}</option></select>
      </div>
      <p v-if="config.data.value?.config_version" class="text-sm text-fg-muted m-0">{{ t('effectiveConfig.version') }}: <span class="font-mono" :title="config.data.value.config_version">{{ shortHash(config.data.value.config_version) }}</span></p>
    </div>

    <p v-if="profile" class="text-sm m-0" data-test="profile-applied">
      {{ t('effectiveConfig.profileApplied', { code: profile.code, version: profile.version }) }}
      <span v-if="profile.synthetic" class="badge badge-warning ml-1">{{ t('effectiveConfig.syntheticProfile') }}</span>
    </p>
    <p v-else-if="config.data.value" class="text-sm text-fg-muted m-0" data-test="no-profile">{{ t('effectiveConfig.noProfile') }}</p>

    <LoadingState v-if="config.loading.value && !config.data.value" :lines="4" />
    <ErrorState v-else-if="config.error.value" :error="config.error.value" compact @retry="config.execute" />
    <template v-else-if="config.data.value">
      <InlineNotice v-if="generalError" kind="danger" data-test="config-error">{{ generalError }}</InlineNotice>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t(`effectiveConfig.cores.${core}`) }}</caption>
          <thead><tr><th scope="col">{{ t('effectiveConfig.key') }}</th><th scope="col">{{ t('effectiveConfig.value') }}</th><th scope="col">{{ t('effectiveConfig.origin') }}</th><th v-if="canEdit" scope="col"></th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" :data-key="row.key">
              <td><code class="text-xs">{{ row.key }}</code></td>
              <td>
                <template v-if="canEdit">
                  <label class="sr-only" :for="`ec-${row.key}`">{{ row.key }}</label>
                  <select v-if="typeof row.value === 'boolean'" :id="`ec-${row.key}`" class="select !min-h-9" :value="String(isEdited(row.key) ? draft[row.key] : row.value)" @change="edit(row.key, row.value, ($event.target as HTMLSelectElement).value)">
                    <option value="true">{{ t('common.yes') }}</option><option value="false">{{ t('common.no') }}</option>
                  </select>
                  <input v-else :id="`ec-${row.key}`" class="input !min-h-9 font-mono text-xs" :value="texts[row.key]" :aria-invalid="localErrors[row.key] || serverIssues[row.key] ? true : undefined" @input="edit(row.key, row.value, ($event.target as HTMLInputElement).value)" />
                </template>
                <span v-else class="font-mono text-xs break-all">{{ formatConfigValue(row.value) }}</span>
                <p v-if="row.source === 'tenant' && !isEdited(row.key)" class="hint m-0" data-test="will-restore">{{ t('effectiveConfig.willRestore') }}</p>
                <p v-if="localErrors[row.key]" class="field-error" role="alert">{{ localErrors[row.key] }}</p>
                <p v-for="(m, i) in serverIssues[row.key] ?? []" :key="i" class="field-error" role="alert" data-test="config-issue">{{ m }}</p>
              </td>
              <td>
                <span class="badge" :class="sourceKind(isEdited(row.key) ? 'tenant' : row.source) === 'tenant' ? 'badge-brand' : sourceKind(row.source) === 'profile' ? 'badge-info' : ''" :data-source="isEdited(row.key) ? 'tenant' : sourceKind(row.source)">{{ sourceText(isEdited(row.key) ? 'tenant' : row.source) }}</span>
              </td>
              <td v-if="canEdit"><button v-if="isEdited(row.key)" type="button" class="btn btn-ghost btn-sm" @click="restore(row.key)">{{ t('effectiveConfig.restore') }}</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="canEdit" class="flex flex-wrap items-center gap-3">
        <button type="button" class="btn btn-primary" :disabled="saving || !dirty || Object.keys(localErrors).length > 0" data-test="save-config" @click="save">{{ t('common.save') }}</button>
        <span v-if="dirty" class="hint m-0">{{ t('effectiveConfig.unsaved') }}</span>
      </div>
      <p v-else class="hint m-0">{{ t('effectiveConfig.readOnly') }}</p>
      <p class="hint m-0">{{ config.data.value.note }}</p>
    </template>

    <div v-if="extension?.providers?.length" data-test="extensions">
      <h3 class="section-title mb-1">{{ t('effectiveConfig.extensionsTitle') }}</h3>
      <ul class="list-none p-0 m-0 flex flex-col gap-1 text-sm">
        <li v-for="p in extension.providers" :key="`${p.kind}-${p.name}`"><span class="badge mr-1">{{ p.kind }}</span><span class="font-medium">{{ p.name }}</span> <span class="text-fg-muted">v{{ p.version }} · {{ p.temporal_availability }}</span></li>
      </ul>
    </div>
  </div>
</template>
