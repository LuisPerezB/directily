/**
 * Logotipo de Predictily (opción 1, nov-2026): una P maciza atravesada por la línea de un pronóstico que sube
 * y termina en un punto ámbar, el siguiente dato. Redibujado en vector a partir del boceto elegido
 * (branding/opciones-2026-11/logo-1-p-linea.png). El mismo dibujo está en index.html (icono de la pestaña) y en
 * public/media/icono.svg: si cambia aquí, cámbielo allí.
 */
export const LOGO_P = 'M8 5H25.5A14.5 14.5 0 0 1 25.5 34H20V43H8Z'
export const LOGO_LINE = 'M8 29.5L17 22.5L22.5 27L32.5 16'
export const LOGO_DOT = { cx: 33.6, cy: 14.8, r: 4.6 }

export function Logo({ size = 32 }: { size?: number }) {
  return (
    <span className="lp-logo">
      <svg width={size} height={size} viewBox="0 0 48 48" role="img" aria-label="Predictily" focusable="false">
        <path fill="var(--brand)" d={LOGO_P} />
        {/* La línea y el aro del punto son «huecos»: toman el color del fondo de la página (claro u oscuro). */}
        <path fill="none" stroke="var(--bg)" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round" d={LOGO_LINE} />
        <circle fill="var(--amber)" stroke="var(--bg)" strokeWidth="2" {...LOGO_DOT} />
      </svg>
      <span style={{ fontSize: Math.round(size * 0.7) }}>Predictily</span>
    </span>
  )
}
