<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import { alertsApi } from '@/api'
import { useTenantStore } from '@/stores/tenant'

/** Contador de alertas abiertas (consulta periódica cada 2 minutos). */
const { t } = useI18n()
const tenant = useTenantStore()
const count = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

async function refresh(): Promise<void> {
  if (!tenant.hasTenant || !tenant.can('alerts.view')) return
  try {
    const page = await alertsApi.list({ status: 'open', per_page: 1 })
    count.value = page.meta?.total ?? page.data.length
  } catch {
    // Silencioso: el contador es informativo.
  }
}

onMounted(() => {
  void refresh()
  timer = setInterval(() => void refresh(), 120_000)
})
onBeforeUnmount(() => timer && clearInterval(timer))
watch(() => tenant.currentUuid, () => void refresh())
defineExpose({ refresh })
</script>

<template>
  <RouterLink :to="{ name: 'alerts' }" class="btn btn-ghost btn-sm relative" :aria-label="t('layout.alertsCount', { count })">
    <AppIcon name="bell" :size="18" />
    <span v-if="count > 0" class="absolute -top-0.5 -right-0.5 min-w-[1.15rem] h-[1.15rem] px-1 rounded-full bg-danger text-white text-[0.68rem] font-bold flex items-center justify-center tabular">
      {{ count > 99 ? '99+' : count }}
    </span>
  </RouterLink>
</template>
