import { KpiTile } from '@/shared/components/atoms/KpiTile'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { Button } from '@/components/ui/button'
import { useIndicatorSummary } from '@/modules/reports/hooks/useIndicatorQueries'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { tenantCurrentMonth } from '@/shared/utils/tenantTime'
import { Skeleton } from 'boneyard-js/react'
import { useMemo } from 'react'
import { Link } from 'react-router-dom'

/**
 * Home del tablero. El shell (sidebar + topbar) lo aporta DashboardLayout.
 *
 * Muestra el resumen del periodo actual reusando el indicador agregado
 * (`useIndicatorSummary`), en el lenguaje del sistema: celdas que se tocan por
 * hairline y el semáforo clínico con sus colores reales. Sin datos del periodo,
 * cae en un estado vacío que apunta a la acción.
 */

export function DashboardPage() {
  // Mes actual en la zona del tenant (no el mes UTC: el último día del mes por la noche ya sería el siguiente).
  const timeZone = useTenantTimeZone()
  const period = useMemo(() => tenantCurrentMonth(timeZone), [timeZone])
  const { data, isLoading, isError } = useIndicatorSummary({ period })

  const hasData = !!data && data.screenings_applied > 0

  return (
    <div className='space-y-6'>
      <PageHeader title='Inicio' description='Resumen del periodo actual' />

      {isLoading ? (
        <Skeleton name='dashboard-summary' loading>
          <div className='grid grid-cols-2 gap-px bg-border lg:grid-cols-4'>
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className='h-[104px] bg-muted' />
            ))}
          </div>
        </Skeleton>
      ) : !hasData || isError ? (
        <div className='border border-border'>
          <div className='flex flex-col items-start gap-4 px-6 py-14'>
            <SectionLabel>Periodo {period}</SectionLabel>
            <h2 className='max-w-[28ch] text-xl font-bold tracking-tight'>
              Todavía no hay Pruebas EDI este mes.
            </h2>
            <p className='max-w-[52ch] text-sm text-muted-foreground'>
              Cuando apliques el primer tamiz del periodo, aquí vas a ver el resumen del semáforo y
              los casos que requieren seguimiento.
            </p>
            <div className='flex flex-wrap gap-2 pt-1'>
              <Button asChild>
                <Link to='/screening'>Ver Pruebas EDI</Link>
              </Button>
              <Button asChild variant='outline'>
                <Link to='/child'>Ir a niños</Link>
              </Button>
            </div>
          </div>
        </div>
      ) : (
        <div className='space-y-8'>
          {/* Semáforo del periodo — celdas que se tocan */}
          <div>
            <div className='mb-2.5 flex items-baseline justify-between'>
              <SectionLabel as='h2'>Semáforo del periodo</SectionLabel>
              <Link
                to='/reports/indicators'
                className='font-mono text-[10.5px] uppercase tracking-[0.1em] text-primary hover:text-foreground'
              >
                Ver indicadores →
              </Link>
            </div>
            <div className='grid grid-cols-2 gap-px border border-border bg-border lg:grid-cols-4'>
              <KpiTile
                label='Aplicados'
                value={data.screenings_applied}
                detail='Pruebas EDI del periodo'
              />
              <KpiTile
                label='Desarrollo normal'
                value={data.result_green_count}
                detail={`${data.percent_green}% del total`}
                swatch='var(--g)'
              />
              <KpiTile
                label='Rezago'
                value={data.result_yellow_count}
                detail={`${data.percent_yellow}% del total`}
                swatch='var(--y)'
              />
              <KpiTile
                label='Riesgo de retraso'
                value={data.result_red_count}
                detail={`${data.percent_red}% del total`}
                swatch='var(--r)'
              />
            </div>
          </div>

          {/* Requieren seguimiento */}
          <div>
            <SectionLabel as='h2' className='mb-2.5'>
              Requieren seguimiento
            </SectionLabel>
            <div className='grid grid-cols-1 gap-px border border-border bg-border sm:grid-cols-3'>
              <KpiTile label='Prueba confirmatoria pendiente' value={data.confirmatory_pending_count} />
              <KpiTile label='Con señales de alerta' value={data.alert_sign_screening_count} />
              <KpiTile label='Con factores de riesgo' value={data.risk_factor_screening_count} />
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
