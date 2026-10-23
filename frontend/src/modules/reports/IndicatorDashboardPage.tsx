import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  useIndicatorAreaBreakdown,
  useIndicatorMonthlySeries,
  useIndicatorSummary,
} from '@/modules/reports/hooks/useIndicatorQueries'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { AreaTrendChart } from '@/shared/components/charts/AreaTrendChart'
import { DonutChart } from '@/shared/components/charts/DonutChart'
import { GroupedBarChart } from '@/shared/components/charts/GroupedBarChart'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { tenantCurrentMonth } from '@/shared/utils/tenantTime'
import { Skeleton } from 'boneyard-js/react'
import { useMemo, useState } from 'react'
import { KpiTile } from '@/shared/components/atoms/KpiTile'

const HEALTH_CENTER_REQUEST = { [CatalogType.HEALTH_CENTER]: { _sort: 'name' } }

const countFormatter = (value: number): string => String(value)

const AREA_NAMES: Record<string, string> = {
  MG: 'Motriz gruesa',
  MF: 'Motriz fina',
  LE: 'Lenguaje',
  SO: 'Social',
  CO: 'Conocimiento',
  EN: 'Exploración neurológica',
  ALA: 'Señales de alarma',
  ALE: 'Señales de alerta',
}

/**
 * Situation keys are stored in the catalog, but this card charted them raw
 * ("first_time_green"), while the nominal listing next to it showed the Spanish
 * label. Mirrors screening_situation.name.
 */
const SITUATION_NAMES: Record<string, string> = {
  first_time_green: 'Primera vez verde',
  first_time_yellow: 'Primera vez amarillo',
  first_time_red: 'Primera vez rojo',
  recovered_from_yellow: 'Recuperado de amarillo',
  recovered_from_red: 'Recuperado de rojo',
  unchanged: 'Sin cambio',
  worsened: 'Empeoró',
}

/**
 * REQ-RPT-010 — aggregate dashboard, zero PII. Filters load catalogs lazily
 * (no `POST /selects` on mount); data via TanStack Query keyed by filters.
 */
export function IndicatorDashboardPage() {
  const [healthCenterFilter, setHealthCenterFilter] = useState<string[]>([])
  const healthCenterId = healthCenterFilter[0]

  const centerCatalog = useLazyCatalogs(useMemo(() => HEALTH_CENTER_REQUEST, []))

  // Mes actual en la zona del tenant (no el mes UTC: el último día del mes por la noche ya sería el siguiente).
  const timeZone = useTenantTimeZone()
  const period = useMemo(() => tenantCurrentMonth(timeZone), [timeZone])

  const summaryQuery = useIndicatorSummary({ period, healthCenterId })
  const seriesQuery = useIndicatorMonthlySeries(12, healthCenterId)
  const areaQuery = useIndicatorAreaBreakdown({ period, healthCenterId })

  const isLoading = summaryQuery.isLoading || seriesQuery.isLoading || areaQuery.isLoading

  const semaphoreData = summaryQuery.data
    ? [
        // Colores REALES del semáforo clínico (theme-aware), no la rampa de marca.
        // Antes: chart-2/4/5 = índigo, y en oscuro chart-5 pintaba "Rojo" de verde.
        {
          key: 'green',
          label: 'Verde',
          value: summaryQuery.data.result_green_count,
          color: 'var(--g)',
        },
        {
          key: 'yellow',
          label: 'Amarillo',
          value: summaryQuery.data.result_yellow_count,
          color: 'var(--y)',
        },
        { key: 'red', label: 'Rojo', value: summaryQuery.data.result_red_count, color: 'var(--r)' },
      ]
    : []

  const seriesData = (seriesQuery.data ?? []).map((point) => ({
    label: point.period,
    value: point.screenings_applied,
  }))

  const areaData = Object.entries(areaQuery.data?.areas ?? {}).map(([code, colors]) => ({
    label: AREA_NAMES[code] ?? code,
    'amarillo+rojo': colors.yellow + colors.red,
  }))

  const situationData = summaryQuery.data
    ? Object.entries(summaryQuery.data.situation_breakdown).map(([key, count], i) => ({
        key,
        label: SITUATION_NAMES[key] ?? key,
        value: count,
        color: `var(--chart-${(i % 5) + 1})`,
      }))
    : []

  return (
    <div className='space-y-6'>
      <div className='flex flex-wrap items-center justify-between gap-4'>
        <PageHeader
          title='Indicadores'
          description='Panel agregado de Pruebas EDI del periodo actual'
        />
        <MultiSelectFilter
          label='Centro de salud'
          options={(centerCatalog.catalogs[CatalogType.HEALTH_CENTER] ?? []).map((item) => ({
            value: item.id,
            label: item.name,
          }))}
          value={healthCenterFilter}
          onChange={setHealthCenterFilter}
          isLoading={centerCatalog.isLoading}
          onFirstOpen={centerCatalog.enable}
        />
      </div>

      <Skeleton name='indicator-dashboard' loading={isLoading}>
        <div className='grid grid-cols-2 gap-px border border-border bg-border lg:grid-cols-4'>
          <KpiTile label='Pruebas EDI aplicadas' value={summaryQuery.data?.screenings_applied ?? 0} />
          <KpiTile label='Niños tamizados' value={summaryQuery.data?.children_screened ?? 0} />
          <KpiTile
            label='Con señales de alerta'
            value={summaryQuery.data?.alert_sign_screening_count ?? 0}
          />
          <KpiTile
            label='Confirmatoria pendiente'
            value={summaryQuery.data?.confirmatory_pending_count ?? 0}
          />
        </div>

        <div className='mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2'>
          <Card>
            <CardHeader>
              <CardTitle>Distribución por semáforo</CardTitle>
            </CardHeader>
            <CardContent>
              <DonutChart
                data={semaphoreData}
                ariaLabel='Distribución por semáforo'
                valueFormatter={countFormatter}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Tendencia mensual</CardTitle>
            </CardHeader>
            <CardContent>
              <AreaTrendChart
                data={seriesData}
                seriesLabel='Pruebas EDI aplicadas'
                ariaLabel='Tendencia mensual de Pruebas EDI'
                valueFormatter={countFormatter}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Áreas más afectadas</CardTitle>
            </CardHeader>
            <CardContent>
              <GroupedBarChart
                data={areaData}
                series={[{ dataKey: 'amarillo+rojo', label: 'Amarillo + Rojo' }]}
                ariaLabel='Áreas más afectadas'
                valueFormatter={countFormatter}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Situación</CardTitle>
            </CardHeader>
            <CardContent>
              <DonutChart
                data={situationData}
                ariaLabel='Distribución por situación'
                valueFormatter={countFormatter}
              />
            </CardContent>
          </Card>
        </div>
      </Skeleton>
    </div>
  )
}

