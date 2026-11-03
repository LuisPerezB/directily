import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { ROLE_ABILITIES, ROLES, roleCan, type Ability } from '../abilities'
import { routes } from '@/router'
import { resolveGuard } from '@/router/guards'

const phpPath = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/app/Enums/TenantRole.php')

/** Lee los mapas de habilidades de App\Enums\TenantRole::abilities() (solo lectura del archivo del backend). */
function serverAbilities(): Record<string, string[]> {
  const src = readFileSync(phpPath, 'utf8')
  const lists: Record<string, string[]> = {}
  for (const m of src.matchAll(/\$(viewer|analyst|admin|owner)\s*=\s*\[([^\]]*)\];/g)) {
    const items: string[] = []
    for (const raw of m[2]!.split(',')) {
      const token = raw.trim()
      if (!token) continue
      if (token.startsWith('...$')) items.push(...(lists[token.slice(4)] ?? []))
      else items.push(token.replace(/^'|'$/g, ''))
    }
    lists[m[1]!] = items
  }
  return lists
}

describe('réplica de habilidades (V25-UIX-018)', () => {
  it.skipIf(!existsSync(phpPath))('coincide con App\\Enums\\TenantRole::abilities() para los cuatro roles', () => {
    const server = serverAbilities()
    const map: Record<string, string> = { owner: 'owner', admin: 'admin', analyst: 'analyst', viewer: 'viewer' }
    for (const role of ROLES) {
      expect([...ROLE_ABILITIES[role]].sort(), role).toEqual([...(server[map[role]!] ?? [])].sort())
    }
  })

  it('las habilidades de decisiones se reparten como en el servidor: ver (todos), gestionar (analista+), aprobar (administrador+)', () => {
    expect(ROLES.map((r) => roleCan(r, 'decisions.view'))).toEqual([true, true, true, true])
    expect(roleCan('viewer', 'decisions.manage')).toBe(false)
    expect(roleCan('analyst', 'decisions.manage')).toBe(true)
    expect(roleCan('analyst', 'decisions.approve')).toBe(false)
    expect(roleCan('admin', 'decisions.approve')).toBe(true)
    expect(roleCan('owner', 'decisions.approve')).toBe(true)
    expect(roleCan(null, 'decisions.view')).toBe(false)
  })
})

describe('rutas Enterprise y habilidades', () => {
  const app = routes.find((r) => r.path === '/app')!.children!
  const byName = (name: string) => app.find((r) => r.name === name)!
  const guard = (name: string, can: (a: Ability) => boolean) =>
    resolveGuard({ fullPath: `/app/${name}`, name, meta: byName(name).meta as never }, { isAuthenticated: true, hasMemberships: true, hasTenant: true, isSuperadmin: false, can })

  it('cada vista declara la habilidad que exige su API', () => {
    expect(byName('executive-overview').meta).toMatchObject({ requiresTenant: true, ability: 'forecast.view' })
    expect(byName('radar').meta).toMatchObject({ requiresTenant: true, ability: 'forecast.view' })
    expect(byName('decision-center').meta).toMatchObject({ requiresTenant: true, ability: 'decisions.view' })
    expect(byName('roi-outcomes').meta).toMatchObject({ requiresTenant: true, ability: 'decisions.view' })
    expect(byName('enterprise-admin').meta).toMatchObject({ requiresTenant: true, ability: 'models.approve' })
  })

  it.each([
    ['viewer', 'executive-overview', true], ['viewer', 'radar', true], ['viewer', 'decision-center', true], ['viewer', 'roi-outcomes', true],
    ['viewer', 'enterprise-admin', false], ['analyst', 'enterprise-admin', false], ['admin', 'enterprise-admin', true], ['owner', 'enterprise-admin', true],
  ] as const)('%s → %s: acceso %s', (role, name, allowed) => {
    const result = guard(name, (a) => roleCan(role, a))
    expect(result === true).toBe(allowed)
    if (!allowed) expect(result).toEqual({ name: 'forbidden' })
  })

  it('el centro de decisiones acepta un id numérico opcional y la administración una pestaña opcional', () => {
    expect(byName('decision-center').path).toContain('(\\d+)?')
    expect(byName('enterprise-admin').path).toBe('administracion/:tab?')
    expect(byName('radar').path).toBe('radar-comercial/:tab?')
  })
})
