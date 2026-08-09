/**
 * REQ-QV-02 (quota-visibility, amended): a quota 422 must reach the user
 * carrying the BACKEND's own Mexican-Spanish message, through the generic error
 * surface — there is no quota-specific frontend branch by design (D9), so the
 * generic path IS the contract.
 *
 * Why this file exists: the requirement had zero automated coverage. The code
 * string lived in `BUSINESS_CODES` with no consumers and no test, so nothing
 * would have caught it drifting from what the backend emits — which is exactly
 * the defect class this change was created to kill (`'unit_limit_reached'` vs
 * `UNIT_LIMIT_EXCEEDED`, an `if` that was never true).
 */
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { BUSINESS_CODES } from '@/shared/api/businessCodes'
import { describe, expect, it } from 'vitest'

/** Exactly what `QuotaExceededException::render()` returns, field for field. */
const BACKEND_422 = {
  message:
    'Alcanzaste el límite de 5 para registros de visitantes de tu plan (Esencial). Actualiza tu plan para continuar.',
  code: 'QUOTA_EXCEEDED',
  resource: 'visitor',
  limit: 5,
  used: 5,
  scope: 'trial',
  plan_name: 'Esencial',
}

describe('quota 422 reaches the user', () => {
  it('holds a code byte-identical to the one the backend emits', () => {
    // `QuotaExceededException::CODE` in
    // backend/Modules/SubscriptionPlan/app/Exceptions/QuotaExceededException.php.
    // Hard-coded on purpose: comparing the constant against itself would prove
    // nothing. This literal is the backend's string, written out.
    expect(BUSINESS_CODES.QUOTA_EXCEEDED).toBe('QUOTA_EXCEEDED')
    expect(BACKEND_422.code).toBe(BUSINESS_CODES.QUOTA_EXCEEDED)
  })

  it("surfaces the backend's message verbatim, without reframing it", () => {
    // The whole point of the amended requirement: with no quota-specific branch,
    // the user must still learn the real reason. A generic fallback like "Error
    // al guardar" would leave a blocked resident guessing.
    const { title, description } = formatApiErrorMessage(
      new ApiError('Request failed with status code 422', 422, BACKEND_422)
    )

    expect(title).toBe(BACKEND_422.message)
    expect(title).toMatch(/Alcanzaste el límite de 5/)
    expect(title).toMatch(/registros de visitantes/)
    expect(title).toMatch(/Esencial/)
    // Tú-form, per the project's Mexican-Spanish UI rule.
    expect(title).toMatch(/Actualiza tu plan/)
    expect(description).toBeUndefined()
  })

  it('does not fall back to the generic message when the payload carries one', () => {
    const { title } = formatApiErrorMessage(new ApiError('Network Error', 422, BACKEND_422))

    expect(title).not.toBe('Error al guardar')
    expect(title).not.toBe('Network Error')
  })

  // DELIBERATELY NOT TESTED: "a quota rejection never opens the permissions modal".
  //
  // There was a fourth test here asserting exactly that, and it was a lie twice
  // over. It passed `skipPermissionsModal: true`, and `handle403` returns on that
  // flag BEFORE it ever reads the payload — so the assertion held for a reason
  // that had nothing to do with the quota code. Worse, the claim itself is false:
  // there is no QUOTA_EXCEEDED branch in `handle403`, so a quota 403 WOULD open
  // the modal.
  //
  // It is not a defect, because `QuotaExceededException::render()` returns 422 and
  // `handle403` only ever sees 403s. Adding a branch for a status the backend does
  // not emit would be dead code. Leaving the note instead of a green check that
  // proves nothing.
})
