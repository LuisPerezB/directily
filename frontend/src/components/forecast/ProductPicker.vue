<script setup lang="ts">
/** Buscador de producto (combobox accesible) contra GET /products?search=. */
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import { catalogApi } from '@/api'
import type { Product } from '@/api/types'

const model = defineModel<Product | null>({ default: null })
const props = withDefaults(defineProps<{ id: string; categoryId?: number | null }>(), { categoryId: null })
const { t } = useI18n()
const query = ref('')
const results = ref<Product[]>([])
const open = ref(false)
const active = ref(-1)
const loading = ref(false)
let timer: ReturnType<typeof setTimeout> | null = null
let seq = 0

async function search(): Promise<void> {
  const current = ++seq
  loading.value = true
  try {
    const page = await catalogApi.products({ search: query.value || undefined, category_id: props.categoryId ?? undefined, per_page: 10, is_active: true })
    if (current === seq) results.value = page.data
  } catch {
    if (current === seq) results.value = []
  } finally {
    if (current === seq) loading.value = false
  }
}

watch(query, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    if (open.value) void search()
  }, 250)
})

function onFocus(): void {
  open.value = true
  void search()
}

function choose(p: Product): void {
  model.value = p
  query.value = ''
  open.value = false
}

function clear(): void {
  model.value = null
}

function onBlur(): void {
  setTimeout(() => (open.value = false), 150)
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    open.value = true
    active.value = Math.min(results.value.length - 1, active.value + 1)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    active.value = Math.max(0, active.value - 1)
  } else if (e.key === 'Enter' && open.value && results.value[active.value]) {
    e.preventDefault()
    choose(results.value[active.value]!)
  } else if (e.key === 'Escape') {
    open.value = false
  }
}
</script>

<template>
  <div class="relative">
    <div v-if="model" class="input flex items-center justify-between gap-2">
      <span class="truncate text-sm"><span class="font-semibold">{{ model.sku }}</span> · {{ model.name }}</span>
      <button type="button" class="btn btn-ghost btn-sm !min-h-6 !p-1" :aria-label="t('filters.clearProduct')" @click="clear"><AppIcon name="x" :size="14" /></button>
    </div>
    <template v-else>
      <input
        :id="id"
        v-model="query"
        class="input"
        type="search"
        role="combobox"
        autocomplete="off"
        :aria-expanded="open"
        :aria-controls="`${id}-list`"
        :aria-activedescendant="active >= 0 ? `${id}-opt-${active}` : undefined"
        :placeholder="t('filters.searchProduct')"
        @focus="onFocus"
        @blur="onBlur"
        @keydown="onKey"
      />
      <ul v-if="open" :id="`${id}-list`" role="listbox" class="absolute z-20 mt-1 w-full card !rounded-xl max-h-72 overflow-y-auto list-none p-1 m-0">
        <li v-if="loading && !results.length" class="px-3 py-2 text-sm text-fg-muted">{{ t('common.loading') }}</li>
        <li v-else-if="!results.length" class="px-3 py-2 text-sm text-fg-muted">{{ t('filters.noProducts') }}</li>
        <li
          v-for="(p, i) in results"
          :id="`${id}-opt-${i}`"
          :key="p.id"
          role="option"
          :aria-selected="i === active"
          class="px-3 py-2 rounded-lg text-sm cursor-pointer"
          :class="i === active ? 'bg-brand-soft' : 'hover:bg-surface-2'"
          @mousedown.prevent="choose(p)"
        >
          <span class="font-semibold">{{ p.sku }}</span> · {{ p.name }}
          <span v-if="p.category" class="text-xs text-fg-muted"> — {{ p.category.name }}</span>
        </li>
      </ul>
    </template>
  </div>
</template>
