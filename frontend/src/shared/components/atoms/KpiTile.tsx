import type { ReactNode } from 'react'

interface KpiTileProps {
  label: ReactNode
  value: ReactNode
  detail?: ReactNode
  /** CSS color (token or hex) for the indicator square and the value. */
  swatch?: string
}

/**
 * KPI cell: mono uppercase label, big tabular number, optional detail. Tiles are
 * meant to sit in a hairline grid (`grid gap-px bg-border border border-border`).
 */
export function KpiTile({ label, value, detail, swatch }: KpiTileProps) {
  return (
    <div className='bg-card px-5 py-4'>
      <div className='flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.14em] text-[var(--ink-3)]'>
        {swatch && <span className='size-[7px]' style={{ background: swatch }} aria-hidden />}
        {label}
      </div>
      <div
        className='mt-2 font-mono text-[28px] leading-none tabular-nums tracking-[-0.03em]'
        style={swatch ? { color: swatch } : undefined}
      >
        {value}
      </div>
      {detail && <div className='mt-1.5 text-xs text-muted-foreground'>{detail}</div>}
    </div>
  )
}
