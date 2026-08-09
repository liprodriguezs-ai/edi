/**
 * Active display currency for the session's tenant.
 * Module-level state with ZERO imports (no dependency cycles) — read by the
 * pure money helpers (money.ts, currencyHelper.ts), written by sessionStore
 * on login/refresh/restore/clear and by the currency-change flow in
 * OperationalConfigSection.
 */
let activeCurrency = 'MXN'

/** Sets the active currency; normalizes null/undefined/empty to the MXN default. */
export function setActiveCurrency(currency: string | null | undefined): void {
  activeCurrency = currency && currency.trim() !== '' ? currency : 'MXN'
}

/** Reads the active currency for the current tenant session. */
export function getActiveCurrency(): string {
  return activeCurrency
}
