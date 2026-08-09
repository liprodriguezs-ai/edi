import type { ICompany } from '@/modules/company/schema'
import type { IUser } from '@/modules/user/schema'
import type { IEntity, IMedia } from '@/shared/interfaces/Entity'
import { z } from 'zod'

/**
 * Días laborales: el backend los persiste como array JSON con claves EN INGLÉS
 * (`CollaboratorRequest::WORK_DAYS`). La etiqueta en español es sólo de UI.
 */
export type WorkDay =
  | 'monday'
  | 'tuesday'
  | 'wednesday'
  | 'thursday'
  | 'friday'
  | 'saturday'
  | 'sunday'

export const WORK_DAY_OPTIONS: Array<{ id: WorkDay; name: string }> = [
  { id: 'monday', name: 'Lunes' },
  { id: 'tuesday', name: 'Martes' },
  { id: 'wednesday', name: 'Miércoles' },
  { id: 'thursday', name: 'Jueves' },
  { id: 'friday', name: 'Viernes' },
  { id: 'saturday', name: 'Sábado' },
  { id: 'sunday', name: 'Domingo' },
]

const WORK_DAY_KEYS: WorkDay[] = WORK_DAY_OPTIONS.map((day) => day.id)

/** Filtra cualquier valor no reconocido y preserva el orden de la semana. */
export function normalizeWorkDays(value: unknown): WorkDay[] {
  if (!Array.isArray(value)) return []
  return WORK_DAY_KEYS.filter((key) => value.includes(key))
}

/** Etiquetas en español listas para mostrar: "Lunes, Martes, Miércoles". */
export function formatWorkDays(value: unknown): string | undefined {
  const selected = normalizeWorkDays(value)
  if (selected.length === 0) return undefined
  return WORK_DAY_OPTIONS.filter((day) => selected.includes(day.id))
    .map((day) => day.name)
    .join(', ')
}

export const collaboratorFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  last_name: z.string().min(1, 'El apellido paterno es requerido'),
  second_last_name: z.string().optional(),
  email: z.string().email('El correo debe ser válido').optional().or(z.literal('')),
  phone: z.string().optional(),
  employee_position_id: z.string().min(1, 'El puesto es requerido'),
  pay_frequency_id: z.string().optional(),
  employee_shift_id: z.string().optional(),
  /** En PESOS — se convierte a minor units al enviar */
  base_salary: z.number().min(0).nullable(),
  work_days: z.array(z.enum(WORK_DAY_KEYS as [WorkDay, ...WorkDay[]])),
  hire_date: z.string().min(1, 'La fecha de alta es requerida'),
  specific_schedule: z.string().optional(),
  address: z.string().optional(),
  rfc: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || (v.trim().length >= 12 && v.trim().length <= 13), {
      message: 'El RFC debe tener 12 o 13 caracteres.',
    }),
  curp: z
    .string()
    .optional()
    .refine((v) => !v || v.trim() === '' || v.trim().length === 18, {
      message: 'El CURP debe ser de 18 caracteres.',
    }),
  nss: z.string().optional(),
  date_of_birth: z.string().optional(),
  create_as_user: z.boolean(),
})

export type ExpedienteStatus = 'complete' | 'incomplete' | 'critical'
export type ICatalogEntity = IEntity & { name?: string }

export interface ICollaborator extends IEntity {
  number?: string
  name?: string
  last_name?: string
  second_last_name?: string
  email?: string
  phone?: string
  full_name?: string
  user_id?: string
  user?: IUser
  job_position_id?: string
  job_position?: ICatalogEntity
  area_id?: string
  area?: ICatalogEntity
  contract_type_id?: string
  contract_type?: ICatalogEntity
  rfc?: string
  curp?: string
  date_of_birth?: string
  contract_at?: string
  contract_end_at?: string
  gender?: string
  contracted?: boolean
  nss?: string
  create_as_user?: boolean
  company_id?: string
  company?: ICompany
  nationality_id?: string
  nationality?: ICatalogEntity
  birth_place?: string
  marital_status_id?: string
  marital_status?: ICatalogEntity
  address?: string
  official_identification?: string
  beneficiary?: string
  work_shift_id?: string
  work_shift?: ICatalogEntity
  specific_schedule?: string
  employee_position_id?: string
  employee_position?: ICatalogEntity
  pay_frequency_id?: string
  pay_frequency?: ICatalogEntity
  employee_shift_id?: string
  employee_shift?: ICatalogEntity
  base_salary?: { amount?: string; formatted?: string } | number | null
  /** Array JSON con claves en inglés (monday…sunday). */
  work_days?: WorkDay[] | null
  hire_date?: string | null
  termination_date?: string | null
  termination_reason?: string | null
  is_active?: boolean
  salary?: { amount?: number | string | null }
  salary_amount?: number
  marriage_record_number?: string
  expediente_status?: ExpedienteStatus
  /** From me endpoint: total document types in catalog */
  total_documents?: number
  /** From me endpoint: required document types count */
  required_documents?: number
  /** From me endpoint: documents relation (DocumentCollaborator with document, media) */
  documents?: Array<{
    id: string
    document_id: string
    updated_at?: string
    expiration_date?: string
    document?: { id: string; name: string; required?: boolean }
    current_media?: IMedia | null
    media?: IMedia[]
  }>
  /** From me endpoint: document_templates relation (generated contracts) */
  document_templates?: ICatalogEntity[]
  /** From me endpoint: full catalog with upload status */
  document_checklist?: Array<{
    document_id: string
    name: string
    required: boolean
    uploaded: boolean
    updated_at?: string
    expiration_date?: string | null
    original_url?: string | null
    media_id?: string | null
    file_name?: string | null
    document_collaborator_id?: string
  }>
}

export type CollaboratorFormValues = z.infer<typeof collaboratorFormSchema>
export const EXPEDIENTE_CONFIG: Record<ExpedienteStatus, { label: string; className: string }> = {
  complete: {
    label: 'Completo',
    className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  },
  incomplete: {
    label: 'Incompleto',
    className: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  },
  critical: {
    label: 'Crítico',
    className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  },
}

export const GENDER_LABELS: Record<string, string> = {
  masculino: 'Masculino',
  femenino: 'Femenino',
}
