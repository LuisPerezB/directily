<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'

const auth = useAuthStore()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const router = useRouter()
const { t } = useI18n()

async function change(event: Event): Promise<void> {
  const value = (event.target as HTMLSelectElement).value
  if (value === '__new') {
    await router.push({ name: 'tenant-create' })
    return
  }
  tenant.select(value)
  catalog.reset()
  await tenant.load()
  await router.push({ name: 'dashboard' })
}
</script>

<template>
  <div class="min-w-0">
    <label for="tenant-switcher" class="sr-only">{{ t('layout.activeCompany') }}</label>
    <select id="tenant-switcher" class="select !min-h-9 !py-1 text-sm font-semibold max-w-[14rem] truncate" :value="tenant.currentUuid ?? ''" @change="change">
      <option v-for="m in auth.memberships" :key="m.uuid" :value="m.uuid">{{ m.name }}</option>
      <option value="__new">+ {{ t('layout.newCompany') }}</option>
    </select>
  </div>
</template>
