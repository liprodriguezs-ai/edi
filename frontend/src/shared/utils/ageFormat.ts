/** Months of 30 days: the convention of the EDI age tables. */
const DAYS_PER_MONTH = 30

/**
 * "3 meses 12 días" from an age in days. A negative age (corrected age before
 * the due date) has no months and is shown in days only.
 */
export function formatAgeDays(days: number): string {
  if (days < 0) return `${days} días`

  const months = Math.floor(days / DAYS_PER_MONTH)
  const remaining = days % DAYS_PER_MONTH
  const monthsText = months === 1 ? '1 mes' : `${months} meses`
  const daysText = remaining === 1 ? '1 día' : `${remaining} días`

  if (months === 0) return daysText
  if (remaining === 0) return monthsText
  return `${monthsText} ${daysText}`
}
