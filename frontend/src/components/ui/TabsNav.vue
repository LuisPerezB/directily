<script setup lang="ts">
/** Pestañas accesibles (tablist). El contenido lo renderiza el padre según el modelo. */
const model = defineModel<string>({ required: true })
defineProps<{ tabs: { key: string; label: string }[]; label: string; idPrefix?: string }>()
</script>

<template>
  <div role="tablist" :aria-label="label" class="flex gap-1 border-b border-line overflow-x-auto">
    <button
      v-for="tab in tabs"
      :id="`${idPrefix ?? 'tab'}-${tab.key}`"
      :key="tab.key"
      type="button"
      role="tab"
      :aria-selected="model === tab.key"
      :aria-controls="`${idPrefix ?? 'tab'}-panel`"
      class="px-3 sm:px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px whitespace-nowrap transition-colors"
      :class="model === tab.key ? 'border-brand text-brand-strong' : 'border-transparent text-fg-muted hover:text-fg'"
      @click="model = tab.key"
    >
      {{ tab.label }}
    </button>
  </div>
</template>
