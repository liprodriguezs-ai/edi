import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { NutritionalAssessmentFormModal } from '@/modules/nutrition/components/NutritionalAssessmentFormModal'
import { NutritionalStatusBadge } from '@/modules/nutrition/components/NutritionalStatusBadge'
import { useChildNutritionalAssessments } from '@/modules/nutrition/hooks/useNutritionalAssessments'
import type { INutritionalAssessment } from '@/modules/nutrition/schema'
import { formatHeightCm, formatWeightKg } from '@/modules/nutrition/utils/nutritionalStatus'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { formatDateOnly } from '@/shared/utils/tenantTime'
import { Plus } from 'lucide-react'
import { memo, useState } from 'react'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'

interface ChildNutritionTabProps {
  childId: string
}

/**
 * S5 — pestaña "Estado nutricional" del expediente: historial de mediciones
 * (capturadas aquí o sincronizadas desde la Prueba EDI) con P/T, P/E y T/E
 * calculados por el servidor, y captura de una medición nueva.
 */
export function ChildNutritionTab({ childId }: ChildNutritionTabProps) {
  const [isFormOpen, setIsFormOpen] = useState(false)
  const { data: assessments = [], isLoading, isError } = useChildNutritionalAssessments(childId)

  return (
    <div className="border border-border">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
        <h3 className="text-sm font-semibold">Estado nutricional</h3>
        <PermissionGuard permission="create.nutritional_assessment">
          <Button size="sm" onClick={() => setIsFormOpen(true)} className="flex items-center gap-1.5">
            <Plus className="h-4 w-4" />
            Registrar medición
          </Button>
        </PermissionGuard>
      </div>

      <HistoryBody assessments={assessments} isLoading={isLoading} isError={isError} />

      <NutritionalAssessmentFormModal isOpen={isFormOpen} childId={childId} onClose={() => setIsFormOpen(false)} />
    </div>
  )
}

function HistoryBody({
  assessments,
  isLoading,
  isError,
}: {
  assessments: INutritionalAssessment[]
  isLoading: boolean
  isError: boolean
}) {
  if (isLoading) return <TableLoadingState rows={3} className="border-0" />
  if (isError) {
    return <InlineEmpty className="px-4 py-3">No se pudo cargar el historial nutricional.</InlineEmpty>
  }
  if (assessments.length === 0) {
    return <InlineEmpty className="px-4 py-3">Este niño aún no tiene mediciones registradas.</InlineEmpty>
  }

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Fecha</TableHead>
            <TableHead>Edad</TableHead>
            <TableHead>Peso</TableHead>
            <TableHead>Talla</TableHead>
            <TableHead>P/T</TableHead>
            <TableHead>P/E</TableHead>
            <TableHead>T/E</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {assessments.map((assessment) => (
            <AssessmentRow key={assessment.id} assessment={assessment} />
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

const AssessmentRow = memo(function AssessmentRow({ assessment }: { assessment: INutritionalAssessment }) {
  return (
    <TableRow>
      <TableCell className="whitespace-nowrap">
        <span className="font-mono text-sm tabular-nums">{formatDateOnly(assessment.measured_on)}</span>
        {assessment.screening_id && <span className="block text-xs text-muted-foreground">Prueba EDI</span>}
      </TableCell>
      <TableCell className="whitespace-nowrap text-sm">{assessment.age_months} meses</TableCell>
      <TableCell className="whitespace-nowrap font-mono text-sm tabular-nums">
        {formatWeightKg(assessment.weight_grams)}
      </TableCell>
      <TableCell className="whitespace-nowrap font-mono text-sm tabular-nums">
        {formatHeightCm(assessment.height_mm)}
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={assessment.weight_for_height_status} />
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={assessment.weight_for_age_status} />
      </TableCell>
      <TableCell>
        <NutritionalStatusBadge status={assessment.height_for_age_status} />
      </TableCell>
    </TableRow>
  )
})
