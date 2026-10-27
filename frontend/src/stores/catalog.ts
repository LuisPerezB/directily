import { defineStore } from 'pinia'
import { ref } from 'vue'
import { catalogApi } from '@/api'
import type { Branch, ProductCategory } from '@/api/types'

/** Sucursales y categorías de la empresa activa, cargadas una vez y reutilizadas en filtros. */
export const useCatalogStore = defineStore('catalog', () => {
  const branches = ref<Branch[]>([])
  const categories = ref<ProductCategory[]>([])
  const loadedFor = ref<string | null>(null)
  let pending: Promise<void> | null = null

  async function ensureLoaded(tenantUuid: string | null, force = false): Promise<void> {
    if (!tenantUuid) return
    if (!force && loadedFor.value === tenantUuid) return
    pending ??= Promise.all([catalogApi.branches(), catalogApi.categories()])
      .then(([b, c]) => {
        branches.value = b
        categories.value = c
        loadedFor.value = tenantUuid
      })
      .finally(() => {
        pending = null
      })
    await pending
  }

  function reset(): void {
    branches.value = []
    categories.value = []
    loadedFor.value = null
  }

  function branchName(id: number | null | undefined): string {
    return branches.value.find((b) => b.id === id)?.name ?? ''
  }

  return { branches, categories, loadedFor, ensureLoaded, reset, branchName }
})
