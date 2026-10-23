import { AppointmentFormSheet, type AppointmentFormPrefill } from '@/modules/appointment/components/AppointmentFormSheet'
import { suggestionToPrefill } from '@/modules/appointment/hooks/useAppointmentSuggestion'
import { AppointmentResource } from '@/modules/appointment/services/AppointmentResource'
import { toast } from '@/shared/hooks/useToast'
import { useState, type ReactNode } from 'react'

export type AppointmentChild = NonNullable<AppointmentFormPrefill['child']>

interface NextAppointmentForm {
  /** Asks the server for the suggested date and opens the prefilled form. */
  open: () => Promise<void>
  isLoading: boolean
  /** The form sheet; render it once wherever the hook is used. */
  sheet: ReactNode
}

/**
 * S7/S10 — the next appointment after a screening: the server suggests the date
 * (from the result colour) and the form opens prefilled. It never books on its
 * own; the staff confirms. `onClosed` runs when the form closes, saved or not.
 */
export function useNextAppointmentForm(
  child: AppointmentChild | null,
  onClosed?: () => void
): NextAppointmentForm {
  const [prefill, setPrefill] = useState<AppointmentFormPrefill | null>(null)
  const [isLoading, setIsLoading] = useState(false)

  const open = async () => {
    if (!child) return
    setIsLoading(true)
    try {
      const suggestion = await AppointmentResource.suggestion(child.id)
      setPrefill(suggestionToPrefill(suggestion, child))
    } catch {
      toast.error('No se pudo obtener la fecha sugerida', 'Puedes elegir la fecha manualmente.')
      setPrefill({ child })
    } finally {
      setIsLoading(false)
    }
  }

  const sheet = (
    <AppointmentFormSheet
      isOpen={prefill !== null}
      mode='book'
      prefill={prefill ?? undefined}
      onClose={() => {
        setPrefill(null)
        onClosed?.()
      }}
    />
  )

  return { open, isLoading, sheet }
}
