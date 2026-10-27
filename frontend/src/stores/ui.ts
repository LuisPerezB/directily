import { defineStore } from 'pinia'
import { ref } from 'vue'
import { storageGet, storageSet } from '@/utils/storage'

export type ThemePreference = 'system' | 'light' | 'dark'
export type ToastKind = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: number
  kind: ToastKind
  message: string
  timeout: number
}

let toastSeq = 0

export const useUiStore = defineStore('ui', () => {
  const theme = ref<ThemePreference>((storageGet('predictily.theme') as ThemePreference | null) ?? 'system')
  const sidebarCollapsed = ref(storageGet('predictily.sidebar') === 'collapsed')
  const mobileNavOpen = ref(false)
  const toasts = ref<Toast[]>([])

  function applyTheme(): void {
    if (typeof document === 'undefined') return
    const root = document.documentElement
    if (theme.value === 'system') root.removeAttribute('data-theme')
    else root.setAttribute('data-theme', theme.value)
  }

  function setTheme(value: ThemePreference): void {
    theme.value = value
    storageSet('predictily.theme', value)
    applyTheme()
  }

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
    storageSet('predictily.sidebar', sidebarCollapsed.value ? 'collapsed' : 'expanded')
  }

  function notify(message: string, kind: ToastKind = 'info', timeout = 5000): number {
    const id = ++toastSeq
    toasts.value.push({ id, kind, message, timeout })
    if (timeout > 0) setTimeout(() => dismiss(id), timeout)
    return id
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return { theme, sidebarCollapsed, mobileNavOpen, toasts, applyTheme, setTheme, toggleSidebar, notify, dismiss }
})

/** Modo oscuro efectivo (considera la preferencia del sistema). */
export function isDarkMode(): boolean {
  if (typeof document === 'undefined') return false
  const attr = document.documentElement.getAttribute('data-theme')
  if (attr) return attr === 'dark'
  return globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}
