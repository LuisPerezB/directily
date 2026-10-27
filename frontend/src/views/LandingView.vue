<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PredictilyLogo from '@/components/brand/PredictilyLogo.vue'
import AppIcon, { type IconName } from '@/components/ui/AppIcon.vue'
import ThemeToggle from '@/components/layout/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'

const { t, tm, rt } = useI18n()
const auth = useAuthStore()
void auth.init()

const steps = computed(() => (tm('landing.steps') as unknown[]).map((s) => {
  const step = s as { title: unknown; text: unknown }
  return { title: rt(step.title as string), text: rt(step.text as string) }
}))
const features: { icon: IconName; key: string }[] = [
  { icon: 'chart', key: 'demand' },
  { icon: 'warehouse', key: 'inventory' },
  { icon: 'target', key: 'accuracy' },
  { icon: 'sliders', key: 'scenarios' },
  { icon: 'database', key: 'integration' },
  { icon: 'shield', key: 'privacy' },
]
const audiences = computed(() => (tm('landing.audiences') as unknown[]).map((a) => rt(a as string)))
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header class="sticky top-0 z-30 bg-canvas/85 backdrop-blur border-b border-line">
      <div class="max-w-6xl mx-auto flex items-center justify-between gap-3 px-4 sm:px-6 h-16">
        <PredictilyLogo :size="30" />
        <nav class="flex items-center gap-1 sm:gap-2" :aria-label="t('landing.navLabel')">
          <a href="#como-funciona" class="hidden md:inline btn btn-ghost btn-sm">{{ t('landing.navHow') }}</a>
          <a href="#transparencia" class="hidden md:inline btn btn-ghost btn-sm">{{ t('landing.navTransparency') }}</a>
          <ThemeToggle />
          <RouterLink v-if="auth.isAuthenticated" :to="{ name: 'dashboard' }" class="btn btn-primary btn-sm">{{ t('landing.goToApp') }}</RouterLink>
          <template v-else>
            <RouterLink :to="{ name: 'login' }" class="btn btn-ghost btn-sm">{{ t('auth.login') }}</RouterLink>
            <RouterLink :to="{ name: 'register' }" class="btn btn-primary btn-sm">{{ t('landing.ctaShort') }}</RouterLink>
          </template>
        </nav>
      </div>
    </header>

    <main id="main">
      <section class="max-w-6xl mx-auto px-4 sm:px-6 pt-14 pb-16 grid lg:grid-cols-2 gap-10 items-center">
        <div>
          <p class="badge badge-brand mb-4">{{ t('landing.kicker') }}</p>
          <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight leading-[1.08] m-0">{{ t('brand.slogan') }}</h1>
          <p class="mt-5 text-lg text-fg-muted max-w-xl">{{ t('landing.lead') }}</p>
          <div class="mt-7 flex flex-wrap gap-3">
            <RouterLink :to="{ name: 'register' }" class="btn btn-primary">{{ t('landing.cta') }}</RouterLink>
            <a href="#como-funciona" class="btn btn-secondary">{{ t('landing.ctaSecondary') }}</a>
          </div>
          <p class="mt-4 hint">{{ t('landing.noReplace') }}</p>
        </div>
        <div class="card p-6" aria-hidden="true">
          <!-- Ilustración conceptual (no son datos): histórico, corte y banda de incertidumbre. -->
          <svg viewBox="0 0 480 260" class="w-full h-auto">
            <defs>
              <linearGradient id="band" x1="0" x2="1">
                <stop offset="0" stop-color="var(--p-brand)" stop-opacity="0.32" />
                <stop offset="1" stop-color="var(--p-brand)" stop-opacity="0.12" />
              </linearGradient>
            </defs>
            <g stroke="var(--p-chart-grid)" stroke-width="1">
              <line x1="20" y1="220" x2="460" y2="220" /><line x1="20" y1="160" x2="460" y2="160" /><line x1="20" y1="100" x2="460" y2="100" /><line x1="20" y1="40" x2="460" y2="40" />
            </g>
            <path d="M290 132 L330 110 L370 120 L410 92 L450 98 L450 40 L410 46 L370 70 L330 66 L290 104 Z" fill="url(#band)" />
            <polyline points="20,190 50,176 80,184 110,160 140,170 170,148 200,156 230,130 260,140 290,118" fill="none" stroke="var(--p-chart-history)" stroke-width="3" stroke-linejoin="round" />
            <polyline points="290,118 330,90 370,96 410,70 450,70" fill="none" stroke="var(--p-brand)" stroke-width="3" stroke-dasharray="7 6" stroke-linejoin="round" />
            <line x1="290" y1="30" x2="290" y2="225" stroke="var(--p-fg-muted)" stroke-dasharray="4 4" />
            <circle cx="290" cy="118" r="6" fill="var(--p-accent)" stroke="var(--p-surface)" stroke-width="2" />
            <text x="296" y="28" font-size="12" fill="var(--p-fg-muted)">{{ t('landing.illustrationToday') }}</text>
            <text x="30" y="246" font-size="12" fill="var(--p-fg-muted)">{{ t('landing.illustrationHistory') }}</text>
            <text x="330" y="246" font-size="12" fill="var(--p-fg-muted)">{{ t('landing.illustrationForecast') }}</text>
          </svg>
          <p class="hint text-center mt-2">{{ t('landing.illustrationNote') }}</p>
        </div>
      </section>

      <section class="bg-surface border-y border-line">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-14">
          <h2 class="text-2xl font-bold m-0">{{ t('landing.featuresTitle') }}</h2>
          <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-8">
            <article v-for="f in features" :key="f.key" class="rounded-2xl border border-line p-5 bg-canvas">
              <span class="inline-flex rounded-xl bg-brand-soft text-brand-strong p-2.5"><AppIcon :name="f.icon" :size="22" /></span>
              <h3 class="mt-3 mb-1 text-base font-semibold">{{ t(`landing.features.${f.key}.title`) }}</h3>
              <p class="text-sm text-fg-muted m-0">{{ t(`landing.features.${f.key}.text`) }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="como-funciona" class="max-w-6xl mx-auto px-4 sm:px-6 py-14">
        <h2 class="text-2xl font-bold m-0">{{ t('landing.howTitle') }}</h2>
        <ol class="grid md:grid-cols-4 gap-5 mt-8 list-none p-0">
          <li v-for="(step, i) in steps" :key="i" class="card p-5">
            <span class="inline-flex w-8 h-8 rounded-full bg-brand text-brand-fg font-bold items-center justify-center">{{ i + 1 }}</span>
            <h3 class="mt-3 mb-1 text-base font-semibold">{{ step.title }}</h3>
            <p class="text-sm text-fg-muted m-0">{{ step.text }}</p>
          </li>
        </ol>
      </section>

      <section id="transparencia" class="bg-surface border-y border-line">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-14 grid lg:grid-cols-2 gap-10">
          <div>
            <h2 class="text-2xl font-bold m-0">{{ t('landing.transparencyTitle') }}</h2>
            <p class="mt-4 text-fg-muted">{{ t('landing.transparencyText') }}</p>
            <ul class="mt-4 flex flex-col gap-2 text-sm list-none p-0">
              <li v-for="k in ['a', 'b', 'c', 'd']" :key="k" class="flex gap-2"><AppIcon name="check" :size="18" class="text-success shrink-0" />{{ t(`landing.transparencyPoints.${k}`) }}</li>
            </ul>
          </div>
          <div>
            <h2 class="text-2xl font-bold m-0">{{ t('landing.audienceTitle') }}</h2>
            <ul class="mt-4 grid sm:grid-cols-2 gap-2 list-none p-0">
              <li v-for="a in audiences" :key="a" class="rounded-xl border border-line px-4 py-3 text-sm font-medium bg-canvas">{{ a }}</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="max-w-6xl mx-auto px-4 sm:px-6 py-16 text-center">
        <h2 class="text-2xl font-bold m-0">{{ t('landing.finalTitle') }}</h2>
        <p class="text-fg-muted mt-3">{{ t('landing.finalText') }}</p>
        <RouterLink :to="{ name: 'register' }" class="btn btn-primary mt-6">{{ t('landing.cta') }}</RouterLink>
      </section>
    </main>

    <footer class="border-t border-line py-8 px-4 text-center text-sm text-fg-muted">
      <PredictilyLogo :size="22" class="justify-center" />
      <p class="mt-2">{{ t('landing.footer') }}</p>
    </footer>
  </div>
</template>
