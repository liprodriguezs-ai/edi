import {
  Bar,
  BarChart,
  CartesianGrid,
  XAxis,
  YAxis,
} from 'recharts'

import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import { formatMinorUnits } from '@/shared/utils/money'

export interface IGroupedBarSeries {
  /** Unique key matching the data property */
  dataKey: string
  /** Spanish label shown in legend and tooltip */
  label: string
  /** CSS color value; defaults to --chart-N by position */
  color?: string
}

export interface IGroupedBarDataPoint {
  /** Category label shown on X axis */
  label: string
  /** Values keyed by series dataKey; values are in minor units */
  [key: string]: number | string
}

export interface IGroupedBarChartProps {
  data: IGroupedBarDataPoint[]
  series: IGroupedBarSeries[]
  /** Accessible description for screen-readers */
  ariaLabel?: string
  height?: number
  /** Axis/tooltip value formatter. Defaults to money (minor units) for backward compatibility — pass a plain formatter (e.g. `String`) for count-based data. */
  valueFormatter?: (_value: number) => string
}

const DEFAULT_COLORS = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
]

/** Reusable grouped bar chart. Each series is a bar group. Values are in minor units. */
export function GroupedBarChart({
  data,
  series,
  ariaLabel,
  height = 300,
  valueFormatter = formatMinorUnits,
}: IGroupedBarChartProps) {
  const resolvedConfig: ChartConfig = Object.fromEntries(
    series.map((s, i) => [
      s.dataKey,
      { label: s.label, color: s.color ?? DEFAULT_COLORS[i % DEFAULT_COLORS.length] },
    ])
  )

  return (
    <ChartContainer
      config={resolvedConfig}
      className="w-full"
      style={{ height }}
      aria-label={ariaLabel}
    >
      <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
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
        <ChartLegend content={<ChartLegendContent />} />
        {series.map((s, i) => (
          <Bar
            key={s.dataKey}
            dataKey={s.dataKey}
            fill={s.color ?? DEFAULT_COLORS[i % DEFAULT_COLORS.length]}
            radius={[4, 4, 0, 0]}
          />
        ))}
      </BarChart>
    </ChartContainer>
  )
}
