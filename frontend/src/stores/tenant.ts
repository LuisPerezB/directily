import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { tenantApi } from '@/api'
import type { CreateTenantPayload, Tenant, TenantRole, UpdateTenantPayload } from '@/api/types'
import { roleCan, type Ability } from '@/utils/abilities'
import { storageGet, storageSet } from '@/utils/storage'
import { useAuthStore } from './auth'

const STORAGE_KEY = 'predictily.tenant'

export const useTenantStore = defineStore('tenant', () => {
  const currentUuid = ref<string | null>(storageGet(STORAGE_KEY))
  const tenant = ref<Tenant | null>(null)
  const loading = ref(false)

  const auth = useAuthStore()

  const membership = computed(() => auth.memberships.find((m) => m.uuid === currentUuid.value) ?? null)
  const role = computed<TenantRole | null>(() => tenant.value?.role ?? membership.value?.role ?? null)
  const hasTenant = computed(() => currentUuid.value !== null && membership.value !== null)
  const currency = computed(() => tenant.value?.currency ?? 'USD')
  const timezone = computed(() => tenant.value?.timezone ?? 'America/Guayaquil')
  const onboarding = computed(() => tenant.value?.onboarding ?? null)

  function can(ability: Ability): boolean {
    return roleCan(role.value, ability)
  }

  /** Elige una empresa válida: la guardada si sigue disponible, si no la primera. */
  function ensureSelection(): string | null {
    const list = auth.memberships
    if (currentUuid.value && list.some((m) => m.uuid === currentUuid.value)) return currentUuid.value
    const first = list[0]?.uuid ?? null
    select(first)
    return first
  }

  function select(uuid: string | null): void {
    if (uuid !== currentUuid.value) tenant.value = null
    currentUuid.value = uuid
    storageSet(STORAGE_KEY, uuid)
  }

  async function load(): Promise<Tenant | null> {
    if (!currentUuid.value) return null
    loading.value = true
    try {
      tenant.value = await tenantApi.current()
      return tenant.value
    } finally {
      loading.value = false
    }
  }

  async function create(payload: CreateTenantPayload): Promise<Tenant> {
    const created = await tenantApi.create(payload)
    await auth.refresh()
    select(created.uuid)
    await load()
    return created
  }

  async function update(payload: UpdateTenantPayload): Promise<Tenant> {
    const updated = await tenantApi.update(payload)
    tenant.value = { ...(tenant.value ?? updated), ...updated }
    return tenant.value
  }

  function clear(): void {
    tenant.value = null
  }

  return { currentUuid, tenant, loading, membership, role, hasTenant, currency, timezone, onboarding, can, ensureSelection, select, load, create, update, clear }
})
