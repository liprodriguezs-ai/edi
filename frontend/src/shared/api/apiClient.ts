import { BUSINESS_CODES } from '@/shared/api/businessCodes'
import { refreshTokenRequest } from '@/shared/auth/authService'
import { sessionStore } from '@/shared/auth/sessionStore'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { usePermissionsModal } from '@/shared/hooks/usePermissionsModal'
import { toast } from '@/shared/hooks/useToast'
import { useTenantContextStore } from '@/shared/store/tenantContextStore'
import axios, { type AxiosRequestConfig, type AxiosRequestHeaders } from 'axios'

declare module 'axios' {
  export interface AxiosRequestConfig {
    /**
     * Cuando es true, el interceptor 403 NO abre el PermissionsModal global.
     * Para endpoints donde un 403 es REGLA DE NEGOCIO (no falta de permiso),
     * ej. /billing/checkout → not_account_owner. El llamador maneja el error.
     */
    skipPermissionsModal?: boolean
  }
}

export type ApiRequestHeaders = AxiosRequestHeaders & {
  'X-Tenant-Id'?: string
}

export interface ApiErrorPayload {
  message?: string
  errors?: Record<string, string[]>
  code?: string
  unit_limit?: number
  unit_count?: number
  /** El backend lo manda en un 422 cuando la operación necesita reenvío con double_confirm=true. */
  requires_confirmation?: boolean
}

export class ApiError extends Error {
  status?: number
  payload?: ApiErrorPayload

  constructor(message: string, status?: number, payload?: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.payload = payload as ApiErrorPayload | undefined
  }
}

/** True when the error is an ApiError 404 (record missing or outside the tenant). */
export function isNotFoundError(error: unknown): error is ApiError {
  return error instanceof ApiError && error.status === 404
}

/**
 * Builds a user-facing error message from API validation/error response.
 * Uses message and, if present, flattens errors object into a short description.
 */
export function formatApiErrorMessage(apiError: ApiError): { title: string; description?: string } {
  const payload = apiError.payload
  const title = payload?.message ?? apiError.message ?? 'Error al guardar'
  const errors = payload?.errors
  if (errors && typeof errors === 'object') {
    const lines = Object.values(errors).flat().filter(Boolean) as string[]
    if (lines.length > 0) {
      return { title, description: lines.slice(0, 5).join(' ') }
    }
  }
  return { title }
}

export const apiClient = axios.create({
  baseURL: APP_CONFIG.apiBaseUrl,
  timeout: 0,
  withCredentials: true,
})

apiClient.interceptors.request.use((config) => {
  const token = sessionStore.getState().token

  if (token) {
    config.headers = {
      ...config.headers,
      Authorization: `Bearer ${token}`,
    } as ApiRequestHeaders
  }

  // Contexto tenant (X-Tenant-Id) para super admin y abogado al atender tickets en un tenant concreto
  const tenantId = useTenantContextStore.getState().tenantId
  if (tenantId) {
    config.headers = {
      ...config.headers,
      'X-Tenant-Id': tenantId,
    } as ApiRequestHeaders
  }

  return config
})

let refreshPromise: Promise<{ access_token: string } | null> | null = null

function logoutAndReject(error: unknown, status: number, message: string): Promise<never> {
  refreshPromise = null
  sessionStore.getState().logout({ propagate: false })
  if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
    window.location.href = '/login'
  }
  const payload =
    error && typeof error === 'object' && 'response' in error
      ? (error as { response?: { data?: unknown } }).response?.data
      : undefined
  return Promise.reject(new ApiError(message, status, payload))
}

// Dedup de refresh: una sola petición en vuelo. RETORNA la promise para que cada
// llamador la awaitee por referencia LOCAL — leer la var compartida después del
// `.finally` (que la pone en null al resolver) provocaba `await null` → logout
// espurio bajo navegación rápida (muchos 401 concurrentes).
function ensureRefreshPromise(): Promise<{ access_token: string } | null> {
  if (!refreshPromise) {
    refreshPromise = refreshTokenRequest().finally(() => {
      refreshPromise = null
    })
  }
  return refreshPromise
}

function getErrorMessage(error: { response?: { data?: unknown } }, fallback: string): string {
  return (error.response?.data as { message?: string })?.message ?? fallback
}

function reject401(error: unknown, status: number, message: string): Promise<never> {
  const payload =
    error && typeof error === 'object' && 'response' in error
      ? (error as { response?: { data?: unknown } }).response?.data
      : undefined
  return Promise.reject(new ApiError(message, status, payload))
}

function hadAuthorizationHeader(config: AxiosRequestConfig): boolean {
  const headers = config.headers as Record<string, unknown> | undefined
  return Boolean(headers?.Authorization)
}

async function handle401Retry(
  error: { response?: { status?: number; data?: unknown } },
  originalConfig: AxiosRequestConfig & { _retry?: boolean }
): Promise<unknown> {
  const status = error.response?.status ?? 401
  const message = getErrorMessage(error, 'Unauthorized')

  if (originalConfig._retry) {
    return logoutAndReject(error, status, message)
  }

  if (!hadAuthorizationHeader(originalConfig)) {
    return reject401(error, status, message)
  }

  try {
    const tokens = await ensureRefreshPromise()
    if (tokens) {
      sessionStore.getState().setTokens({
        token: tokens.access_token,
      })
      originalConfig._retry = true
      return apiClient.request(originalConfig)
    }
  } catch {
    return logoutAndReject(error, status, getErrorMessage(error, 'Session expired'))
  }

  return logoutAndReject(error, status, message)
}

/**
 * Flag de sesión para silenciar 403s de suscripción repetidos mientras ocurre
 * la redirección. Se resetea solo al hacer window.location.href (full-reload).
 */
let subscriptionInactiveHandled = false

/**
 * Maneja el caso en que el backend devuelve 403 con code: 'SUBSCRIPTION_INACTIVE'.
 * Muestra un toast informativo UNA sola vez y redirige al paywall /select-plan.
 * Evita loops: si ya estamos en /select-plan, no redirige de nuevo.
 */
function handleSubscriptionInactive(): void {
  if (subscriptionInactiveHandled) return
  subscriptionInactiveHandled = true

  toast.error('Tu suscripción venció. Renueva para continuar.')

  if (typeof window !== 'undefined' && !window.location.pathname.startsWith('/select-plan')) {
    window.location.href = '/select-plan'
  }
}

export function handle403(error: {
  response?: { data?: unknown }
  config?: { method?: string; url?: string; skipPermissionsModal?: boolean }
}): void {
  // Endpoints donde el 403 es regla de negocio (no falta de permiso) optan por
  // saltarse el modal global y manejar el error inline (ej. /billing/checkout).
  if (error.config?.skipPermissionsModal) {
    return
  }

  const errorData = error.response?.data as { message?: string; code?: string } | undefined

  // Suscripción inactiva: redirige al paywall sin abrir el modal de permisos.
  // Los 403 repetidos del polling (menu, notificaciones, etc.) quedan silenciados
  // porque handleSubscriptionInactive es no-op tras la primera llamada.
  if (errorData?.code === BUSINESS_CODES.SUBSCRIPTION_INACTIVE) {
    handleSubscriptionInactive()
    return
  }

  // 403 de REGLA DE NEGOCIO por morosidad (delinquency-restrictions): no es
  // falta de permiso, es un adeudo vencido. El caller (wizard/modal/form) ya
  // muestra el error correctamente enmarcado vía su propio toast/nota inline
  // (formatApiErrorMessage lee `message` del backend) — abrir el modal global
  // de permisos encima sería un segundo surface de error, mal enmarcado
  // ("no tienes permisos") para lo que en realidad es un problema de cobranza.
  if (errorData?.code === BUSINESS_CODES.DELINQUENT_RESTRICTED) {
    return
  }

  const method = error.config?.method?.toUpperCase() ?? 'REQUEST'
  const url = error.config?.url ?? ''
  const message = errorData?.message ?? 'No tienes permisos para realizar esta acción'
  const actionDescription = `${method} ${url}`
  usePermissionsModal.getState().openModal(message, actionDescription)
}

function rejectWithApiError(error: {
  response?: { data?: ApiErrorPayload; status?: number }
  message?: string
}): Promise<never> {
  const status = error.response?.status
  const message =
    error.response?.data?.message ?? error.message ?? 'Unexpected error, please try again'
  return Promise.reject(new ApiError(message, status, error.response?.data))
}

/**
 * Cuando el request pidió `responseType: 'blob'` (ej. exportar reportes), axios
 * entrega el cuerpo de error 403/422 como un Blob en vez del JSON parseado —
 * `error.response.data.message`/`.code` quedan `undefined` y el modal de permisos,
 * el paywall (SUBSCRIPTION_INACTIVE) y los mensajes de validación se pierden.
 * Lee el Blob como texto y lo reemplaza por el objeto JSON parseado ANTES de que
 * el resto del interceptor lo consuma, para que tenga la misma forma
 * `{ message, code, errors? }` que un error normal.
 */
async function normalizeBlobErrorResponse(error: {
  response?: { data?: unknown; status?: number }
}): Promise<void> {
  const data = error.response?.data
  if (!(data instanceof Blob)) return

  try {
    const text = await data.text()
    error.response!.data = JSON.parse(text) as ApiErrorPayload
  } catch {
    // El blob no era JSON parseable (ej. error de red/HTML) — se deja como está;
    // los fallbacks de mensaje ("Unexpected error...") siguen aplicando.
  }
}

apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    await normalizeBlobErrorResponse(error)

    const status = error.response?.status
    if (status === 401) {
      const originalConfig = error.config as AxiosRequestConfig & { _retry?: boolean }
      return handle401Retry(error, originalConfig)
    }
    if (status === 403) {
      handle403(error)
    }
    return rejectWithApiError(error)
  }
)
