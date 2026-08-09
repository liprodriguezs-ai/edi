import type { IUser } from '@/modules/user/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/** Stub for legacy AuditForm (audit is read-only in UI). */
export const auditFormSchema = z.object({})

/** Parsed audit payload: old/new values as objects keyed by attribute name */
export type AuditValues = Record<string, unknown>

/** Normalize API value (string or object) to AuditValues for safe diff rendering */
export function parseAuditValues(value: unknown): AuditValues {
  if (value == null) return {}
  if (typeof value === 'object' && !Array.isArray(value)) return value as AuditValues
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value) as unknown
      return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
        ? (parsed as AuditValues)
        : {}
    } catch {
      return {}
    }
  }
  return {}
}

export type AuditEvent = 'created' | 'updated' | 'deleted' | 'restored'

export interface IAudit extends IEntity {
  event: AuditEvent
  auditable_type: string
  auditable_id: string
  user_type?: string | null
  user?: IUser | null
  user_id?: string | null
  /** Normalized in client: always object for diff display */
  old_values: AuditValues
  /** Normalized in client: always object for diff display */
  new_values: AuditValues
  url?: string | null
  ip_address?: string | null
  user_agent?: string | null
  tags?: string | null
}

/** Raw audit as returned by API (old_values/new_values may be string) */
export interface IAuditRaw extends Omit<IAudit, 'old_values' | 'new_values'> {
  old_values?: string | AuditValues | null
  new_values?: string | AuditValues | null
}

/** Form type for API compatibility (audit is read-only in UI) */
export type AuditFormValues = Record<string, unknown>
