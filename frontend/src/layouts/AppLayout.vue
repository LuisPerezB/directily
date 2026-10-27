<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import AppTopbar from '@/components/layout/AppTopbar.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useUiStore } from '@/stores/ui'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'

const ui = useUiStore()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const route = useRoute()
const { t } = useI18n()

onMounted(() => void catalog.ensureLoaded(tenant.currentUuid).catch(() => undefined))
watch(() => tenant.currentUuid, (uuid) => void catalog.ensureLoaded(uuid).catch(() => undefined))
watch(() => route.fullPath, () => (ui.mobileNavOpen = false))
</script>

<template>
  <div class="min-h-screen flex">
    <a href="#main" class="sr-only-focusable fixed top-2 left-2 z-[70] btn btn-primary">{{ t('layout.skipToContent') }}</a>
    <aside class="hidden lg:block sticky top-0 h-screen shrink-0">
      <AppSidebar />
    </aside>
    <div v-if="ui.mobileNavOpen" class="fixed inset-0 z-40 lg:hidden">
      <div class="absolute inset-0 bg-black/45" aria-hidden="true" @click="ui.mobileNavOpen = false" />
      <div class="absolute inset-y-0 left-0 shadow-xl">
        <AppSidebar mobile @navigate="ui.mobileNavOpen = false" />
        <button type="button" class="btn btn-secondary btn-sm absolute top-3 -right-12" :aria-label="t('nav.closeMenu')" @click="ui.mobileNavOpen = false">
          <AppIcon name="x" :size="18" />
        </button>
      </div>
    </div>
    <div class="flex-1 min-w-0 flex flex-col">
      <AppTopbar />
      <main id="main" tabindex="-1" class="flex-1 w-full max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div v-if="tenant.tenant?.status && tenant.tenant.status !== 'active'" class="mb-4 rounded-xl bg-warning-soft text-warning px-4 py-3 text-sm">
          {{ t('layout.tenantInactive') }}
        </div>
        <RouterView />
      </main>
    </div>
  </div>
</template>
