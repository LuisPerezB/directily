import { computed, ref, toValue, watch, type MaybeRefOrGetter, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { HorizonSupport } from '@/api/types'
import { useTenantStore } from '@/stores/tenant'
import { HORIZONS } from '@/utils/constants'
import { horizonSupportView } from '@/utils/envelope'
import { allowedHorizons, pickHorizon } from '@/utils/horizons'

/**
 * Selector de horizonte condicionado por el plan de la empresa (`/tenant` → plan.limits.horizons).
 * Mantiene el valor elegido siempre dentro de lo permitido (p. ej. si cambia la empresa o el plan).
 *
 * `support` (opcional): respaldo por horizonte de la ejecución vigente. Un horizonte permitido por el plan pero sin respaldo suficiente
 * (`insufficient_data`) sigue ofreciéndose, marcado y con su motivo en el título: la abstención se ve en el selector, no se oculta.
 */
export function useHorizonSelection<H extends number = (typeof HORIZONS)[number]>(
  domain: readonly H[] = HORIZONS as unknown as readonly H[],
  preferred?: number,
  support?: MaybeRefOrGetter<Record<number, HorizonSupport> | null | undefined>,
) {
  const { t, te } = useI18n()
  const tenant = useTenantStore()
  const allowed = computed<H[]>(() => allowedHorizons(domain, tenant.tenant?.plan?.limits))
  const options = computed(() =>
    allowed.value.map((h) => {
      const label = t('common.days', { n: h })
      const info = toValue(support)?.[h]
      const view = info ? horizonSupportView(info) : null
      if (!view || view.status === 'ok') return { value: h, label }
      const reasons = view.reasonItems.map((r) => (r.code && te(`horizonSupport.reasonCode.${r.code}`) ? t(`horizonSupport.reasonCode.${r.code}`) : r.message))
      const hint = [t(`horizonSupport.status.${view.status}`), ...new Set(reasons)].join(' · ')
      return { value: h, label, status: view.status, hint }
    }),
  )
  const horizon = ref(pickHorizon(allowed.value, preferred ?? tenant.tenant?.settings?.default_horizon_days ?? 30) ?? domain[0]!) as Ref<H>

  watch(allowed, (list) => {
    if (list.length && !list.includes(horizon.value)) {
      const next = pickHorizon(list, horizon.value)
      if (next !== null) horizon.value = next
    }
  })

  return { horizon, allowed, options, hasHorizons: computed(() => allowed.value.length > 0) }
}
