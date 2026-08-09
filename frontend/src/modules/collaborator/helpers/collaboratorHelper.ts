import { GENDER_LABELS } from '@/modules/collaborator/schema'
import { displayFormatCurrency } from '@/shared/utils/currencyHelper'

export function formatSalary(value?: number | string | null): string {
  if (value === undefined || value === null || value === '') return '—'
  const amount = typeof value === 'number' ? value : Number(value)
  if (Number.isNaN(amount)) return String(value)
  return displayFormatCurrency(amount)
}

export function formatGender(value?: string | null): string | undefined {
  if (!value) return undefined
  return GENDER_LABELS[value] ?? value
}

export function formatMaritalStatus(value?: string | null, gender?: string | null): string | undefined {
  if (!value) return undefined
  const v = value.trim()
  const feminineMap: Record<string, string> = { Soltero: 'Soltera', Casado: 'Casada' }
  const normalizedGender = gender?.toLowerCase().trim().replace(/-/g, '_').replace(/\s+/g, '_')
  if (normalizedGender === 'femenino') return feminineMap[v] ?? v
  if (normalizedGender === 'masculino') return v
  return `${v}(a)`
}

