import { useEffect, useRef } from 'react'
import type { LandingLang } from '../copy/types'
import { prefersReducedMotion } from '../lib/motion'
import { mediaUrl } from '../lib/page'

/**
 * Video corto del producto (sin sonido, en bucle). Empieza a reproducirse al entrar en pantalla y
 * se pausa al salir; si la persona pidió menos movimiento, no arranca solo y muestra los controles.
 * El video se genera con branding/tools/render-video.mjs (interfaz con datos ficticios).
 */
export function DemoVideo({ lang, label }: { lang: LandingLang; label: string }) {
  const ref = useRef<HTMLVideoElement>(null)
  const still = prefersReducedMotion()

  useEffect(() => {
    const video = ref.current
    if (!video || still || typeof IntersectionObserver === 'undefined') return
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) void video.play().catch(() => undefined)
        else video.pause()
      },
      { threshold: 0.35 },
    )
    observer.observe(video)
    return () => observer.disconnect()
  }, [still, lang])

  return (
    <video
      ref={ref}
      key={lang}
      className="lp-video__player"
      src={mediaUrl(`predictily-demo-${lang}.mp4`)}
      poster={mediaUrl(`predictily-demo-poster-${lang}.jpg`)}
      width={1280}
      height={720}
      muted
      loop
      playsInline
      preload="metadata"
      controls={still}
      aria-label={label}
      data-testid="demo-video"
    />
  )
}
