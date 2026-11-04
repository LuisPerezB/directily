<script setup lang="ts">
/**
 * Banda de origen de los datos (V25-UIX-012): con datos de demostración o simulados avisa que los importes NO son resultados reales; sin declarar, que no se
 * supone que sean reales. El origen sale de `settings.data_origin` de la empresa (o de la prop, p. ej. el que declara el informe de ROI).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { useTenantStore } from '@/stores/tenant'
import { dataOriginKey, originIsNotReal, tenantDataOrigin, type DataOriginKey } from '@/utils/enterprise'

const props = withDefaults(defineProps<{ origin?: DataOriginKey | string | null }>(), { origin: undefined })
const { t } = useI18n()
const tenant = useTenantStore()
const key = computed<DataOriginKey>(() => (props.origin === undefined ? tenantDataOrigin(tenant.tenant?.settings) : dataOriginKey(props.origin)))
</script>

<template>
  <InlineNotice v-if="originIsNotReal(key)" kind="warning" :title="t(`enterprise.originBand.${key}.title`)" data-test="origin-band" :data-origin="key">{{ t(`enterprise.originBand.${key}.text`) }}</InlineNotice>
  <InlineNotice v-else-if="key === 'undeclared'" kind="info" data-test="origin-band" data-origin="undeclared">{{ t('enterprise.originBand.undeclared.text') }}</InlineNotice>
</template>
