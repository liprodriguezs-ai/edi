import { Field, FieldDescription, FieldLabel } from '@/components/ui/field'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import type { IAgeGroupOption } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { ageGroupOptionLabel } from '@/modules/screening/utils/ageGroupCopy'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useEffect, useRef, useState } from 'react'

export type ApplyMode = 'age' | 'chosen'

const MODE_OPTIONS: ReadonlyArray<{ value: ApplyMode; label: string; description: string }> = [
  { value: 'age', label: 'Prueba EDI', description: 'El grupo se calcula con la edad del niño.' },
  {
    value: 'chosen',
    label: 'Prueba EDI a elección',
    description: 'Tú eliges el grupo de la evaluación a realizar.',
  },
]

interface ApplyModeFieldsProps {
  mode: ApplyMode
  onModeChange: (_mode: ApplyMode) => void
  ageGroupId: string | null
  onAgeGroupChange: (_ageGroupId: string | null) => void
  disabled?: boolean
}

/**
 * S2 — "Prueba EDI" (age-derived group) vs "Prueba EDI a elección" (the legacy
 * "Evaluación a realizar" select). The group list is fetched only when "a
 * elección" is in use: on switching to it, or on mount when the dialog opens
 * in that mode.
 */
export function ApplyModeFields({ mode, onModeChange, ageGroupId, onAgeGroupChange, disabled }: ApplyModeFieldsProps) {
  const [groups, setGroups] = useState<IAgeGroupOption[] | null>(null)
  // The "A elección" shortcuts open the dialog already in chosen mode, so the
  // radio never fires a change: in that case the list loads on mount.
  const [opensInChosenMode] = useState(mode === 'chosen')
  const [isLoadingGroups, setIsLoadingGroups] = useState(opensInChosenMode)
  const requestedRef = useRef(false)

  const fetchGroups = useCallback(() => {
    requestedRef.current = true
    ScreeningResource.ageGroups()
      .then(setGroups)
      .catch(() => {
        // Allow a retry the next time the evaluator picks "a elección".
        requestedRef.current = false
        toast.error('No se pudieron cargar los grupos de la Prueba EDI')
      })
      .finally(() => setIsLoadingGroups(false))
  }, [])

  useEffect(() => {
    if (opensInChosenMode && !requestedRef.current) fetchGroups()
  }, [opensInChosenMode, fetchGroups])

  const handleModeChange = (value: string) => {
    const next: ApplyMode = value === 'chosen' ? 'chosen' : 'age'
    onModeChange(next)
    if (next === 'age') {
      onAgeGroupChange(null)
      return
    }
    if (groups === null && !requestedRef.current) {
      setIsLoadingGroups(true)
      fetchGroups()
    }
  }

  return (
    <div className="space-y-4">
      <RadioGroup
        value={mode}
        onValueChange={handleModeChange}
        className="grid gap-3 sm:grid-cols-2"
        aria-label="Tipo de prueba"
      >
        {MODE_OPTIONS.map((option) => {
          const inputId = `apply-mode-${option.value}`
          return (
            <label
              key={option.value}
              htmlFor={inputId}
              className="flex cursor-pointer flex-col gap-1.5 border border-border p-3 transition-colors hover:border-primary has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5"
            >
              <span className="flex items-center gap-2 text-sm font-medium">
                <RadioGroupItem id={inputId} value={option.value} disabled={disabled} />
                {option.label}
              </span>
              <span className="text-xs text-muted-foreground">{option.description}</span>
            </label>
          )
        })}
      </RadioGroup>

      {mode === 'chosen' && (
        <Field>
          <FieldLabel htmlFor="apply-age-group" required>
            Evaluación a realizar
          </FieldLabel>
          <SearchSelect<IAgeGroupOption>
            id="apply-age-group"
            value={ageGroupId}
            onChange={(value) => onAgeGroupChange((Array.isArray(value) ? value[0] : value) ?? null)}
            options={groups ?? []}
            isLoading={isLoadingGroups}
            isDisabled={disabled || isLoadingGroups}
            getOptionLabel={ageGroupOptionLabel}
            placeholder="Selecciona el grupo…"
          />
          <FieldDescription>La calificación usa las reglas del grupo elegido.</FieldDescription>
        </Field>
      )}
    </div>
  )
}
