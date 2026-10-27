<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'

/** Diálogo modal accesible: foco inicial, Escape para cerrar, foco atrapado, retorno del foco. */
const props = withDefaults(defineProps<{ open: boolean; title: string; size?: 'sm' | 'md' | 'lg'; dismissible?: boolean }>(), { size: 'md', dismissible: true })
const emit = defineEmits<{ close: [] }>()
const { t } = useI18n()
const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null
const titleId = `modal-${Math.random().toString(36).slice(2, 9)}`

function focusables(): HTMLElement[] {
  return Array.from(panel.value?.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])') ?? []).filter((el) => !el.hasAttribute('disabled'))
}

function onKeydown(event: KeyboardEvent): void {
  if (!props.open) return
  if (event.key === 'Escape' && props.dismissible) {
    event.preventDefault()
    emit('close')
  } else if (event.key === 'Tab') {
    const items = focusables()
    if (!items.length) return
    const first = items[0]!
    const last = items[items.length - 1]!
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }
}

watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused = document.activeElement as HTMLElement | null
      document.addEventListener('keydown', onKeydown)
      await nextTick()
      ;(focusables()[0] ?? panel.value)?.focus()
    } else {
      document.removeEventListener('keydown', onKeydown)
      previouslyFocused?.focus?.()
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
      <div class="absolute inset-0 bg-black/45" aria-hidden="true" @click="dismissible && emit('close')" />
      <div
        ref="panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        class="relative card w-full max-h-[92vh] overflow-y-auto rounded-b-none sm:rounded-b-[14px]"
        :class="{ sm: 'sm:max-w-md', md: 'sm:max-w-xl', lg: 'sm:max-w-3xl' }[size]"
      >
        <header class="flex items-center justify-between gap-3 px-5 pt-5 pb-3">
          <h2 :id="titleId" class="section-title">{{ title }}</h2>
          <button v-if="dismissible" type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.close')" @click="emit('close')">
            <AppIcon name="x" :size="18" />
          </button>
        </header>
        <div class="px-5 pb-5"><slot /></div>
        <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 px-5 py-4 border-t border-line"><slot name="footer" /></footer>
      </div>
    </div>
  </Teleport>
</template>
