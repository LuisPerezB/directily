<script setup lang="ts">
/**
 * Mensaje claro, en español, de un error del servidor al actuar (403 sin habilidad o segregación de funciones, 409 conflicto de versión o transición no
 * permitida, 422 validación). El servidor es la autoridad: la interfaz solo explica qué pasó y qué hacer.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { segregationRoleKey, VALIDATION_CODES, type ActionError } from '@/utils/decisions'

const props = defineProps<{ error: ActionError }>()
const { t, te } = useI18n()

const roles = computed(() => props.error.roles.map((r) => (te(`enterprise.roles.${segregationRoleKey(r)}`) ? t(`enterprise.roles.${segregationRoleKey(r)}`) : r)).join(', '))
// Los mensajes por campo se listan SOLO si añaden algo: el del servidor suele repetir el del campo (y los campos repiten entre sí).
const fields = computed(() => [...new Set(Object.values(props.error.fieldErrors).flat())].filter((m) => m !== text.value))
const text = computed(() => {
  const e = props.error
  switch (e.kind) {
    case 'segregation': return roles.value ? t('enterprise.errors.segregationRoles', { roles: roles.value }) : t('enterprise.errors.segregation')
    case 'forbidden': return t('enterprise.errors.forbidden')
    case 'validation':
      return e.code && (VALIDATION_CODES as readonly string[]).includes(e.code) && te(`enterprise.errors.codes.${e.code}`) ? t(`enterprise.errors.codes.${e.code}`) : (e.serverMessage ?? t('enterprise.errors.validation'))
    case 'conflict': case 'unknown': return e.serverMessage ?? t(`enterprise.errors.${e.kind}`)
    default: return t(`enterprise.errors.${e.kind}`)
  }
})
</script>

<template>
  <InlineNotice kind="danger" data-test="action-error" :data-kind="error.kind">
    <p class="m-0">{{ text }}</p>
    <ul v-if="error.kind === 'validation' && fields.length" class="list-disc pl-5 mt-1 mb-0 text-sm"><li v-for="(f, i) in fields" :key="i">{{ f }}</li></ul>
  </InlineNotice>
</template>
