/**
 * Logotipo de Predictily: una P cuyo hueco es un triángulo que apunta hacia arriba (anticipar) y un
 * punto ámbar que marca «hoy». Las fuentes de marca están en branding/logo/.
 */
const MARK =
  'M11.2 8.5H24.1A12.3 12.3 0 0 1 24.1 33.1H19.2V43.3A1.2 1.2 0 0 1 18 44.5H11.2A1.2 1.2 0 0 1 10 43.3V9.7A1.2 1.2 0 0 1 11.2 8.5ZM23.3 16.3L28.1 24.5H18.5Z'

export function Logo({ size = 32 }: { size?: number }) {
  return (
    <span className="lp-logo">
      <svg width={size} height={size} viewBox="0 0 48 48" role="img" aria-label="Predictily" focusable="false">
        <path fill="var(--brand)" fillRule="evenodd" d={MARK} />
        <circle fill="var(--amber)" cx="35.7" cy="6.5" r="3.1" />
      </svg>
      <span style={{ fontSize: Math.round(size * 0.7) }}>Predictily</span>
    </span>
  )
}
