import type { ITicket } from '@/modules/ticket/schema'

interface TicketCompanyNameFallbacks {
  contextCompanyName?: string | null
  sessionCompanyName?: string | null
  sessionTenantName?: string | null
}

export function getTicketCompanyName(
  ticket: ITicket,
  fallbacks?: TicketCompanyNameFallbacks,
): string | undefined {
  const companyName = ticket.company_name?.trim()
  if (companyName) {
    return companyName
  }

  const sessionCompanyName = fallbacks?.sessionCompanyName?.trim()
  if (sessionCompanyName) {
    return sessionCompanyName
  }

  const contextCompanyName = fallbacks?.contextCompanyName?.trim()
  if (contextCompanyName) {
    return contextCompanyName
  }

  const tenantName = ticket.tenant_name?.trim()
  if (tenantName) {
    return tenantName
  }

  const sessionTenantName = fallbacks?.sessionTenantName?.trim()
  if (sessionTenantName) {
    return sessionTenantName
  }

  return undefined
}
