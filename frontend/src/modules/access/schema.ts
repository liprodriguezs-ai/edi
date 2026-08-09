import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

interface INamed {
  id: string
  name: string
}

/** Estados de una visita (catálogo central visitor_status). El QR solo se puede cancelar en PENDING. */
export const VISITOR_STATUS = {
  PENDING: '3f89e1ae-ad90-4b4c-9ed8-b434da6512d7',
  USED: 'be5a6191-8355-42b2-b5ee-015a93440dea',
  CANCELLED: '1bbfd0c8-4316-46e2-b7ca-22b63881e16f',
  EXPIRED: '849bd1cf-144c-4f14-8560-6e59e06742d4',
  PENDING_APPROVAL: '0f894a5e-3aac-4379-bf42-7e4aba0b6e3b',
  APPROVED: '4b78d5c6-619a-402c-9555-b010dec02542',
  REJECTED: '1c63562d-ebd6-4910-8529-34956e25ac0b',
} as const

/**
 * Tipos de QR de visita (catálogo central qr_type). El backend usa estos UUID fijos
 * para resolver la vigencia del QR:
 * - SINGLE_ENTRY: un único uso, sin fecha de vigencia.
 * - TIME_BASED: válido hasta una fecha/hora (qr_expires_at opcional).
 * - EVENT: ventana de tiempo para múltiples ingresos (qr_expires_at obligatorio).
 */
export const QR_TYPE = {
  SINGLE_ENTRY: '6a0297c4-a5b6-4f38-b4bb-4407ac4555d4',
  TIME_BASED: '4e08d416-3344-445d-be61-7161800f8e94',
  EVENT: '994ae3cd-b71c-42ce-870a-232d5346facd',
} as const

/** Respuesta de GET /access/my-qr (QR personal rotativo). El countdown es autoritativo del servidor. */
export interface IMyQr {
  token: string
  expires_at: string
  seconds_remaining: number
}

export interface IVisitor extends IEntity {
  unit_id: string
  name: string
  visitor_motive_id?: string | null
  arrives_by_vehicle?: boolean
  plate?: string | null
  vehicle_model?: string | null
  qr_token?: string | null
  qr_expires_at?: string | null
  visitor_status_id?: string | null
  notes?: string | null
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  visitor_motive?: INamed | null
  visitor_status?: INamed | null
  created_by_user?: { id: string; name?: string } | null
  qr_type?: INamed | null
  access_logs?: IAccessLog[] | null
}

export interface IAccessLog extends IEntity {
  visitor_id?: string | null
  unit_id?: string | null
  visitor_name?: string | null
  entered_at?: string | null
  exited_at?: string | null
  plate?: string | null
  denied_at?: string | null
  denied_reason?: string | null
  correction?: string | null
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  visitor?: IVisitor | null
  access_entry_type?: INamed | null
  access_verification?: INamed | null
  guard_user?: { id: string; name?: string } | null
  visitor_motive?: INamed | null
}

/** Resultado de escanear un QR en la caseta (POST /access/scan-qr). */
export interface IScanResult {
  valid: boolean
  reason?: string
  visitor?: IVisitor | null
  access_log?: IAccessLog | null
  blacklist_match?: boolean
}

export interface IBlacklistEntry extends IEntity {
  blacklist_type_id: string
  value: string
  reason?: string | null
  is_active?: boolean
  blacklist_type?: INamed | null
}

export const manualEntrySchema = z.object({
  unit_id: z.string().min(1, 'La unidad es requerida'),
  visitor_name: z.string().min(1, 'El nombre es requerido').max(255),
  access_verification_id: z.string().min(1, 'La verificación es requerida'),
  visitor_motive_id: z.string().optional(),
  plate: z.string().max(20).optional(),
  id_document_type: z.enum(['ine', 'passport', 'driver_license', 'other']).optional(),
  id_document_number: z.string().max(100).optional(),
  authorized_by: z.string().max(255).optional(),
})

export type ManualEntryValues = z.infer<typeof manualEntrySchema>

/**
 * Solicitud de autorización del guardia (§14.4.4): visita SIN QR previo que
 * empuja una aprobación urgente a los residentes de la unidad (el residente
 * aprueba/rechaza desde su lista de visitas). Requiere unidad, nombre y motivo.
 */
export const entryRequestSchema = z.object({
  unit_id: z.string().min(1, 'La unidad es requerida'),
  name: z.string().min(1, 'El nombre es requerido').max(255),
  visitor_motive_id: z.string().min(1, 'El motivo es requerido'),
  plate: z.string().max(20).optional(),
  notes: z.string().max(1000).optional(),
  id_document_type: z.enum(['ine', 'passport', 'driver_license', 'other']).optional(),
  id_document_number: z.string().max(100).optional(),
})

export type EntryRequestValues = z.infer<typeof entryRequestSchema>

/**
 * Registro de visita (§14): el residente genera un QR para su unidad. El backend
 * (VisitorCreateRequest + regla CallerBelongsToUnit) exige unidad propia, nombre,
 * motivo y tipo de QR; la placa es obligatoria si llega en vehículo.
 */
export const visitorFormSchema = z
  .object({
    unit_id: z.string().min(1, 'La unidad es requerida'),
    name: z.string().min(1, 'El nombre es requerido').max(255),
    visitor_motive_id: z.string().min(1, 'El motivo es requerido'),
    qr_type_id: z.string().min(1, 'El tipo de QR es requerido'),
    qr_expires_at: z.string().optional(),
    arrives_by_vehicle: z.boolean(),
    plate: z.string().max(20).optional(),
    vehicle_model: z.string().max(100).optional(),
    id_document_type: z.enum(['ine', 'passport', 'driver_license', 'other']).optional(),
    id_document_number: z.string().max(100).optional(),
    notes: z.string().max(1000).optional(),
  })
  .superRefine((values, ctx) => {
    if (values.arrives_by_vehicle && !values.plate?.trim()) {
      ctx.addIssue({ code: 'custom', path: ['plate'], message: 'La placa es requerida si llega en vehículo' })
    }

    // El QR de evento exige una vigencia futura: es la ventana en la que el
    // visitante puede ingresar varias veces. Para tiempo (TIME_BASED) es opcional.
    if (values.qr_type_id === QR_TYPE.EVENT) {
      const raw = values.qr_expires_at?.trim()
      if (!raw) {
        ctx.addIssue({ code: 'custom', path: ['qr_expires_at'], message: 'La vigencia es requerida para un QR de evento' })
        return
      }
      const expiry = new Date(raw)
      if (Number.isNaN(expiry.getTime()) || expiry.getTime() <= Date.now()) {
        ctx.addIssue({ code: 'custom', path: ['qr_expires_at'], message: 'La vigencia debe ser una fecha y hora futura' })
      }
    }
  })

export type VisitorFormValues = z.infer<typeof visitorFormSchema>

export const blacklistFormSchema = z.object({
  blacklist_type_id: z.string().min(1, 'El tipo es requerido'),
  value: z.string().min(1, 'El valor es requerido').max(255),
  reason: z.string().max(500).optional(),
  is_active: z.boolean(),
})

export type BlacklistFormValues = z.infer<typeof blacklistFormSchema>
