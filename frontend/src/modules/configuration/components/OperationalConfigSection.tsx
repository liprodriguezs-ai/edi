import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import type { OperationalConfig } from '@/modules/configuration/services/ConfigurationResource'
import { fetchSessionProfile } from '@/shared/auth/authService'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { setActiveCurrency } from '@/shared/utils/currency'
import { useQueryClient, type UseMutationResult } from '@tanstack/react-query'
import { useState } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

interface Props {
  operational: OperationalConfig | undefined
  canUpdate: boolean
  saveMutation: UseMutationResult<
    unknown,
    Error,
    { path: string; payload: Record<string, unknown> }
  >
}

export function OperationalConfigSection({ operational, canUpdate, saveMutation }: Props) {
  const { form, setField } = useSectionEdits<OperationalConfig>(operational)
  const queryClient = useQueryClient()
  const [confirmCurrencyOpen, setConfirmCurrencyOpen] = useState(false)

  const currencyChanged = Boolean(form.currency) && form.currency !== operational?.currency

  const buildPayload = (): Record<string, unknown> => {
    const payload: Record<string, unknown> = {
      type: form.type ?? undefined,
    }
    // Solo se envía `currency` (y su confirmación) cuando REALMENTE cambió —
    // de lo contrario el backend exigiría `confirmed` en cada guardado (rule
    // `required_with:timezone,currency`), aunque solo se haya tocado el tipo
    // de condominio.
    if (currencyChanged) {
      payload.currency = form.currency
      payload.confirmed = true
    }
    return payload
  }

  /**
   * Tras un cambio de moneda confirmado, la sesión y el cache de queries deben
   * reflejar la nueva moneda de inmediato (R-TCUR-5) — de lo contrario los
   * `formatted` ya cacheados en el cliente quedarían con la moneda vieja hasta
   * el próximo refresh manual.
   */
  const syncCurrencyEverywhere = async () => {
    try {
      const profile = await fetchSessionProfile()
      sessionStore.getState().setProfile(profile)
    } catch {
      // El cambio ya quedó guardado en el servidor; la sesión se resincroniza
      // en la próxima navegación, así que no bloqueamos al usuario por esto.
    }
    setActiveCurrency(form.currency)
    void queryClient.invalidateQueries()
  }

  const persistOperational = () => {
    saveMutation.mutate(
      { path: '/config/operational', payload: buildPayload() },
      { onSuccess: currencyChanged ? () => void syncCurrencyEverywhere() : undefined }
    )
  }

  const saveOperational = () => {
    if (currencyChanged) {
      setConfirmCurrencyOpen(true)
      return
    }
    persistOperational()
  }

  const confirmCurrencyChange = () => {
    setConfirmCurrencyOpen(false)
    persistOperational()
  }

  return (
    <div className={CARD}>
      <FieldGroup>
        <div className='grid grid-cols-12 gap-4'>
          <Field className='col-span-12 md:col-span-4'>
            <FieldLabel>Zona horaria</FieldLabel>
            <Input value={form.timezone ?? ''} disabled readOnly />
            <FieldDescription>Cambiarla requiere confirmación del soporte</FieldDescription>
          </Field>
          <Field className='col-span-6 md:col-span-4'>
            <FieldLabel htmlFor='op-currency'>Moneda</FieldLabel>
            <Select
              value={form.currency ?? undefined}
              onValueChange={(v) => setField('currency', v)}
              disabled={!canUpdate || saveMutation.isPending}
            >
              <SelectTrigger id='op-currency' className='w-full'>
                <SelectValue placeholder='Seleccionar…' />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value='MXN'>MXN — Peso mexicano</SelectItem>
                <SelectItem value='USD'>USD — Dólar estadounidense</SelectItem>
              </SelectContent>
            </Select>
          </Field>
          <Field className='col-span-6 md:col-span-4'>
            <FieldLabel htmlFor='op-type'>Tipo de condominio</FieldLabel>
            <Select
              value={form.type ?? undefined}
              onValueChange={(v) => setField('type', v)}
              disabled={!canUpdate || saveMutation.isPending}
            >
              <SelectTrigger id='op-type' className='w-full'>
                <SelectValue placeholder='Seleccionar…' />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value='horizontal'>Horizontal (casas)</SelectItem>
                <SelectItem value='vertical'>Vertical (torres)</SelectItem>
                <SelectItem value='mixed'>Mixto</SelectItem>
              </SelectContent>
            </Select>
          </Field>
        </div>
        {canUpdate && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button
              onClick={saveOperational}
              disabled={saveMutation.isPending}
              className='min-w-[120px] rounded-full'
            >
              {saveMutation.isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </div>
        )}
      </FieldGroup>

      <ConfirmDialog
        isOpen={confirmCurrencyOpen}
        title='Cambiar moneda del condominio'
        description={`Vas a cambiar la moneda de ${operational?.currency ?? 'MXN'} a ${form.currency}. Los montos existentes se reinterpretarán en la nueva moneda — NO se convierten aritméticamente (por ejemplo, un cargo de 1,500.00 pasará a mostrarse en ${form.currency} con el mismo número, sin recalcular el valor).`}
        confirmLabel='Cambiar moneda'
        isLoading={saveMutation.isPending}
        onConfirm={confirmCurrencyChange}
        onCancel={() => setConfirmCurrencyOpen(false)}
      />
    </div>
  )
}
