import type { ICompany } from '@/modules/company/schema'
import { CrudMode } from '@/shared/enums/CrudMode'

export interface IPaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from: number | null
  to: number | null
  first_page_url?: string
  last_page_url?: string
  next_page_url?: string | null
  prev_page_url?: string | null
  path?: string
}

export interface PaginatedResponse<T> {
  data: T[]
  total?: number
  meta?: IPaginationMeta
  links?: {
    first?: string
    last?: string
    prev?: string | null
    next?: string | null
  }
}

export interface IEntity {
  id: string
  name?: string
  description?: string
  include?: string
  created_at?: string
  updated_at?: string
  deleted_at?: string | null
  media?: IMedia[]
  tenant_id?: string
  company?: ICompany
  _method?: string
  [key: string]: unknown
}

export interface IMedia {
  id: string
  collection_name: string
  name: string
  file_name: string
  mime_type: string
  disk: string
  conversions_disk: string
  size: number
  manipulations: unknown[]
  custom_properties: unknown[]
  generated_conversions: unknown[]
  responsive_images: unknown[]
  order_column: number
  created_at: string
  updated_at: string
  model_type: string
  model_id: string
  original_url: string
  preview_url: string
}

export interface IFormProps<T> {
  entity?: Partial<T> | null
  mode: CrudMode
  onCancel?: () => void
  onSuccess?: (entity: T) => void
  formId?: string
}

/**
 * Roles EDI (slugs de Spatie en backend, roles.csv).
 * SUPER_ADMIN administra la plataforma; ACCOUNT_OWNER es quien registró el
 * organización (rol vivo del flujo de registro); el resto son roles operativos
 * dentro del organización.
 */
export enum ERoleUserSlug {
  SUPER_ADMIN = 'admin.super',
  ACCOUNT_OWNER = 'account.owner',
  ADMINISTRATOR = 'administrator',
  PRESIDENT = 'president',
  TREASURER = 'treasurer',
  SECRETARY = 'secretary',
  VOCAL = 'vocal',
  OVERSIGHT_COMMITTEE = 'oversight_committee',
  SECURITY = 'security',
  OPERATIONS_STAFF = 'operations_staff',
  RESIDENT = 'resident',
  RENTER = 'renter',
}

export const roleRoutes: Record<ERoleUserSlug, string> = {
  [ERoleUserSlug.SUPER_ADMIN]: '/user',
  [ERoleUserSlug.ACCOUNT_OWNER]: '/dashboard',
  [ERoleUserSlug.ADMINISTRATOR]: '/dashboard',
  [ERoleUserSlug.PRESIDENT]: '/dashboard',
  [ERoleUserSlug.TREASURER]: '/dashboard',
  [ERoleUserSlug.SECRETARY]: '/dashboard',
  [ERoleUserSlug.VOCAL]: '/dashboard',
  [ERoleUserSlug.OVERSIGHT_COMMITTEE]: '/dashboard',
  [ERoleUserSlug.SECURITY]: '/access',
  [ERoleUserSlug.OPERATIONS_STAFF]: '/collaborator/me',
  [ERoleUserSlug.RESIDENT]: '/my-unit',
  [ERoleUserSlug.RENTER]: '/my-unit',
}
