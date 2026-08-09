import type { IEntity } from '@/shared/interfaces/Entity'
import type { IMoney } from '@/shared/utils/money'
import { z } from 'zod'

export const COMMUNITY_PROJECT_STATUS = {
  DRAFT: 'ccc0f0f1-ddea-4bb1-b793-05205a67835e',
  ACTIVE: 'cf692500-db9b-40da-b441-8fec9f546bf8',
  FULLY_FUNDED: 'f28b7545-56eb-4080-93cf-f51d56c80046',
  EXECUTED: 'bd6182b6-83ce-40c6-8f97-030cb5dd311a',
  CANCELLED: '5e179ecd-221b-454b-9cfd-a3ca2192a54d',
} as const

export const PARTICIPATION_TYPE = {
  MANDATORY: 'c4814f21-ba4f-49f3-9b5d-2d20dea38296',
  VOLUNTARY: '8ad8e08a-031c-4a6c-b7f3-bd548fa743e2',
} as const

export const PROJECT_CONTRIBUTION_STATUS = {
  PENDING: 'f145292c-6de1-4d38-8b96-89c793374784',
  PARTIAL: 'defd8a59-79e6-4930-9b82-623346530209',
  IDEAL: '54099f12-2680-4c81-a648-067a02c183f9',
  GENEROUS: '8beef447-21b1-406a-9118-1266ab18cc6b',
  BELOW_MIN: 'b94c78d1-5aeb-4d35-8d46-32a0db8e5148',
  DECLINED: 'bdd98e40-c08d-40ad-af46-ac01f9b99b1f',
} as const

export interface IProjectContribution extends IEntity {
  community_project_id: string
  unit_id: string
  expected_amount?: IMoney | number | null
  contributed_amount?: IMoney | number | null
  declined?: boolean
  project_contribution_status_id?: string | null
  project?: ICommunityProject | null
  unit?: { id: string; number?: string | null; full_identifier?: string } | null
  contribution_status?: { id: string; name: string } | null
}

export interface ICommunityProject extends IEntity {
  title: string
  community_project_category_id?: string | null
  participation_type_id: string
  amount_mode_id?: string | null
  fixed_amount?: IMoney | number | null
  min_amount?: IMoney | number | null
  ideal_amount?: IMoney | number | null
  max_amount?: IMoney | number | null
  allow_decline?: boolean
  goal_amount?: IMoney | number | null
  start_date?: string | null
  due_date?: string | null
  visible_to_residents?: boolean
  payments_visible_to_residents?: boolean
  allow_comments?: boolean
  description?: string
  description_html?: string | null
  community_project_status_id: string
  status?: { id: string; name: string } | null
  category?: { id: string; name: string } | null
  participation_type?: { id: string; name: string } | null
}

export interface IProjectComment extends IEntity {
  community_project_id: string
  user_id: string
  body: string
  hidden?: boolean
  user?: { id: string; name?: string } | null
}

export interface ProjectSummary {
  raised: IMoney
  executed: IMoney
  available: IMoney
  [key: string]: unknown
}

/** Montos en PESOS */
export const communityProjectFormSchema = z
  .object({
    title: z.string().min(1, 'El título es requerido').max(255),
    description_html: z.string().max(20000).optional(),
    community_project_category_id: z.string().min(1, 'La categoría es requerida'),
    participation_type_id: z.string().min(1, 'El tipo de participación es requerido'),
    goal_amount: z.number().min(0).nullable(),
    ideal_amount: z.number().min(0).nullable(),
    min_amount: z.number().min(0).nullable(),
    allow_decline: z.boolean(),
    start_date: z.string().min(1, 'La fecha de inicio es requerida'),
    due_date: z.string().optional(),
    visible_to_residents: z.boolean(),
    payments_visible_to_residents: z.boolean(),
    allow_comments: z.boolean(),
  })
  .superRefine((values, ctx) => {
    if (
      values.participation_type_id === PARTICIPATION_TYPE.VOLUNTARY &&
      (values.ideal_amount == null || values.ideal_amount <= 0)
    ) {
      ctx.addIssue({
        code: 'custom',
        path: ['ideal_amount'],
        message: 'El monto ideal es requerido para proyectos voluntarios',
      })
    }
  })

export type CommunityProjectFormValues = z.infer<typeof communityProjectFormSchema>

/**
 * Edición restringida post-creación (decisión de producto 2026-07): una vez creado
 * el proyecto solo se editan su presentación (título, descripción enriquecida) y las
 * dos casillas de visibilidad. Todo lo estructural/monetario queda congelado y el
 * backend lo rechaza (422).
 */
export const communityProjectEditSchema = z.object({
  title: z.string().min(1, 'El título es requerido').max(255),
  description_html: z.string().max(20000).optional(),
  visible_to_residents: z.boolean(),
  payments_visible_to_residents: z.boolean(),
})

export type CommunityProjectEditValues = z.infer<typeof communityProjectEditSchema>

/**
 * Aportación voluntaria a un proyecto (§11.8.1): el residente registra el pago de
 * su aportación. El monto va en PESOS y se convierte a minor units antes de enviar
 * (POST /community-project/{id}/contribute exige integer minor units).
 */
export const contributeFormSchema = z.object({
  amount: z.number({ message: 'El monto es requerido' }).positive('El monto debe ser mayor a 0'),
  payment_method_id: z.string().min(1, 'El método de pago es requerido'),
  bank_account_id: z.string().min(1, 'La cuenta destino es requerida'),
  paid_at: z.string().min(1, 'La fecha es requerida'),
  reference: z.string().max(255).optional(),
})

export type ContributeFormValues = z.infer<typeof contributeFormSchema>

/**
 * Reporte de pago de una aportación (flujo con revisión): el residente captura su
 * pago con comprobante; queda pendiente hasta que la mesa directiva lo valide.
 * Espejo de `contributeFormSchema` SIN `bank_account_id` (el backend usa la cuenta
 * primaria del clúster, igual que `/payment/submit`). Monto en PESOS.
 */
export const reportPaymentFormSchema = z.object({
  amount: z.number({ message: 'El monto es requerido' }).positive('El monto debe ser mayor a 0'),
  payment_method_id: z.string().min(1, 'El método de pago es requerido'),
  paid_at: z.string().min(1, 'La fecha es requerida'),
  reference: z.string().max(255).optional(),
})

export type ReportPaymentFormValues = z.infer<typeof reportPaymentFormSchema>
