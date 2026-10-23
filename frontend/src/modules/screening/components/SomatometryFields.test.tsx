import type { IScreening } from '@/modules/screening/schema'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { SomatometryFields } from './SomatometryFields'

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn(), warning: vi.fn() },
}))

function screeningWith(mode: 'age' | 'chosen', extra: Partial<IScreening> = {}): IScreening {
  return {
    id: 's-1',
    weight_grams: null,
    height_mm: null,
    head_circumference_mm: null,
    age_group_selection_mode: mode,
    ...extra,
  } as IScreening
}

const CHOSEN_NOTE =
  'En la Prueba EDI a elección, el reactivo de perímetro cefálico se contesta a mano.'

describe('SomatometryFields', () => {
  it('explains that the head circumference item is answered by hand in "a elección"', () => {
    render(<SomatometryFields screening={screeningWith('chosen')} onSave={vi.fn()} />)

    expect(screen.getByText(CHOSEN_NOTE)).toBeInTheDocument()
  })

  it('does not show that note for an age-derived screening', () => {
    render(<SomatometryFields screening={screeningWith('age')} onSave={vi.fn()} />)

    expect(screen.queryByText(CHOSEN_NOTE)).not.toBeInTheDocument()
  })

  it('shows the stored grams and millimetres in kg and cm, with the accepted range', () => {
    render(
      <SomatometryFields
        screening={screeningWith('age', { weight_grams: 8200, height_mm: 705, head_circumference_mm: 440 })}
        onSave={vi.fn()}
      />
    )

    expect(screen.getByLabelText('Peso (kg)')).toHaveValue('8.2')
    expect(screen.getByLabelText('Talla (cm)')).toHaveValue('70.5')
    expect(screen.getByLabelText('Perímetro cefálico (cm)')).toHaveValue('44')
    expect(screen.getByText('Entre 1.5 y 40 kg')).toBeInTheDocument()
    expect(screen.getByText('Entre 35 y 140 cm')).toBeInTheDocument()
    expect(screen.getByText('Entre 25 y 70 cm')).toBeInTheDocument()
  })

  it('saves what was typed in cm as millimetres', async () => {
    vi.useFakeTimers()
    const onSave = vi.fn().mockResolvedValue(undefined)
    render(<SomatometryFields screening={screeningWith('age')} onSave={onSave} />)

    fireEvent.change(screen.getByLabelText('Talla (cm)'), { target: { value: '70,5' } })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(750)
    })
    vi.useRealTimers()

    expect(onSave).toHaveBeenCalledWith({ weight_grams: null, height_mm: 705, head_circumference_mm: null })
  })
})

