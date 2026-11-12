import type { LandingCopy } from '../copy/types'
import { Icon } from './Icon'

/*
 * Ilustraciones de las secciones «La plataforma» y «Por qué confiar». Son maquetas dibujadas con HTML y SVG (no capturas):
 * pesan casi nada, se leen nítidas en cualquier pantalla y siguen el tema claro u oscuro. Todas usan datos ficticios y
 * son decorativas (`aria-hidden`): el texto que las acompaña ya dice lo mismo.
 */

type Copy = LandingCopy

function Frame({ label, children, note }: { label: string; children: React.ReactNode; note: string }) {
  return (
    <figure className="lp-gfx" aria-hidden="true">
      <div className="lp-gfx__bar">
        <span className="lp-gfx__dots"><i /><i /><i /></span>
        <span className="lp-gfx__label">{label}</span>
      </div>
      <div className="lp-gfx__body">{children}</div>
      <figcaption className="lp-gfx__note">{note}</figcaption>
    </figure>
  )
}

/** Capa «Conecta»: el archivo del cliente, sus columnas emparejadas con los campos de Predictily y el reporte de calidad. */
export function ConnectGraphic({ copy }: { copy: Copy['platform']['graphics'] }) {
  const c = copy.connect
  return (
    <Frame label={c.file} note={copy.note}>
      <div className="lp-map">
        <p className="lp-map__head"><span>{c.fromLabel}</span><span>{c.toLabel}</span></p>
        <ul>
          {c.rows.map((row) => (
            <li key={row.from}>
              <code>{row.from}</code>
              <i className="lp-map__arrow" />
              <span>{row.to}</span>
              <b className="lp-map__ok"><Icon name="check" size={13} /></b>
            </li>
          ))}
        </ul>
      </div>
      <div className="lp-meter">
        <p><span>{c.quality}</span><strong>{c.qualityValue}</strong></p>
        <div className="lp-meter__track"><i style={{ width: '96%' }} /></div>
        <p className="lp-meter__foot"><Icon name="check" size={14} />{c.dedupe}</p>
      </div>
    </Frame>
  )
}

// Serie ficticia y fija: seis semanas de ventas con su ritmo semanal y cuatro de pronóstico con un rango que se abre.
const WEEK = [0.9, 0.97, 1, 1.05, 1.2, 1.34, 0.58]
const W = 520
const H = 210
const PAST = 42
const AHEAD = 28
const wobble = (day: number): number => {
  const x = Math.sin(day * 12.9898) * 43758.5453
  return (x - Math.floor(x)) * 2 - 1
}
const level = (day: number): number => 46 * (WEEK[((day % 7) + 7) % 7] ?? 1) * (1 + day * 0.003)
const px = (day: number): number => 8 + ((day + PAST) / (PAST + AHEAD)) * (W - 16)
const py = (value: number): number => H - 24 - ((value - 14) / (82 - 14)) * (H - 40)
const path = (points: readonly (readonly [number, number])[]): string => points.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ')

const history = Array.from({ length: PAST + 1 }, (_, i) => i - PAST).map((day) => [px(day), py(level(day) * (1 + 0.12 * wobble(day)))] as const)
const future = Array.from({ length: AHEAD + 1 }, (_, day) => day)
const forecast = future.map((day) => [px(day), py(day === 0 ? level(0) * (1 + 0.12 * wobble(0)) : level(day))] as const)
const spread = (day: number): number => level(day) * (0.1 + 0.03 * Math.sqrt(day))
const band = `${path(future.map((day) => [px(day), py(level(day) + (day === 0 ? 0 : spread(day)))] as const))} ${[...future]
  .reverse()
  .map((day) => `L${px(day).toFixed(1)} ${py(level(day) - (day === 0 ? 0 : spread(day))).toFixed(1)}`)
  .join(' ')} Z`

/** Capa «Predice»: ventas registradas, pronóstico y su rango de incertidumbre. Usa las etiquetas de la vista previa. */
export function PredictGraphic({ copy, note, product }: { copy: Copy['preview']['forecast']; note: string; product: string }) {
  return (
    <Frame label={product} note={note}>
      <svg className="lp-mini" viewBox={`0 0 ${W} ${H}`} focusable="false">
        {[0.25, 0.5, 0.75].map((f) => (
          <line key={f} className="lp-mini__grid" x1="8" x2={W - 8} y1={18 + f * (H - 40)} y2={18 + f * (H - 40)} />
        ))}
        <path className="lp-mini__band" d={band} />
        <path className="lp-mini__history" d={path(history)} />
        <path className="lp-mini__forecast" d={path(forecast)} />
        <line className="lp-mini__today" x1={px(0)} x2={px(0)} y1="10" y2={H - 18} />
        <circle className="lp-mini__dot" cx={px(0)} cy={forecast[0]![1]} r="4.5" />
        <text className="lp-mini__label" x={px(0)} y={H - 4} textAnchor="middle">{copy.today}</text>
      </svg>
      <ul className="lp-legend">
        <li><i />{copy.history}</li>
        <li><i className="lp-legend__forecast" />{copy.forecast}</li>
        <li><i className="lp-legend__band" />{copy.band}</li>
      </ul>
    </Frame>
  )
}

/** Capa «Decide»: la cola de recomendaciones priorizadas. Reutiliza los ejemplos de la vista previa (sin sus importes). */
export function DecideGraphic({ copy, note, label }: { copy: Copy['preview']['decisions']; note: string; label: string }) {
  return (
    <Frame label={label} note={note}>
      <ol className="lp-queue">
        {copy.items.map((item, i) => (
          <li key={item.title} className={i === 0 ? 'is-done' : ''}>
            <span className="lp-queue__n">{i + 1}</span>
            <div>
              <p className="lp-queue__title">{item.title}</p>
              <p className="lp-queue__detail">{item.detail}</p>
            </div>
            <span className="lp-queue__btn">{i === 0 && <Icon name="check" size={13} />}{i === 0 ? copy.approved : copy.approve}</span>
          </li>
        ))}
      </ol>
    </Frame>
  )
}

/** «Por qué confiar»: los modelos compiten y queda en uso el de menor error, siempre junto al método de referencia. */
export function ModelsGraphic({ copy }: { copy: Copy['trust']['models'] }) {
  const max = Math.max(...copy.rows.map((row) => row.error))
  const best = Math.min(...copy.rows.map((row) => row.error))
  return (
    <Frame label={copy.title} note={copy.note}>
      <ul className="lp-bars">
        {copy.rows.map((row, i) => (
          <li key={row.name} className={row.error === best ? 'is-best' : ''}>
            <p>
              <span>{row.name}</span>
              {i === 0 && <em>{copy.reference}</em>}
              {row.error === best && <em className="lp-bars__use">{copy.inUse}</em>}
            </p>
            <div className="lp-bars__track"><i style={{ width: `${(row.error / max) * 100}%` }} /></div>
            <strong>{row.error}{copy.suffix}</strong>
          </li>
        ))}
      </ul>
    </Frame>
  )
}
