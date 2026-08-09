import type { IClient } from '@/modules/client/schema'
import type { IUser } from '@/modules/user/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

// === Constantes (UUIDs fijos — para lógica de negocio, NO para definir columnas UI) ===

export const ETicketStatus = {
  PENDING: '8f4c2e3a-7b91-4f6d-a9e2-6d9e4b1c7f52',
  ATTENDED: '4f2c8a7e-1b63-4d9e-8f21-6a9c5e3b7d10',
} as const

export type TicketStatusId = (typeof ETicketStatus)[keyof typeof ETicketStatus]

export const ETicketImportance = {
  TALL: '9f3b7a6e-2c4d-4e8a-b1f6-8c9a2e4d7b15',
  MEDIUM: '3a8d5c21-7f4b-4a6e-9c12-6e2b9f8a0d44',
  LOW: 'c72e4f9b-1a63-4b0f-8e5d-5f9a6c3b2e81',
  UNASSIGNED: '3b9a7d21-5e6f-4c8b-9f12-0a6e8c4d2b71',
} as const

export type TicketImportanceId = (typeof ETicketImportance)[keyof typeof ETicketImportance]

// === Interfaces ===

export interface ITicketStatus extends IEntity {
  name: string
  description?: string
  color: string
  order: number
}

export interface ITicketImportance extends IEntity {
  name: string
  description?: string
  color: string
}

export interface ITicketAssignment extends IEntity {
  ticket_id: string
  user_id: string
  user?: IUser
}

export interface ITicketMessage extends IEntity {
  ticket_id: string
  user_id: string
  message?: string
  user?: IUser
}

export interface ITicket extends IEntity {
  folio: string
  number: number
  prefix: string
  title: string
  description?: string
  is_open: boolean
  user_id: string
  ticket_status_id: string
  ticket_importance_id: string
  client_id?: string
  tenant_id?: string
  tenant_name?: string
  company_name?: string
  user?: IUser
  client?: IClient
  ticket_status?: ITicketStatus
  ticket_importance?: ITicketImportance
  assignments?: ITicketAssignment[]
  messages?: ITicketMessage[]
}

// === Zod Schemas ===

export const ticketFormSchema = z.object({
  title: z.string().min(5, 'El asunto debe tener al menos 5 caracteres').max(100, 'Máximo 100 caracteres'),
  description: z.string().min(20, 'La descripción debe tener al menos 20 caracteres').max(2000, 'Máximo 2000 caracteres'),
  ticket_importance_id: z.string().optional(),
  ticket_status_id: z.string().optional(),
})

export type TicketFormValues = z.infer<typeof ticketFormSchema>

export const ticketMessageFormSchema = z.object({
  message: z.string().min(1, 'El mensaje es requerido'),
  ticket_id: z.string(),
})

export type TicketMessageFormValues = z.infer<typeof ticketMessageFormSchema>
