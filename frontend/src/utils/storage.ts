/** Acceso seguro a localStorage (puede no existir o estar bloqueado). Solo para preferencias. */
export function storageGet(key: string): string | null {
  try {
    return globalThis.localStorage?.getItem(key) ?? null
  } catch {
    return null
  }
}

export function storageSet(key: string, value: string | null): void {
  try {
    if (value === null) globalThis.localStorage?.removeItem(key)
    else globalThis.localStorage?.setItem(key, value)
  } catch {
    // Sin almacenamiento disponible: la preferencia simplemente no se recuerda.
  }
}
