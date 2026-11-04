<script setup lang="ts">
/**
 * Procedencia de una cifra (ADR-V25-22; V25-UIX-008 a -012): «Datos hasta <corte>» con aviso de antigüedad, unidad y moneda (o «unidad no declarada»),
 * incertidumbre, cobertura y si el dato es real, de demostración o simulado (sin declarar NO se supone real).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTenantStore } from '@/stores/tenant'
import { formatDate, todayISO } from '@/utils/format'
import { dataOriginKey, stalenessDays, tenantDataOrigin, type DataOriginKey } from '@/utils/enterprise'

const props = withDefaults(defineProps<{
  asOf?: string | null
  unit?: string | null
  currency?: string | null
  /** Texto de la incertidumbre (p. ej. «Intervalo empírico de validación» o «Incertidumbre desconocida»). */
  uncertainty?: string | null
  /** Cobertura de los datos que sustentan la cifra (texto ya formado). */
  coverage?: string | null
  /** Origen declarado; por omisión, el de la empresa (`settings.data_origin`). */
  origin?: DataOriginKey | string | null
  /** Oculta el origen (cuando la vista ya muestra la banda). */
  hideOrigin?: boolean
  /** Conteo vivo sin fecha de corte propia (p. ej. decisiones pendientes): «al momento de la consulta» en lugar de «corte no informado». */
  live?: boolean
  /** Fecha en que se generó el informe. NO es el corte de los datos: se muestra aparte («Informe generado el…») y no alimenta la antigüedad. */
  generatedAt?: string | null
}>(), { asOf: null, unit: null, currency: null, uncertainty: null, coverage: null, origin: undefined, hideOrigin: false, live: false, generatedAt: null })
const { t } = useI18n()
const tenant = useTenantStore()
const today = computed(() => todayISO(tenant.timezone))
const age = computed(() => stalenessDays(props.asOf, today.value))
const originKey = computed<DataOriginKey>(() => (props.origin === undefined ? tenantDataOrigin(tenant.tenant?.settings) : dataOriginKey(props.origin)))
</script>

<template>
  <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-fg-muted m-0" data-test="provenance">
    <span v-if="asOf" data-test="as-of">{{ t('enterprise.asOf', { date: formatDate(asOf) }) }}</span>
    <span v-else-if="live" data-test="as-of-live">{{ t('enterprise.asOfLive') }}</span>
    <span v-else data-test="as-of-missing">{{ t('enterprise.asOfMissing') }}</span>
    <span v-if="age !== null && age > 0" class="badge badge-warning" data-test="stale">{{ t('enterprise.stale', { n: age }) }}</span>
    <span v-if="generatedAt" data-test="generated-at">{{ t('enterprise.generatedAt', { date: formatDate(generatedAt) }) }}</span>
    <span data-test="unit">{{ unit ? t('enterprise.unit', { unit }) : t('enterprise.unitUndeclared') }}</span>
    <span v-if="currency" data-test="currency">{{ t('enterprise.currency', { currency }) }}</span>
    <span v-if="uncertainty" data-test="uncertainty">{{ t('enterprise.uncertainty', { text: uncertainty }) }}</span>
    <span v-if="coverage" data-test="coverage">{{ t('enterprise.coverage', { text: coverage }) }}</span>
    <span v-if="!hideOrigin" class="badge" :class="originKey === 'real' ? 'badge-success' : originKey === 'undeclared' ? '' : 'badge-warning'" data-test="origin" :data-origin="originKey">{{ t(`enterprise.origin.${originKey}`) }}</span>
  </p>
</template>
