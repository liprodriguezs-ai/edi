import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

interface INamed {
  id: string
  name: string
}

export interface IPackageStatus extends IEntity {
  name: string
  key?: string | null
  color?: string | null
}

export interface IPackage extends IEntity {
  unit_id: string
  package_status_id?: string | null
  recipient_user_id?: string | null
  recipient_name?: string | null
  package_carrier_id?: string | null
  carrier_other?: string | null
  package_type_id?: string | null
  tracking_number?: string | null
  // Note: description is inherited from IEntity as `string | undefined`; do NOT redeclare as `string | null`
  storage_location?: string | null
  received_at?: string | null
  received_by_user_id?: string | null
  notified_at?: string | null
  reminded_at?: string | null
  picked_up_at?: string | null
  picked_up_by_user_id?: string | null
  pickup_proxy_name?: string | null
  pickup_proxy_relationship?: string | null
  incident_reason?: string | null
  // Relations
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  status?: IPackageStatus | null
  carrier?: INamed | null
  type?: INamed | null
  recipient_user?: { id: string; name?: string } | null
  received_by_user?: { id: string; name?: string } | null
  picked_up_by_user?: { id: string; name?: string } | null
}

/**
 * my-packages endpoint returns IPackage with pickup_code exposed (makeVisible).
 * pickup_code is hidden on all other endpoints.
 */
export interface IMyPackage extends IPackage {
  pickup_code?: string | null
}

/** Aging bucket entry from GET /package/report/aging */
export interface IAgingPackageEntry {
  id: string
  unit_full_identifier: string
  carrier?: string | null
  received_at?: string | null
  storage_location?: string | null
  days_pending: number
}

export interface IAgingBucket {
  count: number
  packages: IAgingPackageEntry[]
}

export interface IAgingReport {
  generated_at: string
  total_pending: number
  buckets: {
    d0_2: IAgingBucket
    d3_7: IAgingBucket
    d7_plus: IAgingBucket
  }
}

export interface IPendingCount {
  total: number
  d7_plus: number
}

/**
 * Reduced package shape returned by POST /package/verify-code.
 * The custom resource uses snake_case relation keys (package_carrier/package_status).
 * The Orion relation keys (carrier/status) are also accepted because the pickup modal
 * reuses this shape when launched from the list with a full IPackage (initialPackage).
 */
export interface IVerifyCodePackage {
  id: string
  description?: string | null
  storage_location?: string | null
  received_at?: string | null
  tracking_number?: string | null
  carrier_other?: string | null
  package_carrier?: INamed | null
  package_status?: INamed | null
  carrier?: INamed | null
  status?: INamed | null
}

/** verify-code response: all ready packages of the unit — pickup_code is never included. */
export interface IVerifyCodeResult {
  unit: { id: string; number?: string | null; full_identifier?: string } | null
  packages: IVerifyCodePackage[]
}

// ── Package settings (admin policies) ────────────────────────────────────────

export type PackageSettings = Record<string, unknown>

/**
 * Lightweight pickup-policy response from GET /package/pickup-policy.
 * Exposed separately from /config/package-settings so guards (update.package)
 * can read these two flags without needing read.configuration.
 */
export interface IPickupPolicy {
  requires_pickup_signature: boolean
  requires_pickup_evidence: boolean
  /** When true the guard MUST scan or enter the resident's code before registering the handover. */
  require_pickup_code: boolean
  /** Optional liability disclaimer shown to the resident at pickup time. */
  liability_disclaimer?: string | null
}

// ── Forms ─────────────────────────────────────────────────────────────────────

export const packageFormSchema = z.object({
  unit_id: z.string().min(1, 'La unidad es requerida'),
  package_carrier_id: z.string().optional(),
  carrier_other: z.string().max(100).optional(),
  package_type_id: z.string().optional(),
  recipient_name: z.string().max(150).optional(),
  recipient_user_id: z.string().optional(),
  tracking_number: z.string().max(100).optional(),
  description: z.string().max(500).optional(),
  storage_location: z.string().max(100).optional(),
})

export type PackageFormValues = z.infer<typeof packageFormSchema>

const packageBatchItemSchema = z.object({
  unit_id: z.string().min(1, 'La unidad es requerida'),
  package_carrier_id: z.string().optional(),
  carrier_other: z.string().max(100).optional(),
  package_type_id: z.string().optional(),
  recipient_name: z.string().max(150).optional(),
  tracking_number: z.string().max(100).optional(),
  description: z.string().max(500).optional(),
  storage_location: z.string().max(100).optional(),
})

export const packageBatchSchema = z.object({
  packages: z
    .array(packageBatchItemSchema)
    .min(1, 'Agrega al menos un paquete')
    .max(50, 'Máximo 50 paquetes por lote'),
})

export type PackageBatchValues = z.infer<typeof packageBatchSchema>
export type PackageBatchItem = z.infer<typeof packageBatchItemSchema>

export const packagePickupSchema = z.object({
  package_ids: z
    .array(z.string().uuid())
    .min(1, 'Selecciona al menos un paquete')
    .max(20, 'Máximo 20 paquetes por entrega'),
  pickup_proxy_name: z.string().max(150).optional(),
  pickup_proxy_relationship: z.string().max(100).optional(),
})

export type PackagePickupValues = z.infer<typeof packagePickupSchema>
