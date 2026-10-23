/** Display helpers for the per-child reports (S4/S5). */

export function formatKilograms(grams: number | null | undefined): string {
  return grams == null ? '—' : `${(grams / 1000).toFixed(1)} kg`
}

export function formatCentimeters(millimeters: number | null | undefined): string {
  return millimeters == null ? '—' : `${(millimeters / 10).toFixed(1)} cm`
}

/** `YYYY-MM` → `MM-YYYY` (legacy "Próxima evaluación (mes-año)"). */
export function formatMonthYear(month: string | null | undefined): string {
  if (!month) return '—'
  const [year, monthNumber] = month.split('-')
  return `${monthNumber}-${year}`
}
