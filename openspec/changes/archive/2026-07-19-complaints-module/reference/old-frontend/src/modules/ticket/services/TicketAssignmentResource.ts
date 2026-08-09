import type { ITicketAssignment } from '@/modules/ticket/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

interface TicketAssignmentPayload extends Record<string, unknown> {
  ticket_id: string
  user_id: string
  [key: string]: unknown
}

export const TicketAssignmentResource = createCrudApi<ITicketAssignment, TicketAssignmentPayload>({
  basePath: '/ticket_assignment',
})
