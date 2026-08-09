import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

const optionalString = z.preprocess(
  (v) => (v == null ? '' : v),
  z.string().optional(),
)

export const companyFormSchema = z.object({
  name: z.preprocess((v) => (v == null ? '' : v), z.string().min(1, 'El nombre es requerido')),
  description: optionalString,
  rfc: optionalString,
  legal_name: optionalString,
  legal_representative: optionalString,
  employer_registration: optionalString,
  fiscal_address: optionalString,
  constitutive_deed_number: optionalString,
  constitutive_deed_date: optionalString,
  constitutive_notary_number: optionalString,
  constitutive_notary_name: optionalString,
  constitutive_notary_state: optionalString,
  industry: optionalString,
  employee_count: z.preprocess(
    (v) => (v == null || typeof v !== 'number' ? 50 : v),
    z.number().min(1).max(500),
  ),
})

export interface ICompany extends IEntity {
  name: string
  domain?: string
  description?: string
  rfc?: string
  legal_name?: string
  legal_representative?: string
  employer_registration?: string
  fiscal_address?: string
  constitutive_deed_number?: string
  constitutive_deed_date?: string
  constitutive_notary_number?: string
  constitutive_notary_name?: string
  constitutive_notary_state?: string
  industry?: string
  employee_count?: number
  logo_url?: string
  favicon_url?: string
}

export type CompanyFormValues = z.infer<typeof companyFormSchema>
