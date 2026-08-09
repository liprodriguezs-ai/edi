import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useForm } from 'react-hook-form'
import {
  AmountModeSelector,
  resolveAmountFlags,
} from '@/modules/chargePolicy/components/AmountModeSelector'
import { AMOUNT_MODE, type ChargePolicyFormValues } from '@/modules/chargePolicy/schema'

function Harness({ amountModeId }: { amountModeId: string }) {
  const {
    register,
    formState: { errors },
  } = useForm<ChargePolicyFormValues>({
    defaultValues: { amount_mode_id: amountModeId } as ChargePolicyFormValues,
  })
  return (
    <AmountModeSelector
      register={register}
      errors={errors}
      flags={resolveAmountFlags(amountModeId)}
      isSaving={false}
    />
  )
}

describe('AmountModeSelector (chargePolicy)', () => {
  it('NO renderiza el selector de modo (RadioGroup): el modo queda fijo en Fijo obligatorio', () => {
    render(<Harness amountModeId={AMOUNT_MODE.FIXED_REQUIRED} />)
    // El RadioGroup de tarjetas se ocultó del form por decisión de producto (ver
    // AmountModeSelector.tsx): ningún radio ni las tarjetas de modo deben aparecer.
    expect(screen.queryAllByRole('radio')).toHaveLength(0)
    expect(screen.queryByText('Fijo obligatorio')).not.toBeInTheDocument()
    expect(screen.queryByText('Rango')).not.toBeInTheDocument()
    expect(screen.queryByText('Voluntario')).not.toBeInTheDocument()
    expect(screen.queryByText('Por indiviso')).not.toBeInTheDocument()
    // El campo de monto fijo dependiente sigue presente (flag-driven).
    expect(document.getElementById('policy-fixed_amount')).toBeInTheDocument()
  })

  it('muestra el campo de monto fijo en modo Fijo obligatorio', () => {
    render(<Harness amountModeId={AMOUNT_MODE.FIXED_REQUIRED} />)
    expect(document.getElementById('policy-fixed_amount')).toBeInTheDocument()
    expect(document.getElementById('policy-min_amount')).not.toBeInTheDocument()
  })

  it('muestra mínimo / ideal / máximo en modo Rango (red de seguridad para datos legacy)', () => {
    render(<Harness amountModeId={AMOUNT_MODE.RANGE} />)
    expect(document.getElementById('policy-min_amount')).toBeInTheDocument()
    expect(document.getElementById('policy-ideal_amount')).toBeInTheDocument()
    expect(document.getElementById('policy-max_amount')).toBeInTheDocument()
  })

  it('muestra el total a prorratear en modo Por indiviso (red de seguridad para datos legacy)', () => {
    render(<Harness amountModeId={AMOUNT_MODE.BY_SHARE} />)
    expect(document.getElementById('policy-prorate_total_amount')).toBeInTheDocument()
  })

  it('no muestra campos de monto en modo Voluntario', () => {
    render(<Harness amountModeId={AMOUNT_MODE.FREE} />)
    expect(document.getElementById('policy-fixed_amount')).not.toBeInTheDocument()
    expect(document.getElementById('policy-min_amount')).not.toBeInTheDocument()
    expect(document.getElementById('policy-prorate_total_amount')).not.toBeInTheDocument()
  })
})
