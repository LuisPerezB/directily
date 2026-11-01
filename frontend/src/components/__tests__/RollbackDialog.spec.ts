import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import RollbackDialog from '../models/RollbackDialog.vue'

const props = { open: true, title: '¿Volver a la versión anterior?', message: 'La versión elegida pasará a ser la vigente.', details: [{ label: 'Volver a', value: 'v2 · 26 oct 2026' }] }

describe('RollbackDialog (confirmación de reversión)', () => {
  it('no ejecuta nada por sí sola: solo emite confirm cuando la persona lo acepta', async () => {
    const w = mount(RollbackDialog, { props })
    expect(w.emitted('confirm')).toBeUndefined()
    const confirm = w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!
    await confirm.trigger('click')
    expect(w.emitted('confirm')).toHaveLength(1)
    expect(w.emitted('cancel')).toBeUndefined()
  })

  it('cancelar no confirma', async () => {
    const w = mount(RollbackDialog, { props })
    await w.findAll('button').find((b) => b.text() === 'Cancelar')!.trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
    expect(w.emitted('confirm')).toBeUndefined()
  })

  it('explica qué cambia, que no se reentrena y muestra el motivo si la API la rechazó', () => {
    const w = mount(RollbackDialog, { props: { ...props, errorText: 'Solo se puede volver a una versión anterior a la vigente.' } })
    expect(w.get('[data-test="rollback-details"]').text()).toContain('v2 · 26 oct 2026')
    expect(w.text()).toContain('No se reentrena nada')
    expect(w.get('[data-test="rollback-error"]').text()).toContain('anterior a la vigente')
  })

  it('cerrado no muestra nada, y ocupado deshabilita los botones', async () => {
    expect(mount(RollbackDialog, { props: { ...props, open: false } }).text()).toBe('')
    const busy = mount(RollbackDialog, { props: { ...props, busy: true } })
    expect(busy.findAll('button').filter((b) => ['Cancelar', 'Sí, volver a esta versión'].includes(b.text())).every((b) => b.attributes('disabled') !== undefined)).toBe(true)
  })
})
