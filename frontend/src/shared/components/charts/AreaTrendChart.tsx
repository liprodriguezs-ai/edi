import React from 'react'
import {
  Area,
  AreaChart,
  CartesianGrid,
  XAxis,
  YAxis,
} from 'recharts'

import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import { formatMinorUnits } from '@/shared/utils/money'

export interface IAreaTrendDataPoint {
  /** X-axis label (e.g. "Ene 2025") */
  label: string
  /** Value in minor units */
  value: number
}

export interface IAreaTrendChartProps {
  data: IAreaTrendDataPoint[]
  /** Spanish label for the series (e.g. "Saldo acumulado") */
  seriesLabel: string
  /** Optional fill color; defaults to --chart-1 with opacity gradient */
  color?: string
  /** Accessible description for screen-readers */
  ariaLabel?: string
  height?: number
  /** Axis/tooltip value formatter. Defaults to money (minor units) for backward compatibility — pass a plain formatter (e.g. `String`) for count-based data. */
  valueFormatter?: (_value: number) => string
}

/** Reusable area trend chart for single time-series data in minor units. */
export function AreaTrendChart({
  data,
  seriesLabel,
  color = 'var(--chart-1)',
  ariaLabel,
  height = 300,
  valueFormatter = formatMinorUnits,
}: IAreaTrendChartProps) {
  const resolvedConfig: ChartConfig = {
    value: { label: seriesLabel, color },
  }

  // React.useId() guarantees uniqueness even when multiple instances render on
  // the same page, preventing SVG gradient id collisions.
  const uid = React.useId().replace(/:/g, '')
  const gradientId = `area-gradient-${uid}`

  return (
    <ChartContainer
      config={resolvedConfig}
      className="w-full"
      style={{ height }}
      aria-label={ariaLabel}
    >
      <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="5%" stopColor={color} stopOpacity={0.3} />
            <stop offset="95%" stopColor={color} stopOpacity={0.02} />
          </linearGradient>
        </defs>
        <CartesianGrid strokeDasharray="3 3" vertical={false} />
        <XAxis
          dataKey="label"
          tickLine={false}
          axisLine={false}
          tick={{ fontSize: 12 }}
        />
        <YAxis
          tickLine={false}
          axisLine={false}
          tickFormatter={(value: number) => valueFormatter(value)}
          tick={{ fontSize: 11 }}
          width={80}
        />
        <ChartTooltip
          content={
            <ChartTooltipContent
              formatter={(value) => valueFormatter(value as number)}
            />
          }
        />
        <Area
          type="monotone"
          dataKey="value"
          stroke={color}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          dot={false}
        />
      </AreaChart>
    </ChartContainer>
  )
}
