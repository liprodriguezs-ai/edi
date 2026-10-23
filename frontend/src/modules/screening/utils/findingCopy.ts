import { sourceFieldLabel } from '@/modules/screening/utils/lockedReasonCopy'
/**
 * D12 — `finding_type.key` (+ context) → Spanish sentence. The server owns
 * the structured key and context payload; this UI-only copy map turns them
 * into a readable clinical note (REQ-SUI-009).
 */

type FindingContext = Record<string, unknown> | null | undefined

function contextValue(context: FindingContext, key: string): string | undefined {
  const value = context?.[key]
  if (value === null || value === undefined) return undefined
  return String(value)
}

const FINDING_BUILDERS: Record<string, (_context: FindingContext) => string> = {
  age_group_substituted_by_corrected_age: () =>
    'La edad corregida sustituyó a la edad cronológica para elegir el grupo de aplicación.',
  unknown_perinatal_input: (context) => {
    const field = contextValue(context, 'source_field') ?? contextValue(context, 'field')
    return field
      ? `El dato de ${sourceFieldLabel(field)} no está disponible o no es plausible; no se tomó en cuenta en el cálculo.`
      : 'Un dato perinatal no está disponible o no es plausible; no se tomó en cuenta en el cálculo.'
  },
  auto_answer_overrode_manual: () =>
    'Una respuesta capturada manualmente fue sustituida por el valor derivado automáticamente.',
  unanswered_item: () => 'Un reactivo quedó sin contestar.',
  missing_percentile_reference: () => 'No hay referencia de percentil disponible para este cálculo.',
}

export function findingCopy(findingTypeKey: string | undefined, context: FindingContext): string {
  if (!findingTypeKey) return 'Hallazgo del motor de calificación.'
  const builder = FINDING_BUILDERS[findingTypeKey]
  return builder ? builder(context) : 'Hallazgo del motor de calificación.'
}
