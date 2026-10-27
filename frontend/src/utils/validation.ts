/** Validaciones de formularios usadas en el cliente (la validación definitiva es del backend). */

/** RUC ecuatoriano: 13 dígitos, provincia 01–24 o 30, termina en 001. */
export function isValidRuc(value: string): boolean {
  if (!/^\d{13}$/.test(value)) return false
  const province = Number(value.slice(0, 2))
  if (!((province >= 1 && province <= 24) || province === 30)) return false
  return value.endsWith('001')
}

export function isValidEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

export function passwordStrength(value: string): 'weak' | 'medium' | 'strong' {
  let score = 0
  if (value.length >= 8) score++
  if (value.length >= 12) score++
  if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++
  if (/\d/.test(value)) score++
  if (/[^A-Za-z0-9]/.test(value)) score++
  if (score >= 4) return 'strong'
  if (score >= 2) return 'medium'
  return 'weak'
}
