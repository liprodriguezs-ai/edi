import type { ITicket, ITicketMessage, TicketFormValues } from '@/modules/ticket/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TicketResource = createCrudApi<ITicket, Partial<TicketFormValues>>({
  basePath: '/ticket',
})

export async function moveTicketStatus(
  ticketId: string,
  ticketStatusId: string,
  options?: { tenantId?: string },
): Promise<ITicket> {
  const requestConfig = options?.tenantId
    ? { headers: { 'X-Tenant-Id': options.tenantId } }
    : undefined

  const { data } = await apiClient.post('/ticket/move_status', {
    ticket_id: ticketId,
    ticket_status_id: ticketStatusId,
  }, requestConfig)
  return data.data ?? data
}

/** Actualiza la importancia de un ticket. */
export async function updateTicketImportance(
  ticketId: string,
  ticketImportanceId: string,
): Promise<ITicket> {
  const { data } = await apiClient.put(`/ticket/${ticketId}`, {
    ticket_importance_id: ticketImportanceId,
  })
  return data.data ?? data
}

/** Asigna al usuario actual a un ticket. */
export async function assignTicketToMe(
  ticketId: string,
  userId: string,
): Promise<void> {
  await apiClient.post('/ticket_assignment', {
    ticket_id: ticketId,
    user_id: userId,
  })
}

/** Envía un mensaje con archivos adjuntos opcionales. */
export async function sendTicketMessage(
  ticketId: string,
  message: string,
  files?: File[],
): Promise<ITicketMessage> {
  if (files && files.length > 0) {
    const formData = new FormData()
    formData.append('ticket_id', ticketId)
    formData.append('message', message)
    files.forEach((file, index) => {
      formData.append(`attachments_${index}`, file)
    })
    formData.append('attachments_length', String(files.length))
    const { data } = await apiClient.post('/ticket_message', formData)
    return data.data ?? data
  }

  const { data } = await apiClient.post('/ticket_message', {
    ticket_id: ticketId,
    message,
  })
  return data.data ?? data
}
