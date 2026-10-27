<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import TenantSwitcher from './TenantSwitcher.vue'
import AlertsBell from './AlertsBell.vue'
import ThemeToggle from './ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useTenantStore } from '@/stores/tenant'

const auth = useAuthStore()
const ui = useUiStore()
const tenant = useTenantStore()
const router = useRouter()
const { t } = useI18n()

async function logout(): Promise<void> {
  await auth.logout()
  tenant.clear()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="sticky top-0 z-30 h-16 bg-surface/90 backdrop-blur border-b border-line flex items-center gap-2 px-3 sm:px-5">
    <button type="button" class="btn btn-ghost btn-sm lg:hidden" :aria-label="t('nav.openMenu')" :aria-expanded="ui.mobileNavOpen" @click="ui.mobileNavOpen = true">
      <AppIcon name="menu" :size="20" />
    </button>
    <TenantSwitcher />
    <span v-if="tenant.role" class="badge hidden sm:inline-flex">{{ t(`roles.${tenant.role}`) }}</span>
    <div class="flex-1" />
    <AlertsBell />
    <ThemeToggle />
    <RouterLink v-if="auth.isSuperadmin" :to="{ name: 'admin' }" class="btn btn-ghost btn-sm hidden sm:inline-flex" :title="t('nav.admin')">
      <AppIcon name="shield" :size="18" />
    </RouterLink>
    <div class="hidden md:flex flex-col items-end leading-tight mx-1 min-w-0">
      <span class="text-sm font-semibold truncate max-w-[12rem]">{{ auth.user?.name }}</span>
      <span class="text-xs text-fg-muted truncate max-w-[12rem]">{{ auth.user?.email }}</span>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('auth.logout')" :title="t('auth.logout')" @click="logout">
      <AppIcon name="logout" :size="18" />
    </button>
  </header>
</template>
