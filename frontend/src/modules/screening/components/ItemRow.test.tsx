import { ItemRow } from '@/modules/screening/components/ItemRow'
import type { IBankItem } from '@/modules/screening/schema'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

const OPEN_ITEM: IBankItem = {
  screening_item_id: 'item-1',
  code: 'MG3.1',
  question: '¿Sostiene la cabeza?',
  is_alternate: false,
  locked: false,
  locked_reason: null,
  options: [
    { screening_item_option_id: 'opt-a', label: 'SI' },
    { screening_item_option_id: 'opt-b', label: 'NO' },
  ],
}

const LOCKED_ITEM: IBankItem = {
  ...OPEN_ITEM,
  screening_item_id: 'item-hc',
  locked: true,
  locked_reason: { auto_derivation_key: 'head_circumference', source_field: 'head_circumference_mm', value: 360 },
}

describe('ItemRow', () => {
  it('renders two neutral options with no color hints (REQ-SUI-006)', () => {
    render(<ItemRow item={OPEN_ITEM} value={undefined} onChange={vi.fn()} />)

    expect(screen.getByText('SI')).toBeInTheDocument()
    expect(screen.getByText('NO')).toBeInTheDocument()
    // No color-bearing class name should appear on either radio option — a
    // regression here would mean an option→color mapping crept in.
    expect(screen.queryByText(/verde|amarillo|rojo/i)).not.toBeInTheDocument()
  })

  it('renders a locked item read-only with its structured reason', () => {
    render(<ItemRow item={LOCKED_ITEM} value={undefined} onChange={vi.fn()} />)

    expect(screen.queryByRole('radio')).not.toBeInTheDocument()
    expect(screen.getByText(/perímetro cefálico/)).toBeInTheDocument()
    expect(screen.getByText(/360/)).toBeInTheDocument()
  })

  it('always shows SI before NO, whatever order the instrument stores', () => {
    const reversed: IBankItem = {
      ...OPEN_ITEM,
      options: [
        { screening_item_option_id: 'opt-b', label: 'NO' },
        { screening_item_option_id: 'opt-a', label: 'SI' },
      ],
    }
    render(<ItemRow item={reversed} value={undefined} onChange={vi.fn()} />)

    expect(screen.getAllByRole('radio').map((radio) => radio.getAttribute('value'))).toEqual(['opt-a', 'opt-b'])
  })
})

