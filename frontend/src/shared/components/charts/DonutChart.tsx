import { Cell, Label, Pie, PieChart } from 'recharts'

import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import { formatMinorUnits } from '@/shared/utils/money'

export interface IDonutSlice {
  /** Unique identifier for the slice */
  key: string
  /** Spanish label shown in legend, tooltip, and optionally the center */
  label: string
  /** Value in minor units */
  value: number
  /** Optional CSS color override; defaults to --chart-N by index */
  color?: string
}

export interface IDonutChartProps {
  data: IDonutSlice[]
  /** Optional text rendered in the center of the donut */
  centerLabel?: string
  /** Accessible description for screen-readers */
  ariaLabel?: string
  height?: number
  innerRadius?: number
  outerRadius?: number
}

const DEFAULT_COLORS = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
]

/** Reusable donut chart. Slices values are in minor units. */
export function DonutChart({
  data,
  centerLabel,
  ariaLabel,
  height = 300,
  innerRadius = 60,
  outerRadius = 100,
}: IDonutChartProps) {
  const resolvedConfig: ChartConfig = Object.fromEntries(
    data.map((slice, i) => [
      slice.key,
      { label: slice.label, color: slice.color ?? DEFAULT_COLORS[i % DEFAULT_COLORS.length] },
    ])
  )

  const chartData = data.map((slice, i) => ({
    name: slice.key,
    value: slice.value,
    fill: slice.color ?? DEFAULT_COLORS[i % DEFAULT_COLORS.length],
  }))

  return (
    <ChartContainer
      config={resolvedConfig}
      className="w-full"
      style={{ height }}
      aria-label={ariaLabel}
    >
      <PieChart>
        <ChartTooltip
          content={
            <ChartTooltipContent
              nameKey="name"
              formatter={(value) => formatMinorUnits(value as number)}
            />
          }
        />
        <Pie
          data={chartData}
          dataKey="value"
          nameKey="name"
          innerRadius={innerRadius}
          outerRadius={outerRadius}
          strokeWidth={2}
        >
          {chartData.map((entry, index) => (
            <Cell key={`cell-${index}`} fill={entry.fill} />
          ))}
          {centerLabel && (
            <Label
              content={({ viewBox }) => {
                if (!viewBox || !('cx' in viewBox) || !('cy' in viewBox)) return null
                const cx = viewBox.cx as number
                const cy = viewBox.cy as number
                return (
                  <text
                    x={cx}
                    y={cy}
                    textAnchor="middle"
                    dominantBaseline="middle"
                    className="fill-foreground text-[13px] font-semibold"
                  >
                    {centerLabel}
                  </text>
                )
              }}
            />
          )}
        </Pie>
        <ChartLegend
          content={<ChartLegendContent nameKey="name" />}
          layout="horizontal"
          verticalAlign="bottom"
          align="center"
        />
      </PieChart>
    </ChartContainer>
  )
}
