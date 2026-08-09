import { ApiError, apiClient } from '@/shared/api/apiClient'

export interface GeneralConfig {
  /**
   * Nombre del condominio. Vive en `tenant.name` (no en la company) y es la
   * etiqueta que la app muestra como clúster en el header, el selector y la
   * home del residente.
   */
  cluster_name?: string
  name?: string
  legal_name?: string
  rfc?: string | null
  fiscal_address?: string | null
  phone?: string | null
  email?: string | null
  website?: string | null
  logo_url?: string | null
}

/** Respuesta de `POST /config/branding/logo`: la URL firmada del logo recién subido. */
export interface LogoUploadResult {
  data?: { logo_url?: string | null }
}

export interface OperationalConfig {
  timezone?: string | null
  currency?: string | null
  type?: string | null
}

export type CollectionSettings = Record<string, unknown>

export interface ResidentBankData {
  visible: boolean
  data: {
    name?: string
    bank?: string | null
    account_number?: string | null
    clabe?: string | null
  } | null
}

export interface NotificationTemplate {
  slug: string
  title?: string | null
  body: string
  is_active?: boolean
}

export interface LegalDocument {
  id: string
  type: string
  title?: string | null
  version?: number | null
  is_current?: boolean
  is_visible_to_residents?: boolean
  published_at?: string | null
  file_url?: string | null
}

// ── Notification config ──────────────────────────────────────────────────────

export interface IBoardRoleOption {
  id: string
  name: string
}

export interface INotificationEventConfig {
  key: string
  name: string
  description?: string | null
  audience: 'resident' | 'board'
  supports_email: boolean
  supports_push: boolean
  supports_whatsapp: boolean
  is_mandatory: boolean
  is_active: boolean
  channel_email: boolean
  channel_push: boolean
  channel_whatsapp: boolean
  board_role_ids: string[]
}

export interface INotificationConfig {
  events: INotificationEventConfig[]
  board_roles: IBoardRoleOption[]
}

/** Payload shape for each event in PUT /config/notifications. */
export type NotificationEventPatch = Pick<
  INotificationEventConfig,
  'key' | 'is_active' | 'channel_email' | 'channel_push' | 'channel_whatsapp' | 'board_role_ids'
>

// ── Legal types ──────────────────────────────────────────────────────────────

export const LEGAL_TYPES = [
  { value: 'reglamento', label: 'Reglamento' },
  { value: 'acta_constitutiva', label: 'Acta constitutiva' },
  { value: 'aviso_privacidad', label: 'Aviso de privacidad' },
] as const

async function getConfig<T>(path: string): Promise<T> {
  const { data } = await apiClient.get<{ data: T }>(path)
  return data.data
}

/**
 * Capa de acceso a los endpoints `/config/*` (no son una entidad CRUD, por eso
 * es un servicio plano en vez de createCrudApi). Centraliza las llamadas para que
 * los componentes no usen apiClient directo.
 */
export interface IPlanInfo {
  status: string
  plan: {
    id: string
    name: string
    price?: number | null
    unit_limit?: number | null
    interval?: string | null
    features?: unknown
  } | null
  unit_count: number
  unit_limit: number | null
  units_within_limit: boolean
}

export const ConfigurationResource = {
  getGeneral: () => getConfig<GeneralConfig>('/config/general'),
  getOperational: () => getConfig<OperationalConfig>('/config/operational'),
  getCollectionSettings: () => getConfig<CollectionSettings>('/config/collection-settings'),
  getPlan: () => getConfig<IPlanInfo>('/config/plan'),

  save: async (path: string, payload: Record<string, unknown>): Promise<unknown> => {
    const { data } = await apiClient.put(path, payload)
    return data
  },

  getBankData: async (): Promise<ResidentBankData> => {
    const { data } = await apiClient.get<ResidentBankData>('/config/bank-data')
    return data
  },

  uploadLogo: async (file: File): Promise<LogoUploadResult> => {
    const formData = new FormData()
    formData.append('file', file)
    const { data } = await apiClient.post<LogoUploadResult>('/config/branding/logo', formData)
    return data
  },

  getNotificationTemplates: () =>
    getConfig<NotificationTemplate[]>('/config/notification-templates'),

  updateNotificationTemplates: async (templates: NotificationTemplate[]): Promise<unknown> => {
    const { data } = await apiClient.put('/config/notification-templates', { templates })
    return data
  },

  restoreNotificationTemplates: async (): Promise<unknown> => {
    const { data } = await apiClient.post('/config/notification-templates/restore')
    return data
  },

  getLegalDocuments: () => getConfig<LegalDocument[]>('/config/legal'),

  /**
   * Documento legal vigente y visible a residentes de un tipo (§16.3.8).
   * Devuelve null si el condominio aún no publicó ese documento (404 del backend).
   */
  getCurrentLegalDocument: async (type: string): Promise<LegalDocument | null> => {
    try {
      return await getConfig<LegalDocument>(`/config/legal/${type}/current`)
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) return null
      throw error
    }
  },

  getNotificationConfig: () => getConfig<INotificationConfig>('/config/notifications'),

  updateNotificationConfig: async (events: NotificationEventPatch[]): Promise<unknown> => {
    const { data } = await apiClient.put('/config/notifications', { events })
    return data
  },

  uploadLegalDocument: async (
    payload: { type: string; title?: string; is_visible_to_residents?: boolean },
    file: File
  ): Promise<unknown> => {
    const formData = new FormData()
    formData.append('type', payload.type)
    if (payload.title) formData.append('title', payload.title)
    formData.append('is_visible_to_residents', payload.is_visible_to_residents ? '1' : '0')
    formData.append('file', file)
    const { data } = await apiClient.post('/config/legal', formData)
    return data
  },
}
