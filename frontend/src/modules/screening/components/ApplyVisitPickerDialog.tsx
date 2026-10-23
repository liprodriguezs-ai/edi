import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { childFullName } from '@/modules/child/schema'
import { ApplyModeFields, type ApplyMode } from '@/modules/screening/components/ApplyModeFields'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { CHOSEN_GROUP_PARAM } from '@/modules/screening/utils/ageGroupCopy'
import type { IVisit } from '@/modules/visit/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'

interface ApplyVisitPickerDialogProps {
  isOpen: boolean
  onClose: () => void
  /** Visit already known (child detail, visit list): the picker is skipped. */
  visit?: IVisit | null
  initialMode?: ApplyMode
}

/**
 * S2 — besides the visit, the evaluator picks "Prueba EDI" (age-derived group)
 * or "Prueba EDI a elección" (a chosen official group, passed to the apply route
 * as `?grupo=<id>`).
 *
 * REQ-SUI-002/D8 — the list CTA ("Aplicar tamizaje") opens this dialog: pick
 * a visit via remote SearchSelect, then navigate to
 * `/screening/apply/:visitId`. If the visit already has a screening
 * (VISIT_ALREADY_SCREENED, resolved defensively here too — the wizard also
 * handles the 422 on create), redirect straight to its resume/detail route
 * instead of letting the create call fail.
 */
export function ApplyVisitPickerDialog({
  isOpen,
  onClose,
  visit,
  initialMode,
}: ApplyVisitPickerDialogProps) {
  if (!isOpen) return null
  return (
    <ApplyVisitPickerDialogBody
      onClose={onClose}
      presetVisit={visit ?? null}
      initialMode={initialMode ?? 'age'}
    />
  )
}

interface ApplyVisitPickerDialogBodyProps {
  onClose: () => void
  presetVisit: IVisit | null
  initialMode: ApplyMode
}

function ApplyVisitPickerDialogBody({
  onClose,
  presetVisit,
  initialMode,
}: ApplyVisitPickerDialogBodyProps) {
  const navigate = useNavigate()
  const [selectedVisit, setSelectedVisit] = useState<IVisit | null>(presetVisit)
  const [isChecking, setIsChecking] = useState(false)
  const [mode, setMode] = useState<ApplyMode>(initialMode)
  const [chosenAgeGroupId, setChosenAgeGroupId] = useState<string | null>(null)
  // SearchSelect's onChange only carries the id — cache the last fetched
  // options so onChange can resolve the full IVisit (needed for the redirect
  // decision below) without a second request.
  const knownVisitsRef = useRef<Map<string, IVisit>>(new Map())

  const loadVisitOptions = useCallback(async (term: string) => {
    const results = await VisitResource.searchByTerm(term)
    for (const visit of results) knownVisitsRef.current.set(visit.id, visit)
    return results
  }, [])

  const handleChange = useCallback((value: string | string[] | null) => {
    const id = Array.isArray(value) ? value[0] : value
    setSelectedVisit(id ? (knownVisitsRef.current.get(id) ?? null) : null)
  }, [])

  const canContinue =
    selectedVisit !== null && !isChecking && (mode === 'age' || chosenAgeGroupId !== null)

  const handleContinue = async () => {
    if (!selectedVisit) return
    setIsChecking(true)
    try {
      const existing = await ScreeningResource.findByVisitId(selectedVisit.id)
      if (existing) {
        const targetStatus = existing.screening_status?.key
        onClose()
        if (targetStatus === 'draft' || targetStatus === 'scored') {
          navigate(`/screening/${existing.id}/apply`)
        } else {
          navigate(`/screening/${existing.id}`)
        }
        return
      }
      onClose()
      const query =
        mode === 'chosen' && chosenAgeGroupId
          ? `?${new URLSearchParams({ [CHOSEN_GROUP_PARAM]: chosenAgeGroupId }).toString()}`
          : ''
      navigate(`/screening/apply/${selectedVisit.id}${query}`)
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo verificar la visita')
      }
    } finally {
      setIsChecking(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>Aplicar Prueba EDI</DialogTitle>
        </DialogHeader>

        {presetVisit ? (
          <p className='text-sm text-muted-foreground'>
            Visita <span className='font-mono'>{presetVisit.receipt_number}</span>
            {presetVisit.child ? ` · ${childFullName(presetVisit.child)}` : ''}
          </p>
        ) : (
          <Field>
            <FieldLabel htmlFor='apply-visit-picker' required>
              Visita
            </FieldLabel>
            <SearchSelect<IVisit>
              id='apply-visit-picker'
              value={selectedVisit?.id ?? null}
              onChange={handleChange}
              loadOptions={loadVisitOptions}
              selectedOptions={selectedVisit ? [selectedVisit] : undefined}
              getOptionLabel={(visit) =>
                `${visit.receipt_number} · ${visit.child ? childFullName(visit.child) : 'Sin niño'}`
              }
              placeholder='Buscar por folio o niño…'
              isDisabled={isChecking}
            />
            <FieldDescription>Busca por folio de la visita.</FieldDescription>
          </Field>
        )}

        <ApplyModeFields
          mode={mode}
          onModeChange={setMode}
          ageGroupId={chosenAgeGroupId}
          onAgeGroupChange={setChosenAgeGroupId}
          disabled={isChecking}
        />

        <DialogFooter>
          <Button type='button' variant='outline' onClick={onClose} disabled={isChecking}>
            Cancelar
          </Button>
          <Button type='button' onClick={handleContinue} disabled={!canContinue}>
            {isChecking ? 'Verificando…' : 'Continuar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
