import type { AppointmentFormPrefill } from '@/modules/appointment/components/AppointmentFormSheet'
import type { IAppointmentSuggestion } from '@/modules/appointment/schema'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { useQuery } from '@tanstack/react-query'

/** Hora por defecto al prellenar una sugerencia (sólo propone; recepción la ajusta). */
const SUGGESTED_HOUR = '09:00'

/** S7 — próxima cita propuesta por el servidor a partir de la conducta del último tamizaje. */
export function useAppointmentSuggestion(childId: string | undefined, enabled = true) {
  return useQuery({
    queryKey: ['appointment', 'suggestion', childId],
    queryFn: () => AppointmentResource.suggestion(childId as string),
    enabled: Boolean(childId) && enabled,
  })
}

type SuggestionChild = NonNullable<AppointmentFormPrefill['child']>

/** Convierte la sugerencia en valores iniciales del formulario de cita. */
export function suggestionToPrefill(suggestion: IAppointmentSuggestion, child: SuggestionChild): AppointmentFormPrefill {
  return {
    child,
    appointmentTypeId: suggestion.appointment_type_id,
    startsAtLocal: suggestion.suggested_date ? `${suggestion.suggested_date}T${SUGGESTED_HOUR}` : undefined,
    followUpId: suggestion.follow_up_id,
    hint: suggestion.reason,
  }
}
