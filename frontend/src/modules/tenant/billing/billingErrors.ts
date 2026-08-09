import { ApiError } from '@/shared/api/apiClient'

/**
 * Mapea los códigos de negocio del backend de facturación a mensajes claros en
 * español. Estos 403/422 NO son falta de permiso: son reglas de negocio que se
 * muestran inline (por eso los endpoints usan `skipPermissionsModal`).
 */
const BILLING_ERROR_MESSAGES: Record<string, string> = {
  contact_sales: 'Este volumen necesita un plan a la medida. Contacta al equipo de ventas para continuar.',
  not_account_owner: 'Solo el propietario de la cuenta del condominio puede realizar esta acción.',
  already_subscribed: 'El condominio ya tiene una suscripción activa para este paquete.',
}

/**
 * Resuelve un mensaje legible para el usuario a partir de un error de la API.
 * Prioriza el `message` del backend (suele venir contextualizado, ej. owner
 * ambiguo), luego el mapa por código, y por último un texto genérico.
 */
export function resolveBillingError(error: unknown, fallback = 'No se pudo completar la operación. Inténtalo de nuevo.'): string {
  if (error instanceof ApiError) {
    const code = error.payload?.code
    if (error.payload?.message) {
      return error.payload.message
    }
    if (code && BILLING_ERROR_MESSAGES[code]) {
      return BILLING_ERROR_MESSAGES[code]
    }
    if (error.message) {
      return error.message
    }
  }
  return fallback
}
