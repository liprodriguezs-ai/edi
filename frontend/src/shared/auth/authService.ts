import { apiClient } from '@/shared/api/apiClient'
import type {
  SessionProfile,
  SessionTenant,
  SessionUser,
  SubscriptionState,
} from '@/shared/auth/Session'
import { APP_CONFIG } from '@/shared/config/appConfig'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import axios from 'axios'

export interface LoginRequest {
  email: string
  password: string
}

export interface RegisterRequest {
  name: string
  /**
   * Nombre del organización que se está dando de alta. Va tal cual a `tenant.name`,
   * que es la etiqueta que la app muestra como el organización en todos lados.
   */
  organization_name: string
  email: string
  password: string
  password_confirmation: string
  tenant_id?: string | null
  birthdate?: string | null
  /**
   * UUID v4 generado por el cliente para el evento CompleteRegistration. El
   * servidor lo reusa al disparar su propio CompleteRegistration de respaldo
   * (CAPI + GA4 MP), deduplicando contra el evento que ya disparó el navegador.
   */
  event_id?: string
}

interface LoginRawResponse {
  data: {
    user: SessionUser
    abilities?: string[]
    roles?: string[]
    access_token?: string
  }
  [key: string]: unknown
}

export interface RefreshResponse {
  access_token: string
}

const AUTH_LOGIN_PATH = '/auth/login'
const AUTH_REGISTER_PATH = '/auth/register'
const AUTH_ME_PATH = '/auth/me'
const AUTH_REFRESH_PATH = '/auth/refresh'
const AUTH_FORGOT_PASSWORD_PATH = '/auth/forgot-password'
const AUTH_VERIFY_RESET_TOKEN_PATH = '/auth/verifyResetToken'
const AUTH_RESET_PASSWORD_PATH = '/auth/reset-password'
const AUTH_VERIFY_INVITATION_TOKEN_PATH = '/auth/verify-invitation-token'
const AUTH_ACCEPT_INVITATION_PATH = '/auth/accept-invitation'
const AUTH_ACCEPT_INVITATION_CONFIRM_PATH = '/auth/accept-invitation/confirm'

const refreshClient = axios.create({
  baseURL: APP_CONFIG.apiBaseUrl,
  timeout: 0,
  withCredentials: true,
})

const toSessionProfile = (data: LoginRawResponse): SessionProfile => {
  const rawUser = data?.user as (SessionUser & { full_name?: string | null }) | undefined
  if (!rawUser) {
    throw new Error('Missing user data in session response')
  }

  const fullName = typeof rawUser.full_name === 'string' ? rawUser.full_name : null
  const resolvedName = fullName && fullName.trim().length > 0 ? fullName.trim() : rawUser.name

  const user: SessionUser = {
    ...rawUser,
    email: rawUser.email,
    name: resolvedName,
  }

  const raw = data as Record<string, unknown>
  const tenant = raw.tenant as SessionTenant | null | undefined
  const subscription = (raw.subscription as SubscriptionState | null) ?? null

  return {
    user,
    abilities: Array.isArray(data.abilities) ? (data.abilities as string[]) : [],
    roles: Array.isArray(data.roles) ? (data.roles as ERoleUserSlug[]) : [],
    company: (raw.company as Record<string, unknown> | null) ?? null,
    country: (raw.country as Record<string, unknown> | null) ?? null,
    tenant: tenant ?? null,
    subscription,
  }
}

export interface LoginResponse {
  profile: SessionProfile
  token: string
}

export const loginRequest = async (credentials: LoginRequest): Promise<LoginResponse> => {
  const response = await apiClient.post<LoginRawResponse>(AUTH_LOGIN_PATH, credentials)
  const data = response.data?.data
  const profile = toSessionProfile(data as unknown as LoginRawResponse)

  const token =
    typeof data.access_token === 'string' && data.access_token.length > 0 ? data.access_token : null

  if (!token) {
    throw new Error('Missing access token in login response')
  }

  return { profile, token }
}

export const registerRequest = async (payload: RegisterRequest): Promise<LoginResponse> => {
  const response = await apiClient.post<LoginRawResponse>(AUTH_REGISTER_PATH, payload)
  const data = response.data?.data
  const profile = toSessionProfile(data as unknown as LoginRawResponse)

  const token =
    typeof data?.access_token === 'string' && data.access_token.length > 0
      ? data.access_token
      : null

  if (!token) {
    throw new Error('Missing access token in register response')
  }

  return { profile, token }
}

export const refreshTokenRequest = async (): Promise<RefreshResponse> => {
  const response = await refreshClient.post<{ data: { access_token: string } }>(
    AUTH_REFRESH_PATH,
    {}
  )
  const data = response.data?.data
  if (!data || typeof data.access_token !== 'string') {
    throw new Error('Invalid refresh response')
  }
  return { access_token: data.access_token }
}

export const fetchSessionProfile = async (): Promise<SessionProfile> => {
  const response = await apiClient.get<LoginRawResponse>(AUTH_ME_PATH)
  const data = response.data?.data
  return toSessionProfile(data as unknown as LoginRawResponse)
}

export const forgotPasswordRequest = async (email: string): Promise<{ message: string }> => {
  const response = await apiClient.post<{ message: string }>(AUTH_FORGOT_PASSWORD_PATH, { email })
  return response.data
}

export interface VerifyResetTokenResponse {
  response: boolean
  message: string
}

export const verifyResetTokenRequest = async (
  token: string,
  email: string
): Promise<VerifyResetTokenResponse> => {
  const response = await apiClient.post<VerifyResetTokenResponse>(AUTH_VERIFY_RESET_TOKEN_PATH, {
    token,
    email,
  })
  return response.data
}

export const resetPasswordRequest = async (params: {
  token: string
  email: string
  password: string
  password_confirmation: string
}): Promise<{ message: string }> => {
  const response = await apiClient.post<{ message: string }>(AUTH_RESET_PASSWORD_PATH, params)
  return response.data
}

/**
 * Cambia la contraseña del usuario autenticado (POST /updatePassword). El backend
 * valida la contraseña actual y revoca todas las sesiones, así que tras el cambio
 * hay que reautenticarse.
 */
export const changePasswordRequest = async (params: {
  password: string
  new_password: string
}): Promise<{ message: string }> => {
  const response = await apiClient.post<{ message: string }>('/updatePassword', params)
  return response.data
}

export interface VerifyInvitationTokenResponse {
  valid: boolean
  email?: string
  message?: string
  /** True when the email already has a usable account: the person logs in to join. */
  account_exists?: boolean
  /** Resident-invitation context, present only for unit invitations. */
  organization_name?: string
  unit_label?: string
  relationship_name?: string
  inviter_name?: string
  expires_at?: string
}

export const verifyInvitationTokenRequest = async (
  token: string
): Promise<VerifyInvitationTokenResponse> => {
  const response = await apiClient.post<VerifyInvitationTokenResponse>(
    AUTH_VERIFY_INVITATION_TOKEN_PATH,
    { token }
  )
  return response.data
}

export const acceptInvitationRequest = async (params: {
  token: string
  password: string
  password_confirmation: string
}): Promise<{ message: string }> => {
  const response = await apiClient.post<{ message: string }>(AUTH_ACCEPT_INVITATION_PATH, params)
  return response.data
}

/**
 * Accept a resident invitation with an EXISTING account: the user is already
 * authenticated as the invited email; this links them to the unit (no new password).
 */
export const acceptInvitationConfirmRequest = async (params: {
  token: string
}): Promise<{ message: string }> => {
  const response = await apiClient.post<{ message: string }>(
    AUTH_ACCEPT_INVITATION_CONFIRM_PATH,
    params
  )
  return response.data
}
