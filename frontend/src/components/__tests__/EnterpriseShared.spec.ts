import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { setupTenant } from '@/test/helpers'
import { ApiError } from '@/api/errors'
import { classifyActionError } from '@/utils/decisions'
import { todayISO } from '@/utils/format'
import MoneyFigure from '../enterprise/MoneyFigure.vue'
import ProvenanceLine from '../enterprise/ProvenanceLine.vue'
import DataOriginBand from '../enterprise/DataOriginBand.vue'
import ActionErrorNotice from '../enterprise/ActionErrorNotice.vue'
import WorkflowBadge from '../enterprise/WorkflowBadge.vue'
import PartialSumNote from '../enterprise/PartialSumNote.vue'

enableAutoUnmount(afterEach)
const plugins = () => [setupTenant('analyst')]

describe('MoneyFigure: un null nunca es 0', () => {
  it('sin cifra dice «No estimado» y no muestra 0 ni un guion', () => {
    const w = mount(MoneyFigure, { props: { value: null }, global: { plugins: plugins() } })
    expect(w.text()).toContain('No estimado')
    expect(w.text()).not.toMatch(/0,00|^0$/)
    expect(w.text()).not.toBe('—')
    expect(w.find('[data-test="figure"]').exists()).toBe(false)
  })

  it('un undefined tampoco es cero', () => {
    const w = mount(MoneyFigure, { props: { value: undefined }, global: { plugins: plugins() } })
    expect(w.find('[data-test="not-estimated"]').exists()).toBe(true)
  })

  it('un cero medido sí se muestra como cero', () => {
    const w = mount(MoneyFigure, { props: { value: 0, currency: 'USD' }, global: { plugins: plugins() } })
    expect(w.get('[data-test="figure"]').text()).toMatch(/0,00/)
    expect(w.find('[data-test="not-estimated"]').exists()).toBe(false)
  })

  it('muestra la etiqueta indicada y los motivos de la API traducidos (costo no confiable)', () => {
    const w = mount(MoneyFigure, { props: { value: null, unknownLabel: 'Costo no confiable', reasons: ['cost_dispersion_high', 'no_cost_in_sales_lines', 'codigo_nuevo'] }, global: { plugins: plugins() } })
    expect(w.text()).toContain('Costo no confiable')
    expect(w.get('[data-test="not-estimated-reasons"]').text()).toContain('Costo muy variable')
    expect(w.get('[data-test="not-estimated-reasons"]').text()).toContain('Las ventas no traen costo')
    expect(w.get('[data-test="not-estimated-reasons"]').text()).toContain('codigo_nuevo')
  })

  it('formatea con la moneda indicada', () => {
    const w = mount(MoneyFigure, { props: { value: 1234.5, currency: 'USD' }, global: { plugins: plugins() } })
    expect(w.get('[data-test="figure"]').text()).toMatch(/1\.?234,50/)
  })
})

describe('ProvenanceLine: corte, unidad, incertidumbre, cobertura y origen', () => {
  it('muestra «Datos hasta <corte>», unidad y moneda', () => {
    const w = mount(ProvenanceLine, { props: { asOf: todayISO('America/Guayaquil'), unit: 'unidades', currency: 'USD', uncertainty: 'Intervalo empírico', coverage: 'historia 120 días' }, global: { plugins: plugins() } })
    expect(w.get('[data-test="as-of"]').text()).toMatch(/^Datos hasta /)
    expect(w.get('[data-test="unit"]').text()).toBe('Unidad: unidades')
    expect(w.get('[data-test="currency"]').text()).toBe('Moneda: USD')
    expect(w.get('[data-test="uncertainty"]').text()).toContain('Intervalo empírico')
    expect(w.get('[data-test="coverage"]').text()).toContain('historia 120 días')
    expect(w.find('[data-test="stale"]').exists()).toBe(false)
  })

  it('un corte antiguo muestra el aviso de antigüedad', () => {
    const w = mount(ProvenanceLine, { props: { asOf: '2020-01-01', unit: 'unidades' }, global: { plugins: plugins() } })
    expect(w.get('[data-test="stale"]').text()).toMatch(/día\(s\) de antigüedad/)
  })

  it('sin corte lo dice; sin unidad dice «unidad no declarada»; un conteo vivo dice que es del momento de la consulta', () => {
    const w = mount(ProvenanceLine, { global: { plugins: plugins() } })
    expect(w.get('[data-test="as-of-missing"]').text()).toBe('Fecha de corte no informada')
    expect(w.get('[data-test="unit"]').text()).toBe('Unidad no declarada')
    const live = mount(ProvenanceLine, { props: { live: true }, global: { plugins: plugins() } })
    expect(live.get('[data-test="as-of-live"]').text()).toContain('momento de la consulta')
  })

  it('el origen sin declarar NO se supone real; demostración y simulado se avisan; real solo si se declaró', () => {
    const undeclared = mount(ProvenanceLine, { global: { plugins: plugins() } })
    expect(undeclared.get('[data-test="origin"]').attributes('data-origin')).toBe('undeclared')
    expect(undeclared.get('[data-test="origin"]').text()).toContain('no verificado')
    const demo = mount(ProvenanceLine, { props: { origin: 'demo' }, global: { plugins: plugins() } })
    expect(demo.get('[data-test="origin"]').text()).toBe('Datos de demostración')
    const simulated = mount(ProvenanceLine, { props: { origin: 'simulated' }, global: { plugins: plugins() } })
    expect(simulated.get('[data-test="origin"]').text()).toBe('Datos simulados')
    const real = mount(ProvenanceLine, { props: { origin: 'real' }, global: { plugins: plugins() } })
    expect(real.get('[data-test="origin"]').text()).toContain('reales')
  })

  it('toma el origen de la empresa cuando la vista no lo indica', () => {
    const pinia = setupTenant('analyst')
    const w = mount(ProvenanceLine, { global: { plugins: [pinia] } })
    expect(w.get('[data-test="origin"]').attributes('data-origin')).toBe('undeclared')
  })
})

describe('DataOriginBand', () => {
  it('con datos de demostración o simulados avisa que los importes NO son resultados reales', () => {
    expect(mount(DataOriginBand, { props: { origin: 'demo' }, global: { plugins: plugins() } }).text()).toContain('NO son resultados reales')
    expect(mount(DataOriginBand, { props: { origin: 'simulated' }, global: { plugins: plugins() } }).text()).toContain('NO son resultados reales')
  })
  it('sin declarar, dice que no se supone real; con datos reales declarados no hay banda', () => {
    expect(mount(DataOriginBand, { props: { origin: null }, global: { plugins: plugins() } }).text()).toContain('no se supone que sean reales')
    expect(mount(DataOriginBand, { props: { origin: 'real' }, global: { plugins: plugins() } }).find('[data-test="origin-band"]').exists()).toBe(false)
  })
})

describe('ActionErrorNotice: 403, 409 y 422 con mensajes claros', () => {
  const show = (status: number, body: Record<string, unknown> | null) => mount(ActionErrorNotice, { props: { error: classifyActionError(new ApiError(status, body as never, 'fallo')) }, global: { plugins: plugins() } })

  it('409 de versión: avisa que otra persona la cambió', () => {
    const w = show(409, { code: 'decision_version_conflict', message: 'x' })
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('version_conflict')
    expect(w.text()).toContain('Otra persona cambió esta recomendación')
  })

  it('403 sin habilidad', () => {
    expect(show(403, { code: 'forbidden', required_ability: 'decisions.approve' }).text()).toContain('no tiene permiso')
  })

  it('403 por segregación de funciones nombra la función, sin identificadores', () => {
    const w = show(403, { code: 'decision_segregation_of_duties', context: { roles: ['proposer', 'executor'] } })
    expect(w.text()).toContain('quien la propuso, quien debe ejecutarla')
    expect(w.text()).toContain('otra persona')
  })

  it('409 aprobación pendiente y transición no permitida', () => {
    expect(show(409, { code: 'decision_approval_required' }).text()).toContain('exige la aprobación de otra persona')
    expect(show(409, { code: 'decision_transition_not_allowed' }).text()).toContain('ya no está permitida')
  })

  it('422 con código propio y con errores de campo', () => {
    expect(show(422, { code: 'snooze_beyond_expiry', message: 'x' }).text()).toContain('supera el vencimiento')
    const w = show(422, { message: 'Datos inválidos', errors: { reason: ['El motivo es obligatorio.'] } })
    expect(w.text()).toContain('Datos inválidos')
    expect(w.text()).toContain('El motivo es obligatorio.')
  })

  it('red caída: dice que no se repetirá lo ya registrado', () => {
    expect(show(0, null).text()).toContain('no se repetirá')
  })
})

describe('WorkflowBadge', () => {
  it('rotula la atribución como oportunidad potencial no demostrada', () => {
    expect(mount(WorkflowBadge, { props: { domain: 'attribution', value: 'potential_unproven' }, global: { plugins: plugins() } }).text()).toBe('Oportunidad potencial, atribución no demostrada')
  })
  it('estados del flujo y de la agenda en español', () => {
    expect(mount(WorkflowBadge, { props: { domain: 'workflow', value: 'in_progress' }, global: { plugins: plugins() } }).text()).toBe('En curso')
    expect(mount(WorkflowBadge, { props: { domain: 'agenda', value: 'pedido_recibido' }, global: { plugins: plugins() } }).text()).toBe('Pedido recibido')
  })
})

describe('ProvenanceLine: el informe generado no es el corte de los datos (M4)', () => {
  it('«Informe generado el…» va aparte; sin corte de datos se dice y no se inventa uno', () => {
    const w = mount(ProvenanceLine, { props: { generatedAt: '2026-11-02', unit: 'recomendaciones' }, global: { plugins: plugins() } })
    expect(w.get('[data-test="generated-at"]').text()).toBe('Informe generado el 02 nov 2026')
    expect(w.find('[data-test="as-of"]').exists()).toBe(false)
    expect(w.find('[data-test="as-of-missing"]').exists()).toBe(true)
    expect(w.find('[data-test="stale"]').exists()).toBe(false)
  })

  it('la antigüedad sale del corte real, no de la fecha del informe', () => {
    const w = mount(ProvenanceLine, { props: { asOf: '2020-01-01', generatedAt: todayISO('America/Guayaquil') }, global: { plugins: plugins() } })
    expect(w.get('[data-test="as-of"]').text()).toMatch(/^Datos hasta 01 ene 2020/)
    expect(w.find('[data-test="stale"]').exists()).toBe(true)
  })
})

describe('PartialSumNote: una suma parcial se dice parcial (M2)', () => {
  it('muestra cuántas oportunidades quedaron fuera, por falta de costo confiable o de valor', () => {
    expect(mount(PartialSumNote, { props: { n: 2, reason: 'cost' }, global: { plugins: plugins() } }).get('[data-test="partial-sum"]').text()).toContain('2 sin costo confiable')
    expect(mount(PartialSumNote, { props: { n: 3, reason: 'value' }, global: { plugins: plugins() } }).get('[data-test="partial-sum"]').text()).toContain('3 sin valor estimado')
  })
  it('no muestra nada si no quedó ninguna fuera', () => {
    expect(mount(PartialSumNote, { props: { n: 0, reason: 'cost' }, global: { plugins: plugins() } }).find('[data-test="partial-sum"]').exists()).toBe(false)
    expect(mount(PartialSumNote, { props: { n: null, reason: 'cost' }, global: { plugins: plugins() } }).find('[data-test="partial-sum"]').exists()).toBe(false)
  })
})

describe('ActionErrorNotice: un 422 no imprime el mismo mensaje dos veces (L4)', () => {
  const show = (body: Record<string, unknown>) => mount(ActionErrorNotice, { props: { error: classifyActionError(new ApiError(422, body as never, 'fallo')) }, global: { plugins: plugins() } })
  it('si el mensaje del servidor es el del campo, se muestra una sola vez', () => {
    const w = show({ message: 'Indique expected_version.', errors: { expected_version: ['Indique expected_version.'] } })
    expect(w.text().match(/Indique expected_version\./g)).toHaveLength(1)
    expect(w.findAll('li')).toHaveLength(0)
  })
  it('los mensajes por campo que añaden algo se listan, sin repetirse entre sí', () => {
    const w = show({ message: 'Datos inválidos', errors: { reason: ['Falta el motivo.'], note: ['Falta el motivo.', 'Nota demasiado larga.'] } })
    expect(w.findAll('li').map((li) => li.text())).toEqual(['Falta el motivo.', 'Nota demasiado larga.'])
  })
})

describe('useAsync con alcance: nada del alcance anterior queda en pantalla (M3)', () => {
  const Probe = defineComponent({
    props: { load: { type: Function, required: true } },
    setup(props) {
      const tenant = useTenantStore()
      const calls = ref(0)
      const state = useAsync(async () => { calls.value++; return (props.load as () => Promise<string>)() }, { scope: () => tenant.currentUuid })
      return () => h('div', { 'data-test': 'probe' }, [h('span', { 'data-test': 'data' }, state.data.value ?? 'sin datos'), h('span', { 'data-test': 'calls' }, String(calls.value))])
    },
  })

  it('al cambiar de alcance descarta los datos de inmediato, vuelve a pedir y no deja que una respuesta tardía de la empresa anterior pise a la nueva', async () => {
    const pinia = setupTenant('analyst')
    const tenant = useTenantStore()
    const late: { resolve: (v: string) => void }[] = []
    const load = () => new Promise<string>((resolve) => { late.push({ resolve }) })
    const w = mount(Probe, { props: { load }, global: { plugins: [pinia] } })
    late[0]!.resolve('datos de A')
    await flushPromises()
    expect(w.get('[data-test="data"]').text()).toBe('datos de A')
    tenant.currentUuid = 'empresa-b'
    await flushPromises()
    expect(w.get('[data-test="data"]').text()).toBe('sin datos') // lo de A desapareció mientras llega lo de B
    expect(w.get('[data-test="calls"]').text()).toBe('2')
    tenant.currentUuid = 'empresa-c'
    await flushPromises()
    late[1]!.resolve('datos de B (tardíos)') // respuesta de una empresa que ya no está activa
    await flushPromises()
    expect(w.get('[data-test="data"]').text()).toBe('sin datos')
    late[2]!.resolve('datos de C')
    await flushPromises()
    expect(w.get('[data-test="data"]').text()).toBe('datos de C')
  })
})
