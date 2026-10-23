import { AuditDiffModal } from '@/modules/audit/AuditDiffModal'
import type { IAudit } from '@/modules/audit/schema'
import { setTenantTimeZone } from '@/shared/utils/dateHelper'
import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

const AUDIT = {
  id: 'a-1',
  event: 'updated',
  auditable_type: 'Modules\\ChildRecord\\Models\\Child',
  auditable_id: 'c-1',
  created_at: '2026-10-21T03:00:00.000000Z',
  old_values: { birth_date: '2026-10-20' },
  new_values: { birth_date: '2026-10-21' },
} as unknown as IAudit

describe('AuditDiffModal — fechas', () => {
  afterEach(() => setTenantTimeZone(null))

  it('muestra el instante del cambio en la zona del tenant y las fechas civiles sin corrimiento', () => {
    setTenantTimeZone('Asia/Tokyo')

    render(<AuditDiffModal isOpen audit={AUDIT} onClose={() => {}} />)

    expect(screen.getByText('oct 21, 2026 12:00')).toBeInTheDocument()
    expect(screen.getByText('oct 21, 2026')).toBeInTheDocument()
    expect(screen.getByText('oct 20, 2026')).toBeInTheDocument()
  })
})
