import type { IAgeGroupRef, IScreening } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AppliedGroupSummary } from './AppliedGroupSummary'

function group(ordinal: number): IAgeGroupRef {
  return { id: `g-${ordinal}`, code: `G${ordinal}`, ordinal } as IAgeGroupRef
}

function chosenScreening(overrides: Partial<IScreening>): IScreening {
  return {
    id: 's-1',
    age_group_selection_mode: 'chosen',
    applied_age_group: group(10),
    age_group: group(3),
    ...overrides,
  } as IScreening
}

describe('AppliedGroupSummary', () => {
  it('names the group the corrected age would have applied', () => {
    render(<AppliedGroupSummary screening={chosenScreening({ age_derived_age_group: group(1) })} />)

    expect(screen.getByText(/por edad correspondía el Grupo 1\)/)).toBeInTheDocument()
  })

  it('falls back to the chronological group for screenings created before the field existed', () => {
    render(<AppliedGroupSummary screening={chosenScreening({ age_derived_age_group: null })} />)

    expect(screen.getByText(/por edad correspondía el Grupo 3\)/)).toBeInTheDocument()
  })
})
