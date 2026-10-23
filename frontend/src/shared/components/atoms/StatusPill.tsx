import type { ReactNode } from 'react'

/**
 * Estado visual. Canto vivo (no píldora) y colores por token del tema, no hex
 * hardcodeados. Los tonos g/y/r son el SEMÁFORO CLÍNICO y deben usarse SOLO para
 * resultados del tamiz (normal/rezago/riesgo). Para estados no clínicos
 * (activo/inactivo, sí/no) usá el tono `b` (índigo de marca) o `n` (neutro).
 */
type PillTone = 'g' | 'y' | 'r' | 'b' | 'n'

const TONE: Record<PillTone, string> = {
  g: 'bg-[var(--gbg)] text-[var(--g)]',
  y: 'bg-[var(--ybg)] text-[var(--y)]',
  r: 'bg-[var(--rbg)] text-[var(--r)]',
  b: 'bg-[var(--bbg)] text-[var(--b)]',
  n: 'bg-secondary text-[var(--ink-2)]',
}

const DOT: Record<PillTone, string> = {
  g: 'bg-[var(--g)]',
  y: 'bg-[var(--y)]',
  r: 'bg-[var(--r)]',
  b: 'bg-[var(--b)]',
  n: 'bg-[var(--ink-3)]',
}

export function StatusPill({ tone, children }: { tone: PillTone; children: ReactNode }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-none px-2.5 py-1 text-xs font-medium ${TONE[tone]}`}
    >
      <i aria-hidden className={`h-1.5 w-1.5 flex-none rounded-none ${DOT[tone]}`} />
      {children}
    </span>
  )
}

export type { PillTone }
