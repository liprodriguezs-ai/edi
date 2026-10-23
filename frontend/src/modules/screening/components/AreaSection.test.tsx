import type { IBankArea, IBankItem } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AreaSection } from './AreaSection'

function item(id: string, isAlternate = false): IBankItem {
  return {
    screening_item_id: id,
    code: id.toUpperCase(),
    question: `Pregunta ${id}`,
    is_alternate: isAlternate,
    locked: false,
    locked_reason: null,
    options: [
      { screening_item_option_id: `${id}-si`, label: 'Sí' },
      { screening_item_option_id: `${id}-no`, label: 'No' },
    ],
  }
}

const area: IBankArea = {
  development_area_id: 'area-mg',
  code: 'MG',
  name: 'Motriz gruesa',
  items: [item('mg1'), item('mg2'), item('mga1', true), item('mga2', true)],
}

const alternates = [item('mga1', true), item('mga2', true)]

describe('AreaSection', () => {
  it('counts the required alternate items in the area progress', () => {
    render(
      <AreaSection
        area={area}
        answers={{ mg1: 'mg1-no', mg2: 'mg2-no' }}
        onAnswer={vi.fn()}
        incompleteItemIds={[]}
        alternateItems={alternates}
      />
    )

    expect(screen.getByText('Requiere grupo anterior · 0/2')).toBeInTheDocument()
    expect(screen.getByText('2/4')).toBeInTheDocument()
  })

  it('names the area in the alternate evaluation block', () => {
    render(
      <AreaSection
        area={area}
        answers={{ mg1: 'mg1-no', mg2: 'mg2-no' }}
        onAnswer={vi.fn()}
        incompleteItemIds={[]}
        alternateItems={alternates}
      />
    )

    expect(
      screen.getByRole('region', { name: 'Grupo anterior de Motriz gruesa' })
    ).toBeInTheDocument()
  })

  it('shows the area as complete once the alternate items are answered', () => {
    render(
      <AreaSection
        area={area}
        answers={{ mg1: 'mg1-no', mg2: 'mg2-no', mga1: 'mga1-si', mga2: 'mga2-no' }}
        onAnswer={vi.fn()}
        incompleteItemIds={[]}
        alternateItems={alternates}
      />
    )

    expect(screen.queryByText(/Requiere grupo anterior/)).not.toBeInTheDocument()
    expect(screen.getByText('4/4')).toBeInTheDocument()
  })

  it('keeps the previous counter when no alternate items are required', () => {
    render(
      <AreaSection
        area={area}
        answers={{ mg1: 'mg1-si' }}
        onAnswer={vi.fn()}
        incompleteItemIds={[]}
        alternateItems={undefined}
      />
    )

    expect(screen.getByText('1/2')).toBeInTheDocument()
  })

  it('does not flag an item as missing once it has an answer', () => {
    render(
      <AreaSection
        area={area}
        answers={{ mg1: 'mg1-si' }}
        onAnswer={vi.fn()}
        incompleteItemIds={['mg1', 'mg2']}
        alternateItems={undefined}
      />
    )

    expect(screen.getAllByText('Falta contestar este reactivo.')).toHaveLength(1)
  })
})
