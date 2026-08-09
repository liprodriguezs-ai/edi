import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Line,
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

export interface ILineBarComboDataPoint {
  label: string
  bar: number
  line: number
}

export interface ILineBarComboChartProps {
  /** Data points pre-aggregated by the caller. Values are in minor units. */
  data: ILineBarComboDataPoint[]
  /** Label shown on the bar series (e.g. "Ingresos") */
  barLabel: string
  /** Label shown on the line series (e.g. "Egresos") */
  lineLabel: string
  /** Accessible description for screen-readers */
  ariaLabel?: string
  height?: number
}

/** Reusable combo chart (bars + line) that reads pre-aggregated data in minor units. */
export function LineBarComboChart({
  data,
  barLabel,
  lineLabel,
  ariaLabel,
  height = 300,
}: ILineBarComboChartProps) {
  const resolvedConfig: ChartConfig = {
    bar: { label: barLabel, color: 'var(--chart-1)' },
    line: { label: lineLabel, color: 'var(--chart-2)' },
  }

  return (
    <ChartContainer
      config={resolvedConfig}
      className="w-full"
      style={{ height }}
      aria-label={ariaLabel}
    >
      <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
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
          tickFormatter={(value: number) => formatMinorUnits(value)}
          tick={{ fontSize: 11 }}
          width={80}
        />
        <ChartTooltip
          content={
            <ChartTooltipContent
              formatter={(value) => formatMinorUnits(value as number)}
            />
          }
        />
        <Bar dataKey="bar" fill={resolvedConfig.bar.color} radius={[4, 4, 0, 0]} />
        <Line
          type="monotone"
          dataKey="line"
          stroke={resolvedConfig.line.color}
          strokeWidth={2}
          dot={false}
        />
      </ComposedChart>
    </ChartContainer>
  )
}
