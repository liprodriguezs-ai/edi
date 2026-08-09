import type { ReactNode } from 'react'

interface KpiTileProps {
  /** Color del cuadrito indicador (hex). */
  swatch: string
  label: string
  value: ReactNode
  detail: ReactNode
  /** Puntos del sparkline (viewBox 0 0 120 30) — opcional */
  sparkPoints?: string
  sparkColor?: string
}

/** Tarjeta KPI con sparkline — réplica de `.kpi` del mock Panel v3. */
export function KpiTile({ swatch, label, value, detail, sparkPoints, sparkColor }: KpiTileProps) {
  return (
    <div className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-[11.5px] font-medium text-[var(--ink-3)]">
        <span className="h-[7px] w-[7px] rounded-sm" style={{ background: swatch }} />
        {label}
      </div>
      <div className="mt-2.5 text-[27px] font-semibold leading-none tracking-[-0.02em] tabular-nums">
        {value}
      </div>
      <div className="mt-2 text-xs text-[var(--ink-3)]">{detail}</div>
      {sparkPoints && (
        <svg className="mt-3 h-[30px] w-full" viewBox="0 0 120 30" preserveAspectRatio="none">
          <polyline points={sparkPoints} fill="none" stroke={sparkColor} strokeWidth="1.6" />
        </svg>
      )}
    </div>
  )
}
