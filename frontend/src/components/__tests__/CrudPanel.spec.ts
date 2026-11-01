import { describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import CrudPanel, { type FieldDef } from '../ui/CrudPanel.vue'

interface Row { id: number; name: string; amount: number | null }
const fields: FieldDef[] = [
  { key: 'name', label: 'Nombre', type: 'text', required: true },
  { key: 'amount', label: 'Monto', type: 'number', hint: 'Déjelo vacío si no se conoce.' },
]
const columns = [{ key: 'name', label: 'Nombre' }, { key: 'amount', label: 'Monto' }]
const rows: Row[] = [{ id: 1, name: 'Presupuesto octubre', amount: 500 }]

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(CrudPanel as unknown as Component, { props: { title: 'Presupuestos', columns, rows, fields, canWrite: true, ...props }, global: { plugins: [setupTenant('admin')] } })
}

describe('CrudPanel (formularios de configuración)', () => {
  it('un valor desconocido se envía como null, nunca como cero', async () => {
    const create = vi.fn().mockResolvedValue({})
    const w = mountPanel({ create })
    await w.get('button.btn-secondary').trigger('click')
    await w.get('input[type="text"]').setValue('  Nuevo  ')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(create).toHaveBeenCalledWith({ name: 'Nuevo', amount: null })
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('los errores 422 se muestran junto a su campo y los demás arriba', async () => {
    const create = vi.fn().mockRejectedValue(new ApiError(422, { message: 'x', errors: { amount: ['Debe ser mayor o igual a 0.'], _: ['Ya existe un registro con la misma clave natural.'] } }, 'x'))
    const w = mountPanel({ create })
    await w.get('button.btn-secondary').trigger('click')
    await w.get('input[type="text"]').setValue('X')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Debe ser mayor o igual a 0.')
    expect(w.get('[data-test="crud-error"]').text()).toContain('misma clave natural')
    expect(w.emitted('changed')).toBeUndefined()
  })

  it('editar usa update con el id de la fila', async () => {
    const update = vi.fn().mockResolvedValue({})
    const w = mountPanel({ update })
    await w.findAll('button').find((b) => b.text() === 'Editar')!.trigger('click')
    expect((w.get('input[type="text"]').element as HTMLInputElement).value).toBe('Presupuesto octubre')
    await w.get('input[type="number"]').setValue('')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(update).toHaveBeenCalledWith(1, { name: 'Presupuesto octubre', amount: null })
  })

  it('eliminar pide confirmación antes de llamar a la API', async () => {
    const remove = vi.fn().mockResolvedValue(undefined)
    const w = mountPanel({ remove, rowLabel: (r: Row) => r.name })
    await w.get('button[aria-label="Eliminar"]').trigger('click')
    expect(remove).not.toHaveBeenCalled()
    expect(w.text()).toContain('Presupuesto octubre')
    await w.findAll('button').filter((b) => b.text() === 'Eliminar').at(-1)!.trigger('click')
    await flushPromises()
    expect(remove).toHaveBeenCalledWith(rows[0])
  })

  it('sin permiso de escritura no hay acciones', () => {
    const w = mountPanel({ canWrite: false, create: vi.fn(), update: vi.fn(), remove: vi.fn() })
    expect(w.find('button.btn-secondary').exists()).toBe(false)
    expect(w.text()).not.toContain('Editar')
  })

  it('las acciones por fila se pueden ocultar (p. ej. eventos públicos)', () => {
    const w = mountPanel({ remove: vi.fn(), rowActions: () => ({ edit: false, remove: false }) })
    expect(w.find('button[aria-label="Eliminar"]').exists()).toBe(false)
  })
})
