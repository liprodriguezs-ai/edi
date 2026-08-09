import { ERoleUserSlug } from '@/shared/interfaces/Entity'

export interface SessionUser {
  id: string
  email: string
  name?: string
  profile_image_url?: string | null
  [key: string]: unknown
}

export interface SessionTenant {
  id: string
  name: string
  tenant_payment_type_id: string | null
  payment_type?: { id: string; name: string } | null
  currency?: string | null
  /** URL firmada del logo del organización (se muestra en el selector del menú). */
  logo_url?: string | null
}

export type SubscriptionStatus =
  | 'none'
  | 'active'
  | 'trial'
  | 'expired'
  | 'trialing'
  | 'past_due'
  | 'canceled'
  | 'incomplete'
  | 'incomplete_expired'
  | 'unpaid'
  | 'exempt'

export interface SubscriptionState {
  status: SubscriptionStatus
  is_blocked: boolean
  is_owner: boolean
  /** ISO timestamp cuando vence el período de prueba. Solo presente cuando status==='trial'. */
  trial_ends_at?: string | null
  /** ISO timestamp de vencimiento de la suscripción activa. */
  expires_at?: string | null
  /**
   * true cuando el organización tuvo alguna vez una entitlement de Stripe y su último
   * cargo falló — distingue "tu tarjeta falló" (bloqueado, status='expired') de
   * "nunca elegiste un plan" (status='none') o "tu prueba venció sin contratar"
   * (status='expired', payment_issue=false). Ver SubscriptionStateService (D8).
   */
  payment_issue?: boolean
  /**
   * true cuando la prueba en curso se cobra sola al terminar (prueba de Stripe: ya
   * hay plan y tarjeta detrás). false para una prueba LOCAL de cortesía, que no
   * tiene ninguna de las dos y sí exige contratar antes de que venza.
   * Solo presente cuando status==='trial'. Ver SubscriptionStateService.
   */
  trial_auto_charges?: boolean
  /**
   * Días calendario restantes hasta trial_ends_at, calculados por la API a partir
   * de la MISMA fecha que trial_ends_at (nunca una segunda fuente). Solo presente
   * cuando status==='trial'. El frontend NUNCA calcula este número — ver
   * CalendarDays::until() en el backend (spec: trial-countdown-utility).
   */
  trial_days_left?: number | null
}

export interface SessionProfile {
  user: SessionUser
  abilities: string[]
  roles: ERoleUserSlug[]
  company?: Record<string, unknown> | null
  country?: Record<string, unknown> | null
  tenant?: SessionTenant | null
  subscription?: SubscriptionState | null
}
