import { cn } from '@/lib/utils'
import { NutritionalStatusBadge } from '@/modules/nutrition/components/NutritionalStatusBadge'
import { useScreeningNutritionalAssessment } from '@/modules/nutrition/hooks/useNutritionalAssessments'
import { formatHeightCm, formatWeightKg } from '@/modules/nutrition/utils/nutritionalStatus'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { Skeleton } from 'boneyard-js/react'

interface ScreeningNutritionalStatusProps {
  screeningId: string
  /** Peso y talla actuales; al cambiar se vuelve a consultar la clasificación. */
  measuresKey?: string
  className?: string
}

/**
 * Estado nutricional de una Prueba EDI: la medición que el servidor sincronizó
 * desde su somatometría (P/T, P/E, T/E). Sólo lectura; se corrige editando la
 * somatometría de la Prueba EDI.
 */
export function ScreeningNutritionalStatus({
  screeningId,
  measuresKey,
  className,
}: ScreeningNutritionalStatusProps) {
  const {
    data: assessment,
    isLoading,
    isError,
  } = useScreeningNutritionalAssessment(screeningId, measuresKey)

  if (isLoading) {
    return (
      <Skeleton name='screening-nutritional-status' loading>
        <div className={cn('grid grid-cols-1 gap-3 sm:grid-cols-3', className)}>
          {[0, 1, 2].map((index) => (
            <div key={index} className='h-12 bg-muted' />
          ))}
        </div>
      </Skeleton>
    )
  }

  if (isError) {
    return <InlineEmpty className={className}>No se pudo cargar el estado nutricional.</InlineEmpty>
  }

  if (!assessment) {
    return <InlineEmpty className={className}>Sin somatometría registrada en esta Prueba EDI.</InlineEmpty>
  }

  const indicators = [
    { label: 'Peso para la talla (P/T)', status: assessment.weight_for_height_status },
    { label: 'Peso para la edad (P/E)', status: assessment.weight_for_age_status },
    { label: 'Talla para la edad (T/E)', status: assessment.height_for_age_status },
  ]

  return (
    <div className={cn('space-y-3', className)}>
      <div className='grid grid-cols-1 gap-3 sm:grid-cols-3'>
        {indicators.map((indicator) => (
          <div key={indicator.label} className='space-y-1'>
            <p className='text-xs font-medium text-muted-foreground'>{indicator.label}</p>
            <NutritionalStatusBadge status={indicator.status} />
          </div>
        ))}
      </div>
      <p className='font-mono text-xs tabular-nums text-muted-foreground'>
        {formatWeightKg(assessment.weight_grams)} · {formatHeightCm(assessment.height_mm)} ·{' '}
        {assessment.age_months} meses
      </p>
    </div>
  )
}
