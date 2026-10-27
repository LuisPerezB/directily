<script setup lang="ts">
/** Envoltorio de campo: etiqueta, ayuda y error asociados por id (aria-describedby en el input del slot). */
defineProps<{ id: string; label: string; hint?: string | null; error?: string | null; required?: boolean }>()
</script>

<template>
  <div>
    <label :for="id" class="label">
      {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span>
    </label>
    <slot :describedby="[hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined" :invalid="error ? 'true' : undefined" />
    <p v-if="hint && !error" :id="`${id}-hint`" class="hint mt-1">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
  </div>
</template>
