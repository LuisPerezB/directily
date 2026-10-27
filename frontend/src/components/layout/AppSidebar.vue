<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppIcon, { type IconName } from '@/components/ui/AppIcon.vue'
import PredictilyLogo from '@/components/brand/PredictilyLogo.vue'
import { useUiStore } from '@/stores/ui'
import { useTenantStore } from '@/stores/tenant'
import type { Ability } from '@/utils/abilities'

const props = withDefaults(defineProps<{ mobile?: boolean }>(), { mobile: false })
const emit = defineEmits<{ navigate: [] }>()
const ui = useUiStore()
const tenant = useTenantStore()
const { t } = useI18n()

interface NavItem { name: string; label: string; icon: IconName; ability?: Ability }

const groups = computed<{ title: string; items: NavItem[] }[]>(() => [
  {
    title: t('nav.groupAnalysis'),
    items: [
      { name: 'dashboard', label: t('nav.dashboard'), icon: 'home' },
      { name: 'forecasts', label: t('nav.forecasts'), icon: 'chart', ability: 'forecast.view' },
      { name: 'products', label: t('nav.products'), icon: 'box', ability: 'data.view' },
      { name: 'inventory', label: t('nav.inventory'), icon: 'warehouse', ability: 'forecast.view' },
      { name: 'accuracy', label: t('nav.accuracy'), icon: 'target', ability: 'forecast.view' },
      { name: 'scenarios', label: t('nav.scenarios'), icon: 'sliders', ability: 'forecast.view' },
    ],
  },
  {
    title: t('nav.groupData'),
    items: [
      { name: 'data-sources', label: t('nav.dataSources'), icon: 'database', ability: 'data.view' },
      { name: 'import-new', label: t('nav.import'), icon: 'upload', ability: 'imports.manage' },
      { name: 'alerts', label: t('nav.alerts'), icon: 'bell', ability: 'alerts.view' },
    ],
  },
  {
    title: t('nav.groupAccount'),
    items: [
      { name: 'onboarding', label: t('nav.onboarding'), icon: 'rocket' },
      { name: 'settings', label: t('nav.settings'), icon: 'settings' },
    ],
  },
])

const collapsed = computed(() => !props.mobile && ui.sidebarCollapsed)
</script>

<template>
  <nav :aria-label="t('nav.main')" class="flex flex-col h-full bg-surface border-r border-line" :class="collapsed ? 'w-[4.5rem]' : 'w-64'">
    <div class="flex items-center h-16 px-4 shrink-0" :class="collapsed ? 'justify-center px-2' : ''">
      <RouterLink :to="{ name: 'dashboard' }" class="no-underline" @click="emit('navigate')">
        <PredictilyLogo :size="30" :with-text="!collapsed" />
      </RouterLink>
    </div>
    <div class="flex-1 overflow-y-auto px-3 pb-4">
      <div v-for="group in groups" :key="group.title" class="mt-4">
        <p v-if="!collapsed" class="px-3 mb-1 text-[0.7rem] font-bold uppercase tracking-wider text-fg-muted">{{ group.title }}</p>
        <ul class="flex flex-col gap-0.5 list-none m-0 p-0">
          <template v-for="item in group.items" :key="item.name">
            <li v-if="!item.ability || tenant.can(item.ability)">
              <RouterLink
                :to="{ name: item.name }"
                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium no-underline text-fg-muted hover:bg-surface-2 hover:text-fg"
                active-class="!bg-brand-soft !text-brand-strong"
                :class="collapsed ? 'justify-center px-2' : ''"
                :title="collapsed ? item.label : undefined"
                @click="emit('navigate')"
              >
                <AppIcon :name="item.icon" :size="19" />
                <span v-if="!collapsed">{{ item.label }}</span>
                <span v-else class="sr-only">{{ item.label }}</span>
              </RouterLink>
            </li>
          </template>
        </ul>
      </div>
    </div>
    <div v-if="!mobile" class="p-3 border-t border-line">
      <button type="button" class="btn btn-ghost btn-sm w-full" :aria-label="collapsed ? t('nav.expand') : t('nav.collapse')" :aria-expanded="!collapsed" @click="ui.toggleSidebar()">
        <AppIcon :name="collapsed ? 'chevron-right' : 'chevron-left'" :size="16" />
        <span v-if="!collapsed">{{ t('nav.collapse') }}</span>
      </button>
    </div>
  </nav>
</template>
