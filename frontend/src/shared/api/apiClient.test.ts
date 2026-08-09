/**
 * Tests for `handle403`'s DELINQUENT_RESTRICTED escape hatch (design §8.2/§8.3,
 * `apiClient.ts:handle403`).
 *
 * A delinquency-guard 403 is a business rule (adeudo vencido), not a missing
 * permission — the caller (wizard/modal/form) already renders the correct,
 * debt-framed error via `formatApiErrorMessage`. Opening the global
 * PermissionsModal on top of that gives residents/guards TWO error surfaces,
 * the second one wrongly saying "no tienes permisos". An ordinary 403 must
 * keep opening the modal — this is not a blanket "hide 403s" fix.
 */
import { handle403 } from '@/shared/api/apiClient'
import { usePermissionsModal } from '@/shared/hooks/usePermissionsModal'
import { beforeEach, describe, expect, it } from 'vitest'

function make403(data: unknown, config: Record<string, unknown> = {}) {
  return {
    response: { data },
    config: { method: 'post', url: '/visitor/abc/approve', ...config },
  }
}

describe('handle403', () => {
  beforeEach(() => {
    usePermissionsModal.getState().closeModal()
  })

  it('does NOT open the permissions modal for a DELINQUENT_RESTRICTED 403', () => {
    handle403(
      make403({
        message: 'Función restringida por adeudos vencidos.',
        code: 'DELINQUENT_RESTRICTED',
      })
    )

    expect(usePermissionsModal.getState().isOpen).toBe(false)
  })

  it('still opens the permissions modal for an ordinary 403 (no regression)', () => {
    handle403(make403({ message: 'No tienes permisos para realizar esta acción' }))

    const state = usePermissionsModal.getState()
    expect(state.isOpen).toBe(true)
    expect(state.message).toBe('No tienes permisos para realizar esta acción')
    expect(state.action).toBe('POST /visitor/abc/approve')
  })

  it('still opens the permissions modal for a 403 with an unrelated business code', () => {
    handle403(make403({ message: 'Otro error de negocio', code: 'SOME_OTHER_CODE' }))

    expect(usePermissionsModal.getState().isOpen).toBe(true)
  })
})
