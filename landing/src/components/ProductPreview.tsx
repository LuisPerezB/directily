import { useEffect, useMemo, useState, type KeyboardEvent, type PointerEvent } from 'react'
import type { LandingCopy, LandingLang } from '../copy/types'
import { Icon } from './Icon'

type PreviewCopy = LandingCopy['preview']
type Tab = 'forecast' | 'inventory' | 'decisions'

const TABS: Tab[] = ['forecast', 'inventory', 'decisions']
const HORIZONS = [7, 30, 90] as const
type Horizon = (typeof HORIZONS)[number]

/** Días de historia que se muestran y error histórico ficticio de cada horizonte (más lejos, más error). */
const HISTORY: Record<Horizon, number> = { 7: 21, 30: 42, 90: 60 }
const ERROR_PCT: Record<Horizon, number> = { 7: 14, 30: 18, 90: 23 }

// ── Serie ficticia y determinista: semana con pico el sábado, leve tendencia y ruido fijo ──
const WEEK = [0.92, 0.98, 1, 1.04, 1.18, 1.32, 0.56]
const expected = (day: number): number => 46 * (WEEK[((day % 7) + 7) % 7] ?? 1) * (1 + day * 0.0025)
const noise = (day: number): number => {
  const x = Math.sin(day * 12.9898) * 43758.5453
  return (x - Math.floor(x)) * 2 - 1
}

interface Point {
  day: number
  value: number
  low80: number
  high80: number
  low95: number
  high95: number
}

function buildSeries(horizon: Horizon): Point[] {
  const points: Point[] = []
  for (let day = -HISTORY[horizon]; day <= horizon; day++) {
    if (day <= 0) {
      const value = expected(day) * (1 + 0.13 * noise(day))
      points.push({ day, value, low80: value, high80: value, low95: value, high95: value })
    } else {
      const value = expected(day)
      const spread = value * (0.1 + 0.014 * Math.sqrt(day))
      points.push({ day, value, low80: value - spread, high80: value + spread, low95: value - spread * 1.55, high95: value + spread * 1.55 })
    }
  }
  return points
}

// ── Geometría del gráfico: 720 × 250, o 400 × 250 en pantallas angostas para que no quede aplastado ──
const H = 250
const NARROW = '(max-width: 560px)'

function useNarrow(): boolean {
  const [narrow, setNarrow] = useState(() => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(NARROW).matches)
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return
    const query = window.matchMedia(NARROW)
    const update = () => setNarrow(query.matches)
    query.addEventListener('change', update)
    return () => query.removeEventListener('change', update)
  }, [])
  return narrow
}
const PAD = { left: 34, right: 12, top: 14, bottom: 24 }

function ForecastTab({ copy, lang }: { copy: PreviewCopy['forecast']; lang: LandingLang }) {
  const [horizon, setHorizon] = useState<Horizon>(30)
  const [cursor, setCursor] = useState<number | null>(null)
  const W = useNarrow() ? 400 : 720
  const format = useMemo(() => new Intl.NumberFormat(lang === 'es' ? 'es-EC' : 'en-US', { maximumFractionDigits: 0 }), [lang])

  const chart = useMemo(() => {
    const series = buildSeries(horizon)
    const first = series[0]!.day
    const yMax = Math.ceil(Math.max(...series.map((p) => p.high95)) / 20) * 20
    const x = (day: number): number => PAD.left + ((day - first) / (horizon - first)) * (W - PAD.left - PAD.right)
    const y = (value: number): number => PAD.top + (1 - value / yMax) * (H - PAD.top - PAD.bottom)
    const line = (points: Point[]): string => points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.day).toFixed(1)} ${y(p.value).toFixed(1)}`).join(' ')
    const area = (points: Point[], low: 'low80' | 'low95', high: 'high80' | 'high95'): string =>
      `${points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.day).toFixed(1)} ${y(p[high]).toFixed(1)}`).join(' ')} ${[...points]
        .reverse()
        .map((p) => `L${x(p.day).toFixed(1)} ${y(p[low]).toFixed(1)}`)
        .join(' ')} Z`
    const past = series.filter((p) => p.day <= 0)
    const future = series.filter((p) => p.day >= 0)
    const ahead = series.filter((p) => p.day > 0)
    const total = ahead.reduce((sum, p) => sum + p.value, 0)
    // Los errores diarios no se suman uno a uno: el rango del total es más angosto que la suma de rangos.
    const spread = Math.sqrt(ahead.reduce((sum, p) => sum + (p.high80 - p.value) ** 2, 0)) * 2.2
    return {
      series,
      first,
      yMax,
      x,
      y,
      history: line(past),
      forecast: line(future),
      band80: area(future, 'low80', 'high80'),
      band95: area(future, 'low95', 'high95'),
      total,
      low: total - spread,
      high: total + spread,
    }
  }, [horizon, W])

  const active = cursor === null ? null : (chart.series.find((p) => p.day === cursor) ?? null)

  function onMove(event: PointerEvent<SVGSVGElement>): void {
    const box = event.currentTarget.getBoundingClientRect()
    const ratio = ((event.clientX - box.left) / box.width) * W
    const day = Math.round(chart.first + ((ratio - PAD.left) / (W - PAD.left - PAD.right)) * (horizon - chart.first))
    setCursor(Math.min(horizon, Math.max(chart.first, day)))
  }

  function onKey(event: KeyboardEvent<SVGSVGElement>): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
    event.preventDefault()
    const step = event.key === 'ArrowLeft' ? -1 : 1
    setCursor((current) => Math.min(horizon, Math.max(chart.first, (current ?? 0) + step)))
  }

  const dayLabel = (day: number): string => (day === 0 ? copy.today : (day < 0 ? copy.dayPast : copy.dayFuture).replace('{n}', String(Math.abs(day))))
  const units = (value: number): string => `${format.format(value)} ${copy.units}`

  return (
    <div className="lp-pv__body">
      <div className="lp-pv__top">
        <p className="lp-pv__product">{copy.product}</p>
        <div className="lp-seg" role="group" aria-label={copy.horizon}>
          {HORIZONS.map((option) => (
            <button
              key={option}
              type="button"
              className="lp-seg__btn"
              aria-pressed={horizon === option}
              onClick={() => {
                setHorizon(option)
                setCursor(null)
              }}
            >
              {copy.days.replace('{n}', String(option))}
            </button>
          ))}
        </div>
      </div>

      <dl className="lp-pv__kpis">
        <div>
          <dt>{copy.kpiDemand}</dt>
          <dd data-testid="kpi-demand">{units(chart.total)}</dd>
        </div>
        <div>
          <dt>{copy.kpiRange}</dt>
          <dd>{format.format(chart.low)} – {units(chart.high)}</dd>
        </div>
        <div>
          <dt>{copy.kpiError}</dt>
          <dd>{ERROR_PCT[horizon]} %</dd>
        </div>
      </dl>

      <div className="lp-chart">
        <svg
          viewBox={`0 0 ${W} ${H}`}
          role="img"
          aria-label={copy.chartLabel}
          tabIndex={0}
          onPointerMove={onMove}
          onPointerLeave={() => setCursor(null)}
          onKeyDown={onKey}
          onBlur={() => setCursor(null)}
        >
          {[0.25, 0.5, 0.75, 1].map((fraction) => (
            <g key={fraction} className="lp-chart__grid">
              <line x1={PAD.left} x2={W - PAD.right} y1={chart.y(chart.yMax * fraction)} y2={chart.y(chart.yMax * fraction)} />
              <text x={PAD.left - 8} y={chart.y(chart.yMax * fraction) + 3.5} textAnchor="end">{chart.yMax * fraction}</text>
            </g>
          ))}
          {/* La clave reinicia la animación de trazado cada vez que cambia el horizonte. */}
          <g key={horizon}>
            <path d={chart.band95} className="lp-chart__band lp-chart__band--95" />
            <path d={chart.band80} className="lp-chart__band lp-chart__band--80" />
            <path d={chart.history} className="lp-chart__history" pathLength={1} />
            <path d={chart.forecast} className="lp-chart__forecast" />
          </g>
          <line className="lp-chart__today" x1={chart.x(0)} x2={chart.x(0)} y1={PAD.top} y2={H - PAD.bottom} />
          <text className="lp-chart__label" x={chart.x(0)} y={H - 7} textAnchor="middle">{copy.today}</text>
          <circle className="lp-chart__dot" cx={chart.x(0)} cy={chart.y(chart.series.find((p) => p.day === 0)!.value)} r="4.5" />
          {active && (
            <g>
              <line className="lp-chart__cursor" x1={chart.x(active.day)} x2={chart.x(active.day)} y1={PAD.top} y2={H - PAD.bottom} />
              <circle className="lp-chart__cursor-dot" cx={chart.x(active.day)} cy={chart.y(active.value)} r="4" />
            </g>
          )}
        </svg>
        {active && (
          <div className="lp-chart__tip" style={{ left: `clamp(4.25rem, ${((chart.x(active.day) / W) * 100).toFixed(2)}%, calc(100% - 4.25rem))` }} data-testid="chart-tip">
            <strong>{dayLabel(active.day)}</strong>
            <span>{units(active.value)}</span>
            {active.day > 0 && <span className="lp-chart__tip-range">{format.format(active.low80)} – {format.format(active.high80)}</span>}
          </div>
        )}
      </div>

      <ul className="lp-legend">
        <li><i className="lp-legend__history" />{copy.history}</li>
        <li><i className="lp-legend__forecast" />{copy.forecast}</li>
        <li><i className="lp-legend__band" />{copy.band}</li>
      </ul>
    </div>
  )
}

function InventoryTab({ copy }: { copy: PreviewCopy['inventory'] }) {
  const longest = Math.max(...copy.rows.map((row) => row.cover))
  return (
    <div className="lp-pv__body">
      <div className="lp-pv__scroll">
        <table className="lp-inv">
          <thead>
            <tr>
              {copy.cols.map((col) => (
                <th key={col} scope="col">{col}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {copy.rows.map((row, i) => (
              <tr key={row.name} style={{ animationDelay: `${i * 70}ms` }}>
                <th scope="row">{row.name}</th>
                <td>
                  <span className="lp-inv__cover">
                    <span className="lp-inv__bar"><i className={`lp-inv__fill lp-inv__fill--${row.risk}`} style={{ width: `${Math.max(6, (row.cover / longest) * 100)}%` }} /></span>
                    {copy.days.replace('{n}', String(row.cover))}
                  </span>
                </td>
                <td><span className={`lp-pill lp-pill--${row.risk}`}>{copy.risk[row.risk]}</span></td>
                <td className={row.buy === 0 ? 'lp-inv__none' : 'lp-inv__buy'}>{row.buy === 0 ? copy.noAction : `${row.buy} ${copy.units}`}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function DecisionsTab({ copy }: { copy: PreviewCopy['decisions'] }) {
  const [approved, setApproved] = useState<ReadonlySet<number>>(new Set())
  const toggle = (index: number) =>
    setApproved((current) => {
      const next = new Set(current)
      if (next.has(index)) next.delete(index)
      else next.add(index)
      return next
    })

  return (
    <div className="lp-pv__body">
      <ul className="lp-dec">
        {copy.items.map((item, i) => {
          const done = approved.has(i)
          return (
            <li key={item.title} className={done ? 'is-done' : ''} style={{ animationDelay: `${i * 80}ms` }}>
              <div>
                <p className="lp-dec__priority">{copy.priority.replace('{n}', String(i + 1))} · {item.impact}</p>
                <p className="lp-dec__title">{item.title}</p>
                <p className="lp-dec__detail">{item.detail}</p>
              </div>
              <button type="button" className={`btn btn-sm ${done ? 'btn-done' : 'btn-secondary'}`} aria-pressed={done} onClick={() => toggle(i)}>
                {done && <Icon name="check" size={15} />}
                {done ? copy.approved : copy.approve}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/**
 * Vista previa interactiva del producto: una maqueta con datos FICTICIOS (no es una captura con
 * datos de un cliente) que deja probar el pronóstico por horizonte, el inventario y las decisiones.
 */
export function ProductPreview({ copy, lang }: { copy: PreviewCopy; lang: LandingLang }) {
  const [tab, setTab] = useState<Tab>('forecast')

  return (
    <figure className="lp-pv" aria-label={copy.label}>
      <div className="lp-pv__bar">
        <span className="lp-pv__dots" aria-hidden="true"><i /><i /><i /></span>
        <div className="lp-pv__tabs" role="tablist" aria-label={copy.label}>
          {TABS.map((id) => (
            <button key={id} type="button" role="tab" id={`pv-tab-${id}`} aria-selected={tab === id} aria-controls="pv-panel" className="lp-pv__tab" onClick={() => setTab(id)}>
              {copy.tabs[id]}
            </button>
          ))}
        </div>
      </div>
      <div id="pv-panel" role="tabpanel" aria-labelledby={`pv-tab-${tab}`} key={tab} className="lp-pv__panel">
        {tab === 'forecast' && <ForecastTab copy={copy.forecast} lang={lang} />}
        {tab === 'inventory' && <InventoryTab copy={copy.inventory} />}
        {tab === 'decisions' && <DecisionsTab copy={copy.decisions} />}
      </div>
      <figcaption className="lp-pv__note">{copy.note}</figcaption>
    </figure>
  )
}
