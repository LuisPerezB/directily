<script setup lang="ts" generic="V extends string | number">
/** Control segmentado accesible (radiogroup) para opciones cortas: horizonte, granularidad. */
const model = defineModel<V>({ required: true })
defineProps<{ options: { value: V; label: string }[]; label: string; size?: 'sm' | 'md' }>()

function onKey(event: KeyboardEvent, options: { value: V }[], index: number): void {
  const delta = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0
  if (!delta) return
  event.preventDefault()
  const next = options[(index + delta + options.length) % options.length]
  if (next) model.value = next.value
  const group = (event.currentTarget as HTMLElement).parentElement
  const buttons = group?.querySelectorAll<HTMLButtonElement>('button')
  buttons?.[(index + delta + options.length) % options.length]?.focus()
}
</script>

<template>
  <div role="radiogroup" :aria-label="label" class="inline-flex rounded-xl bg-surface-2 p-1 gap-1">
    <button
      v-for="(option, index) in options"
      :key="String(option.value)"
      type="button"
      role="radio"
      :aria-checked="model === option.value"
      :tabindex="model === option.value ? 0 : -1"
      class="rounded-lg font-semibold transition-colors"
      :class="[
        size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-sm',
        model === option.value ? 'bg-surface text-fg shadow-sm' : 'text-fg-muted hover:text-fg',
      ]"
      @click="model = option.value"
      @keydown="onKey($event, options, index)"
    >
      {{ option.label }}
    </button>
  </div>
</template>
