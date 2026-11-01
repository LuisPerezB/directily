<script setup lang="ts">
/** Conexión de una fuente SQL de solo lectura: parámetros no secretos y credenciales (cifradas; nunca se muestran de vuelta). */
import { useI18n } from 'vue-i18n'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { SqlFormState } from '@/utils/sqlSource'

const form = defineModel<SqlFormState>({ required: true })
withDefaults(defineProps<{ errors?: Record<string, string>; editing?: boolean; hasCredentials?: boolean; hideName?: boolean }>(), { errors: () => ({}), editing: false, hasCredentials: false, hideName: false })
const { t } = useI18n()
const uid = Math.random().toString(36).slice(2, 7)
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2" data-test="sql-source-form">
    <FormField v-if="!hideName" :id="`sq-${uid}-name`" :label="t('sql.fields.name')" :error="errors.name" required class="sm:col-span-2">
      <template #default="{ describedby }"><input :id="`sq-${uid}-name`" v-model="form.name" class="input" maxlength="150" required :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-host`" :label="t('sql.fields.host')" :error="errors.host" :hint="t('sql.hints.host')" required>
      <template #default="{ describedby }"><input :id="`sq-${uid}-host`" v-model="form.host" class="input" autocomplete="off" required :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-port`" :label="t('sql.fields.port')" :error="errors.port">
      <template #default="{ describedby }"><input :id="`sq-${uid}-port`" v-model.number="form.port" type="number" min="1" max="65535" class="input" :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-db`" :label="t('sql.fields.database')" :error="errors.database" required>
      <template #default="{ describedby }"><input :id="`sq-${uid}-db`" v-model="form.database" class="input" autocomplete="off" required :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-ssl`" :label="t('sql.fields.sslmode')" :error="errors.sslmode" :hint="t('sql.hints.sslmode')">
      <template #default="{ describedby }">
        <select :id="`sq-${uid}-ssl`" v-model="form.sslmode" class="select" :aria-describedby="describedby">
          <option v-for="m in ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full']" :key="m" :value="m">{{ m }}</option>
        </select>
      </template>
    </FormField>
    <FormField :id="`sq-${uid}-tz`" :label="t('sql.fields.timezone')" :error="errors.source_timezone" :hint="t('sql.hints.timezone')">
      <template #default="{ describedby }"><input :id="`sq-${uid}-tz`" v-model="form.source_timezone" class="input" :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-sch`" :label="t('sql.fields.schedule')" :error="errors.sync_schedule">
      <template #default="{ describedby }">
        <select :id="`sq-${uid}-sch`" v-model="form.sync_schedule" class="select" :aria-describedby="describedby">
          <option value="manual">{{ t('sql.schedules.manual') }}</option>
          <option value="hourly">{{ t('sql.schedules.hourly') }}</option>
          <option value="daily">{{ t('sql.schedules.daily') }}</option>
        </select>
      </template>
    </FormField>
    <InlineNotice kind="info" class="sm:col-span-2">{{ editing && hasCredentials ? t('sql.credentialsKept') : t('sql.credentialsNote') }}</InlineNotice>
    <FormField :id="`sq-${uid}-user`" :label="t('sql.fields.username')" :error="errors.username" :required="!editing">
      <template #default="{ describedby }"><input :id="`sq-${uid}-user`" v-model="form.username" class="input" autocomplete="off" :required="!editing" :aria-describedby="describedby" /></template>
    </FormField>
    <FormField :id="`sq-${uid}-pass`" :label="t('sql.fields.password')" :error="errors.password" :required="!editing">
      <template #default="{ describedby }"><input :id="`sq-${uid}-pass`" v-model="form.password" type="password" class="input" autocomplete="new-password" :required="!editing" :aria-describedby="describedby" /></template>
    </FormField>
  </div>
</template>
