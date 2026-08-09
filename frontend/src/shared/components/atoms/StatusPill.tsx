import type { ReactNode } from 'react'

/** Estado visual de un cargo/pago — réplica de los pills del mock Panel v3. */
type PillTone = 'g' | 'y' | 'r' | 'b'

const TONE: Record<PillTone, string> = {
  g: 'bg-[#E7F1E9] text-[#3F9A5E]',
  y: 'bg-[#F6ECD6] text-[#C0862B]',
  r: 'bg-[#F6E2DC] text-[#C0533C]',
  b: 'bg-[#E4ECF2] text-[#3F6E8E]',
}

const DOT: Record<PillTone, string> = {
  g: 'bg-[#3F9A5E]',
  y: 'bg-[#C0862B]',
  r: 'bg-[#C0533C]',
  b: 'bg-[#3F6E8E]',
}

export function StatusPill({ tone, children }: { tone: PillTone; children: ReactNode }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${TONE[tone]}`}
    >
      <i aria-hidden className={`h-1.5 w-1.5 flex-none rounded-full ${DOT[tone]}`} />
      {children}
    </span>
  )
}

export type { PillTone }
