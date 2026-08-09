import type { BillingInterval, BillingPaymentMethod } from '@/modules/tenant/billing/schema'
import type { IUser } from '@/modules/user/schema'
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export type TenantStatus = 'activo' | 'pendiente_pago' | 'bloqueado'

export const TENANT_STATUS_CONFIG: Record<TenantStatus, { label: string; className: string }> = {
  activo: {
    label: 'Activo',
    className:
      'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400',
  },
  pendiente_pago: {
    label: 'Pendiente de pago',
    className:
      'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-400',
  },
  bloqueado: {
    label: 'Bloqueado',
    className: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400',
  },
}

/**
 * Edición de un tenant existente (super-admin). SOLO metadatos: nombre + estado.
 * La facturación (plan, método de pago, entitlements) se administra en el modal
 * de facturación del tenant, NO acá — por eso ya no hay selects de plan/tipo de
 * pago legacy en este form (eso causaba el bug de opciones duplicadas y ataba el
 * tenant al `subscription_plan_id` legacy). El alta se hace en el wizard con
 * facturación integrada (ver `tenantProvisionFormSchema`).
 */
export const tenantFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  status: z.enum(['activo', 'pendiente_pago', 'bloqueado'], {
    message: 'El estatus es requerido',
  }),
})

export interface ITenant extends IEntity {
  name: string
  tenancy_db_name?: string
  status?: TenantStatus
  tenant_payment_type_id?: string | null
  payment_method?: BillingPaymentMethod
  timezone?: string
  currency?: string
  type?: string | null
  uses_ownership_share?: boolean
  payment_type?: { id: string; name: string }
  owners?: IUser[]
}

export type TenantFormValues = z.infer<typeof tenantFormSchema>

/**
 * Modo de provisión de la facturación al dar de alta un tenant desde super-admin.
 *
 *  - trial       → prueba de 30 días del paquete base (sin cobro).
 *  - courtesy    → acceso gratuito y permanente a los paquetes elegidos (cortesía).
 *  - manual_paid → se registra un pago manual (transferencia/efectivo) de los
 *                  paquetes elegidos; el monto lo calcula el backend.
 *  - card        → el dueño paga con tarjeta (Stripe) más tarde; el organización queda
 *                  bloqueado hasta que complete el pago.
 */
export type TenantProvisionMode = 'trial' | 'courtesy' | 'manual_paid' | 'card'

export const PROVISION_MODE_CONFIG: Record<
  TenantProvisionMode,
  { label: string; description: string }
> = {
  trial: {
    label: 'Prueba de 30 días',
    description:
      'El organización arranca con el plan activo durante 30 días sin costo. Al vencer, el dueño deberá pagar para no quedar bloqueado.',
  },
  courtesy: {
    label: 'Cortesía (gratis)',
    description:
      'Otorga acceso gratuito y permanente al plan que elijas. No se cobra nada y no vence. Úsalo para organizaciones exentos.',
  },
  manual_paid: {
    label: 'Pago manual registrado',
    description:
      'Registra un pago recibido por transferencia o efectivo. Se activa el acceso por un ciclo y queda asentado en el historial. El monto lo calcula el sistema.',
  },
  card: {
    label: 'Tarjeta (el dueño paga)',
    description:
      'No se activa nada ahora. El organización queda bloqueado hasta que el dueño complete el pago con tarjeta (Stripe) desde su cuenta.',
  },
}

/**
 * Alta de tenant + facturación integrada (super-admin). Los datos del armador de
 * plan (unidades, intervalo, paquetes) se manejan aparte del RHF; sólo estos
 * campos base van en el schema. El backend es la ÚNICA autoridad de los montos.
 */
export const tenantProvisionFormSchema = z
  .object({
    name: z.string().min(1, 'El nombre es requerido'),
    provision_mode: z.enum(['trial', 'courtesy', 'manual_paid', 'card'], {
      message: 'Elige un modo de provisión',
    }),
    payment_method: z.enum(['card', 'transfer', 'cash'], {
      message: 'Elige un método de pago',
    }),
    notes: z.string().optional(),
  })
  .superRefine((values, ctx) => {
    if (values.provision_mode === 'manual_paid' && values.payment_method === 'card') {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payment_method'],
        message: 'Un pago manual requiere transferencia o efectivo.',
      })
    }
    if (values.provision_mode === 'card' && values.payment_method !== 'card') {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['payment_method'],
        message: 'Este modo requiere método de pago tarjeta.',
      })
    }
  })

export type TenantProvisionFormValues = z.infer<typeof tenantProvisionFormSchema>

/**
 * Cuerpo de POST /tenant/provision (ya listo para enviar al backend).
 *
 * Modelo por PLAN: `plan_key` (basico..premium, opcional — el backend lo resuelve
 * si falta) e `interval`, requerido en pago manual (define el monto). El backend es
 * la ÚNICA autoridad de los montos.
 *
 * `modules` y `units` quedaron OPCIONALES en el contrato y el frontend YA NO los
 * envía: el backend provisiona el plan completo y la banda de unidades es un atributo
 * del plan elegido, no un dato que se capture aparte.
 */
export interface IProvisionTenantRequest {
  name: string
  payment_method: BillingPaymentMethod
  provision_mode: TenantProvisionMode
  plan_key?: string
  modules?: string[]
  interval?: BillingInterval
  notes?: string
}
