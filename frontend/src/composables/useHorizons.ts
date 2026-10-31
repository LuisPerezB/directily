import { computed, ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTenantStore } from '@/stores/tenant'
import { HORIZONS } from '@/utils/constants'
import { allowedHorizons, pickHorizon } from '@/utils/horizons'

/**
 * Selector de horizonte condicionado por el plan de la empresa (`/tenant` → plan.limits.horizons).
 * Mantiene el valor elegido siempre dentro de lo permitido (p. ej. si cambia la empresa o el plan).
 */
export function useHorizonSelection<H extends number = (typeof HORIZONS)[number]>(
  domain: readonly H[] = HORIZONS as unknown as readonly H[],
  preferred?: number,
) {
  const { t } = useI18n()
  const tenant = useTenantStore()
  const allowed = computed<H[]>(() => allowedHorizons(domain, tenant.tenant?.plan?.limits))
  const options = computed(() => allowed.value.map((h) => ({ value: h, label: t('common.days', { n: h }) })))
  const horizon = ref(pickHorizon(allowed.value, preferred ?? tenant.tenant?.settings?.default_horizon_days ?? 30) ?? domain[0]!) as Ref<H>

  watch(allowed, (list) => {
    if (list.length && !list.includes(horizon.value)) {
      const next = pickHorizon(list, horizon.value)
      if (next !== null) horizon.value = next
    }
  })

  return { horizon, allowed, options, hasHorizons: computed(() => allowed.value.length > 0) }
}
