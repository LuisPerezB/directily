import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import DataTable from '../ui/DataTable.vue'

interface Row { id: number; name: string; qty: number; product: { name: string } }
const rows: Row[] = [
  { id: 1, name: 'B', qty: 5, product: { name: 'Cemento' } },
  { id: 2, name: 'A', qty: 9, product: { name: 'Clavos' } },
]
// El componente es genérico; en pruebas se tipa la fila como object y se convierte.
const rowKey = (r: object) => (r as Row).id
const columns = [
  { key: 'product.name', label: 'Producto' },
  { key: 'qty', label: 'Cantidad', sortable: true, align: 'right' as const },
]

describe('DataTable', () => {
  it('renderiza slots de celdas con claves anidadas (product.name → cell-product_name)', () => {
    const wrapper = mount(DataTable, {
      props: { columns, rows, rowKey },
      slots: { 'cell-product_name': ({ row }: { row: object }) => h('strong', `*${(row as Row).product.name}`) },
    })
    expect(wrapper.findAll('strong').map((s) => s.text())).toEqual(['*Cemento', '*Clavos'])
  })

  it('alterna el orden y expone aria-sort', async () => {
    const Host = defineComponent({
      setup() {
        const sort = ref<string | null>(null)
        return () => h(DataTable as never, { columns, rows, rowKey, clientSide: true, sort: sort.value, 'onUpdate:sort': (v: string) => (sort.value = v) })
      },
    })
    const wrapper = mount(Host)
    const header = wrapper.findAll('th')[1]!
    expect(header.attributes('aria-sort')).toBe('none')
    await header.get('button').trigger('click')
    expect(header.attributes('aria-sort')).toBe('descending')
    expect(wrapper.findAll('tbody tr')[0]!.text()).toContain('9')
    await header.get('button').trigger('click')
    expect(header.attributes('aria-sort')).toBe('ascending')
    expect(wrapper.findAll('tbody tr')[0]!.text()).toContain('5')
  })

  it('muestra el estado vacío', () => {
    const wrapper = mount(DataTable, { props: { columns, rows: [], rowKey, emptyText: 'Nada por aquí' } })
    expect(wrapper.text()).toContain('Nada por aquí')
  })
})
