import type { ITicket } from '@/modules/ticket/schema'
import type { Include, Search, Sort } from '@/shared/interfaces/list.types'

export const TICKET_KANBAN_PAGE_SIZE = 20
export const TICKET_KANBAN_SCROLL_THRESHOLD = 120

export const TICKET_KANBAN_SORT: Sort[] = [
  { field: 'created_at', direction: 'desc' },
]

export const TICKET_KANBAN_INCLUDES: Include[] = [
  { relation: 'ticket_status' },
  { relation: 'ticket_importance' },
  { relation: 'user' },
  { relation: 'client' },
  { relation: 'client.user' },
  { relation: 'assignments' },
  { relation: 'assignments.user' },
]

export function buildTicketKanbanSearch(searchValue: string | null): Search | null {
  if (!searchValue) {
    return null
  }

  return {
    value: searchValue,
    case_sensitive: false,
  }
}

export function mergeUniqueTickets(currentTickets: ITicket[], incomingTickets: ITicket[]): ITicket[] {
  const ticketsById = new Map(currentTickets.map((ticket) => [ticket.id, ticket]))

  for (const ticket of incomingTickets) {
    ticketsById.set(ticket.id, ticket)
  }

  return Array.from(ticketsById.values())
}
